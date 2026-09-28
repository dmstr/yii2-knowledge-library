<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\Topic;
use dmstr\knowledgeLibrary\models\Type;
use dmstr\knowledgeLibrary\tests\TestCase;
use Yii;
use yii\db\IntegrityException;

class TypeTopicTest extends TestCase
{
    public function testTypeNameIsRequired(): void
    {
        $type = new Type(['name' => '   ']);

        $this->assertFalse($type->validate());
        $this->assertTrue($type->hasErrors('name'));
    }

    public function testTypeNameIsUnique(): void
    {
        $this->createType(['name' => 'Guideline']);
        $type = new Type(['name' => 'Guideline']);

        $this->assertFalse($type->validate());
        $this->assertTrue($type->hasErrors('name'));
    }

    public function testTypeBooleansDefaultToTrue(): void
    {
        $type = new Type(['name' => 'Instruction']);

        $this->assertTrue($type->save());
        $type->refresh();
        $this->assertEquals(1, $type->has_validity_period);
        $this->assertEquals(1, $type->requires_review);
    }

    public function testTopicNameIsRequiredAndUnique(): void
    {
        $this->assertFalse((new Topic())->validate());

        $this->createTopic(['name' => 'Forestry']);
        $topic = new Topic(['name' => 'Forestry']);
        $this->assertFalse($topic->validate());
        $this->assertTrue($topic->hasErrors('name'));
    }

    public function testOrderedByName(): void
    {
        $this->createType(['name' => 'B']);
        $this->createType(['name' => 'A']);
        $this->createTopic(['name' => 'Y']);
        $this->createTopic(['name' => 'X']);

        $this->assertSame(['A', 'B'], Type::find()->orderedByName()->select('name')->column());
        $this->assertSame(['X', 'Y'], Topic::find()->orderedByName()->select('name')->column());
    }

    public function testUnusedTypeCanBeDeleted(): void
    {
        $type = $this->createType();

        $this->assertFalse($type->isInUse());
        $this->assertSame(1, $type->delete());
        $this->assertNull(Type::findOne($type->id));
    }

    public function testUnusedTopicCanBeDeleted(): void
    {
        $topic = $this->createTopic();

        $this->assertFalse($topic->isInUse());
        $this->assertSame(1, $topic->delete());
        $this->assertNull(Topic::findOne($topic->id));
    }

    public function testTypeInUseCannotBeDeleted(): void
    {
        $type = $this->createType();
        $this->insertItem($type->id);

        $this->assertTrue($type->isInUse());
        $this->assertFalse($type->delete());
        $this->assertTrue($type->hasErrors('name'));
        $this->assertNotNull(Type::findOne($type->id));
    }

    public function testTopicInUseCannotBeDeleted(): void
    {
        $topic = $this->createTopic();
        $itemId = $this->insertItem($this->createType()->id);
        Yii::$app->db->createCommand()->insert('{{%knowledge_library_item_topic}}', [
            'item_id' => $itemId,
            'topic_id' => $topic->id,
        ])->execute();

        $this->assertTrue($topic->isInUse());
        $this->assertFalse($topic->delete());
        $this->assertTrue($topic->hasErrors('name'));
        $this->assertNotNull(Topic::findOne($topic->id));
    }

    public function testItemWithMissingTypeIsRejectedByForeignKey(): void
    {
        $this->expectException(IntegrityException::class);

        $this->insertItem('00000000-0000-4000-8000-000000000000');
    }

    public function testDeletingItemCascadesToItemTopic(): void
    {
        $topic = $this->createTopic();
        $itemId = $this->insertItem($this->createType()->id);
        $db = Yii::$app->db;
        $db->createCommand()->insert('{{%knowledge_library_item_topic}}', [
            'item_id' => $itemId,
            'topic_id' => $topic->id,
        ])->execute();

        $db->createCommand()->delete('{{%knowledge_library_item}}', ['id' => $itemId])->execute();

        $this->assertFalse($topic->isInUse());
    }
}
