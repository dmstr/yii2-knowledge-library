<?php

namespace dmstr\knowledgeLibrary\models\query;

use dmstr\knowledgeLibrary\models\Item;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\db\Query;

/**
 * @see Item
 *
 * @method Item[] all($db = null)
 * @method Item|null one($db = null)
 */
class ItemQuery extends ActiveQuery
{
    /**
     * Only items that are not archived.
     */
    public function active(): static
    {
        return $this->andWhere([$this->qualify('is_archived') => 0]);
    }

    /**
     * Only archived items.
     */
    public function archived(): static
    {
        return $this->andWhere([$this->qualify('is_archived') => 1]);
    }

    /**
     * Applies the list filters.
     *
     * @param string|null $title part of the title, matched with `LIKE`
     * @param string|null $typeId ID of the type
     * @param string[] $topicIds items assigned to ANY of these topics
     * @param bool|null $archived false: only active items, true: only archived
     * items, null: all items
     */
    public function withFilters(
        ?string $title = null,
        ?string $typeId = null,
        array $topicIds = [],
        ?bool $archived = false
    ): static {
        $title = $title === null ? '' : trim($title);
        if ($title !== '') {
            $this->andWhere(['like', $this->qualify('title'), $title]);
        }

        if ($typeId !== null && $typeId !== '') {
            $this->andWhere([$this->qualify('type_id') => $typeId]);
        }

        $topicIds = array_values(array_filter($topicIds, static fn ($id) => $id !== null && $id !== ''));
        if ($topicIds !== []) {
            // EXISTS instead of a join, so items with several matching topics
            // are returned only once.
            $this->andWhere(['exists', (new Query())
                ->from(['kl_filter_item_topic' => '{{%knowledge_library_item_topic}}'])
                ->where(new Expression('[[kl_filter_item_topic.item_id]] = ' . $this->qualify('id')))
                ->andWhere(['kl_filter_item_topic.topic_id' => $topicIds])]);
        }

        if ($archived === true) {
            $this->archived();
        } elseif ($archived === false) {
            $this->active();
        }

        return $this;
    }

    /**
     * Column name qualified with the table name or alias of this query.
     */
    private function qualify(string $name): string
    {
        [, $alias] = $this->getTableNameAndAlias();

        return $alias . '.[[' . $name . ']]';
    }
}
