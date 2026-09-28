<?php

namespace dmstr\knowledgeLibrary\traits;

use Yii;
use yii\i18n\PhpMessageSource;

/**
 * Registers the `knowledge-library` message category unless the application
 * already configured it.
 */
trait RegistersTranslationsTrait
{
    public function registerTranslations(): void
    {
        if (!isset(Yii::$app->i18n->translations['knowledge-library*'])) {
            Yii::$app->i18n->translations['knowledge-library*'] = [
                'class' => PhpMessageSource::class,
                'basePath' => '@dmstr/knowledgeLibrary/messages',
                'sourceLanguage' => 'en',
            ];
        }
    }
}
