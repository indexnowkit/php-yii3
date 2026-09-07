<?php

declare(strict_types=1);

use IndexNowKit\Yii3\Env;
use IndexNowKit\Yii3\Http\KeyFileHandler;
use IndexNowKit\Yii3\IndexNow;

/*
 * The `indexnowkit/yii3` block: the configuration tree of indexnowkit/core (every key of Config::OPTIONS) plus the
 * Yii3 blocks this package owns. Override any key in your application's params (config/common/params.php);
 * docs/configuration.md lists them all. `./yii indexnow:check` validates the result.
 */
return [
    'indexnowkit/yii3' => [
        'enabled' => true,
        'key' => Env::get('INDEXNOW_KEY'),                       // ./yii indexnow:key:generate --write-env
        'previous_key' => Env::get('INDEXNOW_PREVIOUS_KEY'),     // the key before a rotation: its file is still served
        'base_url' => Env::get('INDEXNOW_BASE_URL'),             // origin of URLs generated outside web requests (console)
        'dry_run' => Env::get('INDEXNOW_DRY_RUN'),               // null = auto (dry outside production_environments when no key)
        'dispatch' => 'sync',                                    // sync (after the response is sent) | none (collect, never send)
        'debounce' => [
            'per_url' => 600,
            'store' => IndexNow::DEFAULT_DEBOUNCE_STORE,        // a PSR-16 cache id in the container | memory | none
        ],
        'http' => [
            'timeout' => 10,
            'client' => null,                                    // a PSR-18 client id in the container; null = discovery
        ],
        'key_file' => [
            'enabled' => true,
            'pattern' => KeyFileHandler::DEFAULT_PATTERN,        // the route pattern of /<key>.txt
            'cache_max_age' => 300,
        ],
        'router' => [
            'locales' => [],                                     // for `locales: 'all'` on a rule
            'locale_parameter' => '_language',                   // the route argument the locale goes into
        ],
        'active_record' => [
            'enabled' => true,                                   // false = #[IndexNowEvents] hooks stay silent
            'namespaces' => ['App\\Model', 'App\\Entity'],       // where a short class name of submit-record / explain is looked up
            'models' => [],                                      // classes without #[IndexNowEvents] to hook (they still need EventsTrait)
        ],
        'logging' => [
            'category' => 'indexnow',                            // the log context category of every line
        ],
        'checks' => [],                                          // container ids of extra Check\CheckInterface lines for indexnow:check
    ],
];
