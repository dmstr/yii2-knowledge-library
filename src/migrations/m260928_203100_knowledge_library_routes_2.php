<?php

use dmstr\knowledgeLibrary\Module;
use dmstr\rbacMigration\Migration;
use yii\rbac\Item;

/**
 * Route permissions of the version wizard, the file download and the
 * relations of the knowledge library.
 *
 * Like m260928_185500_knowledge_library_routes each action gets its own
 * permission `<module-id>_<controller>_<action>`; there is deliberately no
 * prefix permission such as `knowledge-library_version`, which would grant
 * every action below it.
 *
 * Editors may create, edit, publish and discard versions, download files and
 * add or remove relations; reviewers and admins inherit this through the role
 * chain.
 *
 * All permissions use `ensure => PRESENT` and are therefore idempotent; the
 * role must exist (created by m260928_100000_knowledge_library_rbac).
 */
class m260928_203100_knowledge_library_routes_2 extends Migration
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
                    'name' => 'knowledge-library_version_create',
                    'description' => 'knowledge-library/version/create',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_version_update',
                    'description' => 'knowledge-library/version/update',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_version_publish',
                    'description' => 'knowledge-library/version/publish',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_version_discard',
                    'description' => 'knowledge-library/version/discard',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_file_download',
                    'description' => 'knowledge-library/file/download',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_relation_create',
                    'description' => 'knowledge-library/relation/create',
                ],
                [
                    'type' => Item::TYPE_PERMISSION,
                    'name' => 'knowledge-library_relation_delete',
                    'description' => 'knowledge-library/relation/delete',
                ],
            ],
        ],
    ];

    public function safeDown()
    {
        // Intentionally not revertible: the permissions may be assigned to
        // other roles or users meanwhile; removing them would break existing
        // permissions.
        echo "m260928_203100_knowledge_library_routes_2 cannot be reverted.\n";

        return false;
    }
}
