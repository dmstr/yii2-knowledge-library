<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\mcp\JsonRpcException;
use dmstr\knowledgeLibrary\mcp\Server;
use dmstr\knowledgeLibrary\mcp\ToolException;
use dmstr\knowledgeLibrary\mcp\tools\ToolInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Yii;

/**
 * The JSON-RPC handling of the MCP server, independent of the tools.
 */
class McpServerTest extends TestCase
{
    protected function tearDown(): void
    {
        Yii::getLogger()->messages = [];

        parent::tearDown();
    }

    public function testInitializeAnswersTheRequestedVersionIfSupported(): void
    {
        $server = $this->server();

        foreach (Server::PROTOCOL_VERSIONS as $version) {
            $response = $server->handle($this->encode('initialize', ['protocolVersion' => $version], 'init'));

            $this->assertSame('init', $response['id']);
            $this->assertSame($version, $response['result']['protocolVersion']);
        }
    }

    public function testInitializeAnswersTheDefaultVersionOtherwise(): void
    {
        $server = $this->server();

        foreach (['2024-11-05', '2099-01-01', 7, null] as $version) {
            $params = $version === null ? [] : ['protocolVersion' => $version];
            $response = $server->handle($this->encode('initialize', $params));

            $this->assertSame(Server::DEFAULT_PROTOCOL_VERSION, $response['result']['protocolVersion']);
        }
    }

