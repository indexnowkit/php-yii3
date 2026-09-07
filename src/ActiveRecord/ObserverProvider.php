<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\ActiveRecord;

/**
 * The one observer the `#[IndexNowEvents]` handlers forward to. yiisoft/active-record instantiates attribute
 * handlers by reflection, outside the container, so the observer reaches them the way the connection reaches
 * records: through a static provider (`ConnectionProvider` of yiisoft/db), set once per process by the package's
 * `config/bootstrap.php`. Without {@see set()} the handlers warn once (E_USER_WARNING, the log of the SAPI) and stay
 * silent: a save never breaks because IndexNow is not wired.
 */
final class ObserverProvider
{
    private static ?IndexNowObserver $observer = null;
    private static bool $warned = false;

    private function __construct() {}

    public static function set(IndexNowObserver $observer): void
    {
        self::$observer = $observer;
        self::$warned = false;
    }

    public static function get(): ?IndexNowObserver
    {
        if (self::$observer === null && !self::$warned) {
            self::$warned = true;
            trigger_error('indexnowkit/yii3: a record with #[IndexNowEvents] was saved but the observer is not installed: the package\'s config/bootstrap.php did not run (is the "bootstrap" config group of yiisoft/config loaded by the application runner?). Nothing is submitted until it is.', E_USER_WARNING);
        }

        return self::$observer;
    }

    public static function isSet(): bool
    {
        return self::$observer !== null;
    }

    /** Tests: the next process-wide state. */
    public static function reset(): void
    {
        self::$observer = null;
        self::$warned = false;
    }
}
