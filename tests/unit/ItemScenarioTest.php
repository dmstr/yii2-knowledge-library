<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\tests\TestCase;

class ItemScenarioTest extends TestCase
{
    public function testCreateRequiresTitleAndType(): void
    {
        $item = new Item(['scenario' => Item::SCENARIO_CREATE]);
        $item->load(['Item' => ['title' => '  ', 'type_id' => '']]);

        $this->assertFalse($item->validate());
        $this->assertTrue($item->hasErrors('title'));
        $this->assertTrue($item->hasErrors('type_id'));
    }

    public function testCreateAndUpdateOnlyLoadTitleAndType(): void
    {
        $type = $this->createType();
        $item = new Item(['scenario' => Item::SCENARIO_CREATE]);
        $item->load(['Item' => [
            'title' => 'Guideline',
            'type_id' => $type->id,
            'source_name' => 'Ministry',
            'is_archived' => '1',
        ]]);

        $this->assertSame('Guideline', $item->title);
        $this->assertSame($type->id, $item->type_id);
        $this->assertNull($item->source_name);
        $this->assertTrue($item->save());

        $item->scenario = Item::SCENARIO_UPDATE;
        $item->load(['Item' => ['title' => 'Renamed', 'source_url' => 'https://example.com']]);
        $this->assertSame('Renamed', $item->title);
        $this->assertNull($item->source_url);
        $this->assertTrue($item->save());
    }

    public function testSourceRequiresSourceName(): void
    {
        $item = $this->createItem();
        $item->scenario = Item::SCENARIO_SOURCE;
        $item->load(['Item' => ['source_name' => '   ', 'title' => 'Ignored']]);

        $this->assertFalse($item->validate());
        $this->assertTrue($item->hasErrors('source_name'));
        $this->assertNotSame('Ignored', $item->title);

        $item->source_name = 'Ministry';
        $this->assertTrue($item->validate());
    }

    public function testSourceValidatesUrlAndImportMode(): void
    {
        $item = $this->createItem();
        $item->scenario = Item::SCENARIO_SOURCE;
        $item->load(['Item' => [
            'source_name' => 'Ministry',
            'source_url' => 'not a url',
            'source_import_mode' => 'magic',
        ]]);

        $this->assertFalse($item->validate());
        $this->assertTrue($item->hasErrors('source_url'));
        $this->assertTrue($item->hasErrors('source_import_mode'));

        $item->source_url = 'https://example.com/guideline.pdf';
        $item->source_import_mode = Item::SOURCE_IMPORT_AUTOMATIC;
        $this->assertTrue($item->save());
    }

    public function testSourceNameIsOptionalInOtherScenarios(): void
    {
        $type = $this->createType();
        foreach ([Item::SCENARIO_DEFAULT, Item::SCENARIO_CREATE, Item::SCENARIO_UPDATE] as $scenario) {
            $item = new Item(['scenario' => $scenario, 'title' => 'Item', 'type_id' => $type->id]);
            $this->assertTrue($item->validate(), $scenario . ': ' . json_encode($item->getErrors()));
        }
    }

    public function testTypeCanBeChangedWithoutVersions(): void
    {
        $item = $this->createItem();
        $other = $this->createType();

        $this->assertFalse($item->isTypeLocked());
        $item->scenario = Item::SCENARIO_UPDATE;
        $item->load(['Item' => ['type_id' => $other->id]]);
        $this->assertTrue($item->save(), json_encode($item->getErrors()));
        $this->assertSame($other->id, Item::findOne($item->id)->type_id);
    }

    public function testTypeIsLockedOnceVersionsExist(): void
    {
        $item = $this->createItem();
        $originalTypeId = $item->type_id;
        $this->createVersion($item);
        $other = $this->createType();

        $this->assertTrue($item->isTypeLocked());

        // Manipulated POST
        $item->scenario = Item::SCENARIO_UPDATE;
        $item->load(['Item' => ['type_id' => $other->id, 'title' => 'Renamed']]);
        $this->assertFalse($item->save());
        $this->assertSame(
            ['The type cannot be changed once the item has versions.'],
            $item->getErrors('type_id')
        );

        // Direct assignment
        $item = Item::findOne($item->id);
        $item->scenario = Item::SCENARIO_UPDATE;
        $item->type_id = $other->id;
        $this->assertFalse($item->validate());
        $this->assertTrue($item->hasErrors('type_id'));

        $this->assertSame($originalTypeId, Item::findOne($item->id)->type_id);
    }

    public function testLockedItemCanStillBeSavedWithUnchangedType(): void
    {
        $item = $this->createItem();
        $this->createVersion($item);

        $item->scenario = Item::SCENARIO_UPDATE;
        $item->load(['Item' => ['type_id' => $item->type_id, 'title' => 'Renamed']]);
        $this->assertTrue($item->save(), json_encode($item->getErrors()));
    }

    public function testNewItemIsNotTypeLocked(): void
    {
        $this->assertFalse((new Item())->isTypeLocked());
    }
}
