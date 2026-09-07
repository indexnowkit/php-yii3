<?php

declare(strict_types=1);

use IndexNowKit\Yii3\Http\KeyFileHandler;

/*
 * Web only: the PSR-15 handler of `GET /<key>.txt` (config/routes.php). It needs the PSR-17 response and stream
 * factories of the application, which a console container does not have.
 */
return [
    KeyFileHandler::class => KeyFileHandler::class,
];
