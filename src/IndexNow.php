<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3;

use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Adapter\Services;
use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Attribute\IndexNow as IndexNowRule;
use IndexNowKit\Attribute\IndexNowDefaults;
use IndexNowKit\Attribute\RuleRegistry;
use IndexNowKit\Check\CheckerInterface;
use IndexNowKit\Config;
use IndexNowKit\Console\Vocabulary;
use IndexNowKit\Event;
use IndexNowKit\History\Adapter\HistoryServices;
use IndexNowKit\History\HistoryConfig;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyFileResponder;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Result;
use IndexNowKit\Sitemap\Adapter\SitemapServices;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\SitemapSourceInterface;
use IndexNowKit\Transaction\VerifyingStaging;
use IndexNowKit\Url\GuardedUrlResolver;
use IndexNowKit\Url\ResolvedUrl;
use IndexNowKit\Verify\Adapter\VerifyServices;
use IndexNowKit\Verify\RobotsCache;
use IndexNowKit\Verify\VerifyConfig;
use IndexNowKit\Yii3\ActiveRecord\IndexNowObserver;
use IndexNowKit\Yii3\Config\ConfigFactory;
use IndexNowKit\Yii3\Log\CategoryLogger;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface as Psr16;
use Yiisoft\ActiveRecord\ActiveRecordInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * The IndexNow service of a Yii3 application: the `indexnowkit/yii3` params block validated into a `Config`, the
 * core graph described once with `Adapter\ServicesBuilder` ({@see Wiring}: every node a definition of the container,
 * the defaults from the core's factories), the ActiveRecord observer with its verify-on-commit staging, and the
 * optional packages behind their predicates. Inject it, or any piece of the core by its interface (`config/di.php`).
 *
 *   final class PostService { public function __construct(private IndexNow $indexNow) {} }
 *   $this->indexNow->submitRecord($post);          // by hand; the #[IndexNowEvents] hook does it on save()
 *
 * Everything is lazy: a request that saves nothing builds nothing beyond this object.
 */
final class IndexNow
{
    /** The debounce store when `debounce.store` is unset: the PSR-16 cache of the container. */
    public const DEFAULT_DEBOUNCE_STORE = Psr16::class;
    public const DEFAULT_LOCALE_PARAMETER = '_language';
    public const DEFAULT_LOG_CATEGORY = 'indexnow';
    public const DEFAULT_NAMESPACES = ['App\\Model', 'App\\Entity'];
    /** How the check command is invoked, printed in the critical log line of an invalid configuration. */
    public const CHECK_COMMAND = './yii indexnow:check';
    /** Container id of the transport of the pre-flight GETs of indexnowkit/verify (`verify.timeout`, `verify.user_agent` over `http.client`); tests replace it. */
    public const VERIFY_TRANSPORT = 'indexnowkit/yii3.verify_transport';

    private readonly LoggerInterface $logger;
    private ?Config $config = null;
    private ?Wiring $wiring = null;
    private ?Services $services = null;
    private ?VerifyingStaging $staging = null;
    private ?IndexNowObserver $observer = null;
    private ?SitemapConfig $sitemapConfig = null;
    private ?SitemapSourceInterface $sitemap = null;
    private ?VerifyConfig $verifyConfig = null;
    private ?HistoryConfig $historyConfig = null;
    private ?RobotsCache $robots = null;
    private ?SubmitterFactoryInterface $submitterFactory = null;

    /**
     * @param array<string, mixed> $options          the `indexnowkit/yii3` params block (core options + the Yii3 blocks, see docs/configuration.md)
     * @param LoggerInterface|null $logger           the application's PSR-3 logger; every line gets the `logging.category` context (default `indexnow`)
     * @param string|null          $environment      the environment name for `production_environments`; default `YII_ENV` / `APP_ENV`
     * @param bool|null            $sitemapInstalled whether `indexnowkit/sitemap` is installed; null (the default) detects it, false makes the
     *                                               service behave as if the package were absent (tests, a deployment that must not read sitemaps)
     * @param bool|null            $verifyInstalled  the same for `indexnowkit/verify`
     * @param bool|null            $historyInstalled the same for `indexnowkit/history`
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $options = [],
        ?LoggerInterface $logger = null,
        private readonly ?string $environment = null,
        private readonly ?bool $sitemapInstalled = null,
        private readonly ?bool $verifyInstalled = null,
        private readonly ?bool $historyInstalled = null,
    ) {
        $category = $this->block('logging')['category'] ?? null;
        $this->logger = new CategoryLogger($logger ?? new NullLogger(), \is_string($category) && $category !== '' ? $category : self::DEFAULT_LOG_CATEGORY);
    }

    /** The words of the console commands: a Yii3 application manages records with `./yii`. */
    public static function vocabulary(): Vocabulary
    {
        return new Vocabulary(
            subject: 'record',
            subjects: 'records',
            cli: './yii',
            submitSubjects: 'indexnow:submit-record',
            configLocation: 'the indexnowkit/yii3 params block and the INDEXNOW_* env vars',
            keyFileServedBy: 'by the key file route of the package (config/routes.php)',
            check: 'indexnow:check',
            submit: 'indexnow:submit',
            explain: 'indexnow:explain',
        );
    }

    // -- the configuration ---------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * @return array<string, mixed>
     */
    public function block(string $name): array
    {
        $block = $this->options[$name] ?? null;

        return \is_array($block) ? $block : [];
    }

    public function environment(): ?string
    {
        return $this->environment ?? Env::name();
    }

    /** The runtime Config: an invalid value is one critical log line and a disabled Config until it is fixed. */
    public function config(): Config
    {
        return $this->config ??= ConfigFactory::create($this->options, $this->environment(), $this->sitemapPackage(), $this->verifyPackage(), $this->historyPackage(), $this->logger);
    }

    /**
     * The strict Config of `indexnow:check` and `indexnow:config`: throws on an invalid value.
     *
     * @throws \IndexNowKit\Exception\ConfigurationException
     */
    public function buildConfig(): Config
    {
        return ConfigFactory::build($this->options, $this->environment(), $this->sitemapPackage(), $this->verifyPackage(), $this->historyPackage());
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    public function activeRecordEnabled(): bool
    {
        return (bool) ($this->block('active_record')['enabled'] ?? true) && $this->config()->enabled;
    }

    /**
     * Namespaces a short class name of `indexnow:submit-record` / `indexnow:explain` is looked up in (`active_record.namespaces`).
     *
     * @return list<string>
     */
    public function namespaces(): array
    {
        $namespaces = $this->block('active_record')['namespaces'] ?? null;
        $list = \is_array($namespaces) ? array_values(array_filter($namespaces, static fn(mixed $n): bool => \is_string($n) && $n !== '')) : [];

        return $list === [] ? self::DEFAULT_NAMESPACES : $list;
    }

    /**
     * Classes hooked without `#[IndexNowEvents]` (`active_record.models`); they still need `EventsTrait`.
     *
     * @return list<class-string<ActiveRecordInterface>>
     */
    public function modelClasses(): array
    {
        $models = $this->block('active_record')['models'] ?? [];
        $classes = [];
        foreach (\is_array($models) ? $models : [] as $class) {
            if (\is_string($class) && is_subclass_of($class, ActiveRecordInterface::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** Whether a request was matched (the router set the current route): the pre-flight of verify then skips its delay. */
    public function inWebRequest(): bool
    {
        if (!$this->container->has(CurrentRoute::class)) {
            return false;
        }
        $route = $this->container->get(CurrentRoute::class);

        return $route instanceof CurrentRoute && $route->getUri() !== null;
    }

    // -- the graph -----------------------------------------------------------------------------------------------

    public function wiring(): Wiring
    {
        return $this->wiring ??= new Wiring($this, $this->container);
    }

    /**
     * The core graph (`Adapter\Services`) {@see Wiring} describes: every node read from the container, so a
     * definition the application replaced is what every dependent piece uses. Nothing is built before it is used.
     */
    public function services(): Services
    {
        return $this->services ??= $this->wiring()->services();
    }

    public function kit(): IndexNowKit
    {
        return $this->services()->kit();
    }

    public function rules(): RuleRegistry
    {
        return $this->services()->rules();
    }

    public function keys(): KeyProviderInterface
    {
        return $this->services()->keys();
    }

    public function guardedResolver(): GuardedUrlResolver
    {
        return $this->services()->guardedResolver();
    }

    public function keyFileResponder(): KeyFileResponder
    {
        return $this->services()->keyFileResponder();
    }

    public function checker(): CheckerInterface
    {
        return $this->services()->checker();
    }

    /** Commit safety without commit events: the staging the observer verifies at the end of the request. */
    public function staging(): VerifyingStaging
    {
        return $this->staging ??= new VerifyingStaging($this->logger, $this->config()->logUrls);
    }

    public function observer(): IndexNowObserver
    {
        return $this->observer ??= new IndexNowObserver($this, $this->staging(), $this->logger);
    }

    /**
     * The submitter factory of the commands (`--force`, `--dry-run`): the graph's, decorated with the pre-flight
     * when {@see verifyEnabled()}; {@see unverifiedSubmitterFactory()} is the plain one (`indexnow:sitemap --no-verify`).
     */
    public function submitterFactory(): SubmitterFactoryInterface
    {
        if ($this->submitterFactory === null) {
            $plain = $this->unverifiedSubmitterFactory();
            $this->submitterFactory = $this->verifyEnabled() ? VerifyServices::submitterFactoryFor($plain, $this->verifyConfig(), $this->services(), $this->verifyTransport(), $this->robots()) : $plain;
        }

        return $this->submitterFactory;
    }

    /** The plain command submitter factory of the graph (the same events, failure cache and submission store as the application's submitter). */
    public function unverifiedSubmitterFactory(): SubmitterFactoryInterface
    {
        return $this->services()->submitterFactory();
    }

    // -- the optional packages -----------------------------------------------------------------------------------

    /**
     * The optional `indexnowkit/sitemap` behind its one predicate: the `sitemapInstalled` argument, else detection.
     * The core's `OptionalPackage::sitemap()`, not the package's `SitemapServices::package()`: the package's class
     * cannot be loaded when the package is absent, and this predicate is what says so.
     */
    public function sitemapPackage(): OptionalPackage
    {
        return OptionalPackage::sitemap($this->sitemapInstalled);
    }

    public function sitemapInstalled(): bool
    {
        return $this->sitemapPackage()->installed();
    }

    /**
     * The validated `sitemap` block; a broken value disables the sitemap command with a critical log line.
     *
     * @throws LogicException when indexnowkit/sitemap is not installed
     */
    public function sitemapConfig(): SitemapConfig
    {
        $this->requireSitemap();

        return $this->sitemapConfig ??= SitemapServices::config($this->block('sitemap'), $this->logger, self::CHECK_COMMAND);
    }

    /**
     * @throws LogicException when indexnowkit/sitemap is not installed
     */
    public function sitemapSource(): SitemapSourceInterface
    {
        $this->requireSitemap();

        return $this->sitemap ??= SitemapServices::readerFor($this->sitemapConfig(), $this->services());
    }

    private function requireSitemap(): void
    {
        if (!$this->sitemapInstalled()) {
            throw new LogicException($this->sitemapPackage()->notInstalledMessage());
        }
    }

    /** The optional `indexnowkit/verify` behind its one predicate. */
    public function verifyPackage(): OptionalPackage
    {
        return OptionalPackage::verify($this->verifyInstalled);
    }

    public function verifyInstalled(): bool
    {
        return $this->verifyPackage()->installed();
    }

    /**
     * The validated `verify` block; a broken value switches the pre-flight off with a critical log line.
     *
     * @throws LogicException when indexnowkit/verify is not installed
     */
    public function verifyConfig(): VerifyConfig
    {
        $this->requireVerify();

        return $this->verifyConfig ??= VerifyServices::config($this->block('verify'), $this->logger, self::CHECK_COMMAND);
    }

    /** Whether the graph submits through the pre-flight: the package is installed and `verify.enabled` is on. */
    public function verifyEnabled(): bool
    {
        return $this->verifyInstalled() && $this->verifyConfig()->enabled;
    }

    /**
     * The transport of the pre-flight GETs: the container's {@see VERIFY_TRANSPORT} definition, whose default is
     * {@see defaultVerifyTransport()}.
     *
     * @throws LogicException when indexnowkit/verify is not installed
     */
    public function verifyTransport(): TransportInterface
    {
        $this->requireVerify();
        $transport = $this->container->get(self::VERIFY_TRANSPORT);
        if (!$transport instanceof TransportInterface) {
            throw new LogicException(\sprintf('The container definition "%s" must be a %s, got %s.', self::VERIFY_TRANSPORT, TransportInterface::class, get_debug_type($transport)));
        }

        return $transport;
    }

    /**
     * `verify.timeout` and `verify.user_agent` over `http.client` (looked up in the container when it names a service).
     *
     * @throws LogicException when indexnowkit/verify is not installed
     */
    public function defaultVerifyTransport(): TransportInterface
    {
        $this->requireVerify();

        return VerifyServices::transportFor($this->verifyConfig(), $this->services(), fn(string $id): mixed => $this->container->get($id));
    }

    /**
     * @throws LogicException when indexnowkit/verify is not installed
     */
    public function robots(): RobotsCache
    {
        $this->requireVerify();

        return $this->robots ??= VerifyServices::robotsFor($this->verifyConfig(), $this->services(), $this->verifyTransport());
    }

    private function requireVerify(): void
    {
        if (!$this->verifyInstalled()) {
            throw new LogicException($this->verifyPackage()->notInstalledMessage());
        }
    }

    /** The optional `indexnowkit/history` behind its one predicate. */
    public function historyPackage(): OptionalPackage
    {
        return OptionalPackage::history($this->historyInstalled);
    }

    public function historyInstalled(): bool
    {
        return $this->historyPackage()->installed();
    }

    /**
     * The validated `history` block; a broken value switches the history off with a critical log line.
     *
     * @throws LogicException when indexnowkit/history is not installed
     */
    public function historyConfig(): HistoryConfig
    {
        if (!$this->historyInstalled()) {
            throw new LogicException($this->historyPackage()->notInstalledMessage());
        }

        return $this->historyConfig ??= HistoryServices::config($this->block('history'), $this->logger, self::CHECK_COMMAND);
    }

    /** Whether the default submission store is a store of indexnowkit/history: the package is installed and `history.store` is set. */
    public function historyEnabled(): bool
    {
        return $this->historyInstalled() && $this->historyConfig()->store !== null;
    }

    // -- the application-facing API ------------------------------------------------------------------------------

    /**
     * Hooks an ActiveRecord class that carries no `#[IndexNowEvents]` (it still needs `EventsTrait`): with $rules,
     * they replace whatever `#[IndexNow]` attributes the class carries; without, the class's own attributes are used.
     *
     * @param class-string<ActiveRecordInterface> $class
     * @param list<IndexNowRule>                  $rules
     */
    public function observe(string $class, array $rules = [], ?IndexNowDefaults $defaults = null): void
    {
        if ($rules !== [] || $defaults !== null) {
            $this->rules()->register($class, $rules, $defaults);
        }
        $this->observer()->attachTo($class);
    }

    /**
     * @param iterable<string> $urls
     *
     * @return list<Result>
     */
    public function submit(iterable $urls): array
    {
        return $this->kit()->submit($urls);
    }

    /**
     * @return list<Result>
     */
    public function submitRecord(object $record, Event $event = Event::Updated): array
    {
        return $this->kit()->submitEntity($record, $event);
    }

    /**
     * The manual path after updateAll()/deleteAll()/link(), which fire no ActiveRecord events.
     *
     * @param iterable<object> $records
     *
     * @return list<Result>
     */
    public function submitRecords(iterable $records, Event $event = Event::Updated): array
    {
        return $this->kit()->submitEntities($records, $event);
    }

    /**
     * @return list<string>
     */
    public function urlsFor(object $record, Event $event = Event::Updated): array
    {
        return $this->kit()->urlsFor($record, $event);
    }

    /**
     * URLs the rules yield for many records, de-duplicated across the set.
     *
     * @param iterable<object> $records
     *
     * @return list<string>
     */
    public function urlsForAll(iterable $records, Event $event = Event::Updated): array
    {
        return $this->kit()->urlsForAll($records, $event);
    }

    /**
     * @return list<ResolvedUrl>
     */
    public function explain(object $record, Event $event = Event::Updated): array
    {
        return $this->kit()->explain($record, $event);
    }

    /**
     * @param iterable<string> $urls
     */
    public function collect(iterable $urls): void
    {
        $this->kit()->collect($urls);
    }

    /**
     * The end of a unit of work, by hand (a long-running command between batches): the changes staged inside
     * transactions that ended are verified and collected, then the collector is drained into the dispatcher.
     */
    public function flush(): void
    {
        $this->observer()->flushStaging();
        $this->kit()->flush();
    }

    /** The request-end hook (`AfterEmit`, `ApplicationShutdown`): nothing collected means nothing built, so an idle request pays nothing. */
    public function flushIfCollected(): void
    {
        $this->observer?->flushStaging();
        $this->services?->flushIfCollected();
    }
}
