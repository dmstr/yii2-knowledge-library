<?php

use yii\db\ColumnSchemaBuilder;
use yii\db\Migration;

/**
 * Structured details of history entries.
 *
 * `history.details` holds a JSON object with the facts a history entry needs
 * to be described later, e.g. the version number, the reviewer or the
 * previous reviewer. `history.version_id` is set to null when a version is
 * deleted, the details keep the number.
 *
 * Runs on MySQL/MariaDB and SQLite (3.35 or newer for `safeDown()`). On
 * MySQL added columns inherit the charset of the table, not the table options
 * of the schema migration; the column declares utf8mb4 explicitly so it never
 * becomes a binary column, e.g. on a server with charset `binary`.
 */
class m260928_223000_knowledge_library_history_details extends Migration
{
    private const TABLE_HISTORY = 'history';

    public function safeUp()
    {
        $this->addColumn($this->tableName(self::TABLE_HISTORY), 'details', $this->textColumn('LONGTEXT'));
    }

    public function safeDown()
    {
        $this->dropColumnFrom(self::TABLE_HISTORY, 'details');
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
}
