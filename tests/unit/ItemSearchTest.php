<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\search\ItemSearch;
use dmstr\knowledgeLibrary\tests\TestCase;
use Yii;

class ItemSearchTest extends TestCase
{
    /**
     * @return string[] IDs of the items found, in order
     */
    private function searchIds(array $filters = [], array $params = []): array
    {
        $search = new ItemSearch();
        $provider = $search->search(array_merge($params, ['ItemSearch' => $filters]));

        return array_map(static fn (Item $item) => $item->id, $provider->getModels());
    }

    private function setUpdatedAt(string $table, string $id, string $updatedAt): void
    {
        Yii::$app->db->createCommand()
            ->update($table, ['updated_at' => $updatedAt], ['id' => $id])
            ->execute();
    }

    public function testDefaultShowsOnlyActiveItems(): void
    {
        $active = $this->createItem();
        $archived = $this->createItem(['is_archived' => true]);

        $this->assertSame([$active->id], $this->searchIds());
        $this->assertSame([$archived->id], $this->searchIds(['archived' => ItemSearch::ARCHIVED_ARCHIVED]));

        $all = $this->searchIds(['archived' => ItemSearch::ARCHIVED_ALL]);
        sort($all);
        $expected = [$active->id, $archived->id];
        sort($expected);
        $this->assertSame($expected, $all);
    }

    public function testInvalidFiltersDoNotThrow(): void
    {
        $active = $this->createItem();
        $this->createItem(['is_archived' => true]);

        $search = new ItemSearch();
        $provider = $search->search(['ItemSearch' => [
            'archived' => 'everything',
            'title' => ['array'],
            'topicIds' => [['nested']],
            'type_id' => ['array'],
        ]]);

        $this->assertSame([$active->id], array_map(static fn (Item $item) => $item->id, $provider->getModels()));
        $this->assertSame(ItemSearch::ARCHIVED_ACTIVE, $search->archived);
        $this->assertFalse($search->isFiltered());
    }

    public function testTitleWildcardsMatchLiterally(): void
    {
        $percent = $this->createItem(['title' => '100% Forest']);
        $underscore = $this->createItem(['title' => 'forest_road']);
        $this->createItem(['title' => '1000 Forest']);
        $this->createItem(['title' => 'forest-road']);

        $this->assertSame([$percent->id], $this->searchIds(['title' => '%']));
        $this->assertSame([$underscore->id], $this->searchIds(['title' => '_']));
        $this->assertSame([$underscore->id], $this->searchIds(['title' => ' t_r ']));
    }

    public function testTypeFilter(): void
    {
        $type = $this->createType();
        $match = $this->createItem(['type_id' => $type->id]);
        $this->createItem();

        $this->assertSame([$match->id], $this->searchIds(['type_id' => $type->id]));
    }

    public function testTopicFilterReturnsItemWithBothTopicsOnce(): void
    {
        $a = $this->createTopic();
        $b = $this->createTopic();
        $both = $this->createItem(['topicIds' => [$a->id, $b->id]]);
        $this->createItem();

        $search = new ItemSearch();
        $provider = $search->search(['ItemSearch' => ['topicIds' => [$a->id, $b->id]]]);

        $this->assertSame([$both->id], array_map(static fn (Item $item) => $item->id, $provider->getModels()));
        $this->assertSame(1, $provider->getTotalCount());
        $this->assertCount(2, $provider->getModels()[0]->topics);
        $this->assertTrue($provider->getModels()[0]->isRelationPopulated('type'));
    }

    public function testLinksFromTypeAndTopicPagesLoad(): void
    {
        $type = $this->createType();
        $topic = $this->createTopic();
        $archived = $this->createItem(['type_id' => $type->id, 'topicIds' => [$topic->id], 'is_archived' => true]);

        $search = new ItemSearch();
        $search->search(['ItemSearch' => ['type_id' => $type->id, 'archived' => 'all']]);
        $this->assertSame($type->id, $search->type_id);
        $this->assertSame(ItemSearch::ARCHIVED_ALL, $search->archived);

        $this->assertSame([$archived->id], $this->searchIds(['type_id' => $type->id, 'archived' => 'all']));
        $this->assertSame([$archived->id], $this->searchIds(['topicIds' => [$topic->id], 'archived' => 'all']));
    }

