<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3;

use Closure;
use IndexNowKit\Adapter\Services;
use IndexNowKit\Adapter\ServicesBuilder;
use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\DebounceStoreCheck;
use IndexNowKit\ClientInterface;
use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\History\Adapter\HistoryServices;
use IndexNowKit\History\HistoryConfig;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Sitemap\Adapter\SitemapServices;
use IndexNowKit\Submission\NullSubmissionStore;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\Submitter;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Url\ArrayResolverLocator;
use IndexNowKit\Url\ObjectChangeHandler;
use IndexNowKit\Url\ResolverLocatorInterface;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlNormalizerInterface;
use IndexNowKit\Url\UrlResolverInterface;
use IndexNowKit\Verify\Adapter\VerifyServices;
use IndexNowKit\Yii3\ActiveRecord\ActiveRecordSubjectReader;
use IndexNowKit\Yii3\ActiveRecord\ObserverProvider;
use IndexNowKit\Yii3\Check\ActiveRecordCheck;
use IndexNowKit\Yii3\Check\CacheProbe;
use IndexNowKit\Yii3\Check\DispatchCheck;
use IndexNowKit\Yii3\Check\RouterCheck;
use IndexNowKit\Yii3\Check\SampleOptions;
use IndexNowKit\Yii3\Check\VerifySampleCheck;
use IndexNowKit\Yii3\Url\YiiRouteUrlResolver;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\SimpleCache\CacheInterface as Psr16;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Driver\Pdo\PdoConnectionInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\RouteCollectionInterface;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * The core graph of {@see IndexNow}, described with `Adapter\ServicesBuilder` twice over:
 *
 * - {@see services()} is the graph the application runs on: every node is read from the container by its interface
 *   (`TransportInterface`, `SubmitterInterface`, …, the definitions of `config/di.php`), so a definition the
 *   application replaced in its own `di/` is what every dependent piece uses.
 * - {@see transport()}, {@see client()}, {@see submitter()}, … are the defaults of those definitions: each is the
 *   core's own factory for that one node, over a graph whose *other* nodes come from the container. Replace the
 *   transport and the default client is built over your transport; replace nothing and the graph is the one
 *   `IndexNowKit::create()` builds.
 *
 * What is Yii3's here: `http.client` and `debounce.store` are container ids, the PSR-14 dispatcher of the container
 * receives every `Result`, the router bridge is over `UrlGeneratorInterface`, `#[IndexNow(resolver: ...)]` ids are
 * container ids, the stores of indexnowkit/history are over the container's `ConnectionInterface` and PSR-16 cache,
 * and {@see checks()} lists what `./yii indexnow:check` prints beyond the core's own lines.
 */
final class Wiring
{
    /** Node of the graph => the container id it is read from. */
    public const NODES = [
        Services::TRANSPORT => TransportInterface::class,
        Services::KEYS => KeyProviderInterface::class,
        Services::NORMALIZER => UrlNormalizerInterface::class,
        Services::THROTTLE => ThrottleInterface::class,
        Services::DEBOUNCE_STORE => DebounceStoreInterface::class,
        Services::SUBMISSION_STORE => SubmissionStoreInterface::class,
        Services::CLIENT => ClientInterface::class,
        Services::SUBMITTER => SubmitterInterface::class,
        Services::COLLECTOR => CollectorInterface::class,
        Services::DISPATCHER => DispatcherInterface::class,
        Services::READER => AttributeReaderInterface::class,
        Services::ROUTER => RouteUrlResolverInterface::class,
        Services::RESOLVER_LOCATOR => ResolverLocatorInterface::class,
        Services::CLOCK => ClockInterface::class,
        Services::CHANGES => ObjectChangeHandler::class,
        Services::URL_RESOLVER => UrlResolverInterface::class,
        Services::PARAM_EXTRACTOR => ParamExtractor::class,
    ];

    public function __construct(private readonly IndexNow $indexNow, private readonly ContainerInterface $container) {}

    /** The graph the application runs on: every node from the container; nothing built before it is used. */
    public function services(): Services
    {
        return $this->builder(null)->build();
    }

    // -- the defaults of the container definitions, one per node ---------------------------------------------------

    /** `http.client` (a container id) through `Http\TransportFactory::lazy()`, else PSR-18 discovery; nothing is built before the first request. */
    public function transport(): TransportInterface
    {
        return $this->graph(Services::TRANSPORT)->transport();
    }

    public function keys(): KeyProviderInterface
    {
        return $this->graph(Services::KEYS)->keys();
    }

    public function normalizer(): UrlNormalizerInterface
    {
        return $this->graph(Services::NORMALIZER)->normalizer();
    }

    public function throttle(): ThrottleInterface
    {
        return $this->graph(Services::THROTTLE)->throttle();
    }

