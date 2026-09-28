<?php

namespace dmstr\knowledgeLibrary\tests;

use dmstr\knowledgeLibrary\models\Topic;
use dmstr\knowledgeLibrary\models\Type;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\users\UserProviderInterface;
use m260928_100100_knowledge_library_schema;
use Yii;
use yii\console\Application;
use yii\db\Connection;
use yii\i18n\PhpMessageSource;

/**
 * Base test case with a fresh console application and an in-memory SQLite
 * database migrated to the package schema.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected m260928_100100_knowledge_library_schema $schemaMigration;

    protected function setUp(): void
    {
        parent::setUp();

        new Application([
            'id' => 'knowledge-library-test',
            'basePath' => __DIR__,
            'vendorPath' => KNOWLEDGE_LIBRARY_VENDOR_DIR,
            'components' => [
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
                            'class' => PhpMessageSource::class,
                            'basePath' => '@dmstr/knowledgeLibrary/messages',
                            'sourceLanguage' => 'en',
                        ],
                    ],
                ],
            ],
        ]);

        DummyUserProvider::$currentReference = DummyUserProvider::DEFAULT_REFERENCE;
        Yii::$container->setSingleton(UserProviderInterface::class, DummyUserProvider::class);

        require_once dirname(__DIR__) . '/src/migrations/m260928_100100_knowledge_library_schema.php';
        $this->schemaMigration = new m260928_100100_knowledge_library_schema([
            'db' => Yii::$app->db,
            'compact' => true,
        ]);
        $this->runMigration('up');
    }

    protected function tearDown(): void
    {
        Yii::$container->clear(UserProviderInterface::class);
        if (Yii::$app !== null && Yii::$app->has('db', true)) {
            Yii::$app->db->close();
        }
        Yii::$app = null;

        parent::tearDown();
    }

    /**
     * Runs `up` or `down` of the schema migration without output.
     */
    protected function runMigration(string $direction): void
    {
        ob_start();
        try {
            $result = $this->schemaMigration->{$direction}();
        } finally {
            $output = ob_get_clean();
        }

        $this->assertNotFalse($result, "Schema migration $direction failed: $output");
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
}
