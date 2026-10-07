<?php
// file generated with AI assistance: Claude Code - 2026-10-07 20:58:25 UTC

namespace dmstr\knowledgeLibrary\tests\web;

use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\WebTestCase;
use Yii;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Tests of the file download (`file/download`).
 */
class FileControllerTest extends WebTestCase
{
    private const UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    private const CONTENT = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    public function testDownloadSendsTheStoredFileAsAttachment(): void
    {
        $file = $this->createStoredFile($this->createPublishedVersion($this->createItem(), ['valid_from' => '2020-01-01']), 'Forest law "2026".pdf');
        $this->loginAs(Module::ROLE_EDITOR);

        $result = $this->get('file/download', ['id' => $file->id]);

        $this->assertInstanceOf(Response::class, $result);
        $response = $this->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertStringStartsWith('inline; filename="Forest law \\"2026\\".pdf"', $headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $headers->get('Content-Type'));
        $this->assertEquals(strlen(self::CONTENT), $headers->get('Content-Length'));
        $this->assertSame(self::CONTENT, $this->readResponseStream($response));
    }

    public function testDraftFileIsDownloadableForEditorsReviewersAndAdmins(): void
    {
        $file = $this->createStoredFile($this->createVersion($this->createItem()), 'draft.pdf');
        $this->assertSame(Version::STATUS_DRAFT, $file->version->status);

        foreach ([Module::ROLE_EDITOR, Module::ROLE_REVIEWER, Module::ROLE_ADMIN] as $role) {
            $this->loginAs($role);
            $this->assertInstanceOf(Response::class, $this->get('file/download', ['id' => $file->id]), $role);
            $this->assertSame(200, $this->getResponse()->getStatusCode(), $role);
            $this->assertSame(self::CONTENT, $this->readResponseStream($this->getResponse()), $role);
        }
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $file = $this->createStoredFile($this->createVersion($this->createItem()), 'draft.pdf');
        $this->loginAsGuest();

        $this->assertNull($this->get('file/download', ['id' => $file->id]));
        $this->assertRedirectsToLogin();
    }

    public function testUserWithoutRoleIsForbidden(): void
    {
        $file = $this->createStoredFile($this->createVersion($this->createItem()), 'draft.pdf');
        $this->loginAs();

        $this->assertForbidden('GET', 'file/download', ['id' => $file->id]);
    }

    public function testDownloadRequiresGet(): void
    {
        $file = $this->createStoredFile($this->createVersion($this->createItem()), 'draft.pdf');
        $this->loginAs(Module::ROLE_EDITOR);

        $this->assertMethodNotAllowed('POST', 'file/download', ['id' => $file->id]);
    }

    public function testUnknownFileIsNotFound(): void
    {
        $version = $this->createVersion($this->createItem());
        $this->loginAs(Module::ROLE_EDITOR);

        // IDs of other records are no file IDs.
        foreach ([self::UNKNOWN_ID, $version->id, $version->item_id] as $id) {
            $this->assertHttpException(NotFoundHttpException::class, 'GET', 'file/download', ['id' => $id]);
        }
    }

    public function testMissingStoredFileIsNotFound(): void
    {
        $file = $this->createFile($this->createVersion($this->createItem()), ['name' => 'missing.pdf']);
        $this->loginAs(Module::ROLE_EDITOR);

        $exception = $this->assertHttpException(NotFoundHttpException::class, 'GET', 'file/download', ['id' => $file->id]);
        $this->assertSame('The requested file does not exist.', $exception->getMessage());
    }

    /**
     * Creates a file row of the version and writes its content to the
     * storage `fs`.
     */
    private function createStoredFile(Version $version, string $name): File
    {
        $file = $this->createFile($version, [
            'path' => 'knowledge-library/' . $version->item_id . '/' . uniqid() . '.pdf',
            'name' => $name,
            'mime_type' => 'application/pdf',
            'size' => strlen(self::CONTENT),
            'content_hash' => hash('sha256', self::CONTENT),
        ]);
        Yii::$app->get('fs')->write($file->path, self::CONTENT);

        return $file;
    }

    /**
     * Reads the range of the stream prepared by `sendStreamAsFile()` and
     * closes the stream.
     */
    private function readResponseStream(Response $response): string
    {
        $this->assertIsArray($response->stream, 'The response has no stream.');
        [$handle, $begin, $end] = $response->stream;
        try {
            return (string)stream_get_contents($handle, $end - $begin + 1, $begin);
        } finally {
            fclose($handle);
            $response->stream = null;
        }
    }
}