    public function testDefaultOrderIsLastChangeIncludingVersions(): void
    {
        $older = $this->createItem(['title' => 'A']);
        $newer = $this->createItem(['title' => 'B']);
        $withoutVersion = $this->createItem(['title' => 'C']);
        $version = $this->createVersion($older);

        $item = Item::tableName();
        $this->setUpdatedAt($item, $older->id, '2026-01-01 10:00:00');
        $this->setUpdatedAt($item, $newer->id, '2026-03-01 10:00:00');
        $this->setUpdatedAt($item, $withoutVersion->id, '2026-02-01 10:00:00');
        // The version of the oldest item was changed most recently.
        $this->setUpdatedAt('{{%knowledge_library_version}}', $version->id, '2026-04-01 10:00:00');

        $this->assertSame([$older->id, $newer->id, $withoutVersion->id], $this->searchIds());
        $this->assertSame(
            [$withoutVersion->id, $newer->id, $older->id],
            $this->searchIds([], ['sort' => 'lastChange'])
        );

        $models = (new ItemSearch())->search([])->getModels();
        $this->assertSame('2026-04-01 10:00:00', $models[0]->lastChange);
        $this->assertSame('2026-02-01 10:00:00', $models[2]->lastChange);

        // An older version does not win over a newer item.
        $this->setUpdatedAt('{{%knowledge_library_version}}', $version->id, '2025-12-01 10:00:00');
        $this->assertSame([$newer->id, $withoutVersion->id, $older->id], $this->searchIds());
        $this->assertSame('2026-01-01 10:00:00', (new ItemSearch())->search([])->getModels()[2]->lastChange);
    }

    public function testSortByTitle(): void
    {
        $b = $this->createItem(['title' => 'Beta']);
        $a = $this->createItem(['title' => 'Alpha']);

        $this->assertSame([$a->id, $b->id], $this->searchIds([], ['sort' => 'title']));
        $this->assertSame([$b->id, $a->id], $this->searchIds([], ['sort' => '-title']));
    }

    public function testPaginationWithTwentyPerPage(): void
    {
        $type = $this->createType();
        for ($i = 0; $i < 21; $i++) {
            $this->createItem(['type_id' => $type->id]);
        }

        $provider = (new ItemSearch())->search([]);
        $this->assertSame(20, $provider->getCount());
        $this->assertSame(21, $provider->getTotalCount());
        $this->assertSame(2, $provider->getPagination()->getPageCount());
    }

    public function testIsFiltered(): void
    {
        $topic = $this->createTopic();
        $search = new ItemSearch();
        $search->search([]);
        $this->assertFalse($search->isFiltered());

        foreach ([
            ['title' => 'x'],
            ['type_id' => 'some-type'],
            ['topicIds' => [$topic->id]],
            ['archived' => ItemSearch::ARCHIVED_ALL],
            ['archived' => ItemSearch::ARCHIVED_ARCHIVED],
        ] as $filters) {
            $search = new ItemSearch();
            $search->search(['ItemSearch' => $filters]);
            $this->assertTrue($search->isFiltered(), json_encode($filters));
        }

        $search = new ItemSearch();
        $search->search(['ItemSearch' => ['title' => '  ', 'type_id' => '', 'topicIds' => '', 'archived' => 'active']]);
        $this->assertFalse($search->isFiltered());
    }

    public function testArchivedOptions(): void
    {
        $this->assertSame(
            [ItemSearch::ARCHIVED_ACTIVE, ItemSearch::ARCHIVED_ARCHIVED, ItemSearch::ARCHIVED_ALL],
            array_keys(ItemSearch::archivedOptions())
        );
        $this->assertSame('ItemSearch', (new ItemSearch())->formName());
    }
}
