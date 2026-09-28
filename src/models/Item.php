<?php

namespace dmstr\knowledgeLibrary\models;

use dmstr\knowledgeLibrary\models\query\ItemQuery;
use dmstr\knowledgeLibrary\models\query\VersionQuery;
use Yii;
use yii\db\ActiveQuery;
use yii\db\Query;

/**
 * Knowledge item, e.g. a guideline. Its content lives in versions.
 *
 * @property string $id
 * @property string $type_id
 * @property string $title
 * @property string|null $summary
 * @property bool $is_archived
 * @property string|null $source_name
 * @property string|null $source_reference
 * @property string|null $source_url
 * @property string $source_import_mode
 * @property string|null $source_uploaded_at
 * @property string|null $source_uploaded_by
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property string|null $created_by
 * @property string|null $updated_by
 *
 * @property string[] $topicIds
 * @property-read Type|null $type
 * @property-read Topic[] $topics
 * @property-read Version[] $versions
 * @property-read History[] $history
 * @property-read Relation[] $outgoingRelations
 * @property-read Relation[] $incomingRelations
 */
class Item extends ActiveRecord
{
    public const SOURCE_IMPORT_MANUAL = 'manual';
    public const SOURCE_IMPORT_AUTOMATIC = 'automatic';

    private const TABLE_ITEM_TOPIC = '{{%knowledge_library_item_topic}}';

    /**
     * Assigned topic IDs, null as long as they were not set.
     *
     * @var string[]|null
     */
    private ?array $_topicIds = null;

    public static function tableName()
    {
        return '{{%knowledge_library_item}}';
    }

    public static function find(): ItemQuery
    {
        return new ItemQuery(static::class);
    }

    /**
     * @return array<string, string> map `import mode => label`
     */
    public static function sourceImportModes(): array
    {
        return [
            self::SOURCE_IMPORT_MANUAL => Yii::t('knowledge-library', 'Manual'),
            self::SOURCE_IMPORT_AUTOMATIC => Yii::t('knowledge-library', 'Automatic'),
        ];
    }

    public function transactions()
    {
        // Saving includes syncing the topic assignments.
        return [
            self::SCENARIO_DEFAULT => self::OP_ALL,
        ];
    }

    public function rules()
    {
        return [
            ['type_id', 'required'],
            ['type_id', 'exist', 'targetClass' => Type::class, 'targetAttribute' => 'id'],
            ['title', 'trim'],
            ['title', 'required'],
            ['title', 'string', 'max' => 255],
            ['summary', 'string'],
            ['is_archived', 'default', 'value' => false],
            ['is_archived', 'boolean'],
            [['source_name', 'source_reference'], 'string', 'max' => 255],
            ['source_url', 'string', 'max' => 2048],
            ['source_url', 'url'],
            ['source_import_mode', 'default', 'value' => self::SOURCE_IMPORT_MANUAL],
            ['source_import_mode', 'in', 'range' => array_keys(static::sourceImportModes())],
            ['source_uploaded_at', 'datetime', 'format' => 'php:Y-m-d H:i:s'],
            ['source_uploaded_by', 'string', 'max' => 64],
            ['topicIds', 'each', 'rule' => ['exist', 'targetClass' => Topic::class, 'targetAttribute' => 'id']],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => Yii::t('knowledge-library', 'ID'),
            'type_id' => Yii::t('knowledge-library', 'Type'),
            'title' => Yii::t('knowledge-library', 'Title'),
            'summary' => Yii::t('knowledge-library', 'Summary'),
            'is_archived' => Yii::t('knowledge-library', 'Archived'),
            'source_name' => Yii::t('knowledge-library', 'Source'),
            'source_reference' => Yii::t('knowledge-library', 'Source Reference'),
            'source_url' => Yii::t('knowledge-library', 'Source URL'),
            'source_import_mode' => Yii::t('knowledge-library', 'Import Mode'),
            'source_uploaded_at' => Yii::t('knowledge-library', 'Source Uploaded At'),
            'source_uploaded_by' => Yii::t('knowledge-library', 'Source Uploaded By'),
            'topicIds' => Yii::t('knowledge-library', 'Topics'),
            'created_at' => Yii::t('knowledge-library', 'Created At'),
            'updated_at' => Yii::t('knowledge-library', 'Updated At'),
            'created_by' => Yii::t('knowledge-library', 'Created By'),
            'updated_by' => Yii::t('knowledge-library', 'Updated By'),
        ];
    }

