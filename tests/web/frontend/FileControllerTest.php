<?php
// file generated with AI assistance: Claude Code - 2026-10-07 20:58:25 UTC

namespace dmstr\knowledgeLibrary\tests\web\frontend;

use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\tests\FrontendWebTestCase;
use dmstr\knowledgeLibrary\tests\support\FrontendFixtures;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Tests of the frontend file download (`file/download`).
 */
class FileControllerTest extends FrontendWebTestCase
{
    use FrontendFixtures;

    private const UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    public function testPdfOfTheValidVersionIsSentForDisplay(): void
    {
        [, $version] = $this->createValidItem();
        $file = $this->createStoredFile($version, 'Forest law "2026".pdf');
        $this->loginAs('knowledge');

        $result = $this->get('file/download', ['id' => $file->id]);

        $this->assertInstanceOf(Response::class, $result);
        $response = $this->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertStringStartsWith('inline; filename="Forest law \\"2026\\".pdf"', $headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $headers->get('Content-Type'));
        $this->assertEquals(strlen(static::$fileContent), $headers->get('Content-Length'));
        $this->assertSame(static::$fileContent, $this->readResponseStream($response));
    }

    public function testAttachmentOfTheValidVersionIsSent(): void
    {
        [, $version] = $this->createValidItem();
        $file = $this->createStoredFile($version, 'annex.docx', [
            'kind' => File::KIND_ATTACHMENT,
            'title' => 'Annex',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
        $this->loginAs('knowledge');

        $this->assertInstanceOf(Response::class, $this->get('file/download', ['id' => $file->id]));
        // Only the inline MIME types of the module are displayed, everything else is a download.
        $this->assertStringStartsWith('attachment; filename="annex.docx"', $this->getResponse()->getHeaders()->get('Content-Disposition'));
        $this->assertSame(static::$fileContent, $this->readResponseStream($this->getResponse()));
    }

    public function testFilesOfItemsNotValidTodayAreNotFound(): void
    {
        $items = $this->createInvalidItems();
        $files = [];
        foreach ($items as $reason => [, $version]) {
            $files[$reason] = $this->createStoredFile($version, $reason . '.pdf');
        }
        $this->loginAs('knowledge');

        foreach ($files as $reason => $file) {
            $exception = $this->assertHttpException(NotFoundHttpException::class, 'GET', 'file/download', ['id' => $file->id]);
            $this->assertSame('The requested file does not exist.', $exception->getMessage(), $reason);
        }
    }

    public function testFilesOfOtherVersionsOfAValidItemAreNotFound(): void
    {
        $item = $this->createItem();
        $historical = $this->createPublishedVersion($item, ['valid_from' => $this->day(-60)]);
        $current = $this->createPublishedVersion($item, ['valid_from' => $this->day(-10)]);
        $upcoming = $this->createPublishedVersion($item, ['valid_from' => $this->day(10)]);
        $draft = $this->createVersion($item);
        $valid = $this->createStoredFile($current, 'current.pdf');
        $this->loginAs('knowledge');

        foreach (['historical' => $historical, 'upcoming' => $upcoming, 'draft' => $draft] as $reason => $version) {
            $file = $this->createStoredFile($version, $reason . '.pdf');
            $this->assertHttpException(NotFoundHttpException::class, 'GET', 'file/download', ['id' => $file->id]);
        }

        $this->assertInstanceOf(Response::class, $this->get('file/download', ['id' => $valid->id]));
        $this->readResponseStream($this->getResponse());
    }

    public function testFileOfWithdrawnVersionIsNotFoundWhileThePreviousIsSent(): void
    {
        $item = $this->createItem();
        $previous = $this->createPublishedVersion($item, ['valid_from' => $this->day(-400)]);
        $withdrawn = $this->createPublishedVersion($item, ['valid_from' => $this->day(-10)]);
        $previousFile = $this->createStoredFile($previous, 'previous.pdf');
        $withdrawnFile = $this->createStoredFile($withdrawn, 'withdrawn.pdf');
        $this->loginAs('knowledge');
        $this->assertHttpException(NotFoundHttpException::class, 'GET', 'file/download', ['id' => $previousFile->id]);

        $this->assertTrue($withdrawn->withdraw('Faulty', Version::WITHDRAW_PREVIOUS), json_encode($withdrawn->getErrors()));

        $this->assertHttpException(NotFoundHttpException::class, 'GET', 'file/download', ['id' => $withdrawnFile->id]);
        $this->assertInstanceOf(Response::class, $this->get('file/download', ['id' => $previousFile->id]));
        $this->assertSame(static::$fileContent, $this->readResponseStream($this->getResponse()));
    }

    public function testUnknownFileIsNotFound(): void
    {
        [$item, $version] = $this->createValidItem();
        $this->loginAs('knowledge');

        // IDs of other records are no file IDs.
        foreach ([self::UNKNOWN_ID, 'not-a-uuid', $version->id, $item->id] as $id) {
            $this->assertHttpException(NotFoundHttpException::class, 'GET', 'file/download', ['id' => $id]);
        }
    }

    public function testMissingStoredFileIsNotFound(): void
    {
        [, $version] = $this->createValidItem();
        $file = $this->createFile($version, ['name' => 'missing.pdf']);
        $this->loginAs('knowledge');

        $exception = $this->assertHttpException(NotFoundHttpException::class, 'GET', 'file/download', ['id' => $file->id]);
        $this->assertSame('The requested file does not exist.', $exception->getMessage());
    }
}
