<?php

namespace dmstr\knowledgeLibrary\tests\web\frontend;

use dmstr\knowledgeLibrary\frontend\Module as FrontendModule;
use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\FrontendWebTestCase;
use dmstr\knowledgeLibrary\tests\support\FrontendSmokeController;
use Yii;
use yii\base\InvalidConfigException;

/**
 * Tests of the frontend test infrastructure and the frontend module, using
 * the test-only controller `smoke`.
 */
class FrontendWebTestCaseTest extends FrontendWebTestCase
{
    protected function moduleConfig(): array
    {
        return array_merge(parent::moduleConfig(), [
            'controllerMap' => [
                'smoke' => FrontendSmokeController::class,
            ],
        ]);
    }

    public function testBothModulesAreRegistered(): void
    {
        $frontend = Yii::$app->getModule('knowledge');
        $backend = Yii::$app->getModule('knowledge-library');

        $this->assertInstanceOf(FrontendModule::class, $frontend);
        $this->assertInstanceOf(Module::class, $backend);
        $this->assertSame('fs', $backend->fileStorage);
        $this->assertSame($backend, $frontend->getBackendModule());
    }

    public function testDefaultRouteIsTheItemList(): void
    {
        $this->assertSame('item', Yii::$app->getModule('knowledge')->defaultRoute);
    }

    public function testGetBackendModuleFailsWithoutBackendModule(): void
    {
        $module = new FrontendModule('knowledge', Yii::$app, ['backendModuleId' => 'missing']);

        $this->expectException(InvalidConfigException::class);
        $module->getBackendModule();
    }

    public function testGetBackendModuleFailsForAnotherModuleClass(): void
    {
        $module = new FrontendModule('knowledge', Yii::$app, ['backendModuleId' => 'knowledge']);

        $this->expectException(InvalidConfigException::class);
        $module->getBackendModule();
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->loginAsGuest();

        $this->assertNull($this->get('smoke/index'));
        $this->assertRedirectsToLogin();
    }

    public function testUserWithoutPermissionIsForbidden(): void
    {
        $this->loginAs();

        $this->assertForbidden('GET', 'smoke/index');
    }

    public function testBackendAdminWithoutPermissionIsForbidden(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);

        $this->assertForbidden('GET', 'smoke/index');
    }

    public function testPermissionNamedLikeTheModuleGrantsAccess(): void
    {
        $this->loginAs('knowledge');

        $this->assertSame('backend: knowledge-library', $this->assertPage($this->get('smoke/index')));
    }

    public function testPermissionDoesNotGrantTheBackend(): void
    {
        $this->loginAs('knowledge');

        $this->assertFalse(Yii::$app->getUser()->can('knowledge-library_item_index', ['route' => true]));
        $this->assertTrue(Yii::$app->getUser()->can('knowledge_item_index', ['route' => true]));
    }
}
