<?php

namespace dmstr\knowledgeLibrary\tests;

use dmstr\knowledgeLibrary\frontend\Module as FrontendModule;
use yii\helpers\ArrayHelper;

/**
 * Base test case for pages of the frontend module.
 *
 * Like WebTestCase, but requests run against the frontend module registered
 * as `knowledge` without layout. The backend module is registered as well,
 * as `knowledge-library` with the file storage `fs`, so the frontend module
 * resolves it through getBackendModule(). The permission `knowledge` of the
 * RBAC migrations grants access to all frontend routes:
 *
 * ```php
 * $this->loginAs('knowledge');
 * $html = $this->get('item/index');
 * $this->get('file/download', ['id' => $file->id]);
 * ```
 */
abstract class FrontendWebTestCase extends WebTestCase
{
    public const MODULE_ID = 'knowledge';

    public const BACKEND_MODULE_ID = 'knowledge-library';

    protected function applicationConfig(): array
    {
        return ArrayHelper::merge(parent::applicationConfig(), [
            'modules' => [
                static::BACKEND_MODULE_ID => $this->backendModuleConfig(),
            ],
        ]);
    }

    /**
     * Configuration of the frontend module, applied again for every request
     * of a test.
     */
    protected function moduleConfig(): array
    {
        return [
            'class' => FrontendModule::class,
            'layout' => false,
            'backendModuleId' => static::BACKEND_MODULE_ID,
        ];
    }

    /**
     * Configuration of the backend module the frontend module shares its
     * file storage with.
     */
    protected function backendModuleConfig(): array
    {
        return parent::moduleConfig();
    }
}
