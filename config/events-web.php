<?php

declare(strict_types=1);

use IndexNowKit\Yii3\Event\FlushListener;

/*
 * After the response was emitted: the URLs collected during the request leave in one batch, after the changes
 * made inside a transaction were verified against the database (conformance H06).
 */
return [
    \Yiisoft\Yii\Http\Event\AfterEmit::class => [FlushListener::class],
];