    /** `debounce.store`: `memory`, `none`, or the id of a PSR-16 cache in the container (the container's `CacheInterface` by default). */
    public function debounceStore(): DebounceStoreInterface
    {
        return $this->graph(Services::DEBOUNCE_STORE)->debounceStore();
    }

    /** The store of `history.store` (indexnowkit/history) when it is set, else the null store: replace it with your own in the container. */
    public function submissionStore(): SubmissionStoreInterface
    {
        return $this->graph(Services::SUBMISSION_STORE)->submissionStore() ?? new NullSubmissionStore();
    }

    public function client(): ClientInterface
    {
        return $this->graph(Services::CLIENT)->client();
    }

    /** The core submitter, decorated with the pre-flight of indexnowkit/verify when `verify.enabled`. */
    public function submitter(): SubmitterInterface
    {
        return $this->graph(Services::SUBMITTER)->submitter();
    }

    public function collector(): CollectorInterface
    {
        return $this->graph(Services::COLLECTOR)->collector();
    }

    /** `dispatch`: `sync` or `none`. For a queue, replace this definition (yiisoft/queue has no stable release yet). */
    public function dispatcher(): DispatcherInterface
    {
        return $this->graph(Services::DISPATCHER)->dispatcher();
    }

    public function reader(): AttributeReaderInterface
    {
        return $this->graph(Services::READER)->reader();
    }

    /** The bridge over `UrlGeneratorInterface` with the `router` block (locales, the locale argument). */
    public function router(): RouteUrlResolverInterface
    {
        return $this->graph(Services::ROUTER)->router() ?? throw new ConfigurationException('The router bridge could not be built.');
    }

    /** `#[IndexNow(resolver: ...)]` ids: a container id or a class the container can build. */
    public function resolverLocator(): ResolverLocatorInterface
    {
        return $this->graph(Services::RESOLVER_LOCATOR)->resolverLocator() ?? throw new ConfigurationException('The resolver locator could not be built.');
    }

    public function clock(): ClockInterface
    {
        return $this->graph(Services::CLOCK)->clock();
    }

    public function changes(): ObjectChangeHandler
    {
        return $this->graph(Services::CHANGES)->changes();
    }

    public function urlResolver(): UrlResolverInterface
    {
        return $this->graph(Services::URL_RESOLVER)->urlResolver();
    }

    /** How `params` and `when` are read off records: attributes and relations through Active Record, the rest through the core DSL. */
    public function paramExtractor(): ParamExtractor
    {
        return $this->graph(Services::PARAM_EXTRACTOR)->paramExtractor();
    }

    // -- the lines of `./yii indexnow:check` -------------------------------------------------------------------------

    /**
     * Beyond the core's own lines: the Yii3 pieces, the optional packages, then the `checks` option.
     *
     * @return list<CheckInterface>
     */
    public function checks(Services $services): array
    {
        $indexNow = $this->indexNow;
        $routes = $this->container->has(RouteCollectionInterface::class) ? $this->container->get(RouteCollectionInterface::class) : null;
        $checks = [
            new DispatchCheck($services->config->dispatch, static fn(): DispatcherInterface => $services->dispatcher()),
            new DebounceStoreCheck($services->config, (new CacheProbe($this->container))(...), IndexNow::DEFAULT_DEBOUNCE_STORE),
            new RouterCheck($indexNow->options(), $routes instanceof RouteCollectionInterface ? $routes : null),
            new ActiveRecordCheck($indexNow->activeRecordEnabled(), ObserverProvider::isSet(), $indexNow->modelClasses()),
            $indexNow->sitemapInstalled() ? SitemapServices::spoolCheck($indexNow->sitemapConfig()) : $indexNow->sitemapPackage()->check($indexNow->block('sitemap')),
            ...$indexNow->verifyInstalled()
                ? VerifyServices::checksFor($indexNow->verifyConfig(), $services, 'a replaced DispatcherInterface', new VerifySampleCheck($this->samples(), VerifyServices::sampleCheck($indexNow->verifyTransport(), $indexNow->verifyConfig(), $services->normalizer(), $services->keys(), $this->recordSampler(), $indexNow->robots())))
                : [new VerifySampleCheck($this->samples(), null, $indexNow->verifyPackage()->checkLine($indexNow->block('verify')), $indexNow->verifyPackage()->checkLevel($indexNow->block('verify')))],
            ...$indexNow->historyInstalled()
                ? HistoryServices::checksFor($indexNow->historyConfig(), $services)
                : [$indexNow->historyPackage()->check($indexNow->block('history'))],
        ];
        $extra = $indexNow->options()['checks'] ?? [];
        foreach (\is_array($extra) ? $extra : [] as $id) {
            $check = \is_string($id) ? $this->container->get($id) : $id;
            if (!$check instanceof CheckInterface) {
                throw new ConfigurationException(\sprintf('"checks" must list container ids of %s implementations, got %s.', CheckInterface::class, \is_string($id) ? $id : get_debug_type($id)));
            }
            $checks[] = $check;
        }

        return $checks;
    }

