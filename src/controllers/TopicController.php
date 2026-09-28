<?php

namespace dmstr\knowledgeLibrary\controllers;

use dmstr\knowledgeLibrary\models\Topic;
use Yii;
use yii\data\ActiveDataProvider;
use yii\db\IntegrityException;
use yii\helpers\Html;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Manages the topics knowledge items are assigned to.
 */
class TopicController extends BaseController
{
    /**
     * Lists all topics with the number of knowledge items assigned to them.
     */
    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => Topic::find()->orderedByName()->withItemCount(),
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
        $model = new Topic();
        $model->loadDefaultValues();

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
     * Deletes the topic unless knowledge items use it; the reason is shown as
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
            Yii::warning("Topic $model->id not deleted: {$e->getMessage()}", 'knowledge-library');
        }

        if ($deleted) {
            $session->setFlash('success', Yii::t('knowledge-library', 'Topic "{name}" deleted.', [
                'name' => Html::encode($model->name),
            ]));
        } else {
            $session->setFlash(
                'error',
                $model->getFirstError('name') ?? Yii::t('knowledge-library', 'The topic could not be deleted.')
            );
        }

        return $this->redirect(['index']);
    }

    private function redirectSaved(Topic $model): Response
    {
        Yii::$app->getSession()->setFlash('success', Yii::t('knowledge-library', 'Topic "{name}" saved.', [
            'name' => Html::encode($model->name),
        ]));

        return $this->redirect(['index']);
    }

    /**
     * @throws NotFoundHttpException
     */
    protected function findModel(string $id): Topic
    {
        $model = Topic::find()->andWhere(['id' => $id])->one();
        if ($model === null) {
            throw new NotFoundHttpException(Yii::t('knowledge-library', 'The requested topic does not exist.'));
        }

        return $model;
    }
}
