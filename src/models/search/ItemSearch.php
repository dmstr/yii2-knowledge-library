<?php

namespace dmstr\knowledgeLibrary\models\search;

use dmstr\knowledgeLibrary\models\Item;
use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;

/**
 * Filters and sorting of the knowledge item list.
 *
 * Invalid filter values never fail the search, they fall back to their
 * defaults.
 */
class ItemSearch extends Item
{
    public const ARCHIVED_ACTIVE = 'active';
    public const ARCHIVED_ARCHIVED = 'archived';
    public const ARCHIVED_ALL = 'all';

    public const PAGE_SIZE = 20;

    /**
     * @var string|null part of the title
     */
    public $title;

    /**
     * @var string|null ID of the type
     */
    public $type_id;

    /**
     * @var string[] items assigned to any of these topics
     */
    public $topicIds = [];

    /**
     * @var string one of the ARCHIVED_* constants
     */
    public $archived = self::ARCHIVED_ACTIVE;

    /**
     * @return array<string, string> map `archived filter => label`
     */
    public static function archivedOptions(): array
    {
        return [
            self::ARCHIVED_ACTIVE => Yii::t('knowledge-library', 'Active'),
            self::ARCHIVED_ARCHIVED => Yii::t('knowledge-library', 'Archived'),
            self::ARCHIVED_ALL => Yii::t('knowledge-library', 'All'),
        ];
    }

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        unset($behaviors['uuid'], $behaviors['timestamp'], $behaviors['blameable']);

        return $behaviors;
    }

    public function rules()
    {
        return [
            ['title', 'trim'],
            ['title', 'string'],
            ['type_id', 'string'],
            [
                'topicIds',
                'filter',
                'filter' => static fn ($value) => $value === null || $value === '' ? [] : (array)$value,
            ],
            ['topicIds', 'each', 'rule' => ['string']],
            ['archived', 'in', 'range' => array_keys(static::archivedOptions())],
        ];
    }

    public function scenarios()
    {
        // Bypass the scenarios of Item.
        return Model::scenarios();
    }

    /**
     * Item list for the given request parameters, sorted by the last change.
     *
     * @param array $params query parameters; besides the filters they carry
     * the sort and page parameters
     */
    public function search(array $params): ActiveDataProvider
    {
        $this->load($params);
        $this->resetInvalidFilters();

        $archived = match ($this->archived) {
            self::ARCHIVED_ARCHIVED => true,
            self::ARCHIVED_ALL => null,
            default => false,
        };

        $query = Item::find()
            ->withFilters($this->title, $this->type_id, $this->topicIds, $archived)
            ->withLastChange()
            ->with(['type', 'topics']);

        $id = Item::tableName() . '.[[id]]';

        return new ActiveDataProvider([
            'query' => $query,
            'pagination' => [
                'params' => $params,
                'pageSize' => self::PAGE_SIZE,
            ],
            'sort' => [
                'params' => $params,
                'attributes' => [
                    'title' => [
                        'asc' => ['title' => SORT_ASC, $id => SORT_ASC],
                        'desc' => ['title' => SORT_DESC, $id => SORT_DESC],
                        'label' => $this->getAttributeLabel('title'),
                    ],
                    'lastChange' => [
                        'asc' => ['lastChange' => SORT_ASC, $id => SORT_ASC],
                        'desc' => ['lastChange' => SORT_DESC, $id => SORT_DESC],
                        'label' => $this->getAttributeLabel('lastChange'),
                    ],
                ],
                'defaultOrder' => ['lastChange' => SORT_DESC],
            ],
        ]);
    }

    /**
     * Whether any filter differs from its default.
     */
    public function isFiltered(): bool
    {
        return ($this->title !== null && $this->title !== '')
            || ($this->type_id !== null && $this->type_id !== '')
            || $this->topicIds !== []
            || $this->archived !== self::ARCHIVED_ACTIVE;
    }

    /**
     * Validates the loaded filters and resets every invalid one to its
     * default, so that manipulated parameters cannot break the list.
     */
    private function resetInvalidFilters(): void
    {
        if ($this->validate()) {
            return;
        }

        $defaults = [
            'title' => null,
            'type_id' => null,
            'topicIds' => [],
            'archived' => self::ARCHIVED_ACTIVE,
        ];
        foreach (array_keys($this->getErrors()) as $attribute) {
            if (array_key_exists($attribute, $defaults)) {
                $this->$attribute = $defaults[$attribute];
            }
        }
        $this->clearErrors();
    }
}
