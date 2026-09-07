<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Check;

use Closure;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Dispatch\NullDispatcher;
use IndexNowKit\Dispatch\SyncDispatcher;
use Throwable;

/**
 * How collected URLs leave: `sync` after the response, `none` never, or through a `DispatcherInterface` the
 * application replaced in the container (a queue of its own; yiisoft/queue has no stable release to build on).
 */
final class DispatchCheck implements CheckInterface
{
    public const CODE = 'dispatch.mode';

    /**
     * @param Closure(): DispatcherInterface $dispatcher the graph's dispatcher (built here, not before)
     */
    public function __construct(private readonly string $dispatch, private readonly Closure $dispatcher) {}

    public function check(CheckReport $report): void
    {
        try {
            $dispatcher = ($this->dispatcher)();
        } catch (Throwable $e) {
            $report->error(\sprintf('dispatch "%s": the dispatcher cannot be built (%s)', $this->dispatch, $e->getMessage()), self::CODE);

            return;
        }
        if (!$dispatcher instanceof SyncDispatcher && !$dispatcher instanceof NullDispatcher) {
            $report->ok(\sprintf('dispatch "%s": URLs go to %s (the DispatcherInterface definition of the application)', $this->dispatch, $dispatcher::class), self::CODE);

            return;
        }
        $report->ok(\sprintf('dispatch "%s": URLs are %s', $this->dispatch, $this->dispatch === 'none' ? 'collected but never sent (drain the collector yourself)' : 'sent synchronously after the response is sent (or when the command ends); 429/5xx are not retried'), self::CODE);
    }
}
