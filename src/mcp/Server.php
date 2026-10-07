<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\mcp;

use dmstr\knowledgeLibrary\mcp\tools\ToolInterface;
use JsonException;
use stdClass;
use Throwable;
use Yii;

/**
 * Stateless MCP server: JSON-RPC 2.0 over the Streamable HTTP transport.
 *
 * Every HTTP POST is handled on its own. No session ID is issued and none is
 * expected, so clients keep working across deployments and restarts of the
 * application; session-bound features (server-initiated requests,
 * notifications, subscriptions) are therefore not offered. The server
 * supports the protocol revisions 2025-03-26, 2025-06-18 and 2025-11-25 and
 * the methods `initialize`, `ping`, `tools/list` and `tools/call`.
 * Notifications and responses of the client are accepted and ignored,
 * everything else (resources, prompts, logging) is answered with "method not
 * found", as the server does not advertise those capabilities.
 *
 * The protocol is small enough to implement here; the official PHP SDK keeps
 * sessions in the process memory for these revisions, which does not work
 * behind PHP-FPM.
 */
class Server
{
    /**
     * Supported protocol revisions, newest first.
     */
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    /**
     * Revision answered to a client requesting an unsupported one.
     */
    public const DEFAULT_PROTOCOL_VERSION = '2025-11-25';

    public const ERROR_PARSE = -32700;
    public const ERROR_INVALID_REQUEST = -32600;
    public const ERROR_METHOD_NOT_FOUND = -32601;
    public const ERROR_INVALID_PARAMS = -32602;
    public const ERROR_INTERNAL = -32603;

    /**
     * @var array<string, ToolInterface> tools by name
     */
    private array $tools = [];

    public function __construct(
        private string $name,
        private string $version,
        private ?string $instructions = null
    ) {
    }

    public function addTool(ToolInterface $tool): static
    {
        $this->tools[$tool->getName()] = $tool;

        return $this;
    }

    /**
     * @return ToolInterface[]
     */
    public function getTools(): array
    {
        return array_values($this->tools);
    }

    /**
     * Handles the body of one HTTP POST.
     *
     * @return array|null the response message, or the list of response
     * messages for a batch, to send as JSON with HTTP 200; null if there is
     * nothing to send (only notifications or client responses), to answer
     * with HTTP 202
     *
     * @throws JsonRpcException if the body is no JSON-RPC message at all; the
     * error is sent with HTTP 400 and `id` null
     */
    public function handle(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new JsonRpcException(self::ERROR_PARSE, 'Parse error: ' . $e->getMessage());
        }

        if (is_array($decoded) && array_is_list($decoded)) {
            if ($decoded === []) {
                throw new JsonRpcException(self::ERROR_INVALID_REQUEST, 'Invalid Request: empty batch');
            }

            $responses = [];
            foreach ($decoded as $message) {
                try {
                    $response = $this->handleMessage($message);
                } catch (JsonRpcException $e) {
                    $response = self::errorResponse(null, $e);
                }
                if ($response !== null) {
                    $responses[] = $response;
                }
            }

            return $responses === [] ? null : $responses;
        }

        return $this->handleMessage($decoded);
    }

    /**
     * Handles one decoded message: a request is answered, a notification or
     * a client response has no answer.
     *
     * @throws JsonRpcException if the message is no JSON-RPC 2.0 message
     */
    private function handleMessage(mixed $message): ?array
    {
        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
            throw new JsonRpcException(self::ERROR_INVALID_REQUEST, 'Invalid Request: not a JSON-RPC 2.0 message');
        }

        $hasId = array_key_exists('id', $message);
        $id = $message['id'] ?? null;

        if (array_key_exists('method', $message)) {
            $method = $message['method'];
            $params = $message['params'] ?? [];
            if (!is_string($method) || $method === '' || !is_array($params)) {
                throw new JsonRpcException(self::ERROR_INVALID_REQUEST, 'Invalid Request: method must be a string and params an object');
            }

            if (!$hasId) {
                // A notification, e.g. notifications/initialized: nothing to answer.
                return null;
            }

            if (!is_int($id) && !is_string($id)) {
                throw new JsonRpcException(self::ERROR_INVALID_REQUEST, 'Invalid Request: id must be a string or an integer');
            }

            try {
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => $this->handleRequest($method, $params),
                ];
            } catch (JsonRpcException $e) {
                return self::errorResponse($id, $e);
            } catch (Throwable $e) {
                Yii::error("MCP request $method failed: " . $e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);

                return self::errorResponse($id, new JsonRpcException(
                    self::ERROR_INTERNAL,
                    'Internal error',
                    YII_DEBUG ? $e->getMessage() : null
                ));
            }
        }

        if ($hasId && (array_key_exists('result', $message) || array_key_exists('error', $message))) {
            // A response to a server request. This server sends none, so there is nothing to match it with.
            return null;
        }

        throw new JsonRpcException(self::ERROR_INVALID_REQUEST, 'Invalid Request: neither a request, a notification nor a response');
    }

    /**
     * @throws JsonRpcException for an unknown method or invalid params
     */
    private function handleRequest(string $method, array $params): array|stdClass
    {
        return match ($method) {
            'initialize' => $this->initialize($params),
            'ping' => new stdClass(),
            'tools/list' => [
                'tools' => array_map(static fn (ToolInterface $tool): array => $tool->getDefinition(), $this->getTools()),
            ],
            'tools/call' => $this->callTool($params),
            default => throw new JsonRpcException(self::ERROR_METHOD_NOT_FOUND, "Method not found: $method"),
        };
    }

    /**
     * Answers the handshake with the requested protocol revision if supported,
     * the default revision otherwise, and the capabilities of the server.
     * There is no session, so no session ID is handed out.
     */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        $result = [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true)
                ? $requested
                : self::DEFAULT_PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => $this->name,
                'version' => $this->version,
            ],
        ];
        if ($this->instructions !== null && trim($this->instructions) !== '') {
            $result['instructions'] = $this->instructions;
        }

        return $result;
    }

    /**
     * Calls a tool. An unknown tool and invalid arguments are protocol
     * errors, a failing call with valid arguments is a tool result with
     * `isError`.
     *
     * @throws JsonRpcException
     */
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || !isset($this->tools[$name])) {
            throw new JsonRpcException(
                self::ERROR_INVALID_PARAMS,
                'Unknown tool: ' . (is_scalar($name) ? (string)$name : get_debug_type($name))
            );
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new JsonRpcException(self::ERROR_INVALID_PARAMS, 'Tool arguments must be an object');
        }

        try {
            return $this->tools[$name]->call($arguments);
        } catch (ToolException $e) {
            return [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ];
        }
    }

    /**
     * A JSON-RPC error response.
     */
    public static function errorResponse(int|string|null $id, JsonRpcException $error): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error->toArray(),
        ];
    }
}