    /**
     * IDs of the assigned topics; loaded from the database unless assigned.
     *
     * @return string[]
     */
    public function getTopicIds(): array
    {
        if ($this->_topicIds !== null) {
            return $this->_topicIds;
        }

        if ($this->getIsNewRecord()) {
            return [];
        }

        return (new Query())
            ->select('topic_id')
            ->from(self::TABLE_ITEM_TOPIC)
            ->where(['item_id' => $this->id])
            ->column(static::getDb());
    }

    /**
     * Assigns topics; the assignment is stored on the next save.
     *
     * @param string[]|string|null $topicIds
     */
    public function setTopicIds($topicIds): void
    {
        if ($topicIds === null || $topicIds === '') {
            $topicIds = [];
        }

        $this->_topicIds = array_values(array_unique(array_map('strval', (array)$topicIds)));
    }

    public function afterSave($insert, $changedAttributes)
    {
        if ($this->_topicIds !== null) {
            $this->syncTopics($insert);
        }

        parent::afterSave($insert, $changedAttributes);
    }

    public function getType(): ActiveQuery
    {
        return $this->hasOne(Type::class, ['id' => 'type_id']);
    }

    public function getTopics(): ActiveQuery
    {
        return $this->hasMany(Topic::class, ['id' => 'topic_id'])
            ->viaTable(self::TABLE_ITEM_TOPIC, ['item_id' => 'id']);
    }

    public function getVersions(): VersionQuery
    {
        /** @var VersionQuery $query */
        $query = $this->hasMany(Version::class, ['item_id' => 'id']);

        return $query->orderBy(['number' => SORT_ASC]);
    }

    public function getHistory(): ActiveQuery
    {
        return $this->hasMany(History::class, ['item_id' => 'id'])
            ->orderBy(['created_at' => SORT_DESC]);
    }

    public function getOutgoingRelations(): ActiveQuery
    {
        return $this->hasMany(Relation::class, ['source_item_id' => 'id']);
    }

    public function getIncomingRelations(): ActiveQuery
    {
        return $this->hasMany(Relation::class, ['target_item_id' => 'id']);
    }

    /**
     * Version valid at the given date.
     *
     * @param string|null $date date in the format `Y-m-d`, today if null
     */
    public function getValidVersion(?string $date = null): ?Version
    {
        return Version::find()
            ->validAt($date ?? date('Y-m-d'))
            ->forItem($this->id)
            ->one();
    }

    /**
     * Published version with the highest number.
     */
    public function getLatestPublishedVersion(): ?Version
    {
        return Version::find()
            ->published()
            ->forItem($this->id)
            ->orderBy(['number' => SORT_DESC])
            ->limit(1)
            ->one();
    }

    /**
     * Stores the assigned topics: removes unassigned and adds new ones.
     */
    private function syncTopics(bool $insert): void
    {
        $db = static::getDb();
        $current = $insert ? [] : (new Query())
            ->select('topic_id')
            ->from(self::TABLE_ITEM_TOPIC)
            ->where(['item_id' => $this->id])
            ->column($db);

        $removed = array_values(array_diff($current, $this->_topicIds));
        if ($removed !== []) {
            $db->createCommand()->delete(self::TABLE_ITEM_TOPIC, [
                'item_id' => $this->id,
                'topic_id' => $removed,
            ])->execute();
        }

        $added = array_values(array_diff($this->_topicIds, $current));
        if ($added !== []) {
            $db->createCommand()->batchInsert(
                self::TABLE_ITEM_TOPIC,
                ['item_id', 'topic_id'],
                array_map(fn (string $topicId) => [$this->id, $topicId], $added)
            )->execute();
        }

        $this->_topicIds = null;
        unset($this->topics);
    }
}
