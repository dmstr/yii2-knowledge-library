<?php

namespace dmstr\knowledgeLibrary\controllers;

use dmstr\knowledgeLibrary\models\Topic;
use Yii;

/**
 * Manages the topics knowledge items are assigned to.
 */
class TopicController extends MasterDataController
{
    protected function modelClass(): string
    {
        return Topic::class;
    }

    protected function savedMessage(string $name): string
    {
        return Yii::t('knowledge-library', 'Topic "{name}" saved.', ['name' => $name]);
    }

    protected function deletedMessage(string $name): string
    {
        return Yii::t('knowledge-library', 'Topic "{name}" deleted.', ['name' => $name]);
    }

    protected function notDeletedMessage(): string
    {
        return Yii::t('knowledge-library', 'The topic could not be deleted.');
    }

    protected function notFoundMessage(): string
    {
        return Yii::t('knowledge-library', 'The requested topic does not exist.');
    }
}
