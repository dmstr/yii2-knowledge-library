<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\Type;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\tests\TestCase;
use m260928_203000_knowledge_library_versions;
use m260928_223000_knowledge_library_history_details;
use ReflectionMethod;
use Yii;
use yii\db\Connection;

class SchemaTest extends TestCase
{
    /**
     * Columns added by m260928_203000_knowledge_library_versions.
     */
    private const VERSIONS_COLUMNS = [
        'version' => ['draft_title', 'draft_summary', 'draft_topic_ids'],
        'file' => ['content_hash'],
    ];

    private const CONTENT_HASH_INDEX = 'idx_knowledge_library_file_content_hash';

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

    public function testVersionsMigrationAddsNullableColumns(): void
    {
        foreach (self::VERSIONS_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $schema = $this->getTableSchema($table)->getColumn($column);
                $this->assertNotNull($schema, "Column $table.$column is missing");
                $this->assertTrue($schema->allowNull, "Column $table.$column must be nullable");
            }
        }

        $this->assertSame(255, $this->getTableSchema('version')->getColumn('draft_title')->size);
        $this->assertSame(64, $this->getTableSchema('file')->getColumn('content_hash')->size);
    }

    public function testVersionsMigrationIndexesContentHash(): void
    {
        $this->assertSame(['content_hash'], $this->getIndexColumns(self::CONTENT_HASH_INDEX));
    }

    public function testNewColumnsStoreValues(): void
    {
        $version = $this->createVersion($this->createItem());
        $file = $this->createFile($version);
        $hash = hash('sha256', 'Content');
        $summary = str_repeat('Summary with Umlauts äöü. ', 3000);

        $db = Yii::$app->db;
        $db->createCommand()->update('{{%knowledge_library_version}}', [
            'draft_title' => 'Draft title',
            'draft_summary' => $summary,
            'draft_topic_ids' => '["a","b"]',
        ], ['id' => $version->id])->execute();
        $db->createCommand()->update('{{%knowledge_library_file}}', [
            'content_hash' => $hash,
        ], ['id' => $file->id])->execute();

        $version->refresh();
        $file->refresh();
        $this->assertSame('Draft title', $version->draft_title);
        $this->assertSame($summary, $version->draft_summary);
        $this->assertSame('["a","b"]', $version->draft_topic_ids);
        $this->assertSame($hash, $file->content_hash);
    }

    public function testVersionsMigrationDownRemovesColumnsAndIndexAndUpRestoresThem(): void
    {
        $version = $this->createVersion($this->createItem(), ['content' => 'Kept']);
        $this->createFile($version);

        $this->runSchemaMigration($this->versionsMigration, 'down');

        foreach (self::VERSIONS_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertNull($this->getTableSchema($table)->getColumn($column), "Column $table.$column still exists");
            }
        }
        $this->assertNull($this->getIndexColumns(self::CONTENT_HASH_INDEX));
        $this->assertSame('Kept', Yii::$app->db->createCommand(
            'SELECT [[content]] FROM {{%knowledge_library_version}} WHERE [[id]] = :id',
            [':id' => $version->id]
        )->queryScalar());
        $this->assertSame('1', (string)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM {{%knowledge_library_file}}'
        )->queryScalar());

        $this->runSchemaMigration($this->versionsMigration, 'up');

        foreach (self::VERSIONS_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertNotNull($this->getTableSchema($table)->getColumn($column), "Column $table.$column is missing");
            }
        }
        $this->assertSame(['content_hash'], $this->getIndexColumns(self::CONTENT_HASH_INDEX));
    }

    /**
     * On MySQL the added text columns declare utf8mb4, so they stay text
     * columns even if the table or server charset is binary.
     */
    public function testVersionsMigrationDeclaresCharsetOnMysql(): void
    {
        $db = new Connection(['dsn' => 'mysql:host=localhost;dbname=test']);
        $migration = new m260928_203000_knowledge_library_versions(['db' => $db, 'compact' => true]);
        $textColumn = new ReflectionMethod($migration, 'textColumn');

        $expected = [
            'CHAR(64)' => 'CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL',
            'VARCHAR(255)' => 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL',
            'LONGTEXT' => 'LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL',
            'TEXT' => 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL',
        ];
        foreach ($expected as $type => $definition) {
            $this->assertSame($definition, (string)$textColumn->invoke($migration, $type));
        }
        $this->assertFalse($db->getIsActive());
    }

    public function testVersionsMigrationDeclaresNoCharsetOnSqlite(): void
    {
        $textColumn = new ReflectionMethod($this->versionsMigration, 'textColumn');

        $this->assertSame('CHAR(64) NULL DEFAULT NULL', (string)$textColumn->invoke($this->versionsMigration, 'CHAR(64)'));
    }

    public function testHistoryDetailsMigrationAddsNullableColumn(): void
    {
        $column = $this->getTableSchema('history')->getColumn('details');

        $this->assertNotNull($column, 'Column history.details is missing');
        $this->assertTrue($column->allowNull);
        $this->assertSame('text', $column->type);
    }

    public function testHistoryDetailsMigrationDownRemovesColumnAndUpRestoresIt(): void
    {
        $item = $this->createItem();
        Yii::$app->db->createCommand()->insert('{{%knowledge_library_history}}', [
            'id' => '00000000-0000-4000-8000-000000000001',
            'item_id' => $item->id,
            'action' => 'created',
            'created_at' => '2026-09-28 12:00:00',
        ])->execute();

        $this->runSchemaMigration($this->historyDetailsMigration, 'down');

        $this->assertNull($this->getTableSchema('history')->getColumn('details'));
        $this->assertSame('1', (string)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM {{%knowledge_library_history}}'
        )->queryScalar());

        $this->runSchemaMigration($this->historyDetailsMigration, 'up');

        $this->assertNotNull($this->getTableSchema('history')->getColumn('details'));
    }

    public function testHistoryDetailsMigrationDeclaresCharsetOnMysql(): void
    {
        $db = new Connection(['dsn' => 'mysql:host=localhost;dbname=test']);
        $migration = new m260928_223000_knowledge_library_history_details(['db' => $db, 'compact' => true]);
        $textColumn = new ReflectionMethod($migration, 'textColumn');

        $this->assertSame(
            'LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL',
            (string)$textColumn->invoke($migration, 'LONGTEXT')
        );
        $this->assertFalse($db->getIsActive());
    }

    /**
     * @return string[]|null columns of the index of the file table, null if
     * the index does not exist
     */
    private function getIndexColumns(string $name): ?array
    {
        foreach (Yii::$app->db->getSchema()->getTableIndexes('{{%knowledge_library_file}}', true) as $index) {
            if ($index->name === $name) {
                return $index->columnNames;
            }
        }

        return null;
    }

    private function getTableSchema(string $table): ?\yii\db\TableSchema
    {
        return Yii::$app->db->getTableSchema('{{%knowledge_library_' . $table . '}}', true);
    }
}
