<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\mcp\tools;

use dmstr\knowledgeLibrary\mcp\JsonRpcException;
use dmstr\knowledgeLibrary\mcp\ToolException;

/**
 * A tool of the MCP server.
 */
interface ToolInterface
{
    /**
     * Unique name of the tool as used in `tools/call`.
     */
    public function getName(): string;

    /**
     * Definition of the tool as listed by `tools/list`: `name`, `title`,
     * `description`, `inputSchema` (JSON Schema of the arguments) and
     * `annotations`.
     */
    public function getDefinition(): array;

    /**
     * Calls the tool and returns the result of `tools/call`: `content` (list
     * of content blocks) and optionally `structuredContent`.
     *
     * @param array $arguments the arguments of the call, decoded JSON object
     *
     * @throws JsonRpcException with Server::ERROR_INVALID_PARAMS for
     * missing or invalid arguments
     * @throws ToolException if the tool cannot do its job with valid
     * arguments; the message is returned to the client as tool error
     */
    public function call(array $arguments): array;
}
