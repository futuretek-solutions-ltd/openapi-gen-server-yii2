<?php

/**
 * Test bootstrap file.
 *
 * Disables Yii2's error handler before loading the autoloader so it doesn't
 * interfere with Pest's exception-based test flow.
 */

defined('YII_ENABLE_ERROR_HANDLER') or define('YII_ENABLE_ERROR_HANDLER', false);
defined('YII_ENV') or define('YII_ENV', 'test');

require dirname(__DIR__) . '/vendor/autoload.php';

// Yii class is not in a namespace and is not autoloadable via PSR-4.
// It must be explicitly loaded for integration tests that create Yii2 Application instances.
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';


