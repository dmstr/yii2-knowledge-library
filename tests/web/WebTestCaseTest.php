<?php

namespace dmstr\knowledgeLibrary\tests\web;

use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\tests\support\SmokeController;
use dmstr\knowledgeLibrary\tests\WebTestCase;
use League\Flysystem\FilesystemOperator;
use Yii;
use yii\web\Application;
use yii\web\Response;
use yii\web\UploadedFile;

/**
 * Tests of the web test infrastructure and the base controller, using the
 * test-only controller `smoke`.
 */
class WebTestCaseTest extends WebTestCase
{
    private const ROLE = 'SmokeTester';

    /**
     * Called before each action of the application, e.g. to inspect the
     * uploaded files of the request.
     *
     * @var callable|null
     */
    private $beforeAction = null;

    protected function moduleConfig(): array
    {
        return array_merge(parent::moduleConfig(), [
            'controllerMap' => [
                'smoke' => SmokeController::class,
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // An event handler in the module configuration would attach the
        // access behavior of the module too early, so it goes to the
        // application, whose beforeAction also runs for every request.
        Yii::$app->on(Application::EVENT_BEFORE_ACTION, function () {
            if ($this->beforeAction !== null) {
                ($this->beforeAction)();
            }
        });

        $authManager = Yii::$app->getAuthManager();
        $role = $authManager->createRole(self::ROLE);
        $authManager->add($role);
        foreach (['index', 'save', 'delete'] as $action) {
            $permission = $authManager->createPermission('knowledge-library_smoke_' . $action);
            $authManager->add($permission);
            $authManager->addChild($role, $permission);
        }
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->loginAsGuest();

        $result = $this->get('smoke/index');

        $this->assertNull($result);
        $this->assertSame(302, $this->getResponse()->getStatusCode());
        $this->assertRedirectsToLogin();
    }

    public function testUserWithoutRoleIsForbidden(): void
    {
        $this->loginAs();

        $this->assertForbidden('GET', 'smoke/index');
    }

    public function testUserWithPackageRoleButWithoutRoutePermissionIsForbidden(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);

        $this->assertForbidden('GET', 'smoke/index');
    }

    public function testUserWithRoutePermissionSeesPageWithWidgets(): void
    {
        $this->loginAs(self::ROLE);

        $html = $this->assertPage($this->get('smoke/index'));

        $this->assertStringContainsString('<h1 class="smoke-title">Smoke</h1>', $html);
        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('select2', $html);
        $this->assertStringContainsString('grid-view', $html);
        $this->assertStringContainsString('Second', $html);
        $this->assertSame('Smoke', Yii::$app->getView()->title);
    }

    public function testRootBreadcrumbLinksToItemLibrary(): void
    {
        $this->loginAs(self::ROLE);

        $this->get('smoke/index');

        $this->assertSame([
            [
                'label' => 'Knowledge Library',
                'url' => ['/knowledge-library/item/index'],
            ],
            'Smoke',
        ], Yii::$app->getView()->params['breadcrumbs']);
    }

    public function testRootBreadcrumbIsTranslated(): void
    {
        Yii::$app->language = 'de';
        $this->loginAs(self::ROLE);

        $this->get('smoke/index');

        $this->assertSame('Wissensbibliothek', Yii::$app->getView()->params['breadcrumbs'][0]['label']);
    }

    public function testAccessIsCheckedPerRequest(): void
    {
        $authManager = Yii::$app->getAuthManager();
        $authManager->removeChild(
            $authManager->getRole(self::ROLE),
            $authManager->getPermission('knowledge-library_smoke_delete')
        );
        $this->loginAs(self::ROLE);

        $this->assertPage($this->get('smoke/index'));
        $this->assertForbidden('POST', 'smoke/delete');
        $this->assertPage($this->get('smoke/index'));
    }

    public function testDeleteRequiresPost(): void
    {
        $this->loginAs(self::ROLE);

        $this->assertMethodNotAllowed('GET', 'smoke/delete');
        $this->assertSame('deleted', $this->post('smoke/delete'));
    }

    public function testPostRedirectsWithFlash(): void
    {
        $this->loginAs(self::ROLE);

        $result = $this->post('smoke/save', ['name' => 'Law']);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertRedirectsTo(['smoke/index']);
        $this->assertSame('Saved Law.', $this->getFlash('success'));
        $this->assertNull($this->getFlash('success'));
    }

    public function testFlashSurvivesTheFollowingRequest(): void
    {
        $this->loginAs(self::ROLE);

        $this->post('smoke/save', ['name' => 'Law']);
        $this->get('smoke/index');

        $this->assertSame('Saved Law.', $this->getFlash('success'));
    }

    public function testLoginSetsUserReference(): void
    {
        $identity = $this->loginAs(self::ROLE);

        $this->assertSame($identity->uuid, DummyUserProvider::$currentReference);
        $this->assertSame(
            $identity->uuid,
            Yii::$app->getModule('knowledge-library')->getUserProvider()->getCurrentUserReference()
        );
    }

    public function testLogMessagesAreCollected(): void
    {
        Yii::info('Deleted item', 'knowledge-library');
        Yii::warning('Other level', 'knowledge-library');

        $this->assertSame(['Deleted item'], $this->getLogMessages());
    }

    public function testModuleUsesTemporaryFileStorage(): void
    {
        $this->loginAs(self::ROLE);
        $this->get('smoke/index');

        $module = Yii::$app->getModule('knowledge-library');
        $this->assertSame('fs', $module->fileStorage);
        $filesystem = $module->getFilesystem();
        $this->assertInstanceOf(FilesystemOperator::class, $filesystem);

        $filesystem->write('knowledge-library/item/file.txt', 'Stored');

        $this->assertStringStartsWith(sys_get_temp_dir() . '/' . self::STORAGE_DIR_PREFIX, $this->getStorageDir());
        $this->assertStringEqualsFile($this->getStoragePath('knowledge-library/item/file.txt'), 'Stored');
    }

    public function testTearDownRemovesTheStorageDirectory(): void
    {
        $dir = $this->getStorageDir();
        Yii::$app->getModule('knowledge-library')->getFilesystem()->write('a/b/c.txt', 'Nested');
        $this->assertFileExists($dir . '/a/b/c.txt');

        $this->tearDown();
        $this->assertDirectoryDoesNotExist($dir);

        // Restore the state for the regular tearDown().
        $this->setUp();
        $this->assertNotSame($dir, $this->getStorageDir());
    }

    public function testPostFilesProvidesUploadedFiles(): void
    {
        $pdf = $this->createSourceFile('source.pdf', '%PDF-1.4 Source');
        $text = $this->createSourceFile('notes.txt', 'Notes');
        $this->loginAs(self::ROLE);

        $seen = [];
        $this->beforeAction = function () use (&$seen) {
            $describe = static fn(?UploadedFile $file) => $file === null ? null : [
                'name' => $file->name,
                'type' => $file->type,
                'size' => $file->size,
                'error' => $file->error,
                'tempName' => $file->tempName,
                'content' => file_get_contents($file->tempName),
            ];
            $seen = [
                'file' => $describe(UploadedFile::getInstanceByName('file')),
                'mainFiles' => array_map($describe, UploadedFile::getInstancesByName('mainFiles')),
                'attachments' => array_map($describe, UploadedFile::getInstancesByName('Version[attachments]')),
                'main' => $describe(UploadedFile::getInstanceByName('Version[main]')),
                'filesLayout' => $_FILES,
                'body' => Yii::$app->getRequest()->getBodyParam('name'),
            ];
        };

        $result = $this->postFiles('smoke/save', ['name' => 'Law'], [
            'file' => $pdf,
            'mainFiles[]' => [$pdf, $text],
            'Version[attachments][]' => [
                ['path' => $text, 'name' => '../report.pdf', 'type' => 'application/pdf'],
                ['path' => $pdf, 'size' => 20 * 1024 * 1024 + 1],
            ],
            'Version[main]' => ['path' => $text, 'error' => UPLOAD_ERR_INI_SIZE],
        ]);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertRedirectsTo(['smoke/index']);
        $this->assertSame('Law', $seen['body']);

        $this->assertSame('source.pdf', $seen['file']['name']);
        $this->assertSame('%PDF-1.4 Source', $seen['file']['content']);
        $this->assertSame(15, $seen['file']['size']);
        $this->assertSame(UPLOAD_ERR_OK, $seen['file']['error']);
        $this->assertNotSame($pdf, $seen['file']['tempName']);

        $this->assertSame(['source.pdf', 'notes.txt'], array_column($seen['mainFiles'], 'name'));
        $this->assertSame(['%PDF-1.4 Source', 'Notes'], array_column($seen['mainFiles'], 'content'));
        $this->assertSame('text/plain', $seen['mainFiles'][1]['type']);

        $this->assertSame(['../report.pdf', 'source.pdf'], array_column($seen['attachments'], 'name'));
        $this->assertSame('application/pdf', $seen['attachments'][0]['type']);
        $this->assertSame('Notes', $seen['attachments'][0]['content']);
        $this->assertSame(20 * 1024 * 1024 + 1, $seen['attachments'][1]['size']);

        $this->assertSame('notes.txt', $seen['main']['name']);
        $this->assertSame(UPLOAD_ERR_INI_SIZE, $seen['main']['error']);

        // Layout of $_FILES as PHP builds it for these field names.
        $layout = $seen['filesLayout'];
        $this->assertSame('source.pdf', $layout['file']['name']);
        $this->assertSame(['source.pdf', 'notes.txt'], $layout['mainFiles']['name']);
        $this->assertSame(
            ['attachments' => ['../report.pdf', 'source.pdf'], 'main' => 'notes.txt'],
            $layout['Version']['name']
        );
        $this->assertSame(
            ['attachments' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK], 'main' => UPLOAD_ERR_INI_SIZE],
            $layout['Version']['error']
        );
        $this->assertSame(
            ['name', 'type', 'tmp_name', 'error', 'size'],
            array_keys($layout['Version'])
        );

        // The temporary copies are removed, the sources are kept.
        $tempNames = array_merge(
            [$seen['file']['tempName'], $seen['main']['tempName']],
            array_column($seen['mainFiles'], 'tempName'),
            array_column($seen['attachments'], 'tempName')
        );
        $this->assertCount(6, array_unique($tempNames));
        foreach ($tempNames as $tempName) {
            $this->assertFileDoesNotExist($tempName);
        }
        $this->assertStringEqualsFile($pdf, '%PDF-1.4 Source');
        $this->assertStringEqualsFile($text, 'Notes');
    }

    public function testFollowingRequestHasNoUploadedFiles(): void
    {
        $pdf = $this->createSourceFile('source.pdf', '%PDF-1.4 Source');
        $this->loginAs(self::ROLE);
        $this->postFiles('smoke/save', ['name' => 'Law'], ['mainFiles[]' => [$pdf]]);

        $seen = null;
        $this->beforeAction = function () use (&$seen) {
            $seen = [
                'files' => $_FILES,
                'mainFiles' => UploadedFile::getInstancesByName('mainFiles'),
            ];
        };
        $this->post('smoke/save', ['name' => 'Law']);

        $this->assertSame(['files' => [], 'mainFiles' => []], $seen);
    }

    public function testExplicitIndexesAreKept(): void
    {
        $pdf = $this->createSourceFile('source.pdf', '%PDF-1.4 Source');
        $this->loginAs(self::ROLE);

        $seen = null;
        $this->beforeAction = function () use (&$seen) {
            $seen = [
                'names' => $_FILES['remove']['name'],
                'instance' => UploadedFile::getInstanceByName('remove[abc]')?->name,
            ];
        };
        $this->postFiles('smoke/save', [], [
            'remove[abc]' => $pdf,
            'remove[5]' => ['path' => $pdf, 'name' => 'five.pdf'],
            'remove[]' => ['path' => $pdf, 'name' => 'six.pdf'],
        ]);

        $this->assertSame(['abc' => 'source.pdf', 5 => 'five.pdf', 6 => 'six.pdf'], $seen['names']);
        $this->assertSame('source.pdf', $seen['instance']);
    }

    /**
     * Creates a source file for uploads in the storage directory of the test,
     * which tearDown() removes.
     */
    private function createSourceFile(string $name, string $content): string
    {
        $dir = $this->getStorageDir() . '/sources';
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        $path = $dir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }
}
