<?php

namespace dmstr\knowledgeLibrary\frontend\controllers;

use dmstr\knowledgeLibrary\models\Item;
use Yii;
use yii\web\NotFoundHttpException;

/**
 * Read-only pages of the items valid today: the list and the detail page with
 * the content and files of the valid version.
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
     * Detail page of an active item with the version valid today.
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
        ]);
    }
}