    public function testInitializeAnnouncesToolsOnlyAndNoSession(): void
    {
        $response = $this->server()->handle($this->encode('initialize', ['protocolVersion' => '2025-06-18']));

        $this->assertSame([
            'protocolVersion' => '2025-06-18',
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'Test Server', 'version' => '1.2.3'],
            'instructions' => 'Use the echo tool.',
        ], $response['result']);
    }

    public function testInitializeWithoutInstructionsOmitsThem(): void
    {
        $server = new Server('Test Server', '1.2.3');

        $response = $server->handle($this->encode('initialize'));

        $this->assertArrayNotHasKey('instructions', $response['result']);
    }

    public function testPingAnswersAnEmptyObject(): void
    {
        $response = $this->server()->handle($this->encode('ping'));

        $this->assertEquals(new stdClass(), $response['result']);
    }

    public function testNotificationsAndClientResponsesHaveNoAnswer(): void
    {
        $server = $this->server();

        $this->assertNull($server->handle('{"jsonrpc":"2.0","method":"notifications/initialized"}'));
        $this->assertNull($server->handle('{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":1}}'));
        $this->assertNull($server->handle('{"jsonrpc":"2.0","id":5,"result":{}}'));
        $this->assertNull($server->handle('{"jsonrpc":"2.0","id":5,"error":{"code":-1,"message":"x"}}'));
    }

    public function testBodiesThatAreNoMessageAreRejected(): void
    {
        $server = $this->server();

        $cases = [
            '{not json' => Server::ERROR_PARSE,
            '' => Server::ERROR_PARSE,
            '[]' => Server::ERROR_INVALID_REQUEST,
            '"text"' => Server::ERROR_INVALID_REQUEST,
            '{"id":1,"method":"ping"}' => Server::ERROR_INVALID_REQUEST,
            '{"jsonrpc":"1.0","id":1,"method":"ping"}' => Server::ERROR_INVALID_REQUEST,
            '{"jsonrpc":"2.0","id":null,"method":"ping"}' => Server::ERROR_INVALID_REQUEST,
            '{"jsonrpc":"2.0","id":1,"method":7}' => Server::ERROR_INVALID_REQUEST,
            '{"jsonrpc":"2.0","id":1,"method":"ping","params":"x"}' => Server::ERROR_INVALID_REQUEST,
            '{"jsonrpc":"2.0","id":1}' => Server::ERROR_INVALID_REQUEST,
        ];
        foreach ($cases as $body => $code) {
            try {
                $server->handle($body);
                $this->fail("Body $body was accepted");
            } catch (JsonRpcException $e) {
                $this->assertSame($code, $e->getCode(), $body);
            }
        }
    }

    public function testUnknownMethodIsAnErrorResponse(): void
    {
        $response = $this->server()->handle($this->encode('resources/list'));

        $this->assertSame(Server::ERROR_METHOD_NOT_FOUND, $response['error']['code']);
        $this->assertSame('Method not found: resources/list', $response['error']['message']);
        $this->assertArrayNotHasKey('result', $response);
    }

    public function testToolsListReturnsTheDefinitions(): void
    {
        $response = $this->server()->handle($this->encode('tools/list'));

        $this->assertSame([EchoTool::DEFINITION], $response['result']['tools']);
    }

    public function testToolsCallReturnsTheToolResult(): void
    {
        $response = $this->server()->handle($this->encode('tools/call', [
            'name' => 'echo',
            'arguments' => ['text' => 'hi'],
        ]));

        $this->assertSame([
            'content' => [['type' => 'text', 'text' => '{"text":"hi"}']],
        ], $response['result']);
    }

    public function testToolsCallWithoutArgumentsPassesAnEmptyList(): void
    {
        $response = $this->server()->handle($this->encode('tools/call', ['name' => 'echo']));

        $this->assertSame('[]', $response['result']['content'][0]['text']);
    }

    public function testUnknownToolAndInvalidArgumentsAreProtocolErrors(): void
    {
        $server = $this->server();

        $error = $server->handle($this->encode('tools/call', ['name' => 'nope']))['error'];
        $this->assertSame(Server::ERROR_INVALID_PARAMS, $error['code']);
        $this->assertSame('Unknown tool: nope', $error['message']);

        $error = $server->handle($this->encode('tools/call', []))['error'];
        $this->assertSame(Server::ERROR_INVALID_PARAMS, $error['code']);

        $error = $server->handle($this->encode('tools/call', ['name' => 'echo', 'arguments' => 'x']))['error'];
        $this->assertSame(Server::ERROR_INVALID_PARAMS, $error['code']);

        $error = $server->handle($this->encode('tools/call', ['name' => 'echo', 'arguments' => ['invalid' => true]]))['error'];
        $this->assertSame(Server::ERROR_INVALID_PARAMS, $error['code']);
        $this->assertSame('bad', $error['message']);
    }

    public function testToolExceptionIsAToolError(): void
    {
        $response = $this->server()->handle($this->encode('tools/call', [
            'name' => 'echo',
            'arguments' => ['fail' => true],
        ]));

        $this->assertSame([
            'content' => [['type' => 'text', 'text' => 'boom']],
            'isError' => true,
        ], $response['result']);
    }

    public function testOtherExceptionsAreInternalErrors(): void
    {
        $response = $this->server()->handle($this->encode('tools/call', [
            'name' => 'echo',
            'arguments' => ['crash' => true],
        ]));

        $this->assertSame(Server::ERROR_INTERNAL, $response['error']['code']);
        $this->assertSame('Internal error', $response['error']['message']);
        $this->assertSame('crash', $response['error']['data']);
    }

    public function testBatchAnswersTheRequestsOnly(): void
    {
        $responses = $this->server()->handle('[' . implode(',', [
            $this->encode('ping', [], 1),
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            '{"id":3,"method":"ping"}',
            $this->encode('nope', [], 4),
        ]) . ']');

        $this->assertCount(3, $responses);
        $this->assertSame(1, $responses[0]['id']);
        $this->assertEquals(new stdClass(), $responses[0]['result']);
        $this->assertNull($responses[1]['id']);
        $this->assertSame(Server::ERROR_INVALID_REQUEST, $responses[1]['error']['code']);
        $this->assertSame(4, $responses[2]['id']);
        $this->assertSame(Server::ERROR_METHOD_NOT_FOUND, $responses[2]['error']['code']);
    }

    public function testBatchOfNotificationsHasNoAnswer(): void
    {
        $this->assertNull($this->server()->handle('[{"jsonrpc":"2.0","method":"notifications/initialized"}]'));
    }

    public function testErrorResponseCarriesDataOnlyIfGiven(): void
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 'a', 'error' => ['code' => -1, 'message' => 'm']],
            Server::errorResponse('a', new JsonRpcException(-1, 'm'))
        );
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -1, 'message' => 'm', 'data' => ['x' => 1]]],
            Server::errorResponse(null, new JsonRpcException(-1, 'm', ['x' => 1]))
        );
    }

    private function server(): Server
    {
        return (new Server('Test Server', '1.2.3', 'Use the echo tool.'))->addTool(new EchoTool());
    }

    private function encode(string $method, array $params = [], int|string $id = 1): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params === [] ? new stdClass() : $params,
        ], JSON_THROW_ON_ERROR);
    }
}

/**
 * Tool returning its arguments, failing on request.
 */
final class EchoTool implements ToolInterface
{
    public const DEFINITION = [
        'name' => 'echo',
        'description' => 'Returns the arguments.',
        'inputSchema' => ['type' => 'object'],
    ];

    public function getName(): string
    {
        return 'echo';
    }

    public function getDefinition(): array
    {
        return self::DEFINITION;
    }

    public function call(array $arguments): array
    {
        if ($arguments['fail'] ?? false) {
            throw new ToolException('boom');
        }
        if ($arguments['invalid'] ?? false) {
            throw new JsonRpcException(Server::ERROR_INVALID_PARAMS, 'bad');
        }
        if ($arguments['crash'] ?? false) {
            throw new RuntimeException('crash');
        }

        return ['content' => [['type' => 'text', 'text' => json_encode($arguments)]]];
    }
}
