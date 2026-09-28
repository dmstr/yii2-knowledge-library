<?php

namespace dmstr\knowledgeLibrary\controllers;

use Yii;
use yii\filters\VerbFilter;
use yii\helpers\ArrayHelper;
use yii\web\Controller;

/**
 * Base class of the backend controllers of the knowledge library.
 *
 * Access control is done by the module (`AccessBehaviorTrait`), controllers
 * add no access filter of their own.
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
     * `delete` is POST only. Subclasses extend the map by merging with the
     * parent result, e.g. `array_merge(parent::verbs(), ['archive' => ['POST']])`.
     *
     * @return array<string, string[]>
     */
    protected function verbs(): array
    {
        return [
            'delete' => ['POST'],
        ];
    }

    /**
     * Whether the current user may use the route `<controller>/<action>` of
     * the module, checked like the access control of the module
     * (`AccessBehaviorTrait`).
     */
    public function canRoute(string $controllerId, string $actionId): bool
    {
        $permission = str_replace(
            '/',
            '_',
            trim($this->module->getUniqueId(), '/') . '_' . $controllerId . '_' . $actionId
        );

        return Yii::$app->getUser()->can($permission, ['route' => true]);
    }

    /**
     * Root breadcrumb of all backend pages, linking to the item library.
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
     * to the root breadcrumb followed by the given ones.
     *
     * Each crumb is a label string or a `yii\widgets\Breadcrumbs` link array,
     * the last one usually a plain label of the current page. Views call it as
     * `$this->context->setBreadcrumbs([...])`.
     *
     * @param array<string|array> $crumbs
     */
    public function setBreadcrumbs(array $crumbs = []): void
    {
        $this->getView()->params['breadcrumbs'] = array_merge([$this->getRootBreadcrumb()], $crumbs);
    }
}
