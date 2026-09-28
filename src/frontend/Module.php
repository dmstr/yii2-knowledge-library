<?php

namespace dmstr\knowledgeLibrary\frontend;

use dmstr\knowledgeLibrary\traits\RegistersTranslationsTrait;
use dmstr\web\traits\AccessBehaviorTrait;

/**
 * Frontend module rendering the currently valid content of knowledge items.
 *
 * Access is granted by a permission named exactly like the module ID, e.g.
 * `knowledge` when the module is registered as `knowledge`.
 */
class Module extends \yii\base\Module
{
    use AccessBehaviorTrait;
    use RegistersTranslationsTrait;

    /**
     * ID of the backend module whose configuration (file storage, user
     * provider) the frontend module shares.
     */
    public ?string $backendModuleId = 'knowledge-library';

    public function init()
    {
        parent::init();
        $this->registerTranslations();
    }
}
