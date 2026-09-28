<?php

namespace dmstr\knowledgeLibrary\files;

use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\users\DefaultUserProvider;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Throwable;
use Yii;
use yii\base\InvalidArgumentException;
use yii\db\Query;
use yii\helpers\FileHelper;
use yii\validators\FileValidator;
use yii\web\Response;
use yii\web\UploadedFile;

/**
 * Stores, copies and removes the files of versions.
 *
 * Files are written to the flysystem filesystem of the module (see
 * Module::getFilesystem()) under `<targetPath>/<item-id>/<file-id>.<ext>`.
 * Several file rows may point to the same stored file, e.g. when a new
 * version takes over the files of its predecessor; a stored file is deleted
 * only when the last row pointing to it is gone.
 *
 * Storage errors while deleting are logged as warnings and never thrown, so
 * database cleanups are not blocked by the storage.
 */
class FileService
{
    private Module $module;

    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    /**
     * Stores an uploaded file for a saved version.
     *
     * The upload is checked against the allowed extensions (also by its MIME
     * type) and the maximum size of the module. The file is read from its
     * temporary file as stream; `saveAs()` is not used.
     *
     * If the file is main file, the item records it as source upload
     * (`source_uploaded_at`, `source_uploaded_by`; the latest upload wins).
     *
     * @param string $kind File::KIND_MAIN or File::KIND_ATTACHMENT
     * @param string|null $title title of an attachment
     *
     * @return File the saved file row; if the upload was rejected or could
     * not be stored, an unsaved file (`getIsNewRecord()` is true) with the
     * reason as error of the attribute `name`. A rejected upload writes
     * neither a row nor a stored file.
     */
    public function store(Version $version, UploadedFile $upload, string $kind, ?string $title = null): File
    {
        if ($version->getIsNewRecord()) {
            throw new InvalidArgumentException('The version must be saved before files can be stored.');
        }

        $name = static::baseName((string)$upload->name);
        $extension = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION), 'UTF-8');

        $file = new File();
        $file->setAttributes([
            'version_id' => $version->id,
            'kind' => $kind,
            'title' => $title === null || trim($title) === '' ? null : $title,
            'storage_id' => $this->module->fileStorage,
            'storage_item_id' => null,
            'name' => $name,
        ], false);
        $file->path = $this->module->targetPath . '/' . $version->item_id . '/' . $file->id
            . ($extension !== '' ? '.' . $extension : '');

        $error = $this->validateUpload($upload, $name);
        if ($error !== null) {
            $file->addError('name', $error);

            return $file;
        }

        $file->size = (int)filesize($upload->tempName);
        $file->mime_type = FileHelper::getMimeType($upload->tempName, null, false);
        $file->content_hash = hash_file('sha256', $upload->tempName);
        $file->position = $this->nextPosition($version, $kind);

        if (!$file->validate()) {
            return $file;
        }

        $filesystem = $this->module->getFilesystem();
        $stream = @fopen($upload->tempName, 'rb');
        if ($stream === false) {
            return $this->storeFailed($file, "Could not open the uploaded file {$upload->tempName}.");
        }
        try {
            $filesystem->writeStream($file->path, $stream);
        } catch (FilesystemException $e) {
            return $this->storeFailed($file, $e->getMessage());
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $transaction = File::getDb()->beginTransaction();
        try {
            $saved = $file->save(false);
            if ($saved && $kind === File::KIND_MAIN) {
                $this->recordSourceUpload($version);
            }
            $saved ? $transaction->commit() : $transaction->rollBack();
        } catch (Throwable $e) {
            $transaction->rollBack();
            $file->setIsNewRecord(true);
            $this->deleteStoredFile($filesystem, $file->path);

            throw $e;
        }

        if (!$saved) {
            $file->setIsNewRecord(true);
            $this->deleteStoredFile($filesystem, $file->path);

            return $this->storeFailed($file, 'The file row could not be saved.');
        }

        return $file;
    }

    /**
     * Copies a file row to another version; the stored file is shared.
     *
     * @return File the saved copy, or the unsaved copy with errors
     */
    public function copyToVersion(File $file, Version $target): File
    {
        return $file->copyToVersion($target);
    }

    /**
     * Deletes the file row and the stored file, unless another file row
     * still points to it.
     */
    public function remove(File $file): void
    {
        $reference = ['storage_id' => $file->storage_id, 'path' => $file->path];
        $file->delete();
        $this->deleteStoragePaths([$reference]);
    }

    /**
     * Storage references (storage and path) of all file rows of the item,
     * each once. Collect them before deleting the item and pass them to
     * deleteStoragePaths() afterwards.
     *
     * @return array<int, array{storage_id: string, path: string}>
     */
    public function collectStoragePaths(Item $item): array
    {
        return (new Query())
            ->select(['file.storage_id', 'file.path'])
            ->distinct()
            ->from(['file' => File::tableName()])
            ->innerJoin(['version' => Version::tableName()], '[[version.id]] = [[file.version_id]]')
            ->where(['version.item_id' => $item->id])
            ->orderBy(['file.path' => SORT_ASC])
            ->all(File::getDb());
    }

    /**
     * Deletes the stored files no file row points to anymore.
     *
     * @param array<int, array{storage_id: string, path: string}> $paths
     * storage references, see collectStoragePaths()
     *
     * @return array{deleted: int, kept: int, failed: int} number of deleted
     * stored files, of files kept because rows still point to them, and of
     * failed deletions (logged as warning)
     */
    public function deleteStoragePaths(array $paths): array
    {
        $result = ['deleted' => 0, 'kept' => 0, 'failed' => 0];
        $seen = [];

        foreach ($paths as $reference) {
            $storageId = (string)$reference['storage_id'];
            $path = (string)$reference['path'];
            $key = $storageId . "\0" . $path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if (File::find()->where(['storage_id' => $storageId, 'path' => $path])->exists()) {
                $result['kept']++;
                continue;
            }

            try {
                $filesystem = $this->filesystemFor($storageId);
            } catch (Throwable $e) {
                Yii::warning("Could not delete the stored file $path: {$e->getMessage()}", __METHOD__);
                $result['failed']++;
                continue;
            }

            $result[$this->deleteStoredFile($filesystem, $path) ? 'deleted' : 'failed']++;
        }

        return $result;
    }

    /**
     * Files with the same content attached to other items, at most one per
     * item (the most recent row), with version and item loaded.
     *
     * @return File[]
     */
    public function findDuplicates(File $file): array
    {
        if ($file->content_hash === null || $file->content_hash === '') {
            return [];
        }

        $itemId = $file->version !== null ? $file->version->item_id : null;

        $query = File::find()
            ->alias('file')
            ->innerJoinWith('version version', false)
            ->with('version.item')
            ->where(['file.content_hash' => $file->content_hash])
            ->andWhere(['not', ['file.id' => $file->id]])
            ->orderBy(['file.created_at' => SORT_DESC, 'file.id' => SORT_ASC]);
        if ($itemId !== null) {
            $query->andWhere(['not', ['version.item_id' => $itemId]]);
        }

        $duplicates = [];
        foreach ($query->all() as $duplicate) {
            /** @var File $duplicate */
            $duplicates[$duplicate->version->item_id] ??= $duplicate;
        }

        return array_values($duplicates);
    }

    /**
     * Opens the stored file for reading.
     *
     * @return resource|null the stream, null if the stored file is missing
     * or cannot be read
     */
    public function readStream(File $file)
    {
        try {
            $filesystem = $this->filesystemFor($file->storage_id);
            if (!$filesystem->fileExists($file->path)) {
                return null;
            }

            return $filesystem->readStream($file->path);
        } catch (Throwable $e) {
            Yii::warning("Could not read the stored file {$file->path}: {$e->getMessage()}", __METHOD__);

            return null;
        }
    }

    /**
     * Sends the stored file as download (`Content-Disposition: attachment`)
     * with its original name, MIME type and size.
     *
     * Whether the current user may download the file is up to the caller.
     *
     * @return Response|null the response, null if the stored file is missing
     * or cannot be read
     */
    public function send(File $file, Response $response): ?Response
    {
        $stream = $this->readStream($file);
        if ($stream === null) {
            return null;
        }

        $options = [
            'mimeType' => $file->mime_type ?: 'application/octet-stream',
            'inline' => false,
        ];
        if ($file->size !== null) {
            $options['fileSize'] = (int)$file->size;
        }

        return $response->sendStreamAsFile($stream, $file->name, $options);
    }

    /**
     * Human readable size limit, e.g. "20 MB".
     */
    public function getFormattedMaxFileSize(): string
    {
        $bytes = $this->module->maxFileSize;
        $formatter = Yii::$app->formatter;

        if ($bytes >= 1024 * 1024) {
            return $formatter->asDecimal($bytes / (1024 * 1024), $bytes % (1024 * 1024) === 0 ? 0 : 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return $formatter->asDecimal($bytes / 1024, $bytes % 1024 === 0 ? 0 : 1) . ' KB';
        }

        return $bytes . ' B';
    }

    /**
     * File name without any directory part, e.g. `x.pdf` for `../x.pdf`.
     */
    public static function baseName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $position = strrpos($name, '/');

        return $position === false ? $name : substr($name, $position + 1);
    }

    /**
     * Checks the upload, returns the error message or null.
     */
    private function validateUpload(UploadedFile $upload, string $name): ?string
    {
        $checked = clone $upload;
        $checked->name = $name;
        if ($checked->error === UPLOAD_ERR_OK) {
            if (!is_file((string)$checked->tempName)) {
                $checked->error = UPLOAD_ERR_NO_FILE;
            } else {
                // Rely on the actual size, not on the size reported with the upload.
                $checked->size = (int)filesize($checked->tempName);
            }
        }

        // Exposes the validation result; the size limit is the configured
        // maximum, independent of the PHP upload limits, and shown as given.
        $validator = new class ([
            'extensions' => $this->module->allowedExtensions,
            'checkExtensionByMimeType' => true,
            'maxSize' => $this->module->maxFileSize,
            'formattedLimit' => $this->getFormattedMaxFileSize(),
            'wrongExtension' => Yii::t(
                'knowledge-library',
                'The file "{file}" is not an allowed type (allowed: {extensions}).'
            ),
            'tooBig' => Yii::t('knowledge-library', 'The file "{file}" is larger than {formattedLimit}.'),
            'uploadRequired' => Yii::t('knowledge-library', 'The file "{file}" could not be uploaded.'),
            'message' => Yii::t('knowledge-library', 'The file "{file}" could not be uploaded.'),
        ]) extends FileValidator {
            public string $formattedLimit = '';

            public function check(UploadedFile $upload): ?array
            {
                $result = $this->validateValue($upload);
                if ($result !== null && array_key_exists('formattedLimit', $result[1])) {
                    $result[1]['formattedLimit'] = $this->formattedLimit;
                }

                return $result;
            }

            public function getSizeLimit()
            {
                return $this->maxSize;
            }
        };

        $result = $validator->check($checked);
        if ($result === null) {
            return null;
        }

        [$message, $params] = $result;

        return Yii::$app->getI18n()->format($message, array_merge($params, ['file' => $name]), Yii::$app->language);
    }

    /**
     * Logs a storage failure and adds the error to the unsaved file.
     */
    private function storeFailed(File $file, string $reason): File
    {
        Yii::error("Could not store the file {$file->path}: $reason", __METHOD__);
        $file->addError('name', Yii::t(
            'knowledge-library',
            'The file "{file}" could not be stored.',
            ['file' => $file->name]
        ));

        return $file;
    }

    private function nextPosition(Version $version, string $kind): int
    {
        $max = File::find()->where(['version_id' => $version->id, 'kind' => $kind])->max('position');

        return $max === null ? 0 : (int)$max + 1;
    }

    private function recordSourceUpload(Version $version): void
    {
        $item = $version->item;
        if (!$item instanceof Item) {
            return;
        }

        $item->updateAttributes([
            'source_uploaded_at' => date('Y-m-d H:i:s'),
            'source_uploaded_by' => DefaultUserProvider::resolve()->getCurrentUserReference(),
        ]);
    }

    /**
     * Filesystem of the storage component the file row names; usually the
     * one of the module.
     */
    private function filesystemFor(string $storageId): FilesystemOperator
    {
        if ($storageId === $this->module->fileStorage) {
            return $this->module->getFilesystem();
        }

        $module = clone $this->module;
        $module->fileStorage = $storageId;

        return $module->getFilesystem();
    }

    private function deleteStoredFile(FilesystemOperator $filesystem, string $path): bool
    {
        try {
            $filesystem->delete($path);

            return true;
        } catch (Throwable $e) {
            Yii::warning("Could not delete the stored file $path: {$e->getMessage()}", __METHOD__);

            return false;
        }
    }
}

