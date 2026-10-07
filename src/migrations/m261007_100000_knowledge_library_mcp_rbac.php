<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

use dmstr\rbacMigration\Migration;
use yii\rbac\Item;

/**
 * Permission of the MCP module.
 *
 * Like the frontend permission `knowledge`, the permission `knowledge-mcp`
 * is created but not attached to any role: through the prefix resolution of
 * `dmstr\web\User` it grants all routes of the module registered as
 * `knowledge-mcp` (`knowledge-mcp_default_index`, `knowledge-mcp_file_download`)
 * and the application decides who may use the MCP server.
 *
 * `ensure => PRESENT` keeps the permission if it exists, so the migration is
 * idempotent.
 */
class m261007_100000_knowledge_library_mcp_rbac extends Migration
{
    public $defaultFlags = [
        'ensure' => self::PRESENT,
    ];

    public $privileges = [
        [
            'type' => Item::TYPE_PERMISSION,
            'name' => 'knowledge-mcp',
            'description' => 'Use the MCP server of the knowledge library (all routes of the module knowledge-mcp)',
        ],
    ];

    public function safeDown()
    {
        // Intentionally not revertible: the permission may be assigned to
        // roles of the application.
        echo "m261007_100000_knowledge_library_mcp_rbac cannot be reverted.\n";

        return false;
    }
}
