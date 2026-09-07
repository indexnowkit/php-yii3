<?php

declare(strict_types=1);

use IndexNowKit\Yii3\Event\FlushListener;

/*
 * When a console command ends: the URLs it collected leave in one batch. A long-running command calls
 * `IndexNow::flush()` itself between units of work.
 */
return [
    \Yiisoft\Yii\Console\Event\ApplicationShutdown::class => [FlushListener::class],
];
