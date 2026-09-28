<?php

namespace dmstr\knowledgeLibrary\models\query;

use dmstr\knowledgeLibrary\models\Topic;
use yii\db\ActiveQuery;

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
}