    /** The `--sample` / `--sample-class` values of the running `indexnow:check`: one holder in the container, the command fills it. */
    private function samples(): SampleOptions
    {
        $samples = $this->container->get(SampleOptions::class);
        \assert($samples instanceof SampleOptions);

        return $samples;
    }

    /**
     * The `--sample-class` sampler, read when the check runs (the checker is built with the command, the command
     * fills the holder before it runs).
     *
     * @return Closure(string, string|null): list<string>
     */
    private function recordSampler(): Closure
    {
        $samples = $this->samples();

        return static function (string $class, ?string $id) use ($samples): array {
            if ($samples->sampler === null) {
                throw new ConfigurationException('check --sample-class needs the record sampler of the check command.');
            }

            return ($samples->sampler)($class, $id);
        };
    }

    // -- the graphs ------------------------------------------------------------------------------------------------

    /** A graph whose nodes come from the container except $node, which gets the core's default with the Yii3 pieces. */
    private function graph(string $node): Services
    {
        return $this->builder($node)->build();
    }

    /**
     * @param string|null $except the node built by the core's factory (with the Yii3 pieces below) instead of read from the container; null = none
     */
    private function builder(?string $except): ServicesBuilder
    {
        $indexNow = $this->indexNow;
        $container = $this->container;
        $config = $indexNow->config();
        $builder = new ServicesBuilder($config, $indexNow->logger());
        foreach (self::NODES as $node => $id) {
            if ($node !== $except) {
                self::pull($builder, $node, static fn(): object => self::service($container, $id));
            }
        }
        // the pieces of the framework the defaults are built from
        $builder->httpClientLocator(static fn(string $id): mixed => $container->get($id));
        if ($container->has(EventDispatcherInterface::class)) {
            // every Result goes to the application's PSR-14 dispatcher: listen to IndexNowKit\Result in events.php
            $builder->events(static fn(): object => self::service($container, EventDispatcherInterface::class));
        }
        $store = $config->debounceStore ?? IndexNow::DEFAULT_DEBOUNCE_STORE;
        if (!\in_array($store, [DebounceStoreFactory::MEMORY, DebounceStoreFactory::NONE], true)) {
            // The 403 counter (and the robots cache of verify) share the PSR-16 cache behind `debounce.store`; memory/none leave it in the process.
            $builder->failureCache(static fn(): ?Psr16 => $container->has($store) && ($cache = $container->get($store)) instanceof Psr16 ? $cache : null);
        }
        match ($except) {
            Services::DEBOUNCE_STORE => $builder->debounceStore(static fn(Services $s): DebounceStoreInterface => DebounceStoreFactory::fromConfig($s->config, static fn(string $id): mixed => $container->get($id), IndexNow::DEFAULT_DEBOUNCE_STORE, $s->clock())),
            Services::SUBMISSION_STORE => $indexNow->historyEnabled() ? $builder->submissionStore(static fn(Services $s): SubmissionStoreInterface => self::historyStore($indexNow->historyConfig(), $s, $container)) : null,
            Services::SUBMITTER => $indexNow->verifyEnabled() ? $builder->submitter(static fn(Services $s): SubmitterInterface => VerifyServices::submitterFor(
                new Submitter($s->client(), $s->config, $s->debounceStore(), $s->logger, $s->normalizer(), $s->events(), $s->submissionStore(), $s->clock()),
                $indexNow->verifyConfig(),
                $s,
                $indexNow->verifyTransport(),
                $indexNow->robots(),
                $s->config->dispatch === 'sync' && $indexNow->inWebRequest(),
            )) : null,
            Services::PARAM_EXTRACTOR => $builder->paramExtractor(static fn(): ParamExtractor => new ParamExtractor(new ActiveRecordSubjectReader())),
            Services::ROUTER => $builder->router(fn(Services $s): RouteUrlResolverInterface => $this->routerBridge($s)),
            Services::RESOLVER_LOCATOR => $builder->resolverLocator(fn(): ArrayResolverLocator => new ArrayResolverLocator([], locate: $this->locateResolver(...), hint: 'a container id or a class name')),
            null => $builder->checks(fn(Services $s): iterable => $this->checks($s)),
            default => null,
        };

        return $builder;
    }

