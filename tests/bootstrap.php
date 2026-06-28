<?php

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

putenv('SOCIAL_POSTER_CONFIG=' . BASE_DIR . '/tests/fixtures/config.ini');
$_ENV['SOCIAL_POSTER_CONFIG'] = BASE_DIR . '/tests/fixtures/config.ini';
$_SERVER['SOCIAL_POSTER_CONFIG'] = BASE_DIR . '/tests/fixtures/config.ini';

require BASE_DIR . '/vendor/autoload.php';
