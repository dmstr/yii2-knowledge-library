<?php

namespace dmstr\knowledgeLibrary\models;

use dmstr\knowledgeLibrary\models\query\ItemQuery;
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
 *
 * @property-read Item[] $items
 */
class Type extends ActiveRecord
{
    /**
     * Number of knowledge items of this type, archived ones included.
     * Only set when loaded with `TypeQuery::withItemCount()`, else null.
     */
    public ?int $itemCount = null;

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
            ['has_validity_period', 'validateValidityPeriodLock'],
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
     * Rejects a change of the validity period once items of the type have
     * versions: which version of an item is valid depends on it (dates or
     * highest number, see VersionQuery::validAt()), so a change would
     * silently alter what readers see and leave versions with or without
     * dates the type no longer expects.
     */
    public function validateValidityPeriodLock(string $attribute): void
    {
        if ($this->getIsNewRecord() || !$this->isAttributeChanged($attribute, false)) {
            return;
        }

        if ($this->hasVersions()) {
            $this->addError($attribute, static::validityPeriodLockedMessage());
        }
    }

    /**
     * Message for a change of the validity period of a locked type, see
     * isValidityPeriodLocked().
     */
    public static function validityPeriodLockedMessage(): string
    {
        return Yii::t(
            'knowledge-library',
            'The validity period cannot be changed once items of this type have versions.'
        );
    }

    /**
     * Whether the validity period can no longer be changed because items of
     * the type have versions.
     */
    public function isValidityPeriodLocked(): bool
    {
        return !$this->getIsNewRecord() && $this->hasVersions();
    }

    /**
     * Whether any item of this type has a version (of any status).
     */
    public function hasVersions(): bool
    {
        return Version::find()
            ->innerJoin(
                ['kl_type_item' => Item::tableName()],
                '[[kl_type_item.id]] = ' . Version::tableName() . '.[[item_id]]'
            )
            ->andWhere(['kl_type_item.type_id' => $this->id])
            ->exists();
    }

    public function getItems(): ItemQuery
    {
        /** @var ItemQuery $query */
        $query = $this->hasMany(Item::class, ['type_id' => 'id']);

        return $query;
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
