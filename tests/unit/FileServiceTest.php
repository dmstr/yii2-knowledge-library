<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\files\FileService;
use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\tests\TestCase;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;
use Yii;
use yii\log\Logger;
use yii\web\Request;
use yii\web\Response;
use yii\web\UploadedFile;

class FileServiceTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    /**
     * @var string[] temporary upload files
     */
    private array $uploads = [];

    protected function tearDown(): void
    {
        foreach ($this->uploads as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->uploads = [];

        parent::tearDown();
    }

    public function testStoreWritesFileAndRow(): void
    {
        $version = $this->createVersion($this->createItem());
        DummyUserProvider::$currentReference = 'uploader';

        $file = $this->service()->store($version, $this->upload('Guideline.PDF', self::PDF), File::KIND_MAIN);

        $this->assertFalse($file->getIsNewRecord(), json_encode($file->getErrors()));
        $file = File::findOne($file->id);
        $this->assertSame($version->id, $file->version_id);
        $this->assertSame(File::KIND_MAIN, $file->kind);
        $this->assertSame('Guideline.PDF', $file->name);
        $this->assertSame('knowledge-library/' . $version->item_id . '/' . $file->id . '.pdf', $file->path);
        $this->assertSame('fs', $file->storage_id);
        $this->assertNull($file->storage_item_id);
        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertEquals(strlen(self::PDF), $file->size);
        $this->assertSame(hash('sha256', self::PDF), $file->content_hash);
        $this->assertEquals(0, $file->position);
        $this->assertNull($file->title);

        $this->assertFileExists($this->getStoragePath($file->path));
        $this->assertSame(self::PDF, file_get_contents($this->getStoragePath($file->path)));

        $item = Item::findOne($version->item_id);
        $this->assertSame('uploader', $item->source_uploaded_by);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $item->source_uploaded_at);
    }

    public function testStoreAttachmentWithTitleAndPositions(): void
    {
        $version = $this->createVersion($this->createItem());
        $service = $this->service();

        $first = $service->store($version, $this->upload('a.txt', 'Alpha text'), File::KIND_ATTACHMENT, 'Form');
        $second = $service->store($version, $this->upload('b.txt', 'Bravo text'), File::KIND_ATTACHMENT, '  ');
        $main = $service->store($version, $this->upload('c.pdf', self::PDF), File::KIND_MAIN);

        $this->assertSame('Form', $first->title);
        $this->assertNull($second->title);
        $this->assertEquals(0, $first->position);
        $this->assertEquals(1, $second->position);
        $this->assertEquals(0, $main->position);
        $this->assertSame('text/plain', $first->mime_type);
        $this->assertStringEndsWith('.txt', $first->path);

        // Attachments are no source upload.
        $this->assertNotNull(Item::findOne($version->item_id)->source_uploaded_at);
        $other = $this->createVersion($this->createItem());
        $service->store($other, $this->upload('d.txt', 'Delta text'), File::KIND_ATTACHMENT);
        $this->assertNull(Item::findOne($other->item_id)->source_uploaded_at);
    }

    public function testLatestMainUploadWins(): void
    {
        $version = $this->createVersion($this->createItem());
        $service = $this->service();

        DummyUserProvider::$currentReference = 'first';
        $service->store($version, $this->upload('a.pdf', self::PDF), File::KIND_MAIN);
        DummyUserProvider::$currentReference = 'second';
        $service->store($version, $this->upload('b.pdf', self::PDF), File::KIND_MAIN);

        $this->assertSame('second', Item::findOne($version->item_id)->source_uploaded_by);
    }

    public function testDisallowedExtensionIsRejected(): void
    {
        $version = $this->createVersion($this->createItem());

        $file = $this->service()->store($version, $this->upload('script.html', '<html></html>'), File::KIND_ATTACHMENT);

        $this->assertRejected($file, 'The file "script.html" is not an allowed type (allowed: '
            . 'pdf, docx, xlsx, pptx, odt, ods, txt, jpg, jpeg, png, gif, webp).');
    }

    public function testMimeTypeMismatchIsRejected(): void
    {
        $version = $this->createVersion($this->createItem());

        $file = $this->service()->store($version, $this->upload('fake.pdf', 'Just text'), File::KIND_MAIN);

        $this->assertRejected($file, 'The file "fake.pdf" is not an allowed type (allowed: '
            . 'pdf, docx, xlsx, pptx, odt, ods, txt, jpg, jpeg, png, gif, webp).');
        $this->assertNull(Item::findOne($version->item_id)->source_uploaded_at);
    }

    public function testTooLargeFileIsRejected(): void
    {
        $version = $this->createVersion($this->createItem());
        $service = $this->service(['maxFileSize' => 10]);

        $file = $service->store($version, $this->upload('big.txt', str_repeat('x', 11)), File::KIND_ATTACHMENT);
        $this->assertRejected($file, 'The file "big.txt" is larger than 10 B.');

        // The actual size counts, not the reported one.
        $upload = $this->upload('big.txt', str_repeat('x', 11));
        $upload->size = 1;
        $this->assertRejected($service->store($version, $upload, File::KIND_ATTACHMENT), 'The file "big.txt" is larger than 10 B.');

        $file = $service->store($version, $this->upload('fits.txt', str_repeat('x', 10)), File::KIND_ATTACHMENT);
        $this->assertFalse($file->getIsNewRecord(), json_encode($file->getErrors()));
    }

    public function testDefaultMaximumIsTwentyMegabytes(): void
    {
        $service = $this->service();
        $this->assertSame('20 MB', $service->getFormattedMaxFileSize());

        $version = $this->createVersion($this->createItem());
        $path = $this->tempFile();
        $handle = fopen($path, 'wb');
        ftruncate($handle, 20 * 1024 * 1024 + 1);
        fclose($handle);
        $upload = new UploadedFile([
            'name' => 'huge.txt',
            'tempName' => $path,
            'type' => 'text/plain',
            'size' => 20 * 1024 * 1024 + 1,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertRejected($service->store($version, $upload, File::KIND_ATTACHMENT), 'The file "huge.txt" is larger than 20 MB.');
    }

    public function testFailedUploadIsRejected(): void
    {
        $version = $this->createVersion($this->createItem());
        $upload = $this->upload('a.txt', 'Alpha text');
        $upload->error = UPLOAD_ERR_PARTIAL;

        $this->assertRejected($this->service()->store($version, $upload, File::KIND_ATTACHMENT), 'The file "a.txt" could not be uploaded.');
    }

    public function testRejectionMessagesAreTranslated(): void
    {
        Yii::$app->language = 'de';
        $version = $this->createVersion($this->createItem());
        $this->assertRejected(
            $this->service(['maxFileSize' => 10])
                ->store($version, $this->upload('big.txt', str_repeat('x', 11)), File::KIND_ATTACHMENT),
            'Die Datei „big.txt“ ist größer als 10 B.'
        );
        $this->assertRejected(
            $this->service(['allowedExtensions' => ['txt']])
                ->store($version, $this->upload('a.pdf', self::PDF), File::KIND_ATTACHMENT),
            'Die Datei „a.pdf“ ist kein erlaubter Typ (erlaubt: txt).'
        );
    }

    public function testNameWithPathPartsIsReducedToBaseName(): void
    {
        $version = $this->createVersion($this->createItem());

        $file = $this->service()->store($version, $this->upload('../../x.pdf', self::PDF), File::KIND_MAIN);
        $this->assertSame('x.pdf', $file->name);
        $this->assertSame('knowledge-library/' . $version->item_id . '/' . $file->id . '.pdf', $file->path);
        $this->assertFileExists($this->getStoragePath($file->path));

        $file = $this->service()->store($version, $this->upload('C:\\Users\\y.txt', 'Yankee text'), File::KIND_ATTACHMENT);
        $this->assertSame('y.txt', $file->name);
    }

    public function testStoreUsesConfiguredTargetPathAndStorage(): void
    {
        $other = new Filesystem(new LocalFilesystemAdapter($this->getStorageDir() . '/other'));
        Yii::$app->set('otherStorage', $other);
        $version = $this->createVersion($this->createItem());

        $file = $this->service(['fileStorage' => 'otherStorage', 'targetPath' => 'docs'])
            ->store($version, $this->upload('a.txt', 'Alpha text'), File::KIND_ATTACHMENT);

        $this->assertSame('otherStorage', $file->storage_id);
        $this->assertStringStartsWith('docs/' . $version->item_id . '/', $file->path);
        $this->assertFileExists($this->getStoragePath('other/' . $file->path));
    }

    public function testCopyToVersionSharesThePath(): void
    {
        $item = $this->createItem();
        $published = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $service = $this->service();
        $original = $service->store($published, $this->upload('a.pdf', self::PDF), File::KIND_MAIN, 'Title');
        $draft = $this->createVersion($item, ['valid_from' => '2026-02-01']);

        $copy = $service->copyToVersion($original, $draft);

        $this->assertFalse($copy->getIsNewRecord());
        $this->assertNotSame($original->id, $copy->id);
        $this->assertSame($draft->id, $copy->version_id);
        foreach (['kind', 'title', 'storage_id', 'path', 'name', 'mime_type', 'size', 'content_hash', 'position'] as $attribute) {
            $this->assertEquals($original->$attribute, $copy->$attribute, $attribute);
        }
        $this->assertCount(1, glob($this->getStoragePath('knowledge-library/' . $item->id . '/*')));
    }

    public function testRemoveKeepsStoredFileWhileReferenced(): void
    {
        $item = $this->createItem();
        $published = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $service = $this->service();
        $original = $service->store($published, $this->upload('a.pdf', self::PDF), File::KIND_MAIN);
        $draft = $this->createVersion($item, ['valid_from' => '2026-02-01']);
        $copy = $service->copyToVersion($original, $draft);

        $service->remove($copy);

        $this->assertNull(File::findOne($copy->id));
        $this->assertNotNull(File::findOne($original->id));
        $this->assertFileExists($this->getStoragePath($original->path));

        $service->remove($original);
        $this->assertNull(File::findOne($original->id));
        $this->assertFileDoesNotExist($this->getStoragePath($original->path));
    }

    public function testRemoveDeletesFileUploadedOnlyToTheDraft(): void
    {
        $draft = $this->createVersion($this->createItem());
        $service = $this->service();
        $file = $service->store($draft, $this->upload('a.txt', 'Alpha text'), File::KIND_ATTACHMENT);
        $this->assertFileExists($this->getStoragePath($file->path));

        $service->remove($file);

        $this->assertNull(File::findOne($file->id));
        $this->assertFileDoesNotExist($this->getStoragePath($file->path));
    }

    public function testDeleteStoragePathsAfterDeletingItem(): void
    {
        $item = $this->createItem();
        $other = $this->createItem();
        $service = $this->service();
        $first = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $shared = $service->store($first, $this->upload('shared.pdf', self::PDF), File::KIND_MAIN);
        $draft = $this->createVersion($item, ['valid_from' => '2026-02-01']);
        $service->copyToVersion($shared, $draft);
        $own = $service->store($draft, $this->upload('own.txt', 'Own'), File::KIND_ATTACHMENT);
        $foreign = $service->store($this->createVersion($other), $this->upload('foreign.txt', 'Foreign'), File::KIND_ATTACHMENT);

        $paths = $service->collectStoragePaths($item);
        $this->assertEqualsCanonicalizing(
            [['storage_id' => 'fs', 'path' => $shared->path], ['storage_id' => 'fs', 'path' => $own->path]],
            $paths
        );

        $this->assertSame(['deleted' => 0, 'kept' => 2, 'failed' => 0], $service->deleteStoragePaths($paths));
        $this->assertFileExists($this->getStoragePath($shared->path));

        $this->assertNotFalse(Item::findOne($item->id)->delete());
        $this->assertSame(['deleted' => 2, 'kept' => 0, 'failed' => 0], $service->deleteStoragePaths($paths));

        $this->assertFileDoesNotExist($this->getStoragePath($shared->path));
        $this->assertFileDoesNotExist($this->getStoragePath($own->path));
        $this->assertFileExists($this->getStoragePath($foreign->path));
    }

    public function testStorageErrorWhileDeletingIsLoggedAsWarning(): void
    {
        $adapter = new class ($this->getStorageDir()) extends LocalFilesystemAdapter {
            public function delete(string $path): void
            {
                throw UnableToDeleteFile::atLocation($path, 'broken');
            }
        };
        Yii::$app->set('fs', new Filesystem($adapter));
        $service = $this->service();
        $file = $service->store($this->createVersion($this->createItem()), $this->upload('a.txt', 'Alpha text'), File::KIND_ATTACHMENT);

        $service->remove($file);

        $this->assertNull(File::findOne($file->id));
        $this->assertFileExists($this->getStoragePath($file->path));
        $this->assertSame(
            ['deleted' => 0, 'kept' => 0, 'failed' => 1],
            $service->deleteStoragePaths([['storage_id' => 'fs', 'path' => $file->path]])
        );
        $warnings = array_filter(
            Yii::getLogger()->messages,
            static fn (array $message) => $message[1] === Logger::LEVEL_WARNING
                && str_contains((string)$message[0], 'Could not delete the stored file ' . $file->path)
        );
        $this->assertNotEmpty($warnings);
    }

    public function testFindDuplicates(): void
    {
        $item = $this->createItem(['title' => 'This item']);
        $other = $this->createItem(['title' => 'Other item']);
        $service = $this->service();
        $published = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $inOtherVersion = $service->store($published, $this->upload('a.txt', 'Same'), File::KIND_ATTACHMENT);
        $draft = $this->createVersion($item, ['valid_from' => '2026-02-01']);
        $file = $service->store($draft, $this->upload('b.txt', 'Same'), File::KIND_ATTACHMENT);

        // Same item: no duplicate.
        $this->assertSame([], $service->findDuplicates($file));
        $this->assertSame($inOtherVersion->content_hash, $file->content_hash);

        $otherVersion = $this->createVersion($other);
        $duplicate = $service->store($otherVersion, $this->upload('c.txt', 'Same'), File::KIND_ATTACHMENT);
        $service->copyToVersion($duplicate, $this->createPublishedVersion($this->createItem(), ['valid_from' => '2026-01-01']));
        $service->store($otherVersion, $this->upload('d.txt', 'Different'), File::KIND_ATTACHMENT);

        $duplicates = $service->findDuplicates($file);
        $this->assertCount(2, $duplicates);
        $titles = array_map(static fn (File $found) => $found->version->item->title, $duplicates);
        $this->assertContains('Other item', $titles);
        $this->assertNotContains('This item', $titles);
        $this->assertTrue($duplicates[0]->isRelationPopulated('version'));

        $file->content_hash = null;
        $this->assertSame([], $service->findDuplicates($file));
    }

    public function testReadStream(): void
    {
        $service = $this->service();
        $file = $service->store($this->createVersion($this->createItem()), $this->upload('a.txt', 'Content'), File::KIND_ATTACHMENT);

        $stream = $service->readStream($file);
        $this->assertIsResource($stream);
        $this->assertSame('Content', stream_get_contents($stream));
        fclose($stream);

        unlink($this->getStoragePath($file->path));
        $this->assertNull($service->readStream($file));
    }

    public function testSendDeliversTheStoredFileAsAttachment(): void
    {
        // Range handling of Response::sendStreamAsFile() reads the request headers.
        Yii::$app->set('request', ['class' => Request::class, 'cookieValidationKey' => 'test']);
        $service = $this->service();
        $file = $service->store($this->createVersion($this->createItem()), $this->upload('Report.txt', 'Content'), File::KIND_ATTACHMENT);
        $response = new Response();

        $this->assertSame($response, $service->send($file, $response));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('attachment;', (string)$response->getHeaders()->get('Content-Disposition'));
        $this->assertStringContainsString('Report.txt', (string)$response->getHeaders()->get('Content-Disposition'));
        $this->assertSame('text/plain', $response->getHeaders()->get('Content-Type'));
        $this->assertSame('7', (string)$response->getHeaders()->get('Content-Length'));
        [$stream] = $response->stream;
        $this->assertSame('Content', stream_get_contents($stream));
        fclose($stream);
    }

    public function testSendFallsBackToOctetStream(): void
    {
        Yii::$app->set('request', ['class' => Request::class, 'cookieValidationKey' => 'test']);
        $service = $this->service();
        $file = $service->store($this->createVersion($this->createItem()), $this->upload('a.txt', 'Content'), File::KIND_ATTACHMENT);
        $file->mime_type = null;
        $response = new Response();

        $service->send($file, $response);

        $this->assertSame('application/octet-stream', $response->getHeaders()->get('Content-Type'));
        fclose($response->stream[0]);
    }

    public function testSendReturnsNullForMissingStoredFile(): void
    {
        $service = $this->service();
        $file = $service->store($this->createVersion($this->createItem()), $this->upload('a.txt', 'Content'), File::KIND_ATTACHMENT);
        unlink($this->getStoragePath($file->path));
        $response = new Response();

        $this->assertNull($service->send($file, $response));
        $this->assertNull($response->stream);
        $this->assertFalse($response->getHeaders()->has('Content-Disposition'));
    }

    public function testStoreRequiresSavedVersion(): void
    {
        $this->expectException(\yii\base\InvalidArgumentException::class);

        $this->service()->store(new Version(), $this->upload('a.txt', 'Alpha text'), File::KIND_ATTACHMENT);
    }

    private function assertRejected(File $file, string $message): void
    {
        $this->assertTrue($file->getIsNewRecord());
        $this->assertSame($message, $file->getFirstError('name'));
        $this->assertNull(File::findOne(['name' => $file->name]));
        $this->assertSame([], glob($this->getStoragePath('knowledge-library/*/*')));
    }

    private function service(array $config = []): FileService
    {
        return new FileService(new Module('knowledge-library', Yii::$app, $config));
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = $this->tempFile();
        file_put_contents($path, $content);

        return new UploadedFile([
            'name' => $name,
            'tempName' => $path,
            'type' => 'application/octet-stream',
            'size' => strlen($content),
            'error' => UPLOAD_ERR_OK,
        ]);
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'knowledge-library-upload-');
        $this->uploads[] = $path;

        return $path;
    }
}
