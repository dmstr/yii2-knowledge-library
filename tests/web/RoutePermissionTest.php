<?php

namespace dmstr\knowledgeLibrary\tests\web;

use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\WebTestCase;
use Yii;

/**
 * Tests of the route permissions created by
 * m260928_185500_knowledge_library_routes,
 * m260928_203100_knowledge_library_routes_2 and
 * m260928_223100_knowledge_library_routes_3.
 */
class RoutePermissionTest extends WebTestCase
{
    /**
     * Route migrations in migration order, relative to the package root.
     */
    private const MIGRATIONS = [
        '/src/migrations/m260928_185500_knowledge_library_routes.php',
        '/src/migrations/m260928_203100_knowledge_library_routes_2.php',
        '/src/migrations/m260928_223100_knowledge_library_routes_3.php',
    ];

    private const EDITOR_ROUTES = [
        'item/index',
        'item/create',
        'item/view',
        'item/update',
        'item/source',
        'version/create',
        'version/update',
        'version/publish',
        'version/discard',
        'version/withdraw',
        'version/correct',
        'file/download',
        'relation/create',
        'relation/delete',
    ];

    private const REVIEWER_ROUTES = [
        'version/review',
        'version/approve',
        'version/return',
    ];

    private const ADMIN_ONLY_ROUTES = [
        'type/index',
        'type/create',
        'type/update',
        'type/delete',
        'topic/index',
        'topic/create',
        'topic/update',
        'topic/delete',
        'item/delete',
        'item/archive',
        'item/restore',
        'version/reviewer',
    ];

    public function testMigrationCreatesExactlyTheRoutePermissions(): void
    {
        $this->assertSame($this->expectedPermissions(), $this->routePermissions());
    }

    public function testNoPrefixPermissionExists(): void
    {
        $authManager = Yii::$app->getAuthManager();
        $names = [
            'knowledge-library',
            'knowledge-library_item',
            'knowledge-library_type',
            'knowledge-library_topic',
            'knowledge-library_version',
            'knowledge-library_file',
            'knowledge-library_relation',
        ];
        foreach ($names as $name) {
            $this->assertNull($authManager->getPermission($name), "Prefix permission '$name' must not exist.");
            $this->assertNull($authManager->getRole($name), "Prefix role '$name' must not exist.");
        }
    }

    public function testPermissionsAreDirectChildrenOfTheRoles(): void
    {
        $authManager = Yii::$app->getAuthManager();

        $this->assertSame(
            $this->permissionNames(self::EDITOR_ROUTES),
            $this->routeChildren(Module::ROLE_EDITOR)
        );
        $this->assertSame(
            $this->permissionNames(self::REVIEWER_ROUTES),
            $this->routeChildren(Module::ROLE_REVIEWER)
        );
        $this->assertSame(
            $this->permissionNames(self::ADMIN_ONLY_ROUTES),
            $this->routeChildren(Module::ROLE_ADMIN)
        );
        $this->assertTrue($authManager->hasChild(
            $authManager->getRole(Module::ROLE_ADMIN),
            $authManager->getRole(Module::ROLE_REVIEWER)
        ));
    }

    public function testEditorMayUseEditorRoutesOnly(): void
    {
        $this->loginAs(Module::ROLE_EDITOR);

        $this->assertRouteAccess(self::EDITOR_ROUTES, true);
        $this->assertRouteAccess(self::REVIEWER_ROUTES, false);
        $this->assertRouteAccess(self::ADMIN_ONLY_ROUTES, false);
    }

    public function testReviewerMayUseEditorAndReviewerRoutesOnly(): void
    {
        $this->loginAs(Module::ROLE_REVIEWER);

        $this->assertRouteAccess(array_merge(self::EDITOR_ROUTES, self::REVIEWER_ROUTES), true);
        $this->assertRouteAccess(self::ADMIN_ONLY_ROUTES, false);
    }

    public function testAdminMayUseAllRoutes(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);

        $this->assertRouteAccess($this->allRoutes(), true);
    }

    public function testUserWithoutRoleMayUseNoRoute(): void
    {
        $this->loginAs();

        $this->assertRouteAccess($this->allRoutes(), false);
    }

    public function testGuestMayUseNoRoute(): void
    {
        $this->loginAsGuest();

        $this->assertRouteAccess($this->allRoutes(), false);
    }

    public function testUpTwiceKeepsPermissionsAndChildren(): void
    {
        foreach (self::MIGRATIONS as $file) {
            $this->runMigrationFile(dirname(__DIR__, 2) . $file);
        }

        $this->assertSame($this->expectedPermissions(), $this->routePermissions());
        $this->assertSame(
            $this->permissionNames(self::EDITOR_ROUTES),
            $this->routeChildren(Module::ROLE_EDITOR)
        );
        $this->assertSame(
            $this->permissionNames(self::REVIEWER_ROUTES),
            $this->routeChildren(Module::ROLE_REVIEWER)
        );
        $this->assertSame(
            $this->permissionNames(self::ADMIN_ONLY_ROUTES),
            $this->routeChildren(Module::ROLE_ADMIN)
        );
    }

    public function testDownIsNotSupported(): void
    {
        foreach (array_reverse(self::MIGRATIONS) as $file) {
            $file = dirname(__DIR__, 2) . $file;
            require_once $file;
            $class = basename($file, '.php');
            $migration = new $class(['db' => Yii::$app->db, 'compact' => true]);

            ob_start();
            try {
                $result = $migration->down();
            } finally {
                ob_end_clean();
            }

            $this->assertFalse($result, "Down of $class must not be supported.");
            $this->assertSame($this->expectedPermissions(), $this->routePermissions());
        }
    }

    /**
     * Asserts the route check of the backend module (`user->can()` with
     * `route => true`, including the prefix resolution of dmstr\web\User).
     *
     * @param string[] $routes
     */
    private function assertRouteAccess(array $routes, bool $expected): void
    {
        foreach ($this->permissionNames($routes) as $permission) {
            $this->assertSame(
                $expected,
                Yii::$app->getUser()->can($permission, ['route' => true]),
                "Unexpected access to '$permission'."
            );
        }
    }

    /**
     * @param string[] $routes
     * @return string[] sorted permission names
     */
    private function permissionNames(array $routes): array
    {
        $names = array_map(static fn(string $route) => 'knowledge-library_' . str_replace('/', '_', $route), $routes);
        sort($names);

        return $names;
    }

    /**
     * @return string[]
     */
    private function allRoutes(): array
    {
        return array_merge(self::EDITOR_ROUTES, self::REVIEWER_ROUTES, self::ADMIN_ONLY_ROUTES);
    }

    /**
     * @return string[]
     */
    private function expectedPermissions(): array
    {
        return $this->permissionNames($this->allRoutes());
    }

    /**
     * @return string[] sorted names of all permissions starting with the module ID
     */
    private function routePermissions(): array
    {
        $names = array_values(array_filter(
            array_keys(Yii::$app->getAuthManager()->getPermissions()),
            static fn(string $name) => str_starts_with($name, 'knowledge-library')
        ));
        sort($names);

        return $names;
    }

    /**
     * @return string[] sorted names of the direct route permission children of the role
     */
    private function routeChildren(string $role): array
    {
        $names = array_values(array_filter(
            array_keys(Yii::$app->getAuthManager()->getChildren($role)),
            static fn(string $name) => str_starts_with($name, 'knowledge-library_')
        ));
        sort($names);

        return $names;
    }
}
