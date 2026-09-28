<?php

use yii\db\ColumnSchemaBuilder;
use yii\db\Migration;

/**
 * Database schema of the knowledge library.
 *
 * Runs on MySQL/MariaDB and SQLite. SQLite cannot add foreign keys to existing
 * tables, so foreign keys are declared inline there and added afterwards on
 * all other drivers, see createTableWithForeignKeys().
 */
class m260928_100100_knowledge_library_schema extends Migration
{
    private const TABLE_TYPE = 'type';
    private const TABLE_TOPIC = 'topic';
    private const TABLE_ITEM = 'item';
    private const TABLE_ITEM_TOPIC = 'item_topic';
    private const TABLE_VERSION = 'version';
    private const TABLE_FILE = 'file';
    private const TABLE_RELATION = 'relation';
    private const TABLE_HISTORY = 'history';

    /**
     * Tables in creation order; they are dropped in reverse order.
     */
    private const TABLES = [
        self::TABLE_TYPE,
        self::TABLE_TOPIC,
        self::TABLE_ITEM,
        self::TABLE_ITEM_TOPIC,
        self::TABLE_VERSION,
        self::TABLE_FILE,
        self::TABLE_RELATION,
        self::TABLE_HISTORY,
    ];

    public function safeUp()
    {
        $this->createTableWithForeignKeys(self::TABLE_TYPE, $this->withDefaultColumns([
            'name' => $this->string(255)->notNull(),
            'has_validity_period' => $this->boolean()->notNull()->defaultValue(1),
            'requires_review' => $this->boolean()->notNull()->defaultValue(1),
        ]));
        $this->createIndexFor(self::TABLE_TYPE, 'name', true);

        $this->createTableWithForeignKeys(self::TABLE_TOPIC, $this->withDefaultColumns([
            'name' => $this->string(255)->notNull(),
        ]));
        $this->createIndexFor(self::TABLE_TOPIC, 'name', true);

        $this->createTableWithForeignKeys(self::TABLE_ITEM, $this->withDefaultColumns([
            'type_id' => $this->char(36)->notNull(),
            'title' => $this->string(255)->notNull(),
            'summary' => $this->longText(),
            'is_archived' => $this->boolean()->notNull()->defaultValue(0),
            'source_name' => $this->string(255)->null(),
            'source_reference' => $this->string(255)->null(),
            'source_url' => $this->string(2048)->null(),
            'source_import_mode' => $this->string(32)->notNull()->defaultValue('manual'),
            'source_uploaded_at' => $this->dateTime()->null(),
            'source_uploaded_by' => $this->string(64)->null(),
        ]), [
            'type_id' => [self::TABLE_TYPE, 'RESTRICT'],
        ]);
        $this->createIndexFor(self::TABLE_ITEM, 'type_id');
        $this->createIndexFor(self::TABLE_ITEM, 'is_archived');

        $this->createTableWithForeignKeys(self::TABLE_ITEM_TOPIC, [
            'item_id' => $this->char(36)->notNull(),
            'topic_id' => $this->char(36)->notNull(),
            'PRIMARY KEY ([[item_id]], [[topic_id]])',
        ], [
            'item_id' => [self::TABLE_ITEM, 'CASCADE'],
            'topic_id' => [self::TABLE_TOPIC, 'RESTRICT'],
        ]);
        $this->createIndexFor(self::TABLE_ITEM_TOPIC, 'topic_id');

        $this->createTableWithForeignKeys(self::TABLE_VERSION, $this->withDefaultColumns([
            'item_id' => $this->char(36)->notNull(),
            'number' => $this->integer()->notNull(),
            'status' => $this->string(32)->notNull()->defaultValue('draft'),
            'valid_from' => $this->date()->null(),
            'valid_until' => $this->date()->null(),
            'content' => $this->longText(),
            'reviewer_id' => $this->string(64)->null(),
            'review_message' => $this->longText(),
            'review_requested_by' => $this->string(64)->null(),
            'review_requested_at' => $this->dateTime()->null(),
            'return_note' => $this->longText(),
            'returned_by' => $this->string(64)->null(),
            'returned_at' => $this->dateTime()->null(),
            'published_by' => $this->string(64)->null(),
            'published_at' => $this->dateTime()->null(),
            'withdrawn_by' => $this->string(64)->null(),
            'withdrawn_at' => $this->dateTime()->null(),
            'withdraw_reason' => $this->longText(),
            'corrects_version_id' => $this->char(36)->null(),
        ]), [
            'item_id' => [self::TABLE_ITEM, 'CASCADE'],
            'corrects_version_id' => [self::TABLE_VERSION, 'SET NULL'],
        ]);
        $this->createIndexFor(self::TABLE_VERSION, ['item_id', 'number'], true);
        $this->createIndexFor(self::TABLE_VERSION, ['item_id', 'status']);

        $this->createTableWithForeignKeys(self::TABLE_FILE, $this->withDefaultColumns([
            'version_id' => $this->char(36)->notNull(),
            'kind' => $this->string(32)->notNull()->defaultValue('attachment'),
            'title' => $this->string(255)->null(),
            'storage_id' => $this->string(255)->notNull(),
            'storage_item_id' => $this->string(36)->null(),
            'path' => $this->string(1024)->notNull(),
            'name' => $this->string(255)->notNull(),
            'mime_type' => $this->string(255)->null(),
            'size' => $this->bigInteger()->null(),
            'position' => $this->integer()->notNull()->defaultValue(0),
        ]), [
            'version_id' => [self::TABLE_VERSION, 'CASCADE'],
        ]);
        $this->createIndexFor(self::TABLE_FILE, ['version_id', 'kind']);

        $this->createTableWithForeignKeys(self::TABLE_RELATION, $this->withDefaultColumns([
            'source_item_id' => $this->char(36)->notNull(),
            'target_item_id' => $this->char(36)->notNull(),
            'type' => $this->string(32)->notNull(),
        ]), [
            'source_item_id' => [self::TABLE_ITEM, 'CASCADE'],
            'target_item_id' => [self::TABLE_ITEM, 'CASCADE'],
        ]);
        $this->createIndexFor(self::TABLE_RELATION, ['source_item_id', 'target_item_id', 'type'], true);
        $this->createIndexFor(self::TABLE_RELATION, 'target_item_id');

        $this->createTableWithForeignKeys(self::TABLE_HISTORY, [
            'id' => $this->char(36)->notNull(),
            'item_id' => $this->char(36)->notNull(),
            'version_id' => $this->char(36)->null(),
            'action' => $this->string(64)->notNull(),
            'reason' => $this->longText(),
            'actor_id' => $this->string(64)->null(),
            'created_at' => $this->dateTime()->notNull(),
            'PRIMARY KEY ([[id]])',
        ], [
            'item_id' => [self::TABLE_ITEM, 'CASCADE'],
            'version_id' => [self::TABLE_VERSION, 'SET NULL'],
        ]);
        $this->createIndexFor(self::TABLE_HISTORY, ['item_id', 'created_at']);
    }

