<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\mcp\tools;

use dmstr\knowledgeLibrary\mcp\ToolException;
use dmstr\knowledgeLibrary\models\Item;

/**
 * `knowledge_get_item`: the full content of one item valid today.
 */
class GetItemTool extends BaseTool
{
    public const NAME = 'knowledge_get_item';

    public function getDefinition(): array
    {
        return [
            'name' => self::NAME,
            'title' => 'Read a knowledge object',
            'description' => 'Returns a knowledge object that is valid today with its master data, source, the text '
                . 'of the valid version as Markdown, its files (main files carry the content, attachments are '
                . 'supplementary; fetch them with knowledge_get_file) and its relations to other valid objects. '
                . 'Objects that are archived or have no version valid today do not exist for this tool.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'ID of the knowledge object (from knowledge_search)',
                    ],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            'annotations' => $this->readOnlyAnnotations(),
        ];
    }

    public function call(array $arguments): array
    {
        $id = $this->stringArgument($arguments, 'id', true);
        $today = $this->today();

        $item = Item::find()
            ->active()
            ->andWhere([Item::tableName() . '.[[id]]' => $id])
            ->with(['type', 'topics'])
            ->one();
        $version = $item?->getValidVersion($today);
        if ($item === null || $version === null) {
            throw new ToolException("No valid knowledge object with the ID \"$id\" exists.");
        }

        return $this->result($this->presenter()->details($item, $version, $today));
    }
}
