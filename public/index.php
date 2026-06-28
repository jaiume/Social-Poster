<?php

declare(strict_types=1);

use DI\Bridge\Slim\Bridge;
use Slim\Factory\AppFactory;

define('BASE_DIR', dirname(__DIR__));

require BASE_DIR . '/vendor/autoload.php';

$container = require BASE_DIR . '/config/container.php';
AppFactory::setContainer($container);
$app = Bridge::create($container);

(require BASE_DIR . '/config/routes.php')($app);

$app->run();
