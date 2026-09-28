<?php

namespace dmstr\knowledgeLibrary\controllers;

use dmstr\knowledgeLibrary\files\FileService;
use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\Module;
use Yii;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Delivers the files of versions.
 *
 * The files are read from the file storage of the module and are only
 * delivered through this controller, so the route permission of the package
 * applies (`knowledge-library_file_download`, granted to everyone who may
 * see the detail page of an item, including the files of drafts).
 *
 * @property Module $module
 */
class FileController extends BaseController
{
    protected function verbs(): array
    {
        return array_merge(parent::verbs(), [
            'download' => ['GET'],
        ]);
    }

    /**
     * Sends the file as download (`Content-Disposition: attachment`) with its
     * original name, MIME type and size.
     *
     * @throws NotFoundHttpException if the file row, its version or item, or
     * the stored file does not exist
     */
    public function actionDownload(string $id): Response
    {
        $file = File::find()
            ->andWhere([File::tableName() . '.[[id]]' => $id])
            ->with('version.item')
            ->one();
        if ($file === null || $file->version === null || $file->version->item === null) {
            throw new NotFoundHttpException(Yii::t('knowledge-library', 'The requested file does not exist.'));
        }

        $stream = (new FileService($this->module))->readStream($file);
        if ($stream === null) {
            throw new NotFoundHttpException(Yii::t('knowledge-library', 'The requested file does not exist.'));
        }

        $options = [
            'mimeType' => $file->mime_type ?: 'application/octet-stream',
            'inline' => false,
        ];
        if ($file->size !== null) {
            $options['fileSize'] = (int)$file->size;
        }

        return $this->response->sendStreamAsFile($stream, $file->name, $options);
    }
}
