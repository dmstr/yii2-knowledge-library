<?php

use yii\base\InvalidConfigException;
use yii\db\Migration;
use yii\db\Query;
use yii\i18n\DbMessageSource;

/**
 * Optional migration providing the German translations of the package.
 *
 * The messages of the category `knowledge-library` are served by the message
 * source the application configured for that category. If this is a
 * `yii\i18n\DbMessageSource`, this migration writes the package's source
 * messages and their translations into its tables. Otherwise it does nothing.
 *
 * Existing translations are never overwritten, so changes made by editors
 * are kept. Running `up` again only adds missing rows.
 *
 * Assumption: the tables of the message source live in the database of this
 * migration (`$this->db`); a separate `db` of the message source is ignored.
 */
class m260928_100200_knowledge_library_translations extends Migration
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
     */
    private const TRANSLATIONS = [
        'knowledge-library' => [
            'de' => [
                'A new version must start after {date}, the start of the latest published version. Use a correction to change a published version.' => 'Eine neue Version muss nach dem {date} beginnen, dem Beginn der zuletzt veröffentlichten Version. Um eine veröffentlichte Version zu ändern, verwenden Sie eine Korrektur.',
                'Action' => 'Aktion',
                'Actor' => 'Ausgeführt von',
                'An item cannot be related to itself.' => 'Ein Wissenseintrag kann nicht mit sich selbst verknüpft werden.',
                'Archived' => 'Archiviert',
                'Attachment' => 'Anhang',
                'Automatic' => 'Automatisch',
                'Content' => 'Inhalt',
                'Corrects Version' => 'Korrigiert Version',
                'Created At' => 'Erstellt am',
                'Created By' => 'Erstellt von',
                'Draft' => 'Entwurf',
                'Enter a text or attach a main file.' => 'Geben Sie einen Text ein oder hängen Sie eine Hauptdatei an.',
                'File Name' => 'Dateiname',
                'Has Validity Period' => 'Hat Gültigkeitszeitraum',
                'Historical' => 'Historisch',
                'ID' => 'ID',
                'Import Mode' => 'Importmodus',
                'In Force' => 'In Kraft',
                'In Review' => 'In Prüfung',
                'Item' => 'Wissenseintrag',
                'Kind' => 'Art',
                'Knowledge' => 'Wissen',
                'Knowledge Library' => 'Wissensbibliothek',
                'MIME Type' => 'MIME-Typ',
                'Main File' => 'Hauptdatei',
                'Manual' => 'Manuell',
                'Name' => 'Name',
                'Number' => 'Nummer',
                'Only a draft can be submitted for review.' => 'Nur ein Entwurf kann zur Prüfung eingereicht werden.',
                'Only a draft or a version in review can be published.' => 'Nur ein Entwurf oder eine Version in Prüfung kann veröffentlicht werden.',
                'Path' => 'Pfad',
                'Position' => 'Position',
                'Published' => 'Veröffentlicht',
                'Published At' => 'Veröffentlicht am',
                'Published By' => 'Veröffentlicht von',
                'Reason' => 'Begründung',
                'Relation Type' => 'Art der Beziehung',
                'Requires Review' => 'Erfordert Prüfung',
                'Return Note' => 'Rückgabevermerk',
                'Returned At' => 'Zurückgegeben am',
                'Returned By' => 'Zurückgegeben von',
                'Review Message' => 'Nachricht an die Prüfung',
                'Review Requested At' => 'Prüfung angefordert am',
                'Review Requested By' => 'Prüfung angefordert von',
                'Reviewer' => 'Prüfer',
                'Size' => 'Größe',
                'Source' => 'Quelle',
                'Source Item' => 'Ausgangseintrag',
                'Source Reference' => 'Quellenangabe',
                'Source URL' => 'Quell-URL',
                'Source Uploaded At' => 'Quelle hochgeladen am',
                'Source Uploaded By' => 'Quelle hochgeladen von',
                'Status' => 'Status',
                'Storage' => 'Speicher',
                'Storage Item' => 'Speicherobjekt',
                'Summary' => 'Zusammenfassung',
                'Target Item' => 'Zieleintrag',
                'The corrected version must belong to the same item.' => 'Die korrigierte Version muss zum selben Wissenseintrag gehören.',
                'The previous version could not be ended.' => 'Die vorherige Version konnte nicht beendet werden.',
                'The type of this item has no validity period, leave the date empty.' => 'Der Typ dieses Wissenseintrags hat keinen Gültigkeitszeitraum, lassen Sie das Datum leer.',
                'This item already has a draft.' => 'Für diesen Wissenseintrag gibt es bereits einen Entwurf.',
                'This item already has a version in review.' => 'Für diesen Wissenseintrag gibt es bereits eine Version in Prüfung.',
                'This relation already exists.' => 'Diese Beziehung besteht bereits.',
                'This topic is used by knowledge items and cannot be deleted.' => 'Dieses Thema wird von Wissenseinträgen verwendet und kann nicht gelöscht werden.',
                'This type is used by knowledge items and cannot be deleted.' => 'Dieser Typ wird von Wissenseinträgen verwendet und kann nicht gelöscht werden.',
                'This version must be reviewed before it can be published.' => 'Diese Version muss geprüft werden, bevor sie veröffentlicht werden kann.',
                'Title' => 'Titel',
                'Topics' => 'Themen',
                'Type' => 'Typ',
                'Upcoming' => 'Zukünftig',
                'Updated At' => 'Aktualisiert am',
                'Updated By' => 'Aktualisiert von',
                'Valid From' => 'Gültig ab',
                'Valid From is required for review and publication.' => '„Gültig ab“ ist für Prüfung und Veröffentlichung erforderlich.',
                'Valid Until' => 'Gültig bis',
                'Valid Until must not be before Valid From.' => '„Gültig bis“ darf nicht vor „Gültig ab“ liegen.',
                'Version' => 'Version',
                'Withdraw Reason' => 'Grund der Zurückziehung',
                'Withdrawn' => 'Zurückgezogen',
                'Withdrawn At' => 'Zurückgezogen am',
                'Withdrawn By' => 'Zurückgezogen von',
                'is based on' => 'basiert auf',
                'is replaced by' => 'wird ersetzt durch',
                'is supplemented by' => 'wird ergänzt durch',
                'is the basis for' => 'ist Grundlage für',
                'replaces' => 'ersetzt',
                'supplements' => 'ergänzt',
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
