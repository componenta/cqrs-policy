<?php

declare(strict_types=1);

$package = dirname(__DIR__);
if (is_file($package . '/vendor/autoload.php')) {
    $loader = require $package . '/vendor/autoload.php';
} else {
    require dirname($package) . '/cqrs/tests/bootstrap.php';
}
$loader->addPsr4('Componenta\\CQRS\\', dirname(__DIR__) . '/src', true);
$loader->addPsr4('Componenta\\CQRS\\Tests\\', __DIR__, true);
$loader->addPsr4('Componenta\\CQRS\\', dirname(__DIR__, 2) . '/cqrs-transport/src', true);

if (!is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    $loader->setPsr4('Componenta\\Policy\\', dirname(__DIR__, 2) . '/policy/src');
}
