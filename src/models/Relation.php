<?php

namespace dmstr\knowledgeLibrary\models;

use InvalidArgumentException;
use Yii;
use yii\db\ActiveQuery;

/**
 * Directed relation between two knowledge items, e.g. "A supplements B".
 *
 * Seen from the target item the inverse label applies ("B is supplemented
 * by A").
 *
 * @property string $id
 * @property string $source_item_id
 * @property string $target_item_id
 * @property string $type
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property string|null $created_by
 * @property string|null $updated_by
 *
 * @property-read Item|null $sourceItem
 * @property-read Item|null $targetItem
 */
class Relation extends ActiveRecord
{
    public const TYPE_BASED_ON = 'based_on';
    public const TYPE_SUPPLEMENTS = 'supplements';
    public const TYPE_REPLACES = 'replaces';

    public static function tableName()
    {
        return '{{%knowledge_library_relation}}';
    }

    /**
     * Labels of the relation types, seen from the source (`forward`) and from
     * the target (`inverse`).
     *
     * @return array<string, array{forward: string, inverse: string}>
     */
    public static function labels(): array
    {
        return [
            self::TYPE_BASED_ON => [
                'forward' => Yii::t('knowledge-library', 'is based on'),
                'inverse' => Yii::t('knowledge-library', 'is the basis for'),
            ],
            self::TYPE_SUPPLEMENTS => [
                'forward' => Yii::t('knowledge-library', 'supplements'),
                'inverse' => Yii::t('knowledge-library', 'is supplemented by'),
            ],
            self::TYPE_REPLACES => [
                'forward' => Yii::t('knowledge-library', 'replaces'),
                'inverse' => Yii::t('knowledge-library', 'is replaced by'),
            ],
        ];
    }

    public function rules()
    {
        return [
            [['source_item_id', 'target_item_id', 'type'], 'required'],
            [['source_item_id', 'target_item_id'], 'exist', 'targetClass' => Item::class, 'targetAttribute' => 'id'],
            ['type', 'in', 'range' => array_keys(static::labels())],
            [
                'target_item_id',
                'compare',
                'compareAttribute' => 'source_item_id',
                'operator' => '!==',
                'message' => Yii::t('knowledge-library', 'An item cannot be related to itself.'),
            ],
            [
                'target_item_id',
                'unique',
                'targetAttribute' => ['source_item_id', 'target_item_id', 'type'],
                'message' => Yii::t('knowledge-library', 'This relation already exists.'),
            ],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => Yii::t('knowledge-library', 'ID'),
            'source_item_id' => Yii::t('knowledge-library', 'Source Item'),
            'target_item_id' => Yii::t('knowledge-library', 'Target Item'),
            'type' => Yii::t('knowledge-library', 'Relation Type'),
            'created_at' => Yii::t('knowledge-library', 'Created At'),
            'updated_at' => Yii::t('knowledge-library', 'Updated At'),
            'created_by' => Yii::t('knowledge-library', 'Created By'),
            'updated_by' => Yii::t('knowledge-library', 'Updated By'),
        ];
    }

    public function getSourceItem(): ActiveQuery
    {
        return $this->hasOne(Item::class, ['id' => 'source_item_id']);
    }

    public function getTargetItem(): ActiveQuery
    {
        return $this->hasOne(Item::class, ['id' => 'target_item_id']);
    }

    /**
     * Label of the relation seen from the given item.
     *
     * @throws InvalidArgumentException if the item is not part of the relation
     */
    public function getLabelFor(string $perspectiveItemId): string
    {
        $direction = $this->isSource($perspectiveItemId) ? 'forward' : 'inverse';

        return static::labels()[$this->type][$direction] ?? (string)$this->type;
    }

    /**
     * ID of the item on the other side of the relation.
     *
     * @throws InvalidArgumentException if the item is not part of the relation
     */
    public function getOtherItemId(string $perspectiveItemId): string
    {
        return $this->isSource($perspectiveItemId) ? $this->target_item_id : $this->source_item_id;
    }

    /**
     * Whether the given item is the source (true) or the target (false).
     *
     * @throws InvalidArgumentException if the item is not part of the relation
     */
    private function isSource(string $itemId): bool
    {
        if ($itemId === $this->source_item_id) {
            return true;
        }

        if ($itemId === $this->target_item_id) {
            return false;
        }

        throw new InvalidArgumentException("Item $itemId is not part of relation $this->id.");
    }
}
