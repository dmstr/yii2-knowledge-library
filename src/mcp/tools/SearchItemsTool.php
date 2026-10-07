<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\mcp\tools;

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use yii\db\Expression;

/**
 * `knowledge_search`: one page of the items valid today matching a query and
 * filters.
 */
class SearchItemsTool extends BaseTool
{
    public const NAME = 'knowledge_search';

    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 100;

    public function getDefinition(): array
    {
        return [
            'name' => self::NAME,
            'title' => 'Search knowledge objects',
            'description' => 'Searches the knowledge objects that are valid today. The query is matched as a '
                . 'case-insensitive substring against the title, the summary and the text of the valid version; '
                . 'without a query all valid objects are listed. The optional filters by type and topic take the IDs '
                . 'from knowledge_list_types and knowledge_list_topics. The result is one page of objects sorted by '
                . 'title with the total number of matches; use knowledge_get_item for the full text, the files and '
                . 'the relations of an object.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Text to search for in title, summary and content',
                    ],
                    'type_id' => [
                        'type' => 'string',
                        'description' => 'Only objects of this type (ID from knowledge_list_types)',
                    ],
                    'topic_id' => [
                        'type' => 'string',
                        'description' => 'Only objects assigned to this topic (ID from knowledge_list_topics)',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => self::MAX_LIMIT,
                        'default' => self::DEFAULT_LIMIT,
                        'description' => 'Number of objects per page',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'default' => 0,
                        'description' => 'Number of objects to skip',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => $this->readOnlyAnnotations(),
        ];
    }

    public function call(array $arguments): array
    {
        $search = $this->stringArgument($arguments, 'query');
        $typeId = $this->stringArgument($arguments, 'type_id');
        $topicId = $this->stringArgument($arguments, 'topic_id');
        $limit = $this->integerArgument($arguments, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
        $offset = $this->integerArgument($arguments, 'offset', 0, 0, PHP_INT_MAX);

        $today = $this->today();
        $item = Item::tableName();
        $version = Version::tableName();

        $query = $this->visibleItems()
            ->withFilters(null, $typeId, $topicId === null ? [] : [$topicId], null)
            ->with(['type', 'topics']);

        if ($search !== null) {
            // The text lives in the valid version; a correlated EXISTS keeps
            // the item query free of a join that could multiply rows.
            $contentMatches = Version::find()
                ->validAt($today)
                ->andWhere(new Expression("$version.[[item_id]] = $item.[[id]]"))
                ->andWhere(['like', "$version.[[content]]", $search]);

            $query->andWhere([
                'or',
                ['like', "$item.[[title]]", $search],
                ['like', "$item.[[summary]]", $search],
                ['exists', $contentMatches],
            ]);
        }

        $total = (int)(clone $query)->count();
        $items = $query
            ->orderBy(["$item.[[title]]" => SORT_ASC, "$item.[[id]]" => SORT_ASC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        $versions = [];
        if ($items !== []) {
            $versions = Version::find()
                ->validAt($today)
                ->andWhere(["$version.[[item_id]]" => array_map(static fn (Item $i): string => $i->id, $items)])
                ->indexBy('item_id')
                ->all();
        }

        $presenter = $this->presenter();
        $list = [];
        foreach ($items as $found) {
            $valid = $versions[$found->id] ?? null;
            if ($valid !== null) {
                $list[] = $presenter->summary($found, $valid);
            }
        }

        return $this->result([
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'items' => $list,
        ]);
    }
}
