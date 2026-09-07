<?php

declare(strict_types=1);

use IndexNowKit\Key\KeyFileRequestHandler;
use IndexNowKit\Yii3\Http\KeyFileHandler;

/*
 * Web only: the PSR-15 handler of `GET /<key>.txt` (config/routes.php) over the core's `Key\KeyFileRequestHandler`,
 * which builds the PSR-7 response (autowired: the graph's responder and Config of di.php, the PSR-17 response and
 * stream factories of the application, which a console container does not have).
 */
return [
    KeyFileRequestHandler::class => KeyFileRequestHandler::class,
    KeyFileHandler::class => KeyFileHandler::class,
];
