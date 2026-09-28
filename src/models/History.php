<?php

namespace dmstr\knowledgeLibrary\models;

use dmstr\knowledgeLibrary\users\DefaultUserProvider;
use Yii;
use yii\base\Exception;
use yii\db\ActiveQuery;

/**
 * Change history entry of a knowledge item, optionally for one version.
 *
 * @property string $id
 * @property string $item_id
 * @property string|null $version_id
 * @property string $action
 * @property string|null $reason
 * @property string|null $actor_id
 * @property string $created_at
 *
 * @property-read Item|null $item
 * @property-read Version|null $version
 */
class History extends ActiveRecord
{
    public const ACTION_CREATED = 'created';
    public const ACTION_REVIEW_REQUESTED = 'review_requested';
    public const ACTION_RETURNED = 'returned';
    public const ACTION_PUBLISHED = 'published';
    public const ACTION_WITHDRAWN = 'withdrawn';
    public const ACTION_ARCHIVED = 'archived';
    public const ACTION_RESTORED = 'restored';

    public static function tableName()
    {
        return '{{%knowledge_library_history}}';
    }

    /**
     * Writes a history entry for the item and optionally one of its versions.
     *
     * @throws Exception if the entry cannot be saved
     */
    public static function log(Item $item, string $action, ?Version $version = null, ?string $reason = null): self
    {
        $history = new static([
            'item_id' => $item->id,
            'version_id' => $version?->id,
            'action' => $action,
            'reason' => $reason,
        ]);

        if (!$history->save()) {
            throw new Exception('History entry not saved: ' . json_encode($history->getErrors()));
        }

        return $history;
    }

    public function rules()
    {
        return [
            [['item_id', 'action'], 'required'],
            ['item_id', 'exist', 'targetClass' => Item::class, 'targetAttribute' => 'id'],
            ['version_id', 'exist', 'targetClass' => Version::class, 'targetAttribute' => 'id'],
            ['action', 'string', 'max' => 64],
            ['reason', 'string'],
            ['actor_id', 'string', 'max' => 64],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => Yii::t('knowledge-library', 'ID'),
            'item_id' => Yii::t('knowledge-library', 'Item'),
            'version_id' => Yii::t('knowledge-library', 'Version'),
            'action' => Yii::t('knowledge-library', 'Action'),
            'reason' => Yii::t('knowledge-library', 'Reason'),
            'actor_id' => Yii::t('knowledge-library', 'Actor'),
            'created_at' => Yii::t('knowledge-library', 'Created At'),
        ];
    }

    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        if ($insert && ($this->actor_id === null || $this->actor_id === '')) {
            $this->actor_id = DefaultUserProvider::resolve()->getCurrentUserReference();
        }

        return true;
    }

    public function getItem(): ActiveQuery
    {
        return $this->hasOne(Item::class, ['id' => 'item_id']);
    }

    public function getVersion(): ActiveQuery
    {
        return $this->hasOne(Version::class, ['id' => 'version_id']);
    }
}
