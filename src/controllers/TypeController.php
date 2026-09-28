<?php

namespace dmstr\knowledgeLibrary\controllers;

use dmstr\knowledgeLibrary\models\Type;
use Yii;
use yii\data\ActiveDataProvider;
use yii\db\IntegrityException;
use yii\helpers\Html;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Manages the types of knowledge items.
 */
class TypeController extends BaseController
{
    /**
     * Lists all types with the number of knowledge items using them.
     */
    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => Type::find()->orderedByName()->withItemCount(),
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
        $model = new Type();
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
     * Deletes the type unless knowledge items use it; the reason is shown as
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
            Yii::warning("Type $model->id not deleted: {$e->getMessage()}", 'knowledge-library');
        }

        if ($deleted) {
            $session->setFlash('success', Yii::t('knowledge-library', 'Type "{name}" deleted.', [
                'name' => Html::encode($model->name),
            ]));
        } else {
            $session->setFlash(
                'error',
                $model->getFirstError('name') ?? Yii::t('knowledge-library', 'The type could not be deleted.')
            );
        }

        return $this->redirect(['index']);
    }

    private function redirectSaved(Type $model): Response
    {
        Yii::$app->getSession()->setFlash('success', Yii::t('knowledge-library', 'Type "{name}" saved.', [
            'name' => Html::encode($model->name),
        ]));

        return $this->redirect(['index']);
    }

    /**
     * @throws NotFoundHttpException
     */
    protected function findModel(string $id): Type
    {
        $model = Type::find()->andWhere(['id' => $id])->one();
        if ($model === null) {
            throw new NotFoundHttpException(Yii::t('knowledge-library', 'The requested type does not exist.'));
        }

        return $model;
    }
}
