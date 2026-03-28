<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader... (Remove output buffering for the MadelineProto library)
$isWindows = PHP_OS_FAMILY === 'Windows';
if ($isWindows) {
    ob_start();
}

require __DIR__.'/../vendor/autoload.php';

if ($isWindows) {
    ob_end_clean();
}
// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
