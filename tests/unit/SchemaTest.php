<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\Type;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\tests\TestCase;
use Yii;

class SchemaTest extends TestCase
{
    private const TABLES = [
        'type',
        'topic',
        'item',
        'item_topic',
        'version',
        'file',
        'relation',
        'history',
    ];

    public function testAllTablesExistAfterMigration(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertNotNull($this->getTableSchema($table), "Table $table is missing");
        }
    }

    public function testDownRemovesAllTables(): void
    {
        $this->runMigration('down');

        foreach (self::TABLES as $table) {
            $this->assertNull($this->getTableSchema($table), "Table $table still exists");
        }
    }

    public function testHistoryHasOnlyCreatedAt(): void
    {
        $schema = $this->getTableSchema('history');

        $this->assertNotNull($schema->getColumn('created_at'));
        $this->assertNull($schema->getColumn('updated_at'));
        $this->assertNull($schema->getColumn('created_by'));
    }

    public function testItemTopicHasCompositePrimaryKey(): void
    {
        $this->assertSame(['item_id', 'topic_id'], $this->getTableSchema('item_topic')->primaryKey);
    }

    public function testUuidPrimaryKeyIsGeneratedOnInsert(): void
    {
        $type = $this->createType();

        $this->assertIsString($type->id);
        $this->assertSame(36, strlen($type->id));
        $this->assertNotNull(Type::findOne($type->id));
    }

    public function testBlameableColumnsAreFilledFromUserProvider(): void
    {
        $type = $this->createType();
        $this->assertSame('user-1', $type->created_by);
        $this->assertSame('user-1', $type->updated_by);

        DummyUserProvider::$currentReference = 'user-2';
        $type->name = 'Renamed';
        $this->assertTrue($type->save());

        $type->refresh();
        $this->assertSame('user-1', $type->created_by);
        $this->assertSame('user-2', $type->updated_by);
    }

    public function testTimestampsAreFilled(): void
    {
        $type = $this->createType();
        $type->refresh();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $type->created_at);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $type->updated_at);
    }

    private function getTableSchema(string $table): ?\yii\db\TableSchema
    {
        return Yii::$app->db->getTableSchema('{{%knowledge_library_' . $table . '}}', true);
    }
}
