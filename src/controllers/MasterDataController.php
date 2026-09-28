<?php

namespace dmstr\knowledgeLibrary\controllers;

use dmstr\knowledgeLibrary\models\ActiveRecord;
use Yii;
use yii\data\ActiveDataProvider;
use yii\db\IntegrityException;
use yii\helpers\Html;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Base class of the master data pages (types, topics): list with item count,
 * create, update and delete with the model's delete lock.
 *
 * Subclasses name the model class and provide the messages; the messages stay
 * in the subclasses so every text is a literal of its own.
 */
abstract class MasterDataController extends BaseController
{
    /**
     * @return class-string<ActiveRecord> Model class whose query provides
     *     `orderedByName()` and `withItemCount()`
     */
    abstract protected function modelClass(): string;

    abstract protected function savedMessage(string $name): string;

    abstract protected function deletedMessage(string $name): string;

    abstract protected function notDeletedMessage(): string;

    abstract protected function notFoundMessage(): string;

    /**
     * Lists all entries with the number of knowledge items using them.
     */
    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => $this->modelClass()::find()->orderedByName()->withItemCount(),
            'sort' => false,
            'pagination' => ['pageSize' => 50],
        ]);

        return $this->render('index', [
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * @return string|Response
     */
    public function actionCreate()
    {
        $model = $this->newModel();

        if ($model->load($this->request->post()) && $model->save()) {
            return $this->redirectSaved($model);
        }

        return $this->render('create', [
            'model' => $model,
        ]);
    }

    /**
     * @return string|Response
     * @throws NotFoundHttpException
     */
    public function actionUpdate(string $id)
    {
        $model = $this->findModel($id);

        if ($model->load($this->request->post()) && $model->save()) {
            return $this->redirectSaved($model);
        }

        return $this->render('update', [
            'model' => $model,
        ]);
    }

    /**
     * Deletes the entry unless knowledge items use it; the reason is shown as
     * error flash then.
     *
     * @throws NotFoundHttpException
     */
    public function actionDelete(string $id): Response
    {
        $model = $this->findModel($id);
        $session = Yii::$app->getSession();

        try {
            $deleted = $model->delete() !== false;
        } catch (IntegrityException $e) {
            // An item was assigned after the check in beforeDelete().
            $deleted = false;
            Yii::warning(
                sprintf('%s %s not deleted: %s', $model::class, $model->id, $e->getMessage()),
                'knowledge-library'
            );
        }

        if ($deleted) {
            $session->setFlash('success', $this->deletedMessage(Html::encode($model->name)));
        } else {
            $session->setFlash('error', $model->getFirstError('name') ?? $this->notDeletedMessage());
        }

        return $this->redirect(['index']);
    }

    /**
     * New model for the create page, with the database defaults loaded.
     */
    protected function newModel(): ActiveRecord
    {
        $class = $this->modelClass();
        $model = new $class();
        $model->loadDefaultValues();

        return $model;
    }

    /**
     * @throws NotFoundHttpException
     */
    protected function findModel(string $id): ActiveRecord
    {
        $model = $this->modelClass()::find()->andWhere(['id' => $id])->one();
        if ($model === null) {
            throw new NotFoundHttpException($this->notFoundMessage());
        }

        return $model;
    }

    private function redirectSaved(ActiveRecord $model): Response
    {
        Yii::$app->getSession()->setFlash('success', $this->savedMessage(Html::encode($model->name)));

        return $this->redirect(['index']);
    }
}
