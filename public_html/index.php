<?php

declare(strict_types=1);

use Nene2\Http\ResponseEmitter;
use Nene2\Http\RuntimeApplicationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

require dirname(__DIR__) . '/vendor/autoload.php';

$psr17Factory = new Psr17Factory();
$serverRequestCreator = new ServerRequestCreator(
    $psr17Factory,
    $psr17Factory,
    $psr17Factory,
    $psr17Factory,
);

$request = $serverRequestCreator->fromGlobals();
$application = (new RuntimeApplicationFactory($psr17Factory, $psr17Factory))->create();
$response = $application->handle($request);

(new ResponseEmitter())->emit($response);
