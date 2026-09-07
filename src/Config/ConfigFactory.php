<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Config;

use IndexNowKit\Adapter\ConfigFactory as CoreConfigFactory;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\History\Adapter\HistoryServices;
use IndexNowKit\Sitemap\Adapter\SitemapServices;
use IndexNowKit\Verify\Adapter\VerifyServices;
use IndexNowKit\Yii3\Http\KeyFileHandler;
use IndexNowKit\Yii3\IndexNow;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds the runtime Config from the `indexnowkit/yii3` params block: the core's `Adapter\ConfigFactory` declared for
 * Yii3. Values usually come from `.env`, so they are only known at runtime: instead of throwing from an ActiveRecord
 * event or a listener, a broken value is logged once at critical and IndexNow runs disabled until fixed.
 * `./yii indexnow:check` prints the exact error.
 */
final class ConfigFactory
{
    /**
     * Keys this package owns on top of Config::OPTIONS (and the options of the optional packages when they are
     * installed), dotted-path form only: a bare block name in this list would stop unknownOptions() from checking
     * the keys inside the block. `checks` is a list at the top level, which is what a bare name means there.
     */
    public const YII3_OPTIONS = [
        'key_file.pattern',
        'router.locales', 'router.locale_parameter',
        'active_record.enabled', 'active_record.namespaces', 'active_record.models',
        'logging.category',
        'checks',
    ];

    /** The `dispatch` modes this adapter delivers: no queue until yiisoft/queue has a stable release (replace `DispatcherInterface` in the container for one). */
    public const DISPATCH_MODES = ['sync', 'none'];

    /** The defaults under the application's values when its params replace the block instead of merging into it. */
    public const DEFAULTS = [
        'dispatch' => 'sync',
        'key_file' => ['pattern' => KeyFileHandler::DEFAULT_PATTERN],
        'router' => ['locale_parameter' => IndexNow::DEFAULT_LOCALE_PARAMETER],
        'logging' => ['category' => IndexNow::DEFAULT_LOG_CATEGORY],
    ];

    /**
     * Without an optional package its block is ignored as a whole (no "unknown option" warning for options written
     * for the package); with it, its keys are owned and typos inside it are warned about.
     *
     * @param array<string, mixed> $options the `indexnowkit/yii3` block
     */
    public static function factory(array $options, OptionalPackage $sitemap, OptionalPackage $verify, OptionalPackage $history): CoreConfigFactory
    {
        return new CoreConfigFactory(
            ownedOptions: [
                ...self::YII3_OPTIONS,
                ...$sitemap->installed() ? SitemapServices::options() : [],
                ...$verify->installed() ? VerifyServices::options() : [],
                ...$history->installed() ? HistoryServices::options() : [],
            ],
            dispatchModes: self::DISPATCH_MODES,
            needBaseUrl: [],
            defaults: self::DEFAULTS,
            checkCommand: IndexNow::CHECK_COMMAND,
            ignoreBlocks: [...$sitemap->installed() ? [] : ['sitemap'], ...$verify->installed() ? [] : ['verify'], ...$history->installed() ? [] : ['history']],
        );
    }

    /**
     * Runtime path: never throws.
     *
     * @param array<string, mixed> $options
     */
    public static function create(array $options, ?string $environment, OptionalPackage $sitemap, OptionalPackage $verify, OptionalPackage $history, ?LoggerInterface $logger = null): Config
    {
        return self::factory($options, $sitemap, $verify, $history)->load($options, $environment, $logger ?? new NullLogger());
    }

    /**
     * Strict path (`indexnow:check`, tests).
     *
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     */
    public static function build(array $options, ?string $environment, OptionalPackage $sitemap, OptionalPackage $verify, OptionalPackage $history): Config
    {
        return self::factory($options, $sitemap, $verify, $history)->build($options, $environment);
    }
}
