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
                'Action' => 'Aktion',
                'Active' => 'Aktiv',
                'Actor' => 'Ausgeführt von',
                'All' => 'Alle',
                'An item cannot be related to itself.' => 'Ein Wissensobjekt kann nicht mit sich selbst verknüpft werden.',
                'Approval by second person' => 'Freigabe durch zweite Person',
                'Archived' => 'Archiviert',
                'Attachment' => 'Anhang',
                'Attachments' => 'Anhänge',
                'Automatic' => 'Automatisch',
                'Cancel' => 'Abbrechen',
                'Content' => 'Inhalt',
                'Content Hash' => 'Inhalts-Hash',
                'Corrects Version' => 'Korrigiert Version',
                'Create' => 'Anlegen',
                'Create knowledge object' => 'Wissensobjekt anlegen',
                'Create topic' => 'Thema anlegen',
                'Create type' => 'Typ anlegen',
                'Created At' => 'Erstellt am',
                'Created By' => 'Erstellt von',
                'Delete' => 'Löschen',
                'Delete "{title}" together with all versions, file entries, relations and history?' => '„{title}“ samt allen Versionen, Dateizeilen, Beziehungen und Historie löschen?',
                'Delete topic "{name}"?' => 'Thema „{name}“ löschen?',
                'Delete type "{name}"?' => 'Typ „{name}“ löschen?',
                'Draft' => 'Entwurf',
                'Edit' => 'Bearbeiten',
                'Edit knowledge object' => 'Wissensobjekt bearbeiten',
                'Edit source & origin' => 'Quelle & Herkunft bearbeiten',
                'Edit topic' => 'Thema bearbeiten',
                'Edit type' => 'Typ bearbeiten',
                'Enter "Valid From" in the format DD.MM.YYYY.' => 'Bitte „Gilt ab“ im Format TT.MM.JJJJ eingeben.',
                'Enter "Valid Until" in the format DD.MM.YYYY.' => 'Bitte „Gilt bis“ im Format TT.MM.JJJJ eingeben.',
                'Enter a text or attach a main file.' => 'Bitte Text erfassen oder mindestens ein Hauptdokument hinzufügen.',
                'File Name' => 'Dateiname',
                'From {from} to {until} no version is valid.' => 'Vom {from} bis {until} gilt keine Version.',
                'Full list' => 'Gesamte Liste',
                'Has Validity Period' => 'Hat Gültigkeitszeitraum',
                'Has a validity period (Valid From, Valid Until)' => 'Hat einen Gültigkeitszeitraum (Gilt ab, Gilt bis)',
                'Historical' => 'Historisch',
                'History' => 'Historie',
                'ID' => 'ID',
                'Import Mode' => 'Übernahme',
                'In Force' => 'In Kraft',
                'In Review' => 'In Prüfung',
                'In use, cannot be deleted' => 'In Verwendung, kann nicht gelöscht werden',
                'Item' => 'Wissensobjekt',
                'Kind' => 'Art',
                'Knowledge' => 'Wissen',
                'Knowledge Library' => 'Wissensbibliothek',
                'Knowledge object "{title}" deleted.' => 'Wissensobjekt „{title}“ gelöscht.',
                'Knowledge object created.' => 'Wissensobjekt angelegt.',
                'Knowledge object saved.' => 'Wissensobjekt gespeichert.',
                'Knowledge objects' => 'Wissensobjekte',
                'Last Change' => 'Letzte Änderung',
                'List' => 'Liste',
                'MIME Type' => 'MIME-Typ',
                'Main File' => 'Hauptdokument',
                'Main Files' => 'Hauptdokumente',
                'Manual' => 'Manuell',
                'Name' => 'Name',
                'New version' => 'Neue Version',
                'No' => 'Nein',
                'No entries for these filters.' => 'Keine Einträge für diese Filter.',
                'No knowledge objects yet. Create the first one with "Create knowledge object".' => 'Noch keine Wissensobjekte vorhanden. Legen Sie das erste über „Wissensobjekt anlegen“ an.',
                'No topics yet. Create the first one with "Create topic".' => 'Noch keine Themen vorhanden. Legen Sie das erste über „Thema anlegen“ an.',
                'No types yet. Create the first one with "Create type".' => 'Noch keine Typen vorhanden. Legen Sie den ersten über „Typ anlegen“ an.',
                'No valid version' => 'Kein gültiger Stand',
                'Number' => 'Nummer',
                'Only a draft can be submitted for review.' => 'Nur ein Entwurf kann zur Prüfung eingereicht werden.',
                'Only a draft or a version in review can be published.' => 'Nur ein Entwurf oder eine Version in Prüfung kann veröffentlicht werden.',
                'Path' => 'Pfad',
                'Position' => 'Position',
                'Publication requires approval by a second person' => 'Veröffentlichung braucht Freigabe durch zweite Person',
                'Published' => 'Veröffentlicht',
                'Published At' => 'Veröffentlicht am',
                'Published By' => 'Veröffentlicht von',
                'Reason' => 'Begründung',
                'Relation Type' => 'Art der Beziehung',
                'Relations' => 'Beziehungen',
                'Relations to other knowledge objects will be available in a later release.' => 'Die Beziehungen zu anderen Wissensobjekten folgen in einer späteren Ausbaustufe.',
                'Requires Review' => 'Erfordert Prüfung',
                'Return Note' => 'Rückgabevermerk',
                'Returned At' => 'Zurückgegeben am',
                'Returned By' => 'Zurückgegeben von',
                'Review Message' => 'Nachricht an die Prüfung',
                'Review Requested At' => 'Prüfung angefordert am',
                'Review Requested By' => 'Prüfung angefordert von',
                'Reviewer' => 'Prüfer',
                'Save' => 'Speichern',
                'Size' => 'Größe',
                'Source' => 'Quelle',
                'Source & origin' => 'Quelle & Herkunft',
                'Source & origin saved.' => 'Quelle & Herkunft gespeichert.',
                'Source Item' => 'Ausgangsobjekt',
                'Source Reference' => 'Fundstelle',
                'Source URL' => 'Quell-URL',
                'Source Uploaded At' => 'Quelle hochgeladen am',
                'Source Uploaded By' => 'Quelle hochgeladen von',
                'Status' => 'Status',
                'Storage' => 'Speicher',
                'Storage Item' => 'Speicherobjekt',
                'Summary' => 'Zusammenfassung',
                'Target Item' => 'Zielobjekt',
                'Text' => 'Text',
                'The change history will be available in a later release.' => 'Die Änderungshistorie folgt in einer späteren Ausbaustufe.',
                'The content of the valid version will be shown here in a later release.' => 'Der Inhalt der gültigen Version wird hier in einer späteren Ausbaustufe angezeigt.',
                'The corrected version must belong to the same item.' => 'Die korrigierte Version muss zum selben Wissensobjekt gehören.',
                'The date is before the start of version {number} ({date}). Retroactive changes are only possible with "Correct".' => 'Das Datum liegt vor dem Beginn von Version {number} ({date}). Rückwirkende Änderungen gehen nur über „Korrigieren“.',
                'The file "{file}" could not be stored.' => 'Die Datei „{file}“ konnte nicht gespeichert werden.',
                'The file "{file}" could not be uploaded.' => 'Die Datei „{file}“ konnte nicht hochgeladen werden.',
                'The file "{file}" is larger than {formattedLimit}.' => 'Die Datei „{file}“ ist größer als {formattedLimit}.',
                'The file "{file}" is not an allowed type (allowed: {extensions}).' => 'Die Datei „{file}“ ist kein erlaubter Typ (erlaubt: {extensions}).',
                'The knowledge object could not be deleted.' => 'Das Wissensobjekt konnte nicht gelöscht werden.',
                'The previous version could not be ended.' => 'Die vorherige Version konnte nicht beendet werden.',
                'The requested knowledge object does not exist.' => 'Das angeforderte Wissensobjekt existiert nicht.',
                'The requested topic does not exist.' => 'Das angeforderte Thema existiert nicht.',
                'The requested type does not exist.' => 'Der angeforderte Typ existiert nicht.',
                'The topic could not be deleted.' => 'Das Thema konnte nicht gelöscht werden.',
                'The type cannot be changed once the item has versions.' => 'Der Typ kann nicht mehr geändert werden, sobald das Wissensobjekt Versionen hat.',
                'The type could not be deleted.' => 'Der Typ konnte nicht gelöscht werden.',
                'The type of this item has no validity period, leave the date empty.' => 'Der Typ dieses Wissensobjekts hat keinen Gültigkeitszeitraum, lassen Sie das Datum leer.',
                'This item already has a draft.' => 'Für dieses Wissensobjekt gibt es bereits einen Entwurf.',
                'This item already has a version in review.' => 'Für dieses Wissensobjekt gibt es bereits eine Version in Prüfung.',
                'This relation already exists.' => 'Diese Beziehung besteht bereits.',
                'This topic is used by knowledge items and cannot be deleted.' => 'Dieses Thema wird von Wissensobjekten verwendet und kann nicht gelöscht werden.',
                'This type is used by knowledge items and cannot be deleted.' => 'Dieser Typ wird von Wissensobjekten verwendet und kann nicht gelöscht werden.',
                'This version must be reviewed before it can be published.' => 'Diese Version muss geprüft werden, bevor sie veröffentlicht werden kann.',
                'Title' => 'Titel',
                'Today' => 'Heute',
                'Topic "{name}" deleted.' => 'Thema „{name}“ gelöscht.',
                'Topic "{name}" saved.' => 'Thema „{name}“ gespeichert.',
                'Topics' => 'Themen',
                'Type' => 'Typ',
                'Type "{name}" deleted.' => 'Typ „{name}“ gelöscht.',
                'Type "{name}" saved.' => 'Typ „{name}“ gespeichert.',
                'Types' => 'Typen',
                'Upcoming' => 'Bevorstehend',
                'Updated At' => 'Aktualisiert am',
                'Updated By' => 'Aktualisiert von',
                'Valid From' => 'Gilt ab',
                'Valid From is required for review and publication.' => '„Gilt ab“ ist für Prüfung und Veröffentlichung erforderlich.',
                'Valid Until' => 'Gilt bis',
                'Valid Until must not be before Valid From.' => '„Gilt bis“ liegt vor „Gilt ab“.',
                'Validity period' => 'Gültigkeitszeitraum',
                'Version' => 'Version',
                'Version {number}' => 'Version {number}',
                'Version {number} ends on {date}.' => 'Version {number} endet am {date}.',
                'Version {number} is valid from publication, open-ended.' => 'Version {number} gilt ab der Veröffentlichung, ohne Enddatum.',
                'Version {number} is valid from {from} until {until}.' => 'Version {number} gilt ab {from} bis {until}.',
                'Version {number} is valid from {from}, open-ended.' => 'Version {number} gilt ab {from}, unbefristet.',
                'Version {number} replaces version {predecessor} upon publication.' => 'Version {number} ersetzt Version {predecessor} ab der Veröffentlichung.',
                'Versions' => 'Versionen',
                'Versions will be available in a later release.' => 'Die Versionen folgen in einer späteren Ausbaustufe.',
                'Withdraw Reason' => 'Grund der Zurückziehung',
                'Withdrawn' => 'Zurückgezogen',
                'Withdrawn At' => 'Zurückgezogen am',
                'Withdrawn By' => 'Zurückgezogen von',
                'Yes' => 'Ja',
                'empty' => 'leer',
                'is based on' => 'basiert auf',
                'is replaced by' => 'wird ersetzt durch',
                'is supplemented by' => 'wird ergänzt durch',
                'is the basis for' => 'ist Grundlage für',
                'open-ended' => 'unbefristet',
                'replaces' => 'ersetzt',
                'since {date}' => 'seit {date}',
                'supplements' => 'ergänzt',
                '{count, plural, =1{# attachment} other{# attachments}}' => '{count, plural, =1{# Anhang} other{# Anhänge}}',
                '{count, plural, =1{# main document} other{# main documents}}' => '{count, plural, =1{# Hauptdokument} other{# Hauptdokumente}}',
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
