<?php

use dmstr\knowledgeLibrary\Module;
use dmstr\rbacMigration\Migration;
use yii\rbac\Item;

/**
 * Route permissions of the review, the withdrawal and correction of versions
 * and the archive of the knowledge library.
 *
 * Like m260928_185500_knowledge_library_routes each action gets its own
 * permission `<module-id>_<controller>_<action>`; there is deliberately no
 * prefix permission such as `knowledge-library_version`, which would grant
 * every action below it.
 *
 * Editors may withdraw and correct versions; reviewers may review, approve
 * and return versions; admins may change the reviewer of a version in review
 * and archive or restore items. Higher roles inherit the permissions of lower
 * roles through the role chain.
 *
 * All permissions use `ensure => PRESENT` and are therefore idempotent; the
 * roles must exist (created by m260928_100000_knowledge_library_rbac).
 */
class m260928_223100_knowledge_library_routes_3 extends Migration
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
                    'name' => 'knowledge-library_version_withdraw',
                    'description' => 'knowledge-library/version/withdraw',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_version_correct',
                    'description' => 'knowledge-library/version/correct',
                ],
            ],
        ],
        [
            'type' => Item::TYPE_ROLE,
            'name' => Module::ROLE_REVIEWER,
            'ensure' => self::MUST_EXIST,
            'children' => [
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_version_review',
                    'description' => 'knowledge-library/version/review',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_version_approve',
                    'description' => 'knowledge-library/version/approve',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_version_return',
                    'description' => 'knowledge-library/version/return',
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
                    'name' => 'knowledge-library_version_reviewer',
                    'description' => 'knowledge-library/version/reviewer',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_item_archive',
                    'description' => 'knowledge-library/item/archive',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_item_restore',
                    'description' => 'knowledge-library/item/restore',
                ],
            ],
        ],
    ];

    public function safeDown()
    {
        // Intentionally not revertible: the permissions may be assigned to
        // other roles or users meanwhile; removing them would break existing
        // permissions.
        echo "m260928_223100_knowledge_library_routes_3 cannot be reverted.\n";

        return false;
    }
}
