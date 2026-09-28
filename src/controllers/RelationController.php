<?php

namespace dmstr\knowledgeLibrary\controllers;

use dmstr\knowledgeLibrary\models\History;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Relation;
use Throwable;
use Yii;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Adds and removes relations of a knowledge item; both return to the tab
 * relations of the detail page.
 *
 * Both write a history entry (`relation_added`, `relation_removed`) for
 * the source item (seen forward, with the title of the target) and for the
 * target item (seen inverse, with the title of the source), in the same
 * transaction.
 */
class RelationController extends BaseController
{
    protected function verbs(): array
    {
        return array_merge(parent::verbs(), [
            'create' => ['POST'],
        ]);
    }

    /**
     * Adds an outgoing relation of the item: body `Relation[type]` and
     * `Relation[target_item_id]`. Validation errors are shown as flash.
     *
     * @throws NotFoundHttpException
     */
    public function actionCreate(string $itemId): Response
    {
        $item = Item::find()->andWhere([Item::tableName() . '.[[id]]' => $itemId])->one();
        if ($item === null) {
            throw new NotFoundHttpException(
                Yii::t('knowledge-library', 'The requested knowledge object does not exist.')
            );
        }

        $data = $this->request->post('Relation');
        $data = is_array($data) ? $data : [];
        $relation = new Relation();
        // Only type and target are taken from the request, the source is
        // the item of the page.
        $relation->type = is_string($data['type'] ?? null) ? $data['type'] : null;
        $relation->target_item_id = is_string($data['target_item_id'] ?? null) && $data['target_item_id'] !== ''
            ? $data['target_item_id']
            : null;
        $relation->source_item_id = $item->id;
        $relation->populateRelation('sourceItem', $item);

        $session = Yii::$app->getSession();
        if (!$relation->validate()) {
            $session->setFlash('error', implode(' ', $relation->getFirstErrors()));
        } elseif ($this->changeWithHistory($relation, History::ACTION_RELATION_ADDED, static fn () => $relation->save(false))) {
            $session->setFlash('success', Yii::t('knowledge-library', 'Relation added.'));
        } else {
            $session->setFlash('error', implode(' ', $relation->getFirstErrors()));
        }

        return $this->redirect(['item/view', 'id' => $item->id, 'tab' => ItemController::TAB_RELATIONS]);
    }

    /**
     * Removes a relation; it returns to the detail page of its source item.
     *
     * @throws NotFoundHttpException
     */
    public function actionDelete(string $id): Response
    {
        $relation = Relation::findOne(['id' => $id]);
        if ($relation === null) {
            throw new NotFoundHttpException(Yii::t('knowledge-library', 'The requested relation does not exist.'));
        }

        $session = Yii::$app->getSession();
        if ($this->changeWithHistory($relation, History::ACTION_RELATION_REMOVED, static fn () => $relation->delete() !== false)) {
            $session->setFlash('success', Yii::t('knowledge-library', 'Relation removed.'));
        } else {
            $session->setFlash('error', Yii::t('knowledge-library', 'The relation could not be removed.'));
        }

        return $this->redirect(['item/view', 'id' => $relation->source_item_id, 'tab' => ItemController::TAB_RELATIONS]);
    }

    /**
     * Runs the change (save or delete) and writes the history entries of
     * both items in one transaction.
     *
     * @param callable(): bool $change
     */
    private function changeWithHistory(Relation $relation, string $action, callable $change): bool
    {
        $source = $relation->sourceItem;
        $target = $relation->targetItem;

        $transaction = Relation::getDb()->beginTransaction();
        try {
            if (!$change()) {
                $transaction->rollBack();

                return false;
            }
            if ($source instanceof Item) {
                History::log($source, $action, null, null, [
                    'relation' => $relation->type,
                    'direction' => 'forward',
                    'target' => $target instanceof Item ? $target->title : null,
                ]);
            }
            if ($target instanceof Item) {
                History::log($target, $action, null, null, [
                    'relation' => $relation->type,
                    'direction' => 'inverse',
                    'target' => $source instanceof Item ? $source->title : null,
                ]);
            }
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            Yii::warning("Relation $relation->id not changed: {$e->getMessage()}", 'knowledge-library');

            return false;
        }

        return true;
    }
}
