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
                '(optional)' => '(optional)',
                '(optional, several possible)' => '(optional, mehrere möglich)',
                '(required for returning)' => '(Pflicht bei Rückgabe)',
                'A correction keeps the validity period of the corrected version.' => 'Eine Korrektur behält den Gültigkeitszeitraum der korrigierten Version.',
                'A correction of this version is in progress.' => 'Für diese Version ist bereits eine Korrektur in Arbeit.',
                'A correction withdraws this version when it is published.' => 'Eine Korrektur zieht diese Version erst bei ihrer Veröffentlichung zurück.',
                'Action' => 'Aktion',
                'Actions' => 'Aktionen',
                'Active' => 'Aktiv',
                'Actor' => 'Ausgeführt von',
                'Add' => 'Hinzufügen',
                'Add attachment' => 'Anhang hinzufügen',
                'Add main documents' => 'Hauptdokumente hinzufügen',
                'All' => 'Alle',
                'Allowed types: {extensions}. At most {size} per file.' => 'Erlaubte Typen: {extensions}. Höchstens {size} je Datei.',
                'An item cannot be related to itself.' => 'Ein Wissensobjekt kann nicht mit sich selbst verknüpft werden.',
                'Approval by a second person required.' => 'Freigabe durch zweite Person erforderlich.',
                'Approval by second person' => 'Freigabe durch zweite Person',
                'Approve and publish' => 'Freigeben und veröffentlichen',
                'Archived' => 'Archiviert',
                'Attachment' => 'Anhang',
                'Attachments' => 'Anhänge',
                'Automatic' => 'Automatisch',
                'Awaiting my approval ({count})' => 'Wartet auf meine Freigabe ({count})',
                'Back' => 'Zurück',
                'Cancel' => 'Abbrechen',
                'Change reviewer' => 'Prüfer ändern',
                'Change reviewing person' => 'Prüfende Person ändern',
                'Changed compared to version {number}. Markdown is supported.' => 'Geändert gegenüber Version {number}. Markdown wird unterstützt.',
                'Changes the answers to questions about {from} to {until}.' => 'Ändert die Antworten auf Fragen zu {from} bis {until}.',
                'Changes the answers to questions about {year}.' => 'Ändert die Antworten auf Fragen zu {year}.',
                'Check' => 'Prüfen',
                'Consequences' => 'Folgen',
                'Content' => 'Inhalt',
                'Content Hash' => 'Inhalts-Hash',
                'Continue draft' => 'Entwurf fortsetzen',
                'Corrects Version' => 'Korrigiert Version',
                'Create' => 'Anlegen',
                'Create as separate knowledge object' => 'Als eigenes Wissensobjekt anlegen',
                'Create first version' => 'Erste Version anlegen',
                'Create knowledge object' => 'Wissensobjekt anlegen',
                'Create topic' => 'Thema anlegen',
                'Create type' => 'Typ anlegen',
                'Create version {number}' => 'Version {number} anlegen',
                'Created At' => 'Erstellt am',
                'Created By' => 'Erstellt von',
                'Current: {name}' => 'Aktuell: {name}',
                'Delete' => 'Löschen',
                'Delete "{title}" together with all versions, file entries, relations and history?' => '„{title}“ samt allen Versionen, Dateizeilen, Beziehungen und Historie löschen?',
                'Delete topic "{name}"?' => 'Thema „{name}“ löschen?',
                'Delete type "{name}"?' => 'Typ „{name}“ löschen?',
                'Details' => 'Details',
                'Discard draft' => 'Entwurf verwerfen',
                'Discard the draft of version {number}?' => 'Entwurf von Version {number} verwerfen?',
                'Download' => 'Herunterladen',
                'Draft' => 'Entwurf',
                'Draft discarded.' => 'Entwurf verworfen.',
                'Draft of version {number} discarded' => 'Entwurf von Version {number} verworfen',
                'Draft saved.' => 'Entwurf gespeichert.',
                'Edit' => 'Bearbeiten',
                'Edit knowledge object' => 'Wissensobjekt bearbeiten',
                'Edit source & origin' => 'Quelle & Herkunft bearbeiten',
                'Edit topic' => 'Thema bearbeiten',
                'Edit type' => 'Typ bearbeiten',
                'Enter "Valid From" in the format DD.MM.YYYY.' => 'Bitte „Gilt ab“ im Format TT.MM.JJJJ eingeben.',
                'Enter "Valid Until" in the format DD.MM.YYYY.' => 'Bitte „Gilt bis“ im Format TT.MM.JJJJ eingeben.',
                'Enter a note for the author.' => 'Bitte geben Sie eine Anmerkung für die einreichende Person ein.',
                'Enter a reason for the withdrawal.' => 'Bitte geben Sie eine Begründung für das Zurückziehen ein.',
                'Enter a text or attach a main file.' => 'Bitte Text erfassen oder mindestens ein Hauptdokument hinzufügen.',
                'Enter the content as free text' => 'Inhalt als Freitext erfassen',
                'File Name' => 'Dateiname',
                'Files' => 'Dateien',
                'From {from} to {until} no version is valid.' => 'Vom {from} bis {until} gilt keine Version.',
                'Full list' => 'Gesamte Liste',
                'Hand over' => 'Übergeben',
                'Has Validity Period' => 'Hat Gültigkeitszeitraum',
                'Has a validity period (Valid From, Valid Until)' => 'Hat einen Gültigkeitszeitraum (Gilt ab, Gilt bis)',
                'Historical' => 'Historisch',
                'History' => 'Historie',
                'ID' => 'ID',
                'Import Mode' => 'Übernahme',
                'In Force' => 'In Kraft',
                'In Review' => 'In Prüfung',
                'In use, cannot be deleted' => 'In Verwendung, kann nicht gelöscht werden',
                'Incoming' => 'Eingehend',
                'Item' => 'Wissensobjekt',
                'Keep' => 'Behalten',
                'Kind' => 'Art',
                'Knowledge' => 'Wissen',
                'Knowledge Library' => 'Wissensbibliothek',
                'Knowledge object "{title}" deleted.' => 'Wissensobjekt „{title}“ gelöscht.',
                'Knowledge object created' => 'Wissensobjekt angelegt',
                'Knowledge object created.' => 'Wissensobjekt angelegt.',
                'Knowledge object saved.' => 'Wissensobjekt gespeichert.',
                'Knowledge objects' => 'Wissensobjekte',
                'Last Change' => 'Letzte Änderung',
                'List' => 'Liste',
                'MIME Type' => 'MIME-Typ',
                'Main File' => 'Hauptdokument',
                'Main Files' => 'Hauptdokumente',
                'Manual' => 'Manuell',
                'Markdown is supported.' => 'Markdown wird unterstützt.',
                'Master data changed' => 'Stammdaten geändert',
                'Message' => 'Nachricht',
                'Message from {name}' => 'Nachricht von {name}',
                'Name' => 'Name',
                'New reviewing person' => 'Neue prüfende Person',
                'New version' => 'Neue Version',
                'Next' => 'Weiter',
                'No' => 'Nein',
                'No attachments.' => 'Keine Anhänge.',
                'No entries for these filters.' => 'Keine Einträge für diese Filter.',
                'No entries yet.' => 'Noch keine Einträge.',
                'No files.' => 'Keine Dateien.',
                'No knowledge objects yet. Create the first one with "Create knowledge object".' => 'Noch keine Wissensobjekte vorhanden. Legen Sie das erste über „Wissensobjekt anlegen“ an.',
                'No main documents.' => 'Keine Hauptdokumente.',
                'No relations.' => 'Keine Beziehungen.',
                'No text available.' => 'Kein Text hinterlegt.',
                'No topics yet. Create the first one with "Create topic".' => 'Noch keine Themen vorhanden. Legen Sie das erste über „Thema anlegen“ an.',
                'No types yet. Create the first one with "Create type".' => 'Noch keine Typen vorhanden. Legen Sie den ersten über „Typ anlegen“ an.',
                'No valid version' => 'Kein gültiger Stand',
                'No versions yet.' => 'Noch keine Versionen.',
                'No.' => 'Nr.',
                'None.' => 'Keine.',
                'Note' => 'Anmerkung',
                'Number' => 'Nummer',
                'Only a draft can be submitted for review.' => 'Nur ein Entwurf kann zur Prüfung eingereicht werden.',
                'Only a draft or a version in review can be published.' => 'Nur ein Entwurf oder eine Version in Prüfung kann veröffentlicht werden.',
                'Only a published version can be corrected.' => 'Nur eine veröffentlichte Version kann korrigiert werden.',
                'Only a version in force or an upcoming version can be withdrawn.' => 'Nur eine Version, die in Kraft oder bevorstehend ist, kann zurückgezogen werden.',
                'Only a version in review can be approved.' => 'Nur eine Version in Prüfung kann freigegeben werden.',
                'Only a version in review can be returned.' => 'Nur eine Version in Prüfung kann zurückgegeben werden.',
                'Only the reviewer of a version in review can be changed.' => 'Die prüfende Person kann nur bei einer Version in Prüfung geändert werden.',
                'Only the version in force can be corrected, as this type has no validity period.' => 'Nur die Version in Kraft kann korrigiert werden, da dieser Typ keinen Gültigkeitszeitraum hat.',
                'Outgoing' => 'Ausgehend',
                'Path' => 'Pfad',
                'Position' => 'Position',
                'Publication requires approval by a second person' => 'Veröffentlichung braucht Freigabe durch zweite Person',
                'Publish' => 'Veröffentlichen',
                'Publish version {number}?' => 'Version {number} veröffentlichen?',
                'Published' => 'Veröffentlicht',
                'Published At' => 'Veröffentlicht am',
                'Published By' => 'Veröffentlicht von',
                'Reason' => 'Begründung',
                'Relation Type' => 'Art der Beziehung',
                'Relation added.' => 'Beziehung hinzugefügt.',
                'Relation added: {relation} {target}' => 'Beziehung hinzugefügt: {relation} {target}',
                'Relation removed.' => 'Beziehung entfernt.',
                'Relation removed: {relation} {target}' => 'Beziehung entfernt: {relation} {target}',
                'Relations' => 'Beziehungen',
                'Remove' => 'Entfernen',
                'Requires Review' => 'Erfordert Prüfung',
                'Restored' => 'Wiederhergestellt',
                'Return' => 'Zurückgeben',
                'Return Note' => 'Rückgabevermerk',
                'Returned At' => 'Zurückgegeben am',
                'Returned By' => 'Zurückgegeben von',
                'Returned by {name} on {date}' => 'Von {name} zurückgegeben am {date}',
                'Review' => 'Prüfen',
                'Review Message' => 'Nachricht an die Prüfung',
                'Review Requested At' => 'Prüfung angefordert am',
                'Review Requested By' => 'Prüfung angefordert von',
                'Review handed over to {name}.' => 'Prüfung an {name} übergeben.',
                'Review of version {number} handed over to {reviewer} (previously {previous})' => 'Prüfung von Version {number} an {reviewer} übergeben (vorher {previous})',
                'Review version {number}' => 'Version {number} prüfen',
                'Reviewer' => 'Prüfer',
                'Reviewing person' => 'Prüfende Person',
                'Save' => 'Speichern',
                'Save as draft' => 'Als Entwurf speichern',
                'Select a person' => 'Person wählen',
                'Select a reviewer.' => 'Bitte wählen Sie eine prüfende Person.',
                'Select knowledge object' => 'Wissensobjekt wählen',
                'Select what applies instead.' => 'Bitte wählen Sie, was stattdessen gilt.',
                'Sent for review by {name}, without message.' => 'Zur Prüfung gesendet von {name}, ohne Nachricht.',
                'Show' => 'Anzeigen',
                'Size' => 'Größe',
                'Source' => 'Quelle',
                'Source & origin' => 'Quelle & Herkunft',
                'Source & origin changed' => 'Quelle & Herkunft geändert',
                'Source & origin saved.' => 'Quelle & Herkunft gespeichert.',
                'Source Item' => 'Ausgangsobjekt',
                'Source Reference' => 'Fundstelle',
                'Source URL' => 'Quell-URL',
                'Source Uploaded At' => 'Quelle hochgeladen am',
                'Source Uploaded By' => 'Quelle hochgeladen von',
                'Status' => 'Status',
                'Storage' => 'Speicher',
                'Storage Item' => 'Speicherobjekt',
                'Submit for approval' => 'Zur Freigabe senden',
                'Summary' => 'Zusammenfassung',
                'Target Item' => 'Zielobjekt',
                'Text' => 'Text',
                'The corrected version could not be withdrawn.' => 'Die korrigierte Version konnte nicht zurückgezogen werden.',
                'The corrected version is no longer published.' => 'Die korrigierte Version ist nicht mehr veröffentlicht.',
                'The corrected version must belong to the same item.' => 'Die korrigierte Version muss zum selben Wissensobjekt gehören.',
                'The date is before the start of version {number} ({date}). Retroactive changes are only possible with "Correct".' => 'Das Datum liegt vor dem Beginn von Version {number} ({date}). Rückwirkende Änderungen gehen nur über „Korrigieren“.',
                'The draft could not be discarded.' => 'Der Entwurf konnte nicht verworfen werden.',
                'The file "{file}" could not be stored.' => 'Die Datei „{file}“ konnte nicht gespeichert werden.',
                'The file "{file}" could not be uploaded.' => 'Die Datei „{file}“ konnte nicht hochgeladen werden.',
                'The file "{file}" is larger than {formattedLimit}.' => 'Die Datei „{file}“ ist größer als {formattedLimit}.',
                'The file "{file}" is not an allowed type (allowed: {extensions}).' => 'Die Datei „{file}“ ist kein erlaubter Typ (erlaubt: {extensions}).',
                'The knowledge object could not be deleted.' => 'Das Wissensobjekt konnte nicht gelöscht werden.',
                'The knowledge object is archived.' => 'Das Wissensobjekt ist archiviert.',
                'The knowledge object is not archived.' => 'Das Wissensobjekt ist nicht archiviert.',
                'The person who submitted the version cannot review it.' => 'Wer die Version eingereicht hat, kann sie nicht prüfen.',
                'The previous version could not be ended.' => 'Die vorherige Version konnte nicht beendet werden.',
                'The previous version could not be extended.' => 'Die vorherige Version konnte nicht verlängert werden.',
                'The previous version has changed meanwhile.' => 'Die vorherige Version hat sich zwischenzeitlich geändert.',
                'The relation could not be removed.' => 'Die Beziehung konnte nicht entfernt werden.',
                'The requested draft does not exist.' => 'Der angeforderte Entwurf existiert nicht.',
                'The requested file does not exist.' => 'Die angeforderte Datei existiert nicht.',
                'The requested knowledge object does not exist.' => 'Das angeforderte Wissensobjekt existiert nicht.',
                'The requested relation does not exist.' => 'Die angeforderte Beziehung existiert nicht.',
                'The requested topic does not exist.' => 'Das angeforderte Thema existiert nicht.',
                'The requested type does not exist.' => 'Der angeforderte Typ existiert nicht.',
                'The requested version in review does not exist.' => 'Die angeforderte Version in Prüfung existiert nicht.',
                'The selected person already reviews this version.' => 'Die gewählte Person prüft diese Version bereits.',
                'The selected person cannot review this version.' => 'Die gewählte Person kann diese Version nicht prüfen.',
                'The topic could not be deleted.' => 'Das Thema konnte nicht gelöscht werden.',
                'The type cannot be changed once the item has versions.' => 'Der Typ kann nicht mehr geändert werden, sobald das Wissensobjekt Versionen hat.',
                'The type could not be deleted.' => 'Der Typ konnte nicht gelöscht werden.',
                'The type of this item has no validity period, leave the date empty.' => 'Der Typ dieses Wissensobjekts hat keinen Gültigkeitszeitraum, lassen Sie das Datum leer.',
                'There is no previous version.' => 'Keine Vorversion vorhanden.',
                'This file is already attached to "{title}".' => 'Diese Datei ist bereits an „{title}“ angehängt.',
                'This item already has a draft.' => 'Für dieses Wissensobjekt gibt es bereits einen Entwurf.',
                'This item already has a version in review.' => 'Für dieses Wissensobjekt gibt es bereits eine Version in Prüfung.',
                'This knowledge object has no version yet. Use "Create first version" to enter the content.' => 'Dieses Wissensobjekt hat noch keine Version. Über „Erste Version anlegen“ erfassen Sie den Inhalt.',
                'This relation already exists.' => 'Diese Beziehung besteht bereits.',
                'This topic is used by knowledge items and cannot be deleted.' => 'Dieses Thema wird von Wissensobjekten verwendet und kann nicht gelöscht werden.',
                'This type is used by knowledge items and cannot be deleted.' => 'Dieser Typ wird von Wissensobjekten verwendet und kann nicht gelöscht werden.',
                'This version can be published without review.' => 'Diese Version kann ohne Prüfung veröffentlicht werden.',
                'This version must be reviewed before it can be published.' => 'Diese Version muss geprüft werden, bevor sie veröffentlicht werden kann.',
                'Title' => 'Titel',
                'Title of the attachment' => 'Titel des Anhangs',
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
                'Valid from publication, open-ended.' => 'Gilt ab Veröffentlichung, ohne Enddatum.',
                'Validity' => 'Gültigkeit',
                'Validity period' => 'Gültigkeitszeitraum',
                'Version' => 'Version',
                'Version {number}' => 'Version {number}',
                'Version {number} approved and published.' => 'Version {number} freigegeben und veröffentlicht.',
                'Version {number} approved by {actor}' => 'Version {number} freigegeben durch {actor}',
                'Version {number} corrected by version {correction}' => 'Version {number} korrigiert durch Version {correction}',
                'Version {number} ends on {date}.' => 'Version {number} endet am {date}.',
                'Version {number} is awaiting approval by {name}.' => 'Version {number} wartet auf Freigabe durch {name}.',
                'Version {number} is valid from publication, open-ended.' => 'Version {number} gilt ab der Veröffentlichung, ohne Enddatum.',
                'Version {number} is valid from {from} until {until}.' => 'Version {number} gilt ab {from} bis {until}.',
                'Version {number} is valid from {from}, open-ended.' => 'Version {number} gilt ab {from}, unbefristet.',
                'Version {number} is withdrawn.' => 'Version {number} wird zurückgezogen.',
                'Version {number} published' => 'Version {number} veröffentlicht',
                'Version {number} published, valid from {date}' => 'Version {number} veröffentlicht, gilt ab {date}',
                'Version {number} published.' => 'Version {number} veröffentlicht.',
                'Version {number} remains valid, as this type has no validity period.' => 'Version {number} gilt weiter, da dieser Typ keinen Gültigkeitszeitraum hat.',
                'Version {number} replaces version {predecessor} upon publication.' => 'Version {number} ersetzt Version {predecessor} ab der Veröffentlichung.',
                'Version {number} returned by {actor}' => 'Version {number} von {actor} zurückgegeben',
                'Version {number} returned to {name}.' => 'Version {number} an {name} zurückgegeben.',
                'Version {number} saved as draft' => 'Version {number} als Entwurf gespeichert',
                'Version {number} sent to {reviewer} for approval' => 'Version {number} zur Freigabe an {reviewer} gesendet',
                'Version {number} submitted for approval.' => 'Version {number} zur Freigabe gesendet.',
                'Version {number} withdrawn, no valid version' => 'Version {number} zurückgezogen, kein gültiger Stand',
                'Version {number} withdrawn, version {successor} remains valid' => 'Version {number} zurückgezogen, Version {successor} gilt weiter',
                'Version {number}, {status}' => 'Version {number}, {status}',
                'Versions' => 'Versionen',
                'View' => 'Ansehen',
                'What' => 'Was',
                'When' => 'Wann',
                'Who' => 'Wer',
                'Withdraw Reason' => 'Grund der Zurückziehung',
                'Withdrawn' => 'Zurückgezogen',
                'Withdrawn At' => 'Zurückgezogen am',
                'Withdrawn By' => 'Zurückgezogen von',
                'Yes' => 'Ja',
                'You are not the reviewer of this version.' => 'Sie sind nicht die prüfende Person dieser Version.',
                'You cannot review your own version.' => 'Sie können Ihre eigene Version nicht prüfen.',
                'becomes the version "in force" from {date}' => 'wird ab {date} die Version „in Kraft“',
                'changed' => 'geändert',
                'empty' => 'leer',
                'ends version {number} on {date}' => 'beendet Version {number} am {date}',
                'is based on' => 'basiert auf',
                'is in force immediately' => 'ist sofort in Kraft',
                'is replaced by' => 'wird ersetzt durch',
                'is supplemented by' => 'wird ergänzt durch',
                'is the basis for' => 'ist Grundlage für',
                'no text' => 'keiner',
                'none' => 'keine',
                'open-ended' => 'unbefristet',
                'replaces' => 'ersetzt',
                'since {date}' => 'seit {date}',
                'supplements' => 'ergänzt',
                'unchanged' => 'unverändert',
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
