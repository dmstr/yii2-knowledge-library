<?php

namespace dmstr\knowledgeLibrary\models;

use dmstr\knowledgeLibrary\models\query\TypeQuery;
use Yii;
use yii\db\Query;

/**
 * Type of a knowledge item, e.g. guideline or instruction.
 *
 * @property string $id
 * @property string $name
 * @property bool $has_validity_period
 * @property bool $requires_review
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property string|null $created_by
 * @property string|null $updated_by
 */
class Type extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%knowledge_library_type}}';
    }

    public static function find(): TypeQuery
    {
        return new TypeQuery(static::class);
    }

    public function rules()
    {
        return [
            ['name', 'trim'],
            ['name', 'required'],
            ['name', 'string', 'max' => 255],
            ['name', 'unique'],
            [['has_validity_period', 'requires_review'], 'default', 'value' => true],
            [['has_validity_period', 'requires_review'], 'boolean'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => Yii::t('knowledge-library', 'ID'),
            'name' => Yii::t('knowledge-library', 'Name'),
            'has_validity_period' => Yii::t('knowledge-library', 'Has Validity Period'),
            'requires_review' => Yii::t('knowledge-library', 'Requires Review'),
            'created_at' => Yii::t('knowledge-library', 'Created At'),
            'updated_at' => Yii::t('knowledge-library', 'Updated At'),
            'created_by' => Yii::t('knowledge-library', 'Created By'),
            'updated_by' => Yii::t('knowledge-library', 'Updated By'),
        ];
    }

    /**
     * Whether any knowledge item uses this type.
     */
    public function isInUse(): bool
    {
        return (new Query())
            ->from('{{%knowledge_library_item}}')
            ->where(['type_id' => $this->id])
            ->exists(static::getDb());
    }

    public function beforeDelete()
    {
        if (!parent::beforeDelete()) {
            return false;
        }

        if ($this->isInUse()) {
            $this->addError(
                'name',
                Yii::t('knowledge-library', 'This type is used by knowledge items and cannot be deleted.')
            );

            return false;
        }

        return true;
    }
}
