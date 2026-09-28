<?php

namespace dmstr\knowledgeLibrary\controllers;

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\ItemState;
use dmstr\knowledgeLibrary\models\search\ItemSearch;
use dmstr\knowledgeLibrary\models\Topic;
use dmstr\knowledgeLibrary\models\Type;
use dmstr\knowledgeLibrary\Module;
use Yii;
use yii\db\Exception as DbException;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Library of the knowledge items: list, detail page, master data, source and
 * deletion.
 *
 * @property Module $module
 */
class ItemController extends BaseController
{
    /**
     * Lists the items with filters, sorting and the state of each item.
     */
    public function actionIndex(): string
    {
        $searchModel = new ItemSearch();
        $dataProvider = $searchModel->search($this->request->get());
        $items = $dataProvider->getModels();

        // The hint for an empty library replaces the grid only if nothing is
        // filtered, otherwise the grid shows its empty row.
        $isEmpty = $items === [] && !$searchModel->isFiltered() && !Item::find()->exists();

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'states' => ItemState::forItems($items),
            'typeOptions' => $this->typeOptions(),
            'topicOptions' => ArrayHelper::map(Topic::find()->orderedByName()->all(), 'id', 'name'),
            'isEmpty' => $isEmpty,
        ]);
    }

    /**
     * @return string|Response
     */
    public function actionCreate()
    {
        // No loadDefaultValues(): the database defaults apply on insert, and
        // SQLite reports the default of nullable columns as the string 'NULL'.
        $model = new Item(['scenario' => Item::SCENARIO_CREATE]);

        if ($model->load($this->request->post()) && $model->save()) {
            Yii::$app->getSession()->setFlash('success', Yii::t('knowledge-library', 'Knowledge object created.'));

            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('create', [
            'model' => $model,
            'typeOptions' => $this->typeOptions(),
        ]);
    }

    /**
     * Detail page with the header and the tabs of the item.
     *
     * @throws NotFoundHttpException
     */
    public function actionView(string $id): string
    {
        $model = $this->findModel($id);
        $uploadedBy = $model->source_uploaded_by;

        return $this->render('view', [
            'model' => $model,
            'canDelete' => $this->canDelete(),
            'uploadedByName' => $uploadedBy === null || $uploadedBy === ''
                ? null
                : ($this->module->getUserProvider()->getDisplayName($uploadedBy) ?? $uploadedBy),
        ]);
    }

    /**
     * Edits the master data (title and type); the type is locked once the
     * item has versions.
     *
     * @return string|Response
     * @throws NotFoundHttpException
     */
    public function actionUpdate(string $id)
    {
        $model = $this->findModel($id);
        $model->setScenario(Item::SCENARIO_UPDATE);

        if ($model->load($this->request->post()) && $model->save()) {
            Yii::$app->getSession()->setFlash('success', Yii::t('knowledge-library', 'Knowledge object saved.'));

            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('update', [
            'model' => $model,
            'typeOptions' => $this->typeOptions(),
        ]);
    }

    /**
     * Edits source and origin of the item.
     *
     * @return string|Response
     * @throws NotFoundHttpException
     */
    public function actionSource(string $id)
    {
        $model = $this->findModel($id);
        $model->setScenario(Item::SCENARIO_SOURCE);

        if ($model->load($this->request->post()) && $model->save()) {
            Yii::$app->getSession()->setFlash('success', Yii::t('knowledge-library', 'Source & origin saved.'));

            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('source', [
            'model' => $model,
        ]);
    }

    /**
     * Deletes the item with all versions, file entries, relations, history
     * and topic assignments (cascading foreign keys).
     *
     * The deletion is logged before, as the numbers are gone afterwards.
     *
     * @throws NotFoundHttpException
     */
    public function actionDelete(string $id): Response
    {
        $model = $this->findModel($id);
        $session = Yii::$app->getSession();

        Yii::info(sprintf(
            'Deleting knowledge item %s "%s" with %d version(s), user %s, at %s',
            $model->id,
            $model->title,
            (int)$model->getVersions()->count(),
            $this->module->getUserProvider()->getCurrentUserReference() ?? '-',
            date('c')
        ), 'knowledge-library');

        try {
            $deleted = $model->delete() !== false;
        } catch (DbException $e) {
            $deleted = false;
            Yii::warning("Knowledge item $model->id not deleted: {$e->getMessage()}", 'knowledge-library');
        }

        if (!$deleted) {
            $session->setFlash('error', Yii::t('knowledge-library', 'The knowledge object could not be deleted.'));

            return $this->redirect(['view', 'id' => $model->id]);
        }

        $session->setFlash('success', Yii::t('knowledge-library', 'Knowledge object "{title}" deleted.', [
            'title' => Html::encode($model->title),
        ]));

        return $this->redirect(['index']);
    }

    /**
     * Whether the current user may delete items, checked like the access
     * control of the module (`AccessBehaviorTrait`).
     */
    public function canDelete(): bool
    {
        $permission = str_replace('/', '_', trim($this->module->getUniqueId(), '/') . '_' . $this->id . '_delete');

        return Yii::$app->getUser()->can($permission, ['route' => true]);
    }

    /**
     * @return array<string, string> map `type ID => name`, ordered by name
     */
    private function typeOptions(): array
    {
        return ArrayHelper::map(Type::find()->orderedByName()->all(), 'id', 'name');
    }

    /**
     * @throws NotFoundHttpException
     */
    protected function findModel(string $id): Item
    {
        $model = Item::find()->andWhere([Item::tableName() . '.[[id]]' => $id])->with('type')->one();
        if ($model === null) {
            throw new NotFoundHttpException(
                Yii::t('knowledge-library', 'The requested knowledge object does not exist.')
            );
        }

        return $model;
    }
}