    public function safeDown()
    {
        foreach (array_reverse(self::TABLES) as $table) {
            $this->dropTable($this->tableName($table));
        }
    }

    private function tableName(string $table): string
    {
        return '{{%knowledge_library_' . $table . '}}';
    }

    /**
     * Adds the UUID primary key and the timestamp and blameable columns.
     */
    private function withDefaultColumns(array $columns): array
    {
        return array_merge(
            ['id' => $this->char(36)->notNull()],
            $columns,
            [
                'created_at' => $this->dateTime()->null(),
                'updated_at' => $this->dateTime()->null(),
                'created_by' => $this->string(64)->null(),
                'updated_by' => $this->string(64)->null(),
                'PRIMARY KEY ([[id]])',
            ]
        );
    }

    private function longText(): ColumnSchemaBuilder
    {
        return $this->db->getSchema()->createColumnSchemaBuilder('LONGTEXT')->null();
    }

    /**
     * Creates the table including its foreign keys.
     *
     * @param array<string, array{0: string, 1: string}> $foreignKeys map
     * `column => [referenced table, ON DELETE action]`, always referencing `id`
     */
    private function createTableWithForeignKeys(string $table, array $columns, array $foreignKeys = []): void
    {
        $isSqlite = $this->db->driverName === 'sqlite';
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        if ($isSqlite) {
            foreach ($foreignKeys as $column => [$refTable, $onDelete]) {
                $columns[] = sprintf(
                    'CONSTRAINT %s FOREIGN KEY ([[%s]]) REFERENCES %s ([[id]]) ON DELETE %s',
                    $this->db->quoteColumnName($this->constraintName('fk', $table, $column)),
                    $column,
                    $this->tableName($refTable),
                    $onDelete
                );
            }
        }

        $this->createTable($this->tableName($table), $columns, $tableOptions);

        if (!$isSqlite) {
            foreach ($foreignKeys as $column => [$refTable, $onDelete]) {
                $this->addForeignKey(
                    $this->constraintName('fk', $table, $column),
                    $this->tableName($table),
                    $column,
                    $this->tableName($refTable),
                    'id',
                    $onDelete
                );
            }
        }
    }

    /**
     * @param string|string[] $columns
     */
    private function createIndexFor(string $table, $columns, bool $unique = false): void
    {
        $this->createIndex(
            $this->constraintName($unique ? 'uq' : 'idx', $table, $columns),
            $this->tableName($table),
            $columns,
            $unique
        );
    }

    /**
     * Builds a constraint name without table prefix, e.g.
     * `fk_knowledge_library_item_type_id`.
     *
     * @param string|string[] $columns
     */
    private function constraintName(string $kind, string $table, $columns): string
    {
        return $kind . '_knowledge_library_' . $table . '_' . implode('_', (array)$columns);
    }
}
