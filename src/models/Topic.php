<?php

namespace dmstr\knowledgeLibrary\models;

use dmstr\knowledgeLibrary\models\query\ItemQuery;
use dmstr\knowledgeLibrary\models\query\TopicQuery;
use Yii;
use yii\db\Query;

/**
 * Topic knowledge items can be assigned to.
 *
 * @property string $id
 * @property string $name
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property string|null $created_by
 * @property string|null $updated_by
 *
 * @property-read Item[] $items
 */
class Topic extends ActiveRecord
{
    /**
     * Number of knowledge items assigned to this topic, archived ones
     * included. Only set when loaded with `TopicQuery::withItemCount()`,
     * else null.
     */
    public ?int $itemCount = null;

    public static function tableName()
    {
        return '{{%knowledge_library_topic}}';
    }

    public static function find(): TopicQuery
    {
        return new TopicQuery(static::class);
    }

    public function rules()
    {
        return [
            ['name', 'trim'],
            ['name', 'required'],
            ['name', 'string', 'max' => 255],
            ['name', 'unique'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => Yii::t('knowledge-library', 'ID'),
            'name' => Yii::t('knowledge-library', 'Name'),
            'created_at' => Yii::t('knowledge-library', 'Created At'),
            'updated_at' => Yii::t('knowledge-library', 'Updated At'),
            'created_by' => Yii::t('knowledge-library', 'Created By'),
            'updated_by' => Yii::t('knowledge-library', 'Updated By'),
        ];
    }

    public function getItems(): ItemQuery
    {
        /** @var ItemQuery $query */
        $query = $this->hasMany(Item::class, ['id' => 'item_id'])
            ->viaTable('{{%knowledge_library_item_topic}}', ['topic_id' => 'id']);

        return $query;
    }

    /**
     * Whether any knowledge item is assigned to this topic.
     */
    public function isInUse(): bool
    {
        return (new Query())
            ->from('{{%knowledge_library_item_topic}}')
            ->where(['topic_id' => $this->id])
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
                Yii::t('knowledge-library', 'This topic is used by knowledge items and cannot be deleted.')
            );

            return false;
        }

        return true;
    }
}
