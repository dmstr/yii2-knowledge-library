<?php

namespace dmstr\knowledgeLibrary\frontend\controllers;

use dmstr\knowledgeLibrary\frontend\Module;
use Yii;
use yii\filters\VerbFilter;
use yii\helpers\ArrayHelper;
use yii\web\Controller;

/**
 * Base class of the frontend controllers of the knowledge library.
 *
 * Access control is done by the module (`AccessBehaviorTrait`), controllers
 * add no access filter of their own. All frontend pages are read-only.
 *
 * @property Module $module
 */
abstract class BaseController extends Controller
{
    public function behaviors()
    {
        return ArrayHelper::merge(parent::behaviors(), [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => $this->verbs(),
            ],
        ]);
    }

    /**
     * Allowed HTTP methods by action ID, see `VerbFilter::$actions`.
     *
     * Subclasses list their actions, usually as GET only.
     *
     * @return array<string, string[]>
     */
    protected function verbs(): array
    {
        return [];
    }

    /**
     * Root breadcrumb of all frontend pages, linking to the list of the valid
     * items.
     */
    public function getRootBreadcrumb(): array
    {
        return [
            'label' => Yii::t('knowledge-library', 'Knowledge Library'),
            'url' => ['/' . $this->module->getUniqueId() . '/item/index'],
        ];
    }

    /**
     * Sets the breadcrumbs of the page (`$this->view->params['breadcrumbs']`)
     * to the root breadcrumb followed by the given ones. Views call it as
     * `$this->context->setBreadcrumbs([...])`.
     *
     * @param array<string|array> $crumbs
     */
    public function setBreadcrumbs(array $crumbs = []): void
    {
        $this->getView()->params['breadcrumbs'] = array_merge([$this->getRootBreadcrumb()], $crumbs);
    }
}
