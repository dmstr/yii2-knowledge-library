<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\mcp\tools;

use dmstr\knowledgeLibrary\models\Topic;

/**
 * `knowledge_list_topics`: all topics with the number of their valid items.
 */
class ListTopicsTool extends BaseTool
{
    public const NAME = 'knowledge_list_topics';

    public function getDefinition(): array
    {
        return [
            'name' => self::NAME,
            'title' => 'List knowledge topics',
            'description' => 'Lists the topics (keywords) of the knowledge library with their IDs and how many '
                . 'objects assigned to the topic are valid today. Use the ID as topic_id filter of '
                . 'knowledge_search.',
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
        $topics = Topic::find()->orderBy(['name' => SORT_ASC, 'id' => SORT_ASC])->all();

        return $this->result([
            'topics' => array_map(static fn (Topic $topic): array => [
                'id' => $topic->id,
                'name' => $topic->name,
                'item_count' => (int)$topic->getItems()->active()->validAt($today)->count(),
            ], $topics),
        ]);
    }
}
