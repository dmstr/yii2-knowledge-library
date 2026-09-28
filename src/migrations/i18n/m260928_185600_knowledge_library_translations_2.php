<?php

use yii\base\InvalidConfigException;
use yii\db\Migration;
use yii\db\Query;
use yii\i18n\DbMessageSource;

/**
 * Optional migration providing the German translations of the backend pages
 * of the package, in addition to m260928_100200_knowledge_library_translations.
 *
 * Works exactly like the first translation migration: if the category
 * `knowledge-library` is served by a `yii\i18n\DbMessageSource`, this
 * migration writes its source messages and translations into the tables of
 * that source, otherwise it does nothing. Existing translations are never
 * overwritten, running `up` again only adds missing rows.
 *
 * The messages of this migration do not overlap with those of the first one,
 * so `down` of either migration keeps the rows of the other.
 *
 * Assumption: the tables of the message source live in the database of this
 * migration (`$this->db`); a separate `db` of the message source is ignored.
 */
class m260928_185600_knowledge_library_translations_2 extends Migration
{
    /**
     * Source message table; null uses the table of the configured
     * DbMessageSource.
     */
    public ?string $sourceMessageTable = null;

    /**
     * Translation table; null uses the table of the configured
     * DbMessageSource.
     */
    public ?string $messageTable = null;

    /**
     * Name of the application language table; languages missing in it are
     * skipped because translations reference it by foreign key. The check is
     * skipped if the table does not exist.
     */
    public string $languageTable = '{{%language}}';

    /**
     * Translations by category and language, keyed by source message.
     *
     * Only messages that are not part of an earlier translation migration of
     * the package: `down` removes all source messages listed here.
     */
    private const TRANSLATIONS = [
        'knowledge-library' => [
            'de' => [
            ],
        ],
    ];

    public function safeUp()
    {
        foreach (self::TRANSLATIONS as $category => $languages) {
            $tables = $this->resolveTables($category);
            if ($tables === null) {
                continue;
            }
            [$sourceTable, $translationTable] = $tables;

            $sourceIds = $this->findSourceIds($sourceTable, $category);
            $insertedSources = 0;
            foreach ($languages as $translations) {
                foreach (array_keys($translations) as $message) {
                    $message = (string)$message;
                    if (isset($sourceIds[$message])) {
                        continue;
                    }
                    $keys = $this->db->getSchema()->insert($sourceTable, [
                        'category' => $category,
                        'message' => $message,
                    ]);
                    if ($keys === false || !isset($keys['id'])) {
                        throw new RuntimeException("Could not insert source message '$message'.");
                    }
                    $sourceIds[$message] = (int)$keys['id'];
                    $insertedSources++;
                }
            }
            echo "    > category '$category': $insertedSources source messages added\n";

            $existing = $this->findExistingTranslations($translationTable, array_values($sourceIds));
            foreach ($languages as $language => $translations) {
                if (!$this->languageExists($language)) {
                    echo "    > language '$language' does not exist in $this->languageTable, skipped\n";
                    continue;
                }
                $inserted = 0;
                foreach ($translations as $message => $translation) {
                    $id = $sourceIds[(string)$message];
                    if (isset($existing[$id][$language])) {
                        continue;
                    }
                    $this->db->createCommand()->insert($translationTable, [
                        'id' => $id,
                        'language' => $language,
                        'translation' => $translation,
                    ])->execute();
                    $existing[$id][$language] = true;
                    $inserted++;
                }
                echo "    > category '$category', language '$language': $inserted translations added\n";
            }
        }

        return true;
    }

    public function safeDown()
    {
        foreach (self::TRANSLATIONS as $category => $languages) {
            $tables = $this->resolveTables($category);
            if ($tables === null) {
                continue;
            }
            [$sourceTable, $translationTable] = $tables;

            $messages = [];
            foreach ($languages as $translations) {
                foreach (array_keys($translations) as $message) {
                    $messages[(string)$message] = true;
                }
            }
            $ids = array_values(array_intersect_key($this->findSourceIds($sourceTable, $category), $messages));
            if ($ids === []) {
                continue;
            }

            $deleted = $this->db->createCommand()->delete($translationTable, [
                'id' => $ids,
                'language' => array_map('strval', array_keys($languages)),
            ])->execute();

            $translated = (new Query())
                ->select('id')
                ->distinct()
                ->from($translationTable)
                ->where(['id' => $ids])
                ->column($this->db);
            $unused = array_values(array_diff($ids, array_map('intval', $translated)));
            $deletedSources = $unused === []
                ? 0
                : $this->db->createCommand()->delete($sourceTable, ['id' => $unused])->execute();

            echo "    > category '$category': $deleted translations and $deletedSources source messages removed\n";
        }

        return true;
    }

    /**
     * Returns the source message and translation table for the category, or
     * null if the category is not served by a DbMessageSource.
     *
     * @return string[]|null
     */
    private function resolveTables(string $category): ?array
    {
        if ($this->sourceMessageTable !== null && $this->messageTable !== null) {
            return [$this->sourceMessageTable, $this->messageTable];
        }

        $source = null;
        try {
            $source = Yii::$app->getI18n()->getMessageSource($category);
        } catch (InvalidConfigException $e) {
            // No message source for the category, handled below.
        }

        if (!$source instanceof DbMessageSource) {
            echo "    > category '$category' is not served by a DbMessageSource, skipped\n";

            return null;
        }

        return [
            $this->sourceMessageTable ?? $source->sourceMessageTable,
            $this->messageTable ?? $source->messageTable,
        ];
    }

    /**
     * Returns the IDs of the category's source messages, keyed by message.
     * Matching is done in PHP to be exact regardless of the column collation.
     *
     * @return int[]
     */
    private function findSourceIds(string $table, string $category): array
    {
        $rows = (new Query())
            ->select(['id', 'message'])
            ->from($table)
            ->where(['category' => $category])
            ->orderBy(['id' => SORT_ASC])
            ->all($this->db);

        $ids = [];
        foreach ($rows as $row) {
            $message = (string)$row['message'];
            if (!isset($ids[$message])) {
                $ids[$message] = (int)$row['id'];
            }
        }

        return $ids;
    }

    /**
     * Returns the existing translations as `[id => [language => true]]`.
     *
     * @param int[] $ids
     */
    private function findExistingTranslations(string $table, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = (new Query())
            ->select(['id', 'language'])
            ->from($table)
            ->where(['id' => $ids])
            ->all($this->db);

        $existing = [];
        foreach ($rows as $row) {
            $existing[(int)$row['id']][(string)$row['language']] = true;
        }

        return $existing;
    }

    private function languageExists(string $language): bool
    {
        $schema = $this->db->getTableSchema($this->languageTable, true);
        if ($schema === null || $schema->getColumn('language_id') === null) {
            return true;
        }

        return (new Query())
            ->from($this->languageTable)
            ->where(['language_id' => $language])
            ->exists($this->db);
    }
}
