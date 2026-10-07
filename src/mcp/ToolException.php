<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\mcp;

use RuntimeException;

/**
 * Error of a tool call with valid arguments, e.g. a knowledge object that does
 * not exist or a file too large to return. The server answers the call with a
 * tool result that has `isError` set and the message as text, so the client
 * can pass it to the model; protocol errors are JsonRpcException.
 */
class ToolException extends RuntimeException
{
}
