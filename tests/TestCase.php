<?php

namespace dmstr\knowledgeLibrary\tests;

use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Topic;
use dmstr\knowledgeLibrary\models\Type;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\users\UserProviderInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use m260928_100100_knowledge_library_schema;
use m260928_203000_knowledge_library_versions;
use m260928_223000_knowledge_library_history_details;
use Yii;
use yii\base\Application;
use yii\console\Application as ConsoleApplication;
use yii\db\Connection;
use yii\db\Migration;
use yii\helpers\FileHelper;
use yii\i18n\DbMessageSource;

/**
 * Base test case with a fresh console application and an in-memory SQLite
 * database migrated to the package schema.
 *
 * The application has a file storage component `fs`, a flysystem filesystem
 * on a temporary directory of the test (see getStorageDir()), which is
 * created on first use and removed in tearDown().
 *
 * Subclasses may use another application class or change the configuration
 * by overriding applicationClass() and applicationConfig().
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * Name prefix of the temporary storage directories in the system temp
     * directory.
     */
    public const STORAGE_DIR_PREFIX = 'knowledge-library-test-storage-';

    protected m260928_100100_knowledge_library_schema $schemaMigration;

    protected m260928_203000_knowledge_library_versions $versionsMigration;

    protected m260928_223000_knowledge_library_history_details $historyDetailsMigration;

    /**
     * Temporary directory of the file storage `fs`, null until first use.
     */
    private ?string $storageDir = null;

    /**
     * Whether setUp() installs the German translations of the package via
     * the optional i18n migration, as an application would.
     */
    protected bool $installTranslations = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockApplication();

        DummyUserProvider::$currentReference = DummyUserProvider::DEFAULT_REFERENCE;
        DummyUserProvider::$reviewerOptions = null;
        Yii::$container->setSingleton(UserProviderInterface::class, DummyUserProvider::class);

        $this->createMessageTables();
        if ($this->installTranslations) {
            $this->installTranslations();
        }

        require_once dirname(__DIR__) . '/src/migrations/m260928_100100_knowledge_library_schema.php';
        require_once dirname(__DIR__) . '/src/migrations/m260928_203000_knowledge_library_versions.php';
        require_once dirname(__DIR__) . '/src/migrations/m260928_223000_knowledge_library_history_details.php';
        $this->schemaMigration = new m260928_100100_knowledge_library_schema([
            'db' => Yii::$app->db,
            'compact' => true,
        ]);
        $this->versionsMigration = new m260928_203000_knowledge_library_versions([
            'db' => Yii::$app->db,
            'compact' => true,
        ]);
        $this->historyDetailsMigration = new m260928_223000_knowledge_library_history_details([
            'db' => Yii::$app->db,
            'compact' => true,
        ]);
        $this->runMigration('up');
    }

    /**
     * Creates the application of the test, available as `Yii::$app`.
     */
    protected function mockApplication(): Application
    {
        $class = $this->applicationClass();

        return new $class($this->applicationConfig());
    }

    /**
     * Class of the test application.
     *
     * @return class-string<Application>
     */
    protected function applicationClass(): string
    {
        return ConsoleApplication::class;
    }

    /**
     * Configuration of the test application: an in-memory SQLite database, a
     * DbMessageSource for `knowledge-library` and the file storage `fs`.
     */
    protected function applicationConfig(): array
    {
        return [
            'id' => 'knowledge-library-test',
            'basePath' => __DIR__,
            'vendorPath' => KNOWLEDGE_LIBRARY_VENDOR_DIR,
            'components' => [
                'fs' => fn() => new Filesystem(new LocalFilesystemAdapter($this->getStorageDir())),
                'db' => [
                    'class' => Connection::class,
                    'dsn' => 'sqlite::memory:',
                    'on afterOpen' => static function ($event) {
                        $event->sender->createCommand('PRAGMA foreign_keys = ON')->execute();
                    },
                ],
                'i18n' => [
                    'translations' => [
                        'knowledge-library*' => [
                            'class' => DbMessageSource::class,
                            'sourceMessageTable' => '{{%language_source}}',
                            'messageTable' => '{{%language_translate}}',
                            'sourceLanguage' => 'en',
                            'enableCaching' => false,
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        Yii::$container->clear(UserProviderInterface::class);
        if (Yii::$app !== null && Yii::$app->has('db', true)) {
            Yii::$app->db->close();
        }
        Yii::$app = null;
        $this->releaseLogger();

        if ($this->storageDir !== null) {
            FileHelper::removeDirectory($this->storageDir);
            $this->storageDir = null;
        }

        parent::tearDown();
    }

    /**
     * Empties the current logger and replaces it with a fresh one.
     *
     * `yii\log\Logger::init()` registers a shutdown function holding the
     * logger, so a logger replaced via `Yii::setLogger()` is never freed
     * before the end of the process. Tests that keep messages (flushInterval
     * 0 or PHP_INT_MAX) would otherwise leak all log and profiling messages
     * of every test, including every SQL query.
     */
    protected function releaseLogger(): void
    {
        $logger = Yii::getLogger();
        $logger->messages = [];
        $logger->dispatcher = null;
        Yii::setLogger(null);
    }

    /**
     * Temporary root directory of the file storage `fs`, created on first
     * call and removed in tearDown().
     */
    protected function getStorageDir(): string
    {
        if ($this->storageDir === null) {
            $dir = sys_get_temp_dir() . '/' . static::STORAGE_DIR_PREFIX . bin2hex(random_bytes(8));
            FileHelper::createDirectory($dir);
            $this->storageDir = $dir;
        }

        return $this->storageDir;
    }

    /**
     * Absolute local path of a path inside the file storage `fs`, e.g. to
     * assert that a stored file exists.
     */
    protected function getStoragePath(string $path): string
    {
        return $this->getStorageDir() . '/' . ltrim($path, '/');
    }

    /**
     * Creates the tables of the DbMessageSource serving `knowledge-library`,
     * like the translation tables of a phd5 application.
     */
    protected function createMessageTables(): void
    {
        $command = Yii::$app->db->createCommand();
        $command->createTable('{{%language_source}}', [
            'id' => 'pk',
            'category' => 'string(32)',
            'message' => 'text',
        ])->execute();
        $command->createTable('{{%language_translate}}', [
            'id' => 'integer NOT NULL',
            'language' => 'string(5) NOT NULL',
            'translation' => 'text',
            'PRIMARY KEY (id, language)',
        ])->execute();
    }

    /**
     * Writes the German translations into the message tables by running `up`
     * of all translation migrations of the package.
     */
    protected function installTranslations(): void
    {
        foreach (static::translationMigrationClasses() as $class) {
            $migration = new $class([
                'db' => Yii::$app->db,
                'compact' => true,
            ]);

            ob_start();
            try {
                $result = $migration->up();
            } finally {
                $output = ob_get_clean();
            }

            $this->assertNotFalse($result, "Translation migration $class failed: $output");
        }
    }

    /**
     * Loads the translation migrations in `src/migrations/i18n` and returns
     * their class names in migration order.
     *
     * @return string[]
     */
    protected static function translationMigrationClasses(): array
    {
        $files = glob(dirname(__DIR__) . '/src/migrations/i18n/m*.php');
        sort($files);

        $classes = [];
        foreach ($files as $file) {
            require_once $file;
            $classes[] = basename($file, '.php');
        }

        return $classes;
    }

    /**
     * Runs `up` or `down` of all schema migrations without output: `up` in
     * migration order, `down` in reverse order.
     */
    protected function runMigration(string $direction): void
    {
        $migrations = [$this->schemaMigration, $this->versionsMigration, $this->historyDetailsMigration];
        if ($direction === 'down') {
            $migrations = array_reverse($migrations);
        }

        foreach ($migrations as $migration) {
            $this->runSchemaMigration($migration, $direction);
        }
    }

    /**
     * Runs `up` or `down` of the single migration without output.
     */
    protected function runSchemaMigration(Migration $migration, string $direction): void
    {
        ob_start();
        try {
            $result = $migration->{$direction}();
        } finally {
            $output = ob_get_clean();
        }

        $this->assertNotFalse($result, 'Migration ' . get_class($migration) . " $direction failed: $output");
    }

    protected function createType(array $attributes = []): Type
    {
        $type = new Type();
        $type->setAttributes(array_merge(['name' => 'Type ' . uniqid()], $attributes), false);
        $this->assertTrue($type->save(), 'Type not saved: ' . json_encode($type->getErrors()));

        return $type;
    }

    protected function createTopic(array $attributes = []): Topic
    {
        $topic = new Topic();
        $topic->setAttributes(array_merge(['name' => 'Topic ' . uniqid()], $attributes), false);
        $this->assertTrue($topic->save(), 'Topic not saved: ' . json_encode($topic->getErrors()));

        return $topic;
    }

    /**
     * Inserts a knowledge item row directly, returns its ID.
     */
    protected function insertItem(string $typeId): string
    {
        $id = sprintf('00000000-0000-4000-8000-%012d', random_int(0, 999999999999));
        Yii::$app->db->createCommand()->insert('{{%knowledge_library_item}}', [
            'id' => $id,
            'type_id' => $typeId,
            'title' => 'Item ' . $id,
        ])->execute();

        return $id;
    }

    /**
     * Creates a knowledge item, with a new type unless `type_id` is given.
     */
    protected function createItem(array $attributes = []): Item
    {
        if (!isset($attributes['type_id'])) {
            $attributes['type_id'] = $this->createType()->id;
        }

        $item = new Item();
        if (array_key_exists('topicIds', $attributes)) {
            // Virtual attribute, not covered by setAttributes() with safeOnly = false.
            $item->topicIds = $attributes['topicIds'];
            unset($attributes['topicIds']);
        }
        $item->setAttributes(array_merge(['title' => 'Item ' . uniqid()], $attributes), false);
        $this->assertTrue($item->save(), 'Item not saved: ' . json_encode($item->getErrors()));

        return $item;
    }

    /**
     * Creates a version of the item, a draft with content by default.
     */
    protected function createVersion(Item $item, array $attributes = []): Version
    {
        $version = new Version();
        $version->setAttributes(array_merge([
            'item_id' => $item->id,
            'status' => Version::STATUS_DRAFT,
            'content' => 'Content',
        ], $attributes), false);
        $this->assertTrue($version->save(), 'Version not saved: ' . json_encode($version->getErrors()));

        return $version;
    }

    /**
     * Creates a draft of the item and publishes it via review: submitted by
     * the current user to `user-2`, published as `user-2`. The current user
     * reference is restored afterwards.
     */
    protected function createPublishedVersion(Item $item, array $attributes = []): Version
    {
        $version = $this->createVersion($item, $attributes);
        $this->assertTrue(
            $version->submitForReview('user-2'),
            'Version not submitted: ' . json_encode($version->getErrors())
        );

        $current = DummyUserProvider::$currentReference;
        DummyUserProvider::$currentReference = 'user-2';
        try {
            $published = $version->publish();
        } finally {
            DummyUserProvider::$currentReference = $current;
        }
        $this->assertTrue($published, 'Version not published: ' . json_encode($version->getErrors()));

        return $version;
    }

    protected function createFile(Version $version, array $attributes = []): File
    {
        $file = new File();
        $file->setAttributes(array_merge([
            'version_id' => $version->id,
            'kind' => File::KIND_ATTACHMENT,
            'storage_id' => 'fs',
            'path' => 'knowledge-library/' . uniqid() . '.pdf',
            'name' => 'document.pdf',
        ], $attributes), false);
        $this->assertTrue($file->save(), 'File not saved: ' . json_encode($file->getErrors()));

        return $file;
    }
}
