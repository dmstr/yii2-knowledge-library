<?php
// file generated with AI assistance: Claude Code - 2026-10-07 22:10:00 UTC

use dmstr\knowledgeLibrary\migrations\i18n\TranslationMigration;

/**
 * Optional migration providing the German translation of the validity
 * period lock of types, see TranslationMigration.
 */
class m261007_120000_knowledge_library_translations_3 extends TranslationMigration
{
    /**
     * Translations by category and language, keyed by source message.
     */
    protected const TRANSLATIONS = [
        'knowledge-library' => [
            'de' => [
                'The validity period cannot be changed once items of this type have versions.' => 'Der Gültigkeitszeitraum kann nicht mehr geändert werden, sobald Wissensobjekte dieses Typs Versionen haben.',
            ],
        ],
    ];
}
