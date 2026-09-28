<?php

namespace dmstr\knowledgeLibrary\models;

use dmstr\knowledgeLibrary\users\DefaultUserProvider;
use eluhr\uuidAttributeBehavior\behaviors\UuidAttributeBehavior;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;

/**
 * Base class of all knowledge library models.
 *
 * Attaches the UUID, timestamp and blameable behaviors depending on the
 * columns the table actually has.
 */
abstract class ActiveRecord extends \yii\db\ActiveRecord
{
    public function behaviors()
    {
        $behaviors = parent::behaviors();

        if (static::primaryKey() === ['id'] && $this->hasAttribute('id')) {
            $behaviors['uuid'] = [
                'class' => UuidAttributeBehavior::class,
                'uuidAttribute' => 'id',
            ];
        }

        if ($this->hasAttribute('created_at')) {
            $behaviors['timestamp'] = [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => $this->hasAttribute('updated_at') ? 'updated_at' : false,
                'value' => static fn () => date('Y-m-d H:i:s'),
            ];
        }

        if ($this->hasAttribute('created_by')) {
            $behaviors['blameable'] = [
                'class' => BlameableBehavior::class,
                'createdByAttribute' => 'created_by',
                'updatedByAttribute' => $this->hasAttribute('updated_by') ? 'updated_by' : false,
                'value' => static fn () => DefaultUserProvider::resolve()->getCurrentUserReference(),
            ];
        }

        return $behaviors;
    }
}
