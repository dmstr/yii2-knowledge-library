<?php

use yii\db\ColumnSchemaBuilder;
use yii\db\Migration;

/**
 * Draft details of versions and content hashes of files.
 *
 * - `version.draft_title`, `version.draft_summary` and
 *   `version.draft_topic_ids` (JSON list of topic IDs) hold the item details
 *   edited in a draft; publishing the version applies them to the item.
 * - `file.content_hash` holds the SHA-256 hex digest of the stored content,
 *   used to find the same file attached to other items.
 *
 * Runs on MySQL/MariaDB and SQLite (3.35 or newer for `safeDown()`). On
 * MySQL added columns inherit the charset of the table, not the table options
 * of the schema migration; the text columns declare utf8mb4 explicitly so
 * they never become binary columns, e.g. on a server with charset `binary`.
 */
class m260928_203000_knowledge_library_versions extends Migration
{
    private const TABLE_VERSION = 'version';
    private const TABLE_FILE = 'file';

    private const VERSION_COLUMNS = ['draft_title', 'draft_summary', 'draft_topic_ids'];

    public function safeUp()
    {
        $this->addColumn($this->tableName(self::TABLE_VERSION), 'draft_title', $this->textColumn('VARCHAR(255)'));
        $this->addColumn($this->tableName(self::TABLE_VERSION), 'draft_summary', $this->textColumn('LONGTEXT'));
        $this->addColumn($this->tableName(self::TABLE_VERSION), 'draft_topic_ids', $this->textColumn('TEXT'));

        $this->addColumn($this->tableName(self::TABLE_FILE), 'content_hash', $this->textColumn('CHAR(64)'));
        $this->createIndex(
            $this->constraintName('idx', self::TABLE_FILE, 'content_hash'),
            $this->tableName(self::TABLE_FILE),
            'content_hash'
        );
    }

    public function safeDown()
    {
        $this->dropIndex(
            $this->constraintName('idx', self::TABLE_FILE, 'content_hash'),
            $this->tableName(self::TABLE_FILE)
        );
        $this->dropColumnFrom(self::TABLE_FILE, 'content_hash');

        foreach (array_reverse(self::VERSION_COLUMNS) as $column) {
            $this->dropColumnFrom(self::TABLE_VERSION, $column);
        }
    }

    private function tableName(string $table): string
    {
        return '{{%knowledge_library_' . $table . '}}';
    }

    /**
     * Nullable text column of the type, with charset utf8mb4 on MySQL.
     */
    private function textColumn(string $type): ColumnSchemaBuilder
    {
        if ($this->db->driverName === 'mysql') {
            $type .= ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        return $this->db->getSchema()->createColumnSchemaBuilder($type)->null();
    }

    /**
     * Drops the column; the query builder of Yii does not support this on
     * SQLite, which has `ALTER TABLE ... DROP COLUMN` since 3.35.
     */
    private function dropColumnFrom(string $table, string $column): void
    {
        if ($this->db->driverName !== 'sqlite') {
            $this->dropColumn($this->tableName($table), $column);

            return;
        }

        $this->execute(sprintf(
            'ALTER TABLE %s DROP COLUMN %s',
            $this->db->quoteTableName($this->tableName($table)),
            $this->db->quoteColumnName($column)
        ));
        $this->db->getSchema()->refreshTableSchema($this->tableName($table));
    }

    /**
     * Builds a constraint name without table prefix like the schema
     * migration, e.g. `idx_knowledge_library_file_content_hash`.
     */
    private function constraintName(string $kind, string $table, string $column): string
    {
        return $kind . '_knowledge_library_' . $table . '_' . $column;
    }
}
