<?php

namespace dmstr\knowledgeLibrary\tests;

use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\tests\support\TestIdentity;
use dmstr\knowledgeLibrary\tests\support\TestSession;
use dmstr\rbacMigration\Migration as RbacMigration;
use dmstr\web\User;
use Throwable;
use Yii;
use yii\db\MigrationInterface;
use yii\di\Container;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;
use yii\log\Logger;
use yii\rbac\DbManager;
use yii\web\Application;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Request;
use yii\web\Response;
use yii\web\View;

/**
 * Base test case for pages of the backend module.
 *
 * Extends TestCase with a web application: the backend module registered as
 * `knowledge-library` without layout, `dmstr\web\User` without session,
 * `yii\rbac\DbManager` on the test database with the RBAC tables of Yii and
 * all RBAC migrations of the package, an in-memory session for flash messages
 * and disabled asset bundles.
 *
 * Requests run through `Yii::$app->runAction()`, so the access check of the
 * module (`AccessBehaviorTrait`) and the filters of the controllers apply:
 *
 * ```php
 * $this->loginAs(Module::ROLE_ADMIN);
 * $html = $this->get('type/index');
 * $this->post('type/create', ['Type' => ['name' => 'Law']]);
 * $this->assertRedirectsTo(['type/index']);
 * $this->assertSame('...', $this->getFlash('success'));
 * ```
 */
abstract class WebTestCase extends TestCase
{
    public const MODULE_ID = 'knowledge-library';

    public const LOGIN_URL = ['/user/login'];

    private array $serverBackup = [];

    private array $getBackup = [];

    private array $postBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $_SESSION = [];
        TestIdentity::reset();
        Yii::setLogger(null);
        // Keep all messages of a test for getLogMessages().
        Yii::getLogger()->flushInterval = 0;

        parent::setUp();

