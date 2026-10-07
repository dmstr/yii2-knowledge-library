<?php
// file generated with AI assistance: Claude Code - 2026-10-07 20:58:25 UTC

namespace dmstr\knowledgeLibrary;

use dmstr\knowledgeLibrary\users\DefaultUserProvider;
use dmstr\knowledgeLibrary\users\UserProviderInterface;
use dmstr\web\traits\AccessBehaviorTrait;
use League\Flysystem\FilesystemOperator;
use Yii;
use yii\base\InvalidConfigException;

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
     * storage for version files, see getFilesystem().
     */
    public string $fileStorage = 'fs';

    /**
     * Target directory inside the file storage.
     */
    public string $targetPath = 'knowledge-library';

    /**
     * File extensions allowed for uploaded version files (lower case, without
     * leading dot).
     *
     * @var string[]
     */
    public array $allowedExtensions = ['pdf', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Maximum size of an uploaded version file in bytes.
     */
    public int $maxFileSize = 20 * 1024 * 1024;

    /**
     * MIME types of files that `file/download` sends for display in the
     * browser (`Content-Disposition: inline`) instead of as download;
     * every other file is sent as attachment.
     *
     * @var string[]
     */
    public array $inlineMimeTypes = ['application/pdf'];

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

    /**
     * Returns the filesystem of the file storage component named by
     * $fileStorage.
     *
     * The component is either a flysystem filesystem itself or a wrapper
     * providing one through `getFilesystem()`, e.g. the file storage of
     * eluhr/yii2-flysystem-rest-api. The package always works on the raw
     * filesystem, so permission layers of such a wrapper do not apply.
     *
     * @throws InvalidConfigException if the component does not exist or
     * provides no flysystem filesystem
     */
    public function getFilesystem(): FilesystemOperator
    {
        $component = Yii::$app->get($this->fileStorage);

        if ($component instanceof FilesystemOperator) {
            return $component;
        }

        if (is_object($component) && method_exists($component, 'getFilesystem')) {
            $filesystem = $component->getFilesystem();
            if ($filesystem instanceof FilesystemOperator) {
                return $filesystem;
            }
        }

        throw new InvalidConfigException(sprintf(
            'The file storage component "%s" must implement %s or provide one through getFilesystem(), got %s.',
            $this->fileStorage,
            FilesystemOperator::class,
            get_debug_type($component)
        ));
    }
}
