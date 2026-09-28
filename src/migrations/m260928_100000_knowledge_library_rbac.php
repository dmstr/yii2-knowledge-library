<?php

use dmstr\knowledgeLibrary\Module;
use dmstr\rbacMigration\Migration;
use yii\rbac\Item;

/**
 * Permissions and roles of the knowledge library.
 *
 * The roles build on each other: an admin is a reviewer, a reviewer is an
 * editor. The frontend permission `knowledge` is created but not attached to
 * any role; the application decides who may read the frontend.
 *
 * All items use `ensure => PRESENT` and are therefore idempotent: existing
 * items are kept unchanged and existing links are not duplicated.
 */
class m260928_100000_knowledge_library_rbac extends Migration
{
    public $defaultFlags = [
        'ensure' => self::PRESENT,
    ];

    public $privileges = [
        [
            'type' => Item::TYPE_ROLE,
            'name' => Module::ROLE_EDITOR,
            'description' => 'Knowledge library editor',
            'children' => [
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => Module::PERMISSION_EDITOR,
                    'description' => 'Create and edit knowledge items and their drafts',
                ],
            ],
        ],
        [
            'type' => Item::TYPE_ROLE,
            'name' => Module::ROLE_REVIEWER,
            'description' => 'Knowledge library reviewer',
            'children' => [
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => Module::PERMISSION_REVIEWER,
                    'description' => 'Review, return and publish knowledge item versions',
                ],
                [
                    'type' => Item::TYPE_ROLE,
                    'name' => Module::ROLE_EDITOR,
                    'ensure' => self::MUST_EXIST,
                ],
            ],
        ],
        [
            'type' => Item::TYPE_ROLE,
            'name' => Module::ROLE_ADMIN,
            'description' => 'Knowledge library administrator',
            'children' => [
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => Module::PERMISSION_ADMIN,
                    'description' => 'Manage types and topics and administrate all knowledge items',
                ],
                [
                    'type' => Item::TYPE_ROLE,
                    'name' => Module::ROLE_REVIEWER,
                    'ensure' => self::MUST_EXIST,
                ],
            ],
        ],
        [
            'type' => Item::TYPE_PERMISSION,
            'name' => 'knowledge',
            'description' => 'Grants access to the frontend module registered as knowledge',
        ],
    ];

    public function safeDown()
    {
        // Intentionally not revertible: the items may have existed before this
        // migration or may be assigned to users meanwhile; removing them would
        // break existing permissions.
        echo "m260928_100000_knowledge_library_rbac cannot be reverted.\n";

        return false;
    }
}
