<?php

declare(strict_types=1);

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Yii3\Http\KeyFileHandler;
use Yiisoft\Router\Route;

/** @var array<string, mixed> $params */

/*
 * `GET /<key>.txt` -> the key itself, for a key of the requested host (404 otherwise). No route at all when
 * `key_file.enabled` is false (the web server serves the file then); an unreadable value registers nothing and
 * `./yii indexnow:check` reports it.
 */
/** @var array<string, mixed> $block */
$block = is_array($params['indexnowkit/yii3'] ?? null) ? $params['indexnowkit/yii3'] : [];
try {
    $enabled = Config::serveKeyFileFrom($block);
} catch (ConfigurationException) {
    $enabled = false;
}
$keyFile = $block['key_file'] ?? null;
$pattern = is_array($keyFile) ? ($keyFile['pattern'] ?? null) : null;

return $enabled
    ? [Route::get(is_string($pattern) && $pattern !== '' ? $pattern : KeyFileHandler::DEFAULT_PATTERN)->action(KeyFileHandler::class)->name(KeyFileHandler::ROUTE_NAME)]
    : [];
