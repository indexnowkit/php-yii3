<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Event;

use IndexNowKit\Yii3\IndexNow;

/**
 * The end of the unit of work: `AfterEmit` of yiisoft/yii-http (the response was sent, the client waits for
 * nothing) and `ApplicationShutdown` of yiisoft/yii-console (the command ended). Registered by the package's
 * `config/events-web.php` and `config/events-console.php`; the event itself is not read.
 */
final class FlushListener
{
    public function __construct(private readonly IndexNow $indexNow) {}

    public function __invoke(object $event): void
    {
        $this->indexNow->flushIfCollected();
    }
}
