<?php

namespace dmstr\knowledgeLibrary\controllers;

use dmstr\knowledgeLibrary\models\ActiveRecord;
use dmstr\knowledgeLibrary\models\Type;
use Yii;

/**
 * Manages the types of knowledge items.
 */
class TypeController extends MasterDataController
{
    protected function modelClass(): string
    {
        return Type::class;
    }

    /**
     * A new type has a validity period but needs no approval, like the
     * create dialog of the design.
     */
    protected function newModel(): ActiveRecord
    {
        $model = parent::newModel();
        $model->has_validity_period = true;
        $model->requires_review = false;

        return $model;
    }

    protected function savedMessage(string $name): string
    {
        return Yii::t('knowledge-library', 'Type "{name}" saved.', ['name' => $name]);
    }

    protected function deletedMessage(string $name): string
    {
        return Yii::t('knowledge-library', 'Type "{name}" deleted.', ['name' => $name]);
    }

    protected function notDeletedMessage(): string
    {
        return Yii::t('knowledge-library', 'The type could not be deleted.');
    }

    protected function notFoundMessage(): string
    {
        return Yii::t('knowledge-library', 'The requested type does not exist.');
    }
}
