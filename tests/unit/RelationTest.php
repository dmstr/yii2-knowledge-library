<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Relation;
use dmstr\knowledgeLibrary\tests\TestCase;
use InvalidArgumentException;

class RelationTest extends TestCase
{
    private function createRelation(Item $source, Item $target, string $type = Relation::TYPE_SUPPLEMENTS): Relation
    {
        $relation = new Relation([
            'source_item_id' => $source->id,
            'target_item_id' => $target->id,
            'type' => $type,
        ]);
        $this->assertTrue($relation->save(), json_encode($relation->getErrors()));

        return $relation;
    }

    public function testCreate(): void
    {
        $source = $this->createItem();
        $target = $this->createItem();

        $relation = $this->createRelation($source, $target);

        $relation = Relation::findOne($relation->id);
        $this->assertSame($source->id, $relation->sourceItem->id);
        $this->assertSame($target->id, $relation->targetItem->id);
        $this->assertSame('user-1', $relation->created_by);
    }

    public function testRequiredFieldsAndType(): void
    {
        $relation = new Relation();
        $this->assertFalse($relation->validate());
        $this->assertTrue($relation->hasErrors('source_item_id'));
        $this->assertTrue($relation->hasErrors('target_item_id'));
        $this->assertTrue($relation->hasErrors('type'));

        $relation->setAttributes([
            'source_item_id' => $this->createItem()->id,
            'target_item_id' => '00000000-0000-4000-8000-000000000000',
            'type' => 'unknown',
        ]);
        $this->assertFalse($relation->validate());
        $this->assertTrue($relation->hasErrors('target_item_id'));
        $this->assertTrue($relation->hasErrors('type'));
    }

    public function testItemCannotBeRelatedToItself(): void
    {
        $item = $this->createItem();
        $relation = new Relation([
            'source_item_id' => $item->id,
            'target_item_id' => $item->id,
            'type' => Relation::TYPE_BASED_ON,
        ]);

        $this->assertFalse($relation->validate());
        $this->assertTrue($relation->hasErrors('target_item_id'));
    }

    public function testDuplicateIsRejected(): void
    {
        $source = $this->createItem();
        $target = $this->createItem();
        $this->createRelation($source, $target, Relation::TYPE_BASED_ON);

        $duplicate = new Relation([
            'source_item_id' => $source->id,
            'target_item_id' => $target->id,
            'type' => Relation::TYPE_BASED_ON,
        ]);
        $this->assertFalse($duplicate->validate());
        $this->assertTrue($duplicate->hasErrors('target_item_id'));

        // Another type between the same items is fine.
        $duplicate->type = Relation::TYPE_SUPPLEMENTS;
        $this->assertTrue($duplicate->validate());
    }

    public function testLabelsFromBothPerspectives(): void
    {
        $source = $this->createItem();
        $target = $this->createItem();
        $expected = [
            Relation::TYPE_BASED_ON => ['is based on', 'is the basis for'],
            Relation::TYPE_SUPPLEMENTS => ['supplements', 'is supplemented by'],
            Relation::TYPE_REPLACES => ['replaces', 'is replaced by'],
        ];

        foreach ($expected as $type => [$forward, $inverse]) {
            $relation = $this->createRelation($source, $target, $type);

            $this->assertSame($forward, $relation->getLabelFor($source->id));
            $this->assertSame($inverse, $relation->getLabelFor($target->id));
            $this->assertSame($target->id, $relation->getOtherItemId($source->id));
            $this->assertSame($source->id, $relation->getOtherItemId($target->id));
        }
    }

    public function testGermanLabels(): void
    {
        \Yii::$app->language = 'de';

        $this->assertSame(
            ['forward' => 'ergänzt', 'inverse' => 'wird ergänzt durch'],
            Relation::labels()[Relation::TYPE_SUPPLEMENTS]
        );
    }

    public function testUnrelatedItemIsRejected(): void
    {
        $relation = $this->createRelation($this->createItem(), $this->createItem());

        $this->expectException(InvalidArgumentException::class);
        $relation->getLabelFor($this->createItem()->id);
    }

    public function testOutgoingAndIncomingRelations(): void
    {
        $a = $this->createItem();
        $b = $this->createItem();
        $c = $this->createItem();
        $ab = $this->createRelation($a, $b);
        $cb = $this->createRelation($c, $b, Relation::TYPE_REPLACES);

        $this->assertSame([$ab->id], array_column($a->outgoingRelations, 'id'));
        $this->assertSame([], $a->incomingRelations);
        $this->assertEqualsCanonicalizing([$ab->id, $cb->id], array_column($b->incomingRelations, 'id'));
        $this->assertSame([], $b->outgoingRelations);
    }

    public function testRelationsAreDeletedWithTheItem(): void
    {
        $a = $this->createItem();
        $b = $this->createItem();
        $c = $this->createItem();
        $ab = $this->createRelation($a, $b);
        $ca = $this->createRelation($c, $a);
        $cb = $this->createRelation($c, $b);

        $this->assertSame(1, $a->delete());

        $this->assertNull(Relation::findOne($ab->id));
        $this->assertNull(Relation::findOne($ca->id));
        $this->assertNotNull(Relation::findOne($cb->id));
    }
}