    /**
     * @param Closure(): object $from
     */
    private static function pull(ServicesBuilder $builder, string $node, Closure $from): void
    {
        match ($node) {
            Services::TRANSPORT => $builder->transport($from),
            Services::KEYS => $builder->keys($from),
            Services::NORMALIZER => $builder->normalizer($from),
            Services::THROTTLE => $builder->throttle($from),
            Services::DEBOUNCE_STORE => $builder->debounceStore($from),
            Services::SUBMISSION_STORE => $builder->submissionStore($from),
            Services::CLIENT => $builder->client($from),
            Services::SUBMITTER => $builder->submitter($from),
            Services::COLLECTOR => $builder->collector($from),
            Services::DISPATCHER => $builder->dispatcher($from),
            Services::READER => $builder->reader($from),
            Services::ROUTER => $builder->router($from),
            Services::RESOLVER_LOCATOR => $builder->resolverLocator($from),
            Services::CLOCK => $builder->clock($from),
            Services::CHANGES => $builder->changes($from),
            Services::URL_RESOLVER => $builder->urlResolver($from),
            Services::PARAM_EXTRACTOR => $builder->paramExtractor($from),
            default => throw new ConfigurationException(\sprintf('Unknown graph node "%s".', $node)),
        };
    }

    private static function service(ContainerInterface $container, string $id): object
    {
        $service = $container->get($id);
        if (!\is_object($service)) {
            throw new ConfigurationException(\sprintf('The container definition "%s" must be an object, got %s.', $id, get_debug_type($service)));
        }

        return $service;
    }

    /**
     * The store of `history.store` (indexnowkit/history): `pdo` over the PDO of the `ConnectionInterface` the
     * container holds under `history.pdo.service` (the default connection when unset) or a PDO built from
     * `history.pdo.dsn`; `psr16` over the cache named by `debounce.store` (the container's `CacheInterface` with `memory`/`none`).
     */
    private static function historyStore(HistoryConfig $history, Services $services, ContainerInterface $container): SubmissionStoreInterface
    {
        if ($history->store === HistoryConfig::STORE_PDO) {
            if ($history->pdoDsn !== null) {
                return HistoryServices::pdoStore(HistoryServices::pdoFromDsn($history->pdoDsn), $history);
            }
            $id = $history->pdoService ?? ConnectionInterface::class;
            $connection = $container->get($id);
            if (!$connection instanceof PdoConnectionInterface) {
                throw new ConfigurationException(\sprintf('history.pdo.service "%s" must be a PDO-backed yiisoft/db connection (%s), got %s.', $id, PdoConnectionInterface::class, get_debug_type($connection)));
            }
            return HistoryServices::pdoStore($connection->getActivePdo(), $history);
        }
        if ($history->store !== HistoryConfig::STORE_PSR16) {
            throw new ConfigurationException('history.store is set but no store was built.');
        }
        $id = HistoryServices::debounceCacheId($services->config) ?? IndexNow::DEFAULT_DEBOUNCE_STORE;
        $cache = $container->get($id);
        if (!$cache instanceof Psr16) {
            throw new ConfigurationException(\sprintf('history.store "psr16" needs a PSR-16 cache under "%s", got %s.', $id, get_debug_type($cache)));
        }

        return HistoryServices::psr16Store($cache, $history, $services->config);
    }

    /** The URL generator bridge with the `router` block (locales, the locale argument); the request host in a web request, `base_url` elsewhere. */
    private function routerBridge(Services $services): RouteUrlResolverInterface
    {
        $router = $this->indexNow->block('router');
        $locales = \is_array($router['locales'] ?? null) ? array_values(array_filter($router['locales'], 'is_string')) : [];
        $parameter = $router['locale_parameter'] ?? null;
        $currentRoute = $this->container->has(CurrentRoute::class) ? $this->container->get(CurrentRoute::class) : null;

        return new YiiRouteUrlResolver(
            self::urlGenerator($this->container),
            $services->config,
            $currentRoute instanceof CurrentRoute ? $currentRoute : null,
            $locales,
            \is_string($parameter) && $parameter !== '' ? $parameter : IndexNow::DEFAULT_LOCALE_PARAMETER,
        );
    }

    private static function urlGenerator(ContainerInterface $container): UrlGeneratorInterface
    {
        $generator = $container->get(UrlGeneratorInterface::class);
        if (!$generator instanceof UrlGeneratorInterface) {
            throw new ConfigurationException(\sprintf('The container must hold a %s (yiisoft/router-fastroute defines one) for #[IndexNow(route: ...)] rules, got %s.', UrlGeneratorInterface::class, get_debug_type($generator)));
        }

        return $generator;
    }

    /** `#[IndexNow(resolver: ...)]` ids: a container id, or a class the container can build (autowiring). */
    private function locateResolver(string $id): ?object
    {
        try {
            if (!$this->container->has($id) && !class_exists($id)) {
                return null;
            }
            $resolver = $this->container->get($id);
        } catch (Throwable $e) {
            throw new ConfigurationException(\sprintf('IndexNow URL resolver "%s" cannot be built: %s', $id, $e->getMessage()), 0, $e);
        }

        return \is_object($resolver) ? $resolver : null;
    }
}
