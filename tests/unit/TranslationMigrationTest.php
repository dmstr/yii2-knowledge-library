<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\tests\TestCase;
use m260928_100200_knowledge_library_translations;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Yii;
use yii\db\Query;
use yii\i18n\PhpMessageSource;

class TranslationMigrationTest extends TestCase
{
    private const CATEGORY = 'knowledge-library';

    /**
     * Start with empty message tables.
     */
    protected bool $installTranslations = false;

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2)
            . '/src/migrations/i18n/m260928_100200_knowledge_library_translations.php';
    }

    public function testUpInsertsSourceMessagesAndGermanTranslations(): void
    {
        $this->runTranslationMigration('up');

        $expected = count($this->germanTranslations());
        $this->assertSame($expected, $this->countSourceRows(self::CATEGORY));
        $this->assertSame($expected, $this->countTranslationRows('de'));
        $this->assertSame('Titel', Yii::t('knowledge-library', 'Title', [], 'de'));
        $this->assertSame('Title', Yii::t('knowledge-library', 'Title'));
    }

    public function testUpTwiceCreatesNoDuplicates(): void
    {
        $this->runTranslationMigration('up');
        $this->runTranslationMigration('up');

        $expected = count($this->germanTranslations());
        $this->assertSame($expected, $this->countSourceRows(self::CATEGORY));
        $this->assertSame($expected, $this->countTranslationRows('de'));
    }

    public function testUpAfterDownRestoresRows(): void
    {
        $this->runTranslationMigration('up');
        $this->runTranslationMigration('down');
        $this->runTranslationMigration('up');

        $expected = count($this->germanTranslations());
        $this->assertSame($expected, $this->countSourceRows(self::CATEGORY));
        $this->assertSame($expected, $this->countTranslationRows('de'));
    }

    public function testUpKeepsExistingTranslation(): void
    {
        $db = Yii::$app->db;
        $db->createCommand()->insert('{{%language_source}}', [
            'category' => self::CATEGORY,
            'message' => 'Title',
        ])->execute();
        $id = (int)$db->getLastInsertID();
        $db->createCommand()->insert('{{%language_translate}}', [
            'id' => $id,
            'language' => 'de',
            'translation' => 'Überschrift',
        ])->execute();

        $this->runTranslationMigration('up');

        $this->assertSame(count($this->germanTranslations()), $this->countSourceRows(self::CATEGORY));
        $this->assertSame('Überschrift', (new Query())
            ->select('translation')
            ->from('{{%language_translate}}')
            ->where(['id' => $id, 'language' => 'de'])
            ->scalar($db));
        $this->assertSame('Überschrift', Yii::t('knowledge-library', 'Title', [], 'de'));
    }

    public function testDownRemovesPackageRowsOnly(): void
    {
        $db = Yii::$app->db;
        $db->createCommand()->insert('{{%language_source}}', [
            'category' => 'app',
            'message' => 'Title',
        ])->execute();
        $otherId = (int)$db->getLastInsertID();
        $db->createCommand()->insert('{{%language_translate}}', [
            'id' => $otherId,
            'language' => 'de',
            'translation' => 'Titel',
        ])->execute();

        $this->runTranslationMigration('up');
        $this->runTranslationMigration('down');

        $this->assertSame(0, $this->countSourceRows(self::CATEGORY));
        $this->assertSame(1, $this->countSourceRows('app'));
        $this->assertSame(1, $this->countTranslationRows('de'));
        $this->assertTrue((new Query())
            ->from('{{%language_translate}}')
            ->where(['id' => $otherId, 'language' => 'de'])
            ->exists($db));
    }

    public function testDownKeepsSourceMessageWithOtherTranslations(): void
    {
        $this->runTranslationMigration('up');
        $id = (int)(new Query())
            ->select('id')
            ->from('{{%language_source}}')
            ->where(['category' => self::CATEGORY, 'message' => 'Title'])
            ->scalar(Yii::$app->db);
        Yii::$app->db->createCommand()->insert('{{%language_translate}}', [
            'id' => $id,
            'language' => 'fr',
            'translation' => 'Titre',
        ])->execute();

        $this->runTranslationMigration('down');

        $this->assertSame(1, $this->countSourceRows(self::CATEGORY));
        $this->assertSame(0, $this->countTranslationRows('de'));
        $this->assertSame(1, $this->countTranslationRows('fr'));
    }

    public function testUpIsNoOpWithoutDbMessageSource(): void
    {
        Yii::$app->i18n->translations['knowledge-library*'] = [
            'class' => PhpMessageSource::class,
            'basePath' => '@runtime/messages',
            'sourceLanguage' => 'en',
        ];

        $output = $this->runTranslationMigration('up');

        $this->assertStringContainsString('not served by a DbMessageSource', $output);
        $this->assertSame(0, $this->countSourceRows(self::CATEGORY));
        $this->assertSame(0, $this->countTranslationRows('de'));
    }

    public function testUpSkipsLanguageMissingInLanguageTable(): void
    {
        $db = Yii::$app->db;
        $db->createCommand()->createTable('{{%language}}', [
            'language_id' => 'string(5) NOT NULL PRIMARY KEY',
        ])->execute();
        $db->createCommand()->insert('{{%language}}', ['language_id' => 'en'])->execute();

        $output = $this->runTranslationMigration('up');

        $this->assertStringContainsString("language 'de' does not exist", $output);
        $this->assertSame(count($this->germanTranslations()), $this->countSourceRows(self::CATEGORY));
        $this->assertSame(0, $this->countTranslationRows('de'));

        $db->createCommand()->insert('{{%language}}', ['language_id' => 'de'])->execute();
        $this->runTranslationMigration('up');

        $this->assertSame(count($this->germanTranslations()), $this->countTranslationRows('de'));
    }

    public function testEveryUsedMessageHasGermanTranslation(): void
    {
        $translations = $this->germanTranslations();
        $pattern = "/Yii::t\\(\\s*'knowledge-library'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/";
        $src = dirname(__DIR__, 2) . '/src';
        $found = 0;

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all($pattern, file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $message) {
                $message = stripcslashes($message);
                $found++;
                $this->assertArrayHasKey(
                    $message,
                    $translations,
                    "No German translation for '$message' in {$file->getPathname()}"
                );
                $this->assertNotSame('', $translations[$message]);
            }
        }

        $this->assertGreaterThan(0, $found, 'No Yii::t() calls found');
    }

    /**
     * Runs `up` or `down` of the translation migration, returns its output.
     */
    private function runTranslationMigration(string $direction): string
    {
        $migration = new m260928_100200_knowledge_library_translations([
            'db' => Yii::$app->db,
            'compact' => true,
        ]);

        ob_start();
        try {
            $result = $migration->{$direction}();
        } finally {
            $output = ob_get_clean();
        }

        $this->assertNotFalse($result, "Translation migration $direction failed: $output");

        return $output;
    }

    /**
     * @return array<string, string>
     */
    private function germanTranslations(): array
    {
        $translations = (new ReflectionClass(m260928_100200_knowledge_library_translations::class))
            ->getConstant('TRANSLATIONS');

        return $translations[self::CATEGORY]['de'];
    }

    private function countSourceRows(string $category): int
    {
        return (int)(new Query())
            ->from('{{%language_source}}')
            ->where(['category' => $category])
            ->count('*', Yii::$app->db);
    }

    private function countTranslationRows(string $language): int
    {
        return (int)(new Query())
            ->from('{{%language_translate}}')
            ->where(['language' => $language])
            ->count('*', Yii::$app->db);
    }
}
