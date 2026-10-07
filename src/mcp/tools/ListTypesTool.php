<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\mcp\tools;

use dmstr\knowledgeLibrary\models\Type;

/**
 * `knowledge_list_types`: all types with the number of their valid items.
 */
class ListTypesTool extends BaseTool
{
    public const NAME = 'knowledge_list_types';

    public function getDefinition(): array
    {
        return [
            'name' => self::NAME,
            'title' => 'List knowledge types',
            'description' => 'Lists the types of knowledge objects (e.g. guideline, law) with their IDs, whether '
                . 'their versions have a validity period and how many objects of the type are valid today. '
                . 'Use the ID as type_id filter of knowledge_search.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'additionalProperties' => false,
            ],
            'annotations' => $this->readOnlyAnnotations(),
        ];
    }

    public function call(array $arguments): array
    {
        $today = $this->today();
        $types = Type::find()->orderBy(['name' => SORT_ASC, 'id' => SORT_ASC])->all();

        return $this->result([
            'types' => array_map(static fn (Type $type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'has_validity_period' => (bool)$type->has_validity_period,
                'requires_review' => (bool)$type->requires_review,
                'item_count' => (int)$type->getItems()->active()->validAt($today)->count(),
            ], $types),
        ]);
    }
}
