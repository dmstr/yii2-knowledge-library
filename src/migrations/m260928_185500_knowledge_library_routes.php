<?php

use dmstr\knowledgeLibrary\Module;
use dmstr\rbacMigration\Migration;
use yii\rbac\Item;

/**
 * Route permissions of the backend controllers of the knowledge library.
 *
 * The backend module checks every action via `AccessBehaviorTrait` against a
 * permission named `<module-id>_<controller>_<action>`, e.g.
 * `knowledge-library_item_delete`. Each action gets its own permission.
 *
 * There is deliberately no permission for a prefix such as
 * `knowledge-library` or `knowledge-library_item`: `dmstr\web\User` resolves
 * route permissions by prefix, so such a permission would grant every action
 * below it, including deleting items.
 *
 * Editors may list, create, view and edit items including their source data;
 * reviewers and admins inherit this through the role chain. Only admins manage
 * types and topics and delete items.
 *
 * All permissions use `ensure => PRESENT` and are therefore idempotent; the
 * roles must exist (created by m260928_100000_knowledge_library_rbac).
 */
class m260928_185500_knowledge_library_routes extends Migration
{
    public $defaultFlags = [
        'ensure' => self::PRESENT,
    ];

    public $privileges = [
        [
            'type' => Item::TYPE_ROLE,
            'name' => Module::ROLE_EDITOR,
            'ensure' => self::MUST_EXIST,
            'children' => [
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_item_index',
                    'description' => 'knowledge-library/item/index',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_item_create',
                    'description' => 'knowledge-library/item/create',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_item_view',
                    'description' => 'knowledge-library/item/view',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_item_update',
                    'description' => 'knowledge-library/item/update',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_item_source',
                    'description' => 'knowledge-library/item/source',
                ],
            ],
        ],
        [
            'type' => Item::TYPE_ROLE,
            'name' => Module::ROLE_ADMIN,
            'ensure' => self::MUST_EXIST,
            'children' => [
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_type_index',
                    'description' => 'knowledge-library/type/index',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_type_create',
                    'description' => 'knowledge-library/type/create',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_type_update',
                    'description' => 'knowledge-library/type/update',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_type_delete',
                    'description' => 'knowledge-library/type/delete',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_topic_index',
                    'description' => 'knowledge-library/topic/index',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_topic_create',
                    'description' => 'knowledge-library/topic/create',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_topic_update',
                    'description' => 'knowledge-library/topic/update',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_topic_delete',
                    'description' => 'knowledge-library/topic/delete',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_item_delete',
                    'description' => 'knowledge-library/item/delete',
                ],
            ],
        ],
    ];

    public function safeDown()
    {
        // Intentionally not revertible: the permissions may be assigned to
        // other roles or users meanwhile; removing them would break existing
        // permissions.
        echo "m260928_185500_knowledge_library_routes cannot be reverted.\n";

        return false;
    }
}
