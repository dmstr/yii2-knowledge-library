<?php

namespace dmstr\knowledgeLibrary\frontend;

use dmstr\knowledgeLibrary\Module as BackendModule;
use dmstr\web\traits\AccessBehaviorTrait;
use Yii;
use yii\base\InvalidConfigException;

/**
 * Frontend module rendering the currently valid content of knowledge items.
 *
 * Access is granted by a permission named exactly like the module ID, e.g.
 * `knowledge` when the module is registered as `knowledge`.
 */
class Module extends \yii\base\Module
{
    use AccessBehaviorTrait;

    /**
     * ID of the backend module whose configuration (file storage, user
     * provider) the frontend module shares.
     */
    public ?string $backendModuleId = 'knowledge-library';

    /**
     * The list of the valid knowledge items.
     */
    public $defaultRoute = 'item';

    public function init()
    {
        parent::init();
    }

    /**
     * Backend module registered in the application as `backendModuleId`.
     *
     * @throws InvalidConfigException if no module is registered under that ID
     * or it is no backend module of the package
     */
    public function getBackendModule(): BackendModule
    {
        $module = $this->backendModuleId === null || $this->backendModuleId === ''
            ? null
            : Yii::$app->getModule($this->backendModuleId);

        if (!$module instanceof BackendModule) {
            throw new InvalidConfigException(sprintf(
                'The backend module "%s" must be registered as %s, got %s.',
                (string)$this->backendModuleId,
                BackendModule::class,
                get_debug_type($module)
            ));
        }

        return $module;
    }
}
