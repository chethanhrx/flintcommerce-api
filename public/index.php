<?php

declare(strict_types=1);

use App\Security\TrustedProxies;
use FlintPHP\Framework\Foundation\Application;

require __DIR__ . '/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__ . '/../bootstrap/app.php';
$app->boot();

$response = $app->kernel()->handleGlobals(TrustedProxies::configuration($app));
$response->send();
