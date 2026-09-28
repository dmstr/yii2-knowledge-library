<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\Topic;
use dmstr\knowledgeLibrary\models\Type;
use dmstr\knowledgeLibrary\tests\TestCase;
use Yii;

/**
 * Tests of `TypeQuery::withItemCount()` and `TopicQuery::withItemCount()`.
 */
class ItemCountQueryTest extends TestCase
{
    protected function setUp(): void
    {
        Yii::setLogger(null);
        Yii::getLogger()->flushInterval = 0;

        parent::setUp();
    }

    public function testTypeCountIncludesArchivedItems(): void
    {
        $law = $this->createType(['name' => 'Law']);
        $this->insertItem($law->id);
        $this->archive($this->insertItem($law->id));
        $other = $this->createType(['name' => 'Other']);
        $this->insertItem($other->id);
        $this->createType(['name' => 'Empty']);

        $counts = [];
        foreach (Type::find()->orderedByName()->withItemCount()->all() as $type) {
            $counts[$type->name] = $type->itemCount;
        }

        $this->assertSame(['Empty' => 0, 'Law' => 2, 'Other' => 1], $counts);
    }

    public function testTopicCountIncludesArchivedItems(): void
    {
        $type = $this->createType();
        $forestry = $this->createTopic(['name' => 'Forestry']);
        $hunting = $this->createTopic(['name' => 'Hunting']);
        $this->createTopic(['name' => 'Empty']);
        $active = $this->insertItem($type->id);
        $archived = $this->insertItem($type->id);
        $this->archive($archived);
        $this->assignTopic($active, $forestry->id);
        $this->assignTopic($archived, $forestry->id);
        $this->assignTopic($archived, $hunting->id);

        $counts = [];
        foreach (Topic::find()->orderedByName()->withItemCount()->all() as $topic) {
            $counts[$topic->name] = $topic->itemCount;
        }

        $this->assertSame(['Empty' => 0, 'Forestry' => 2, 'Hunting' => 1], $counts);
    }

    public function testRecordsKeepAllAttributes(): void
    {
        $type = $this->createType(['name' => 'Law', 'has_validity_period' => false, 'requires_review' => true]);
        $topic = $this->createTopic(['name' => 'Forestry']);

        $loadedType = Type::find()->withItemCount()->andWhere(['id' => $type->id])->one();
        $loadedTopic = Topic::find()->withItemCount()->andWhere(['id' => $topic->id])->one();

        $this->assertSame($type->id, $loadedType->id);
        $this->assertSame('Law', $loadedType->name);
        $this->assertFalse((bool)$loadedType->has_validity_period);
        $this->assertTrue((bool)$loadedType->requires_review);
        $this->assertSame(0, $loadedType->itemCount);
        $this->assertSame($topic->id, $loadedTopic->id);
        $this->assertSame('Forestry', $loadedTopic->name);
        $this->assertSame(0, $loadedTopic->itemCount);
    }

    public function testCountIsNullWithoutWithItemCount(): void
    {
        $type = $this->createType();
        $this->insertItem($type->id);
        $topic = $this->createTopic();

        $this->assertNull(Type::findOne($type->id)->itemCount);
        $this->assertNull(Topic::findOne($topic->id)->itemCount);
    }

    public function testWorksWithTableAlias(): void
    {
        $type = $this->createType(['name' => 'Law']);
        $this->insertItem($type->id);
        $topic = $this->createTopic(['name' => 'Forestry']);
        $this->assignTopic($this->insertItem($type->id), $topic->id);

        $loadedType = Type::find()->alias('t')->withItemCount()->andWhere(['t.id' => $type->id])->one();
        $loadedTopic = Topic::find()->alias('t')->withItemCount()->andWhere(['t.id' => $topic->id])->one();

        $this->assertSame(2, $loadedType->itemCount);
        $this->assertSame(1, $loadedTopic->itemCount);
    }

    public function testCountWorksWithDataProviderTotalCount(): void
    {
        $this->createType();
        $this->createType();

        $this->assertSame(2, (int)Type::find()->withItemCount()->count());
        $this->assertSame(0, (int)Topic::find()->withItemCount()->count());
    }

    public function testListNeedsASingleQuery(): void
    {
        $type = $this->createType();
        for ($i = 0; $i < 5; $i++) {
            $item = $this->insertItem($this->createType()->id);
            $this->assignTopic($item, $this->createTopic()->id);
        }
        $this->insertItem($type->id);
        // Load the table schemas before counting.
        Type::find()->withItemCount()->all();
        Topic::find()->withItemCount()->all();

        $before = $this->countQueries();
        $types = Type::find()->orderedByName()->withItemCount()->all();
        $typeCounts = array_map(static fn (Type $type) => $type->itemCount, $types);
        $this->assertSame(1, $this->countQueries() - $before);

        $before = $this->countQueries();
        $topics = Topic::find()->orderedByName()->withItemCount()->all();
        $topicCounts = array_map(static fn (Topic $topic) => $topic->itemCount, $topics);
        $this->assertSame(1, $this->countQueries() - $before);

        $this->assertSame([1, 1, 1, 1, 1, 1], $typeCounts);
        $this->assertSame([1, 1, 1, 1, 1], $topicCounts);
    }

    private function archive(string $itemId): void
    {
        Yii::$app->db->createCommand()
            ->update('{{%knowledge_library_item}}', ['is_archived' => 1], ['id' => $itemId])
            ->execute();
    }

    private function assignTopic(string $itemId, string $topicId): void
    {
        Yii::$app->db->createCommand()->insert('{{%knowledge_library_item_topic}}', [
            'item_id' => $itemId,
            'topic_id' => $topicId,
        ])->execute();
    }

    private function countQueries(): int
    {
        return count(Yii::getLogger()->getProfiling(['yii\db\Command::query']));
    }
}
