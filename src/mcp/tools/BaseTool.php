<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\mcp\tools;

use dmstr\knowledgeLibrary\mcp\ItemPresenter;
use dmstr\knowledgeLibrary\mcp\JsonRpcException;
use dmstr\knowledgeLibrary\mcp\Module;
use dmstr\knowledgeLibrary\mcp\Server;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\query\ItemQuery;

/**
 * Base class of the tools: access to the module, the items the server shows,
 * argument parsing and the result format.
 *
 * All tools are read-only and see exactly what the frontend module shows:
 * the active items with a version valid today.
 */
abstract class BaseTool implements ToolInterface
{
    public function __construct(protected Module $module)
    {
    }

    public function getName(): string
    {
        return static::NAME;
    }

    /**
     * Today in the time zone of the application, `Y-m-d`.
     */
    protected function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * Query of the items the server shows: active, with a version valid
     * today.
     */
    protected function visibleItems(): ItemQuery
    {
        return Item::find()->active()->validAt($this->today());
    }

    protected function presenter(): ItemPresenter
    {
        return new ItemPresenter($this->module);
    }

    /**
     * Annotations of a tool that only reads.
     */
    protected function readOnlyAnnotations(): array
    {
        return [
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
    }

    /**
     * Tool result with the data as structured content and, for clients
     * without support for it, as pretty-printed JSON text.
     */
    protected function result(array $data): array
    {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode(
                        $data,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    ),
                ],
            ],
            'structuredContent' => $data,
        ];
    }

    /**
     * A trimmed string argument, null if missing or empty.
     *
     * @throws JsonRpcException if the argument is no string, or missing
     * although required
     */
    protected function stringArgument(array $arguments, string $name, bool $required = false): ?string
    {
        $value = $arguments[$name] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new JsonRpcException(Server::ERROR_INVALID_PARAMS, "Argument \"$name\" must be a string");
        }

        $value = $value === null ? '' : trim($value);
        if ($value === '') {
            if ($required) {
                throw new JsonRpcException(Server::ERROR_INVALID_PARAMS, "Argument \"$name\" is required");
            }

            return null;
        }

        return $value;
    }

    /**
     * An integer argument within the bounds, the default if missing. Integer
     * strings and whole floats are accepted, as some clients send them.
     *
     * @throws JsonRpcException if the argument is no integer or out of bounds
     */
    protected function integerArgument(array $arguments, string $name, int $default, int $min, int $max): int
    {
        $value = $arguments[$name] ?? null;
        if ($value === null) {
            return $default;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            $value = (int)$value;
        } elseif (is_float($value) && floor($value) === $value) {
            $value = (int)$value;
        }
        if (!is_int($value)) {
            throw new JsonRpcException(Server::ERROR_INVALID_PARAMS, "Argument \"$name\" must be an integer");
        }
        if ($value < $min || $value > $max) {
            throw new JsonRpcException(Server::ERROR_INVALID_PARAMS, "Argument \"$name\" must be between $min and $max");
        }

        return $value;
    }
}
