<?php

namespace dmstr\knowledgeLibrary\tests\support;

use dmstr\knowledgeLibrary\frontend\Module;
use yii\web\Controller;

/**
 * Controller used only by the frontend test infrastructure tests, registered
 * via the frontend module's `controllerMap` as `smoke`.
 *
 * @property Module $module
 */
class FrontendSmokeController extends Controller
{
    public function actionIndex(): string
    {
        return 'backend: ' . $this->module->getBackendModule()->getUniqueId();
    }
}
