<?php

namespace dmstr\knowledgeLibrary\tests\web\frontend;

use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\FrontendWebTestCase;
use dmstr\knowledgeLibrary\tests\support\FrontendFixtures;
use yii\web\Response;

/**
 * Access control and HTTP methods of all frontend routes.
 */
class AccessTest extends FrontendWebTestCase
{
    use FrontendFixtures;

    public function testGuestIsRedirectedToLoginOnAllRoutes(): void
    {
        $routes = $this->routes();
        $this->loginAsGuest();

        foreach ($routes as [$route, $params]) {
            $this->assertNull($this->get($route, $params), $route);
            $this->assertRedirectsToLogin();
        }
    }

    public function testUserWithoutRoleIsForbiddenOnAllRoutes(): void
    {
        $routes = $this->routes();
        $this->loginAs();

        foreach ($routes as [$route, $params]) {
            $this->assertForbidden('GET', $route, $params);
        }
    }

    public function testBackendRolesWithoutPermissionAreForbiddenOnAllRoutes(): void
    {
        $routes = $this->routes();

        foreach ([Module::ROLE_EDITOR, Module::ROLE_REVIEWER, Module::ROLE_ADMIN] as $role) {
            $this->loginAs($role);
            foreach ($routes as [$route, $params]) {
                $this->assertForbidden('GET', $route, $params);
            }
        }
    }

    public function testPermissionGrantsAllRoutes(): void
    {
        [$index, $view, $download] = $this->routes();
        $this->loginAs('knowledge');

        // The module URL and the controller URL open the list (defaultRoute).
        $this->assertStringContainsString('class="knowledge-items"', $this->assertPage($this->get('')));
        $this->assertStringContainsString('class="knowledge-items"', $this->assertPage($this->get('item')));
        $this->assertPage($this->get(...$index));
        $this->assertPage($this->get(...$view));
        $this->assertInstanceOf(Response::class, $this->get(...$download));
        $this->assertSame(200, $this->getResponse()->getStatusCode());
        $this->readResponseStream($this->getResponse());
    }

    public function testAllRoutesAreGetOnly(): void
    {
        $routes = $this->routes();
        $this->loginAs('knowledge');

        foreach ($routes as [$route, $params]) {
            foreach (['POST', 'PUT', 'DELETE'] as $method) {
                $this->assertMethodNotAllowed($method, $route, $params);
            }
        }
    }

    /**
     * The three routes with the params of a valid item and file.
     *
     * @return array<int, array{0: string, 1: array}>
     */
    private function routes(): array
    {
        [, $version] = $this->createValidItem();
        $file = $this->createStoredFile($version, 'law.pdf');

        return [
            ['item/index', []],
            ['item/view', ['id' => $version->item_id]],
            ['file/download', ['id' => $file->id]],
        ];
    }
}
