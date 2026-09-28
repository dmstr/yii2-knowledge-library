<?php

namespace dmstr\knowledgeLibrary\models\query;

use dmstr\knowledgeLibrary\models\Type;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\db\Query;

/**
 * @see Type
 *
 * @method Type[] all($db = null)
 * @method Type|null one($db = null)
 */
class TypeQuery extends ActiveQuery
{
    public function orderedByName(): static
    {
        return $this->addOrderBy(['name' => SORT_ASC]);
    }

    /**
     * Adds the number of knowledge items of each type, archived ones
     * included, as `itemCount` (see `Type::$itemCount`).
     *
     * The count is a correlated subquery, so a list of types needs a single
     * query. Selects all columns of the type table unless a select is set.
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
                ->from(['item_count' => '{{%knowledge_library_item}}'])
                ->where(new Expression('{{item_count}}.[[type_id]] = ' . $table . '.[[id]]')),
        ]);
    }
}
