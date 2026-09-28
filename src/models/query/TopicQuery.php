<?php

namespace dmstr\knowledgeLibrary\models\query;

use dmstr\knowledgeLibrary\models\Topic;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\db\Query;

/**
 * @see Topic
 *
 * @method Topic[] all($db = null)
 * @method Topic|null one($db = null)
 */
class TopicQuery extends ActiveQuery
{
    public function orderedByName(): static
    {
        return $this->addOrderBy(['name' => SORT_ASC]);
    }

    /**
     * Adds the number of knowledge items assigned to each topic, archived
     * ones included, as `itemCount` (see `Topic::$itemCount`).
     *
     * The count is a correlated subquery, so a list of topics needs a single
     * query. Selects all columns of the topic table unless a select is set.
     */
    public function withItemCount(): static
    {
        [, $alias] = $this->getTableNameAndAlias();
        $table = str_starts_with($alias, '{{') ? $alias : '{{' . $alias . '}}';

        if (empty($this->select)) {
            $this->select([$table . '.*']);
        }

        return $this->addSelect([
            'itemCount' => (new Query())
                ->select(new Expression('COUNT(*)'))
                ->from(['item_count' => '{{%knowledge_library_item_topic}}'])
                ->where(new Expression('{{item_count}}.[[topic_id]] = ' . $table . '.[[id]]')),
        ]);
    }
}
