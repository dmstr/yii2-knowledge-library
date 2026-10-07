<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\mcp;

use RuntimeException;

/**
 * JSON-RPC 2.0 error: the code is one of the Server::ERROR_* constants, the
 * optional data is sent as `error.data`.
 *
 * Thrown by the server for protocol errors (parse error, invalid request,
 * unknown method, invalid params) and by tools for invalid arguments. A tool
 * that cannot do its job with valid arguments throws a ToolException instead,
 * which becomes a tool result with `isError` the client can show to the
 * model.
 */
class JsonRpcException extends RuntimeException
{
    public function __construct(int $code, string $message, private mixed $data = null)
    {
        parent::__construct($message, $code);
    }

    /**
     * The `error` member of a JSON-RPC response.
     */
    public function toArray(): array
    {
        $error = [
            'code' => $this->getCode(),
            'message' => $this->getMessage(),
        ];
        if ($this->data !== null) {
            $error['data'] = $this->data;
        }

        return $error;
    }
}
