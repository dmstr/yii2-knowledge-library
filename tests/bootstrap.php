<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
// Leave error handling to PHPUnit, Yii's handler would be reported as risky.
defined('YII_ENABLE_ERROR_HANDLER') or define('YII_ENABLE_ERROR_HANDLER', false);

// Standalone checkout or installed inside an application's vendor directory.
$vendorDir = is_file(__DIR__ . '/../vendor/autoload.php')
    ? dirname(__DIR__) . '/vendor'
    : dirname(__DIR__, 3);

$loader = require $vendorDir . '/autoload.php';
// autoload-dev of this package is not registered when installed in an app.
$loader->addPsr4('dmstr\\knowledgeLibrary\\tests\\', __DIR__);

require $vendorDir . '/yiisoft/yii2/Yii.php';

define('KNOWLEDGE_LIBRARY_VENDOR_DIR', $vendorDir);

Yii::setAlias('@dmstr/knowledgeLibrary', dirname(__DIR__) . '/src');
