<?php

declare(strict_types=1);

/*
 * Test bootstrap: composer autoload and a fixed environment name, so `production_environments` sees a
 * non-production environment (the fixtures set `dry_run` explicitly, as a staging copy must).
 */
require __DIR__ . '/../vendor/autoload.php';

$_ENV['YII_ENV'] = 'test';
