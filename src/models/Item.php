<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\models;

use dmstr\knowledgeLibrary\models\query\ItemQuery;
use dmstr\knowledgeLibrary\models\query\VersionQuery;
use Throwable;
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

    public const SCENARIO_CREATE = 'create';
    public const SCENARIO_UPDATE = 'update';
    public const SCENARIO_SOURCE = 'source';

    private const TABLE_ITEM_TOPIC = '{{%knowledge_library_item_topic}}';

    /**
     * Assigned topic IDs, null as long as they were not set.
     *
     * @var string[]|null
     */
    private ?array $_topicIds = null;

    /**
     * Most recent change of the item or one of its versions, only populated
     * by queries using ItemQuery::withLastChange().
     */
    public ?string $lastChange = null;

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
            self::SCENARIO_CREATE => self::OP_ALL,
            self::SCENARIO_UPDATE => self::OP_ALL,
            self::SCENARIO_SOURCE => self::OP_ALL,
        ];
    }

    public function scenarios()
    {
        $scenarios = parent::scenarios();
        $scenarios[self::SCENARIO_CREATE] = ['title', 'type_id'];
        $scenarios[self::SCENARIO_UPDATE] = ['title', 'type_id'];
        $scenarios[self::SCENARIO_SOURCE] = ['source_name', 'source_reference', 'source_url', 'source_import_mode'];

        return $scenarios;
    }

    public function rules()
    {
        return [
            ['type_id', 'required'],
            ['type_id', 'exist', 'targetClass' => Type::class, 'targetAttribute' => 'id'],
            ['type_id', 'validateTypeLock'],
            ['title', 'trim'],
            ['title', 'required'],
            ['title', 'string', 'max' => 255],
            ['summary', 'string'],
            ['is_archived', 'default', 'value' => false],
            ['is_archived', 'boolean'],
            ['source_name', 'trim'],
            ['source_name', 'required', 'on' => self::SCENARIO_SOURCE],
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
            'lastChange' => Yii::t('knowledge-library', 'Last Change'),
            'created_at' => Yii::t('knowledge-library', 'Created At'),
            'updated_at' => Yii::t('knowledge-library', 'Updated At'),
            'created_by' => Yii::t('knowledge-library', 'Created By'),
            'updated_by' => Yii::t('knowledge-library', 'Updated By'),
        ];
    }

    /**
     * Message for actions that are not possible on an archived item, e.g.
     * creating or publishing a version.
     */
    public static function archivedMessage(): string
    {
        return Yii::t('knowledge-library', 'The knowledge object is archived.');
    }

    /**
     * Archives the item and writes the history entry `archived`, in one
     * transaction. Open drafts and reviews are kept; while archived no new
     * versions can be created or published.
     *
     * @return bool whether the item was archived; see the errors otherwise
     */
    public function archive(?string $reason = null): bool
    {
        if ($this->is_archived) {
            $this->addError('is_archived', static::archivedMessage());

            return false;
        }

        return $this->setArchived(true, History::ACTION_ARCHIVED, $reason);
    }

    /**
     * Restores an archived item and writes the history entry `restored`, in
     * one transaction.
     *
     * @return bool whether the item was restored; see the errors otherwise
     */
    public function restore(?string $reason = null): bool
    {
        if (!$this->is_archived) {
            $this->addError('is_archived', Yii::t('knowledge-library', 'The knowledge object is not archived.'));

            return false;
        }

        return $this->setArchived(false, History::ACTION_RESTORED, $reason);
    }

    /**
     * Rejects a change of the type once the item has versions, as the
     * validity rules of the versions depend on it.
     */
    public function validateTypeLock(string $attribute): void
    {
        if ($this->getIsNewRecord() || !$this->isAttributeChanged($attribute, false)) {
            return;
        }

        if ($this->getVersions()->exists()) {
            $this->addError(
                $attribute,
                Yii::t('knowledge-library', 'The type cannot be changed once the item has versions.')
            );
        }
    }

    /**
     * Whether the type can no longer be changed because versions exist.
     */
    public function isTypeLocked(): bool
    {
        return !$this->getIsNewRecord() && $this->getVersions()->exists();
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
     * Outgoing and incoming relations of the item whose other item is shown
     * to readers (active, with a version valid at the date); relations to
     * any other item are left out, like the item itself.
     *
     * Grouped by the label seen from the item, in the order of
     * Relation::labels() (forward before inverse), the items of a group
     * sorted by title and ID.
     *
     * @param string $date date in the format `Y-m-d`
     *
     * @return array<string, Item[]> map `label => related items`
     */
    public function findVisibleRelations(string $date): array
    {
        /** @var Relation[] $relations */
        $relations = array_merge($this->outgoingRelations, $this->incomingRelations);
        if ($relations === []) {
            return [];
        }

        $otherIds = array_map(fn (Relation $relation): string => $relation->getOtherItemId($this->id), $relations);
        $visible = static::find()
            ->active()
            ->validAt($date)
            ->andWhere([static::tableName() . '.[[id]]' => array_values(array_unique($otherIds))])
            ->orderBy(['title' => SORT_ASC, 'id' => SORT_ASC])
            ->indexBy('id')
            ->all();

        $groups = [];
        foreach (Relation::labels() as $labels) {
            $groups[$labels['forward']] = [];
            $groups[$labels['inverse']] = [];
        }
        foreach ($visible as $id => $other) {
            foreach ($relations as $relation) {
                if ($relation->getOtherItemId($this->id) === $id) {
                    $groups[$relation->getLabelFor($this->id)][] = $other;
                }
            }
        }

        return array_filter($groups, static fn (array $items): bool => $items !== []);
    }

    /**
     * Stores the archive flag (with timestamp and user of the change) and
     * writes the history entry in a transaction; restores the flag on
     * failure.
     */
    private function setArchived(bool $archived, string $action, ?string $reason): bool
    {
        $previous = $this->is_archived;
        $this->is_archived = $archived;

        $transaction = static::getDb()->beginTransaction();
        try {
            if (!$this->save(false, ['is_archived', 'updated_at', 'updated_by'])) {
                $transaction->rollBack();
                $this->is_archived = $previous;

                return false;
            }

            History::log($this, $action, null, $reason);
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            $this->is_archived = $previous;
            $this->setOldAttribute('is_archived', $previous);

            throw $e;
        }

        return true;
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
