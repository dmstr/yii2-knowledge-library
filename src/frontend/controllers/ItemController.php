<?php

namespace dmstr\knowledgeLibrary\frontend\controllers;

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Relation;
use Yii;
use yii\web\NotFoundHttpException;

/**
 * Read-only pages of the items valid today: the list and the detail page with
 * the content and files of the valid version and the related items.
 *
 * Archived items and items without a version valid today do not exist for
 * the frontend (404), without telling the two cases apart.
 */
class ItemController extends BaseController
{
    protected function verbs(): array
    {
        return array_merge(parent::verbs(), [
            'index' => ['GET'],
            'view' => ['GET'],
        ]);
    }

    /**
     * List of the active items with a version valid today, sorted by title
     * and ID, without paging.
     */
    public function actionIndex(): string
    {
        $items = Item::find()
            ->active()
            ->validAt(date('Y-m-d'))
            ->with(['type', 'topics'])
            ->orderBy(['title' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return $this->render('index', [
            'items' => $items,
        ]);
    }

    /**
     * Detail page of an active item with the version valid today and its
     * relations to other items shown in the frontend.
     *
     * @throws NotFoundHttpException if the item does not exist, is archived or
     * has no version valid today
     */
    public function actionView(string $id): string
    {
        $item = Item::find()
            ->active()
            ->andWhere([Item::tableName() . '.[[id]]' => $id])
            ->with(['type', 'topics'])
            ->one();
        $version = $item?->getValidVersion();
        if ($item === null || $version === null) {
            throw new NotFoundHttpException(Yii::t('knowledge-library', 'The requested knowledge object does not exist.'));
        }

        return $this->render('view', [
            'item' => $item,
            'version' => $version,
            'relations' => $this->findVisibleRelations($item, date('Y-m-d')),
        ]);
    }

    /**
     * Outgoing and incoming relations of the item whose other item is shown
     * in the frontend (active, with a version valid at the date); relations
     * to any other item are left out, like the item itself.
     *
     * Grouped by the label seen from the item, in the order of
     * Relation::labels() (forward before inverse), the items of a group
     * sorted by title and ID.
     *
     * @return array<string, Item[]> map `label => related items`
     */
    private function findVisibleRelations(Item $item, string $date): array
    {
        /** @var Relation[] $relations */
        $relations = array_merge($item->outgoingRelations, $item->incomingRelations);
        if ($relations === []) {
            return [];
        }

        $otherIds = array_map(static fn (Relation $relation) => $relation->getOtherItemId($item->id), $relations);
        $visible = Item::find()
            ->active()
            ->validAt($date)
            ->andWhere([Item::tableName() . '.[[id]]' => array_values(array_unique($otherIds))])
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
                if ($relation->getOtherItemId($item->id) === $id) {
                    $groups[$relation->getLabelFor($item->id)][] = $other;
                }
            }
        }

        return array_filter($groups, static fn (array $items) => $items !== []);
    }
}
