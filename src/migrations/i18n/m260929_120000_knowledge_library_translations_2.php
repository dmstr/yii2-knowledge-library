<?php

use dmstr\knowledgeLibrary\migrations\i18n\TranslationMigration;

/**
 * Optional migration providing the German translations of the titles of
 * main documents, see TranslationMigration.
 */
class m260929_120000_knowledge_library_translations_2 extends TranslationMigration
{
    /**
     * Translations by category and language, keyed by source message.
     */
    protected const TRANSLATIONS = [
        'knowledge-library' => [
            'de' => [
                'Add main document' => 'Hauptdokument hinzufügen',
                'Title of the main document' => 'Titel des Hauptdokuments',
            ],
        ],
    ];
}
