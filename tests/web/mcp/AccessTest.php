<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\tests\web\mcp;

use dmstr\knowledgeLibrary\mcp\auth\StaticTokenAuth;
use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\McpWebTestCase;
use dmstr\knowledgeLibrary\tests\support\FrontendFixtures;
use Yii;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * Access control, authentication and HTTP methods of the MCP module.
 */
class AccessTest extends McpWebTestCase
{
    use FrontendFixtures;

    private const PING = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];

    public function testGuestWithoutAuthenticatorIsSentToTheLogin(): void
    {
        $this->loginAsGuest();

        $this->assertNull($this->rpc(self::PING));
        $this->assertRedirectsToLogin();
    }

    public function testUserWithoutPermissionIsForbidden(): void
    {
        [, $version] = $this->createValidItem();
        $file = $this->createStoredFile($version, 'law.pdf');
        $this->loginAs();

        $this->assertForbidden('POST', 'default/index');
        $this->assertForbidden('GET', 'file/download', ['id' => $file->id]);
    }

    public function testFrontendPermissionAndBackendRolesDoNotGrantAccess(): void
    {
        foreach (['knowledge', Module::ROLE_EDITOR, Module::ROLE_REVIEWER, Module::ROLE_ADMIN] as $role) {
            $this->loginAs($role);

            $this->assertForbidden('POST', 'default/index');
            $this->assertForbidden('GET', 'file/download', ['id' => 'x']);
        }
    }

    public function testPermissionGrantsTheEndpointWithoutSession(): void
    {
        $this->loginAs(static::PERMISSION);

        $response = $this->rpc(self::PING);

        $this->assertSame(200, $this->getResponse()->getStatusCode());
        $this->assertSame(Response::FORMAT_JSON, $this->getResponse()->format);
        $this->assertSame(1, $response['id']);
        $this->assertFalse($this->getResponse()->getHeaders()->has('Mcp-Session-Id'));
        $this->assertFalse(Yii::$app->getUser()->enableSession);

        // The module URL is the endpoint.
        $this->assertSame(1, $this->rpc(self::PING, '')['id']);
    }

    public function testPermissionGrantsTheFileDownload(): void
    {
        [, $version] = $this->createValidItem();
        $file = $this->createStoredFile($version, 'law.pdf');
        $this->loginAs(static::PERMISSION);

        $response = $this->get('file/download', ['id' => $file->id]);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(static::$fileContent, $this->readResponseStream($response));
    }

    public function testFileDownloadFollowsTheFrontendRules(): void
    {
        $invalid = $this->createInvalidItems();
        $this->loginAs(static::PERMISSION);

        foreach ($invalid as $reason => [, $version]) {
            $file = $this->createStoredFile($version, "$reason.pdf");
            $this->assertHttpException(NotFoundHttpException::class, 'GET', 'file/download', ['id' => $file->id]);
        }
    }

    public function testEndpointIsPostOnlyAndDownloadGetOnly(): void
    {
        $this->loginAs(static::PERMISSION);

        foreach (['GET', 'DELETE', 'PUT'] as $method) {
            $this->assertMethodNotAllowed($method, 'default/index');
        }
        foreach (['POST', 'DELETE', 'PUT'] as $method) {
            $this->assertMethodNotAllowed($method, 'file/download', ['id' => 'x']);
        }
    }

    public function testBearerTokenAuthenticatesTheConfiguredIdentity(): void
    {
        $identity = $this->loginAs(static::PERMISSION);
        $this->loginAsGuest();
        $this->authenticator = [
            'class' => StaticTokenAuth::class,
            'tokens' => ['', 'secret-token'],
            'identity' => static fn () => $identity,
        ];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret-token';
        $response = $this->rpc(self::PING);

        $this->assertSame(200, $this->getResponse()->getStatusCode());
        $this->assertSame(1, $response['id']);
        $this->assertSame($identity->getId(), Yii::$app->getUser()->getId());
    }

    public function testWrongOrMissingBearerTokenIsUnauthorized(): void
    {
        $identity = $this->loginAs(static::PERMISSION);
        $this->loginAsGuest();
        $this->authenticator = [
            'class' => StaticTokenAuth::class,
            'tokens' => 'secret-token',
            'identity' => static fn () => $identity,
        ];

        foreach (['Bearer wrong', 'Bearer ', 'Basic c2VjcmV0', null] as $header) {
            unset($_SERVER['HTTP_AUTHORIZATION']);
            if ($header !== null) {
                $_SERVER['HTTP_AUTHORIZATION'] = $header;
            }

            $this->assertHttpException(UnauthorizedHttpException::class, 'POST', 'default/index');
            $this->assertStringStartsWith('Bearer', (string)$this->getResponse()->getHeaders()->get('WWW-Authenticate'));
            $this->assertTrue(Yii::$app->getUser()->getIsGuest());
        }
    }

    public function testBearerTokenOfIdentityWithoutPermissionIsForbidden(): void
    {
        $identity = $this->loginAs();
        $this->loginAsGuest();
        $this->authenticator = [
            'class' => StaticTokenAuth::class,
            'tokens' => ['secret-token'],
            'identity' => static fn () => $identity,
        ];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret-token';

        $this->assertForbidden('POST', 'default/index');
    }

    public function testEmptyConfiguredTokenNeverMatches(): void
    {
        $identity = $this->loginAs(static::PERMISSION);
        $this->loginAsGuest();
        $this->authenticator = [
            'class' => StaticTokenAuth::class,
            'tokens' => [''],
            'identity' => static fn () => $identity,
        ];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';

        $this->assertHttpException(UnauthorizedHttpException::class, 'POST', 'default/index');
    }
}
