<?php

namespace dmstr\knowledgeLibrary\tests\web;

use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\tests\support\SmokeController;
use dmstr\knowledgeLibrary\tests\WebTestCase;
use Yii;
use yii\web\Response;

/**
 * Tests of the web test infrastructure and the base controller, using the
 * test-only controller `smoke`.
 */
class WebTestCaseTest extends WebTestCase
{
    private const ROLE = 'SmokeTester';

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
}
