<?php

namespace dmstr\knowledgeLibrary\models\query;

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Type;
use dmstr\knowledgeLibrary\models\Version;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\db\Query;

/**
 * @see Version
 *
 * @method Version[] all($db = null)
 * @method Version|null one($db = null)
 */
class VersionQuery extends ActiveQuery
{
    public function forItem(string $itemId): static
    {
        return $this->andWhere([$this->qualify('item_id') => $itemId]);
    }

    public function published(): static
    {
        return $this->andWhere([$this->qualify('status') => Version::STATUS_PUBLISHED]);
    }

    /**
     * Published versions valid at the given date, at most one per item.
     *
     * For items whose type has a validity period, a version is valid if the
     * date lies within `valid_from` and `valid_until` (open-ended if null).
     * For items whose type has no validity period, the published version with
     * the highest number is valid regardless of the date. If several versions
     * of an item match, the one with the highest number wins.
     *
     * @param string $date date in the format `Y-m-d`
     */
    public function validAt(string $date): static
    {
        $item = 'kl_valid_at_item';
        $type = 'kl_valid_at_type';
        $candidate = 'kl_valid_at_candidate';

        $highestNumber = (new Query())
            ->select(new Expression("MAX([[$candidate.number]])"))
            ->from([$candidate => Version::tableName()])
            ->where(new Expression("[[$candidate.item_id]] = " . $this->qualify('item_id')))
            ->andWhere(["$candidate.status" => Version::STATUS_PUBLISHED])
            ->andWhere([
                'or',
                ["$type.has_validity_period" => 0],
                [
                    'and',
                    ['<=', "$candidate.valid_from", $date],
                    ['or', ["$candidate.valid_until" => null], ['>=', "$candidate.valid_until", $date]],
                ],
            ]);

        return $this
            ->innerJoin([$item => Item::tableName()], "[[$item.id]] = " . $this->qualify('item_id'))
            ->innerJoin([$type => Type::tableName()], "[[$type.id]] = [[$item.type_id]]")
            ->published()
            ->andWhere([$this->qualify('number') => $highestNumber]);
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
