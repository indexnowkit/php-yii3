<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\ActiveRecord;

use Psr\Log\LoggerInterface;

/**
 * The one observer the `#[IndexNowEvents]` handlers forward to. yiisoft/active-record instantiates attribute
 * handlers by reflection, outside the container, so the observer reaches them the way the connection reaches
 * records: through a static provider (`ConnectionProvider` of yiisoft/db), set once per process by the package's
 * `config/bootstrap.php`. Without {@see set()} the handlers warn once and stay silent: a save never breaks because
 * IndexNow is not wired. The warning goes to the PSR-3 logger {@see set()} was given, or — before any bootstrap ran,
 * when there is no logger to give — to the SAPI's log through E_USER_WARNING.
 */
final class ObserverProvider
{
    private const NOT_INSTALLED = 'indexnowkit/yii3: a record with #[IndexNowEvents] was saved but the observer is not installed: the package\'s config/bootstrap.php did not run (is the "bootstrap" config group of yiisoft/config loaded by the application runner?). Nothing is submitted until it is.';

    private static ?IndexNowObserver $observer = null;
    private static ?LoggerInterface $logger = null;
    private static bool $warned = false;

    private function __construct() {}

    /**
     * @param LoggerInterface|null $logger where a save without an observer is warned about after {@see reset()}; null keeps E_USER_WARNING
     */
    public static function set(IndexNowObserver $observer, ?LoggerInterface $logger = null): void
    {
        self::$observer = $observer;
        self::$logger = $logger;
        self::$warned = false;
    }

    public static function get(): ?IndexNowObserver
    {
        if (self::$observer === null && !self::$warned) {
            self::$warned = true;
            if (self::$logger !== null) {
                self::$logger->warning(self::NOT_INSTALLED);
            } else {
                trigger_error(self::NOT_INSTALLED, E_USER_WARNING);
            }
        }

        return self::$observer;
    }

    public static function isSet(): bool
    {
        return self::$observer !== null;
    }

    /**
     * The next process-wide state, for tests only: the observer gives every class it wrapped its own dispatcher
     * back ({@see IndexNowObserver::detach()}), so neither it nor the container behind it is held by the static
     * provider of yiisoft/active-record. An application never calls this — a request does not undo its bootstrap.
     *
     * @internal test support (`Fixtures::destroy()` of the package's suite); not part of the BC promise
     */
    public static function reset(): void
    {
        self::$observer?->detach();
        self::$observer = null;
        self::$warned = false;
    }

    /**
     * The next process-wide state including the logger, for tests only ({@see reset()} keeps the logger, so a test
     * that undoes the bootstrap still sees the warning where the application would).
     *
     * @internal test support; not part of the BC promise
     */
    public static function resetLogger(): void
    {
        self::$logger = null;
    }
}
