<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\tests\TestCase;

class ItemTest extends TestCase
{
    public function testCreateWithTopics(): void
    {
        $forestry = $this->createTopic(['name' => 'Forestry']);
        $hunting = $this->createTopic(['name' => 'Hunting']);

        $item = $this->createItem(['topicIds' => [$forestry->id, $hunting->id]]);

        $item = Item::findOne($item->id);
        $this->assertEqualsCanonicalizing([$forestry->id, $hunting->id], $item->topicIds);
        $this->assertCount(2, $item->topics);
    }

    public function testTopicIdsSyncAddsAndRemoves(): void
    {
        $a = $this->createTopic();
        $b = $this->createTopic();
        $c = $this->createTopic();
        $item = $this->createItem(['topicIds' => [$a->id, $b->id]]);

        $item->topicIds = [$b->id, $c->id];
        $this->assertTrue($item->save());

        $this->assertEqualsCanonicalizing([$b->id, $c->id], Item::findOne($item->id)->topicIds);
        $this->assertEqualsCanonicalizing([$b->id, $c->id], array_column($item->topics, 'id'));

        $item->topicIds = '';
        $this->assertTrue($item->save());
        $this->assertSame([], Item::findOne($item->id)->topicIds);
    }

    public function testTopicsAreKeptWhenTopicIdsAreNotAssigned(): void
    {
        $topic = $this->createTopic();
        $item = $this->createItem(['topicIds' => [$topic->id]]);

        $item = Item::findOne($item->id);
        $item->title = 'Changed';
        $this->assertTrue($item->save());

        $this->assertSame([$topic->id], Item::findOne($item->id)->topicIds);
    }

    public function testUnknownTopicIsRejected(): void
    {
        $item = new Item([
            'type_id' => $this->createType()->id,
            'title' => 'Item',
            'topicIds' => ['00000000-0000-4000-8000-000000000000'],
        ]);

        $this->assertFalse($item->validate());
        $this->assertTrue($item->hasErrors('topicIds'));
    }

    public function testRequiredFields(): void
    {
        $item = new Item(['title' => '   ']);

        $this->assertFalse($item->validate());
        $this->assertTrue($item->hasErrors('type_id'));
        $this->assertTrue($item->hasErrors('title'));
    }

    public function testUnknownTypeIsRejected(): void
    {
        $item = new Item(['type_id' => '00000000-0000-4000-8000-000000000000', 'title' => 'Item']);

        $this->assertFalse($item->validate());
        $this->assertTrue($item->hasErrors('type_id'));
    }

    public function testDefaults(): void
    {
        $item = $this->createItem();
        $item->refresh();

        $this->assertEquals(0, $item->is_archived);
        $this->assertSame(Item::SOURCE_IMPORT_MANUAL, $item->source_import_mode);
        $this->assertSame('user-1', $item->created_by);
    }

    public function testSourceUrlValidation(): void
    {
        $item = new Item(['type_id' => $this->createType()->id, 'title' => 'Item', 'source_url' => 'not a url']);
        $this->assertFalse($item->validate());
        $this->assertTrue($item->hasErrors('source_url'));

        $item->source_url = 'https://example.com/guideline.pdf';
        $this->assertTrue($item->validate());
    }

    public function testSourceImportModeAndUploadedAtValidation(): void
    {
        $item = new Item([
            'type_id' => $this->createType()->id,
            'title' => 'Item',
            'source_import_mode' => 'magic',
            'source_uploaded_at' => '2026-13-01',
        ]);
        $this->assertFalse($item->validate());
        $this->assertTrue($item->hasErrors('source_import_mode'));
        $this->assertTrue($item->hasErrors('source_uploaded_at'));

        $item->source_import_mode = Item::SOURCE_IMPORT_AUTOMATIC;
        $item->source_uploaded_at = '2026-09-28 10:15:00';
        $this->assertTrue($item->validate());
    }

    public function testFilterByTitle(): void
    {
        $match = $this->createItem(['title' => 'Forest Road Guideline']);
        $this->createItem(['title' => 'Hunting Rules']);

        $ids = Item::find()->withFilters('guideline road')->select('id')->column();
        $this->assertSame([], $ids);

        $ids = Item::find()->withFilters('ROAD')->select('id')->column();
        $this->assertSame([$match->id], $ids);
    }

    public function testFilterByType(): void
    {
        $type = $this->createType();
        $match = $this->createItem(['type_id' => $type->id]);
        $this->createItem();

        $this->assertSame([$match->id], Item::find()->withFilters(null, $type->id)->select('id')->column());
    }

    public function testFilterByTopicsMatchesAnyWithoutDuplicates(): void
    {
        $a = $this->createTopic();
        $b = $this->createTopic();
        $c = $this->createTopic();
        $both = $this->createItem(['topicIds' => [$a->id, $b->id]]);
        $onlyB = $this->createItem(['topicIds' => [$b->id]]);
        $this->createItem(['topicIds' => [$c->id]]);
        $this->createItem();

        $items = Item::find()->withFilters(null, null, [$a->id, $b->id])->all();

        $this->assertCount(2, $items);
        $this->assertEqualsCanonicalizing([$both->id, $onlyB->id], array_column($items, 'id'));
        $this->assertSame(2, (int)Item::find()->withFilters(null, null, [$a->id, $b->id])->count());
    }

    public function testFilterByArchived(): void
    {
        $active = $this->createItem();
        $archived = $this->createItem(['is_archived' => true]);

        $this->assertSame([$active->id], Item::find()->withFilters()->select('id')->column());
        $this->assertSame([$archived->id], Item::find()->withFilters(null, null, [], true)->select('id')->column());
        $this->assertEqualsCanonicalizing(
            [$active->id, $archived->id],
            Item::find()->withFilters(null, null, [], null)->select('id')->column()
        );
    }

    public function testTypeAndTopicItems(): void
    {
        $topic = $this->createTopic();
        $type = $this->createType();
        $item = $this->createItem(['type_id' => $type->id, 'topicIds' => [$topic->id]]);

        $this->assertSame([$item->id], array_column($type->items, 'id'));
        $this->assertSame([$item->id], array_column($topic->items, 'id'));
        $this->assertSame($type->id, $item->type->id);
    }
}