        $this->installRbac();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Yii::$container = new Container();
        Yii::setLogger(null);
        TestIdentity::reset();
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        unset($_SESSION);
    }

    protected function applicationClass(): string
    {
        return Application::class;
    }

    protected function applicationConfig(): array
    {
        return ArrayHelper::merge(parent::applicationConfig(), [
            'components' => [
                'request' => $this->requestConfig(),
                'user' => [
                    'class' => User::class,
                    'identityClass' => TestIdentity::class,
                    'enableSession' => false,
                    'loginUrl' => static::LOGIN_URL,
                    'rootUsers' => [],
                ],
                'authManager' => [
                    'class' => DbManager::class,
                ],
                'session' => [
                    'class' => TestSession::class,
                ],
                // Asset bundles are replaced by empty dummies: nothing is
                // published and widgets register no files.
                'assetManager' => [
                    'bundles' => false,
                ],
            ],
            'modules' => [
                static::MODULE_ID => $this->moduleConfig(),
            ],
        ]);
    }

    /**
     * Configuration of the request component, applied again for every
     * request of a test.
     */
    protected function requestConfig(): array
    {
        return [
            'class' => Request::class,
            'hostInfo' => 'http://localhost',
            'scriptUrl' => '/index.php',
            'scriptFile' => __DIR__ . '/index.php',
            'cookieValidationKey' => 'knowledge-library-test',
            'enableCsrfValidation' => false,
        ];
    }

    /**
     * Configuration of the backend module, applied again for every request of
     * a test. Override to add e.g. a `controllerMap`.
     */
    protected function moduleConfig(): array
    {
        return [
            'class' => Module::class,
            'layout' => false,
        ];
    }

    /**
     * Creates the RBAC tables of Yii and runs all RBAC migrations of the
     * package in migration order.
     */
    protected function installRbac(): void
    {
        $this->runMigrationFile(Yii::getAlias('@yii/rbac/migrations/m140506_102106_rbac_init.php'));

        $files = glob(dirname(__DIR__) . '/src/migrations/m*.php');
        sort($files);
        foreach ($files as $file) {
            require_once $file;
            if (is_subclass_of(basename($file, '.php'), RbacMigration::class)) {
                $this->runMigrationFile($file);
            }
        }
    }

    /**
     * Runs `up` of the migration in the file without output, returns the
     * output.
     */
    protected function runMigrationFile(string $file): string
    {
        require_once $file;
        $class = basename($file, '.php');
        /** @var MigrationInterface $migration */
        $migration = new $class([
            'db' => Yii::$app->db,
            'compact' => true,
        ]);

        ob_start();
        try {
            $result = $migration->up();
        } finally {
            $output = ob_get_clean();
        }

        $this->assertNotFalse($result, "Migration $class failed: $output");

        return $output;
    }

    /**
     * Logs out: following requests run as guest.
     */
    protected function loginAsGuest(): void
    {
        Yii::$app->getUser()->setIdentity(null);
        DummyUserProvider::$currentReference = null;
    }

    /**
     * Creates a user, assigns the role (or permission) if given and logs the
     * user in for the following requests. The user reference of the
     * DummyUserProvider becomes the UUID of the user.
     */
    protected function loginAs(?string $role = null, ?string $username = null): TestIdentity
    {
        $identity = TestIdentity::create($username ?? ($role ?? 'user') . '-' . uniqid());

        if ($role !== null) {
            $authManager = Yii::$app->getAuthManager();
            $item = $authManager->getRole($role) ?? $authManager->getPermission($role);
            $this->assertNotNull($item, "Role or permission '$role' does not exist.");
            $authManager->assign($item, $identity->getId());
        }

        Yii::$app->getUser()->setIdentity($identity);
        DummyUserProvider::$currentReference = $identity->uuid;

        return $identity;
    }

    /**
     * Runs a GET request of the route, relative to the module (e.g.
     * `item/view`), with the query params. Returns the result of the action:
     * the rendered page, a Response or null if a filter stopped the request.
     *
     * @return string|Response|mixed
     */
    protected function get(string $route, array $params = [])
    {
        return $this->request('GET', $route, $params);
    }

    /**
     * Runs a POST request of the route with the body params and query params.
     *
     * @return string|Response|mixed
     */
    protected function post(string $route, array $body = [], array $params = [])
    {
        return $this->request('POST', $route, $params, $body);
    }

    /**
     * Runs a request of the route, relative to the module, like the web
     * application would: fresh request, response and view components, a fresh
     * module instance and action params from the query params. The session is
     * closed and reopened, so flash messages behave as in a sequence of HTTP
     * requests.
     *
     * @return string|Response|mixed
     */
    protected function request(string $method, string $route, array $params = [], array $body = [])
    {
        $app = Yii::$app;
        $route = static::MODULE_ID . '/' . ltrim($route, '/');

        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = $params;
        $_POST = $body;

        $app->set('request', $this->requestConfig());
        $app->set('response', ['class' => Response::class]);
        $app->set('view', ['class' => View::class]);
        // The access behavior of the module binds to the controller of the
        // request when it is attached, so every request needs a new module.
        $app->setModule(static::MODULE_ID, $this->moduleConfig());
        $app->controller = null;
        $app->requestedAction = null;
        $app->requestedRoute = $route;
        $app->requestedParams = $params;
        $app->getSession()->close();

        $request = $app->getRequest();
        $request->setQueryParams($params);
        $request->setBodyParams($body);
        $url = Url::to(array_merge(['/' . $route], $params));
        $request->setUrl($url);
        $_SERVER['REQUEST_URI'] = $url;

        ob_start();
        try {
            $result = $app->runAction($route, $params);
        } finally {
            $output = ob_get_clean();
        }

        $this->assertSame('', $output, "Request $method $route wrote output");

        return $result;
    }

    /**
     * Response of the last request.
     */
    protected function getResponse(): Response
    {
        return Yii::$app->getResponse();
    }

    /**
     * Converts a route relative to the module (`['item/view', 'id' => 1]`) into
     * an absolute route; routes starting with `/` are kept.
     */
    protected function moduleRoute(array $route): array
    {
        if (!str_starts_with($route[0], '/')) {
            $route[0] = '/' . static::MODULE_ID . '/' . $route[0];
        }

        return $route;
    }

    /**
     * Asserts that the last request rendered a page with status 200 and
     * returns it.
     */
    protected function assertPage($result): string
    {
        $this->assertIsString($result, 'Expected a rendered page, got ' . get_debug_type($result));
        $this->assertSame(200, $this->getResponse()->getStatusCode());

        return $result;
    }

    /**
     * Asserts that the last request redirected to the URL, given as route
     * relative to the module, absolute route or URL string.
     */
    protected function assertRedirectsTo(array|string $url): void
    {
        $response = $this->getResponse();
        $this->assertTrue(
            $response->getIsRedirection(),
            "Expected a redirect, got status {$response->getStatusCode()}"
        );

        $expected = is_array($url) ? Url::to($this->moduleRoute($url), true) : $url;
        $this->assertSame($expected, $response->getHeaders()->get('Location'));
    }

    /**
     * Asserts that the last request redirected to the login URL.
     */
    protected function assertRedirectsToLogin(): void
    {
        $this->assertRedirectsTo(static::LOGIN_URL);
    }

    /**
     * Runs the request and asserts that it throws an HTTP exception of the
     * class, which is returned.
     */
    protected function assertHttpException(
        string $class,
        string $method,
        string $route,
        array $params = [],
        array $body = []
    ): HttpException {
        try {
            $this->request($method, $route, $params, $body);
        } catch (Throwable $e) {
            $this->assertInstanceOf($class, $e, "Unexpected exception for $method $route: " . $e->getMessage());

            return $e;
        }

        $this->fail("Expected $class for $method $route, got status {$this->getResponse()->getStatusCode()}");
    }

    /**
     * Asserts that the request is denied with a ForbiddenHttpException (403).
     */
    protected function assertForbidden(string $method, string $route, array $params = [], array $body = []): void
    {
        $this->assertHttpException(ForbiddenHttpException::class, $method, $route, $params, $body);
    }

    /**
     * Asserts that the request is denied with a MethodNotAllowedHttpException
     * (405), e.g. a GET request of a POST-only action.
     */
    protected function assertMethodNotAllowed(string $method, string $route, array $params = [], array $body = []): void
    {
        $this->assertHttpException(MethodNotAllowedHttpException::class, $method, $route, $params, $body);
    }

    /**
     * Returns the flash message of the key and removes it.
     *
     * @return mixed
     */
    protected function getFlash(string $key)
    {
        return Yii::$app->getSession()->getFlash($key, null, true);
    }

    /**
     * Returns the logged messages of the category and level, oldest first.
     *
     * @return string[]
     */
    protected function getLogMessages(string $category = 'knowledge-library', int $level = Logger::LEVEL_INFO): array
    {
        $messages = [];
        foreach (Yii::getLogger()->messages as $message) {
            if ($message[1] === $level && $message[2] === $category) {
                $messages[] = (string)$message[0];
            }
        }

        return $messages;
    }
}
