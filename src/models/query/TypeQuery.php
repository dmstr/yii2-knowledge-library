<?php

namespace dmstr\knowledgeLibrary\models\query;

use dmstr\knowledgeLibrary\models\Type;
use yii\db\ActiveQuery;

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
}
