<?php

namespace dmstr\knowledgeLibrary;

use dmstr\knowledgeLibrary\users\DefaultUserProvider;
use dmstr\knowledgeLibrary\users\UserProviderInterface;
use dmstr\web\traits\AccessBehaviorTrait;
use Yii;

/**
 * Backend module for managing knowledge items.
 */
class Module extends \yii\base\Module
{
    use AccessBehaviorTrait;

    public const PERMISSION_EDITOR = 'knowledge_library_editor';
    public const PERMISSION_REVIEWER = 'knowledge_library_reviewer';
    public const PERMISSION_ADMIN = 'knowledge_library_admin';

    public const ROLE_EDITOR = 'KnowledgeLibraryEditor';
    public const ROLE_REVIEWER = 'KnowledgeLibraryReviewer';
    public const ROLE_ADMIN = 'KnowledgeLibraryAdmin';

    /**
     * The module URL opens the item list.
     */
    public $defaultRoute = 'item';

    /**
     * Name of the application component used as (flysystem-based) file
     * storage for version files.
     */
    public string $fileStorage = 'fs';

    /**
     * Target directory inside the file storage.
     */
    public string $targetPath = 'knowledge-library';

    /**
     * Definition of a user provider object, registered as DI container
     * singleton for UserProviderInterface unless the container already has a
     * definition. Can be a class name, a configuration array or an object.
     * Null uses the default provider.
     *
     * @var string|array|object|null
     */
    public $userProvider = null;

    public function init()
    {
        parent::init();

        if ($this->userProvider !== null && !Yii::$container->has(UserProviderInterface::class)) {
            Yii::$container->setSingleton(UserProviderInterface::class, $this->userProvider);
        }
    }

    public function getUserProvider(): UserProviderInterface
    {
        return DefaultUserProvider::resolve();
    }
}
