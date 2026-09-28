<?php

namespace dmstr\knowledgeLibrary\tests\support;

use dmstr\knowledgeLibrary\controllers\BaseController;
use yii\base\DynamicModel;
use yii\data\ArrayDataProvider;

/**
 * Controller used only by the web test infrastructure tests, registered via
 * the module's `controllerMap` as `smoke`.
 */
class SmokeController extends BaseController
{
    public function getViewPath()
    {
        return __DIR__ . '/views/smoke';
    }

    public function actionIndex(): string
    {
        $this->setBreadcrumbs(['Smoke']);

        return $this->render('index', [
            'model' => new DynamicModel(['name' => null, 'topicIds' => []]),
            'dataProvider' => new ArrayDataProvider([
                'allModels' => [['name' => 'First'], ['name' => 'Second']],
            ]),
        ]);
    }

    public function actionSave()
    {
        \Yii::$app->session->setFlash('success', 'Saved ' . $this->request->getBodyParam('name') . '.');

        return $this->redirect(['index']);
    }

    public function actionDelete(): string
    {
        return 'deleted';
    }
}
