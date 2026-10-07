<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\tests;

use dmstr\knowledgeLibrary\mcp\Module as McpModule;
use stdClass;
use yii\helpers\ArrayHelper;

/**
 * Base test case for the MCP module.
 *
 * Like WebTestCase, but requests run against the MCP module registered as
 * `knowledge-mcp` without authenticator, so the identity of loginAs() is
 * the client. The backend module is registered as `knowledge-library` with
 * the file storage `fs`. The permission `knowledge-mcp` of the RBAC
 * migrations grants access to all routes of the module:
 *
 * ```php
 * $this->loginAs('knowledge-mcp');
 * $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
 * $data = $this->structured('knowledge_search', ['query' => 'forest']);
 * ```
 */
abstract class McpWebTestCase extends WebTestCase
{
    public const MODULE_ID = 'knowledge-mcp';

    public const BACKEND_MODULE_ID = 'knowledge-library';

    public const PERMISSION = 'knowledge-mcp';

    /**
     * Authenticator of the module for the following requests, null for none.
     */
    protected array|string|null $authenticator = null;

    /**
     * `maxInlineFileSize` of the module for the following requests.
     */
    protected int $maxInlineFileSize = 5 * 1024 * 1024;

    /**
     * Raw body of the next request, see rpc().
     */
    private ?string $rawBody = null;

    private int $nextId = 1;

    protected function applicationConfig(): array
    {
        return ArrayHelper::merge(parent::applicationConfig(), [
            'modules' => [
                static::BACKEND_MODULE_ID => $this->backendModuleConfig(),
            ],
        ]);
    }

    protected function requestConfig(): array
    {
        return array_merge(parent::requestConfig(), [
            'rawBody' => $this->rawBody ?? '',
        ]);
    }

    /**
     * Configuration of the MCP module, applied again for every request of a
     * test.
     */
    protected function moduleConfig(): array
    {
        return [
            'class' => McpModule::class,
            'layout' => false,
            'backendModuleId' => static::BACKEND_MODULE_ID,
            'authenticator' => $this->authenticator,
            'maxInlineFileSize' => $this->maxInlineFileSize,
        ];
    }

    /**
     * Configuration of the backend module the MCP module shares its file
     * storage with.
     */
    protected function backendModuleConfig(): array
    {
        return parent::moduleConfig();
    }

    /**
     * Sends the message (encoded as JSON) or the raw body to the endpoint and
     * returns the result of the action: the response message(s) as array or
     * null.
     *
     * @param string $route route of the endpoint relative to the module
     *
     * @return array|null
     */
    protected function rpc(array|string $message, string $route = 'default/index')
    {
        $this->rawBody = is_string($message) ? $message : json_encode($message, JSON_THROW_ON_ERROR);
        try {
            return $this->post($route);
        } finally {
            $this->rawBody = null;
        }
    }

    /**
     * Sends a request and asserts a response to it with status 200.
     */
    protected function rpcRequest(string $method, array|stdClass $params = []): array
    {
        $id = $this->nextId++;
        $response = $this->rpc([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);

        $this->assertSame(200, $this->getResponse()->getStatusCode());
        $this->assertIsArray($response, "No response to $method");
        $this->assertSame('2.0', $response['jsonrpc'] ?? null);
        $this->assertSame($id, $response['id'] ?? null);

        return $response;
    }

    /**
     * Sends a request and returns its result, asserting that there is no
     * error.
     */
    protected function rpcResult(string $method, array|stdClass $params = []): array|stdClass
    {
        $response = $this->rpcRequest($method, $params);
        $this->assertArrayNotHasKey('error', $response, "$method failed: " . json_encode($response['error'] ?? null));
        $this->assertArrayHasKey('result', $response);

        return $response['result'];
    }

    /**
     * Sends a request and returns its error, asserting that there is one.
     */
    protected function rpcError(string $method, array|stdClass $params = []): array
    {
        $response = $this->rpcRequest($method, $params);
        $this->assertArrayHasKey('error', $response, "$method succeeded: " . json_encode($response['result'] ?? null));

        return $response['error'];
    }

    /**
     * Calls a tool and returns the result of `tools/call`.
     */
    protected function callTool(string $name, array $arguments = []): array
    {
        return $this->rpcResult('tools/call', [
            'name' => $name,
            'arguments' => $arguments === [] ? new stdClass() : $arguments,
        ]);
    }

    /**
     * Calls a tool and returns its structured content, asserting that the
     * call succeeded and the text content carries the same data.
     */
    protected function structured(string $name, array $arguments = []): array
    {
        $result = $this->callTool($name, $arguments);
        $this->assertArrayNotHasKey('isError', $result, "$name failed: " . json_encode($result['content'] ?? null));
        $this->assertArrayHasKey('structuredContent', $result);
        $this->assertSame('text', $result['content'][0]['type'] ?? null);
        $this->assertSame($result['structuredContent'], json_decode($result['content'][0]['text'], true));

        return $result['structuredContent'];
    }

    /**
     * Calls a tool and returns the message of its error, asserting that the
     * call failed with a tool error (not a protocol error).
     */
    protected function toolError(string $name, array $arguments = []): string
    {
        $result = $this->callTool($name, $arguments);
        $this->assertTrue($result['isError'] ?? false, "$name succeeded: " . json_encode($result));
        $this->assertSame('text', $result['content'][0]['type'] ?? null);

        return $result['content'][0]['text'];
    }
}
