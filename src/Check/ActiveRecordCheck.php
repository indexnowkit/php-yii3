<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Check;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;

/**
 * Whether ActiveRecord changes reach IndexNow on their own: the hooks are enabled and the observer the
 * `#[IndexNowEvents]` handlers forward to is installed (the package's bootstrap ran).
 */
final class ActiveRecordCheck implements CheckInterface
{
    public const CODE = 'active_record.enabled';

    /**
     * @param list<class-string> $models classes hooked through `active_record.models`
     */
    public function __construct(private readonly bool $enabled, private readonly bool $observerSet, private readonly array $models = []) {}

    public function check(CheckReport $report): void
    {
        if (!$this->enabled) {
            $report->warning('active record: hooks are NOT active (active_record.enabled or enabled is false); use indexnow:submit or IndexNow::submit()', self::CODE);

            return;
        }
        if (!$this->observerSet) {
            $report->error('active record: the observer is not installed, #[IndexNowEvents] records submit nothing. The package\'s config/bootstrap.php did not run: check that the "bootstrap" config group of yiisoft/config reaches the application runner (bootstrap-web / bootstrap-console)', self::CODE);

            return;
        }
        $report->ok(\sprintf('active record: records with #[IndexNowEvents] (and EventsTrait)%s are submitted automatically after the response (changes inside a transaction are verified against the row first)', $this->models !== [] ? ' and ' . implode(', ', $this->models) : ''), self::CODE);
    }
}
