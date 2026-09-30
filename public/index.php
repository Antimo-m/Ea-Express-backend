<?php

use App\Http\Middleware\LimitRequestInput;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

Request::enableHttpMethodParameterOverride();
$baseRequest = Symfony\Component\HttpFoundation\Request::createFromGlobals();
try {
    (new LimitRequestInput)->validatePayload($baseRequest);
} catch (HttpException $exception) {
    (new JsonResponse(['message' => $exception->getMessage()], $exception->getStatusCode()))->send();
    exit;
}
$app->handleRequest(Request::createFromBase($baseRequest));
