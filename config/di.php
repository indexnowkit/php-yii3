<?php

declare(strict_types=1);

use IndexNowKit\Adapter\Services;
use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Attribute\RuleRegistry;
use IndexNowKit\Check\CheckerInterface;
use IndexNowKit\Check\SampleOptions;
use IndexNowKit\ClientInterface;
use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\Config;
use IndexNowKit\Console\ResultFormatterInterface;
use IndexNowKit\Console\ResultRenderer;
use IndexNowKit\Console\SubjectLoaderInterface;
use IndexNowKit\Console\Vocabulary;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyFileResponder;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Retry\ForbiddenCounter;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Transaction\VerifyingStaging;
use IndexNowKit\Url\GuardedUrlResolver;
use IndexNowKit\Url\ObjectChangeHandler;
use IndexNowKit\Url\ResolverLocatorInterface;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlNormalizerInterface;
use IndexNowKit\Url\UrlResolverInterface;
use IndexNowKit\Yii3\ActiveRecord\ActiveRecordLoader;
use IndexNowKit\Yii3\ActiveRecord\IndexNowObserver;
use IndexNowKit\Yii3\IndexNow;
use IndexNowKit\Yii3\Wiring;
use Psr\Clock\ClockInterface;

/** @var array<string, mixed> $params */

/*
 * Every node of the core graph is one definition, so an application replaces any of them in its own `di/` (the same
 * key wins): `TransportInterface::class => MyTransport::class`. The default of each is the core graph's factory
 * over the other definitions (`Wiring::<node>()`, see docs/extending.md), so a replaced transport is what the
 * client, the checker and the console submitters use. `Services` is the graph every piece is read through, and
 * `Wiring::NODES` maps node to definition id (`WiringTest` keeps that map complete against `Adapter\Services`).
 *
 * The one node with no definition of its own is `failureCache`: it is not a piece the application replaces but the
 * PSR-16 cache behind `debounce.store`, which the package derives from that option — replace the cache instead.
 */
return [
    IndexNow::class => [
        'class' => IndexNow::class,
        '__construct()' => [
            'options' => $params['indexnowkit/yii3'] ?? [],
        ],
    ],
    Wiring::class => static fn(IndexNow $indexNow): Wiring => $indexNow->wiring(),
    Services::class => static fn(IndexNow $indexNow): Services => $indexNow->services(),
    Config::class => static fn(IndexNow $indexNow): Config => $indexNow->config(),
    IndexNowKit::class => static fn(Services $services): IndexNowKit => $services->kit(),

    // -- the nodes of the graph: replace any of them in your di/ --------------------------------------------------
    TransportInterface::class => static fn(Wiring $wiring): TransportInterface => $wiring->transport(),
    KeyProviderInterface::class => static fn(Wiring $wiring): KeyProviderInterface => $wiring->keys(),
    UrlNormalizerInterface::class => static fn(Wiring $wiring): UrlNormalizerInterface => $wiring->normalizer(),
    ThrottleInterface::class => static fn(Wiring $wiring): ThrottleInterface => $wiring->throttle(),
    DebounceStoreInterface::class => static fn(Wiring $wiring): DebounceStoreInterface => $wiring->debounceStore(),
    SubmissionStoreInterface::class => static fn(Wiring $wiring): SubmissionStoreInterface => $wiring->submissionStore(),
    ClientInterface::class => static fn(Wiring $wiring): ClientInterface => $wiring->client(),
    SubmitterInterface::class => static fn(Wiring $wiring): SubmitterInterface => $wiring->submitter(),
    CollectorInterface::class => static fn(Wiring $wiring): CollectorInterface => $wiring->collector(),
    DispatcherInterface::class => static fn(Wiring $wiring): DispatcherInterface => $wiring->dispatcher(),
    AttributeReaderInterface::class => static fn(Wiring $wiring): AttributeReaderInterface => $wiring->reader(),
    RouteUrlResolverInterface::class => static fn(Wiring $wiring): RouteUrlResolverInterface => $wiring->router(),
    ResolverLocatorInterface::class => static fn(Wiring $wiring): ResolverLocatorInterface => $wiring->resolverLocator(),
    ClockInterface::class => static fn(Wiring $wiring): ClockInterface => $wiring->clock(),
    ObjectChangeHandler::class => static fn(Wiring $wiring): ObjectChangeHandler => $wiring->changes(),
    UrlResolverInterface::class => static fn(Wiring $wiring): UrlResolverInterface => $wiring->urlResolver(),
    ParamExtractor::class => static fn(Wiring $wiring): ParamExtractor => $wiring->paramExtractor(),

    // -- derived from the graph -----------------------------------------------------------------------------------
    RuleRegistry::class => static fn(Services $services): RuleRegistry => $services->rules(),
    GuardedUrlResolver::class => static fn(Services $services): GuardedUrlResolver => $services->guardedResolver(),
    KeyFileResponder::class => static fn(Services $services): KeyFileResponder => $services->keyFileResponder(),
    CheckerInterface::class => static fn(Services $services): CheckerInterface => $services->checker(),
    ForbiddenCounter::class => static fn(Services $services): ForbiddenCounter => $services->forbiddenCounter(),
    // the command submitters (--force, --dry-run), decorated with the pre-flight of indexnowkit/verify when verify.enabled
    SubmitterFactoryInterface::class => static fn(IndexNow $indexNow): SubmitterFactoryInterface => $indexNow->submitterFactory(),
    IndexNow::VERIFY_TRANSPORT => static fn(IndexNow $indexNow): TransportInterface => $indexNow->defaultVerifyTransport(),

    // -- the ActiveRecord hook ------------------------------------------------------------------------------------
    VerifyingStaging::class => static fn(IndexNow $indexNow): VerifyingStaging => $indexNow->staging(),
    IndexNowObserver::class => static fn(IndexNow $indexNow): IndexNowObserver => $indexNow->observer(),

    // -- the console ----------------------------------------------------------------------------------------------
    Vocabulary::class => static fn(): Vocabulary => IndexNow::vocabulary(),
    SubjectLoaderInterface::class => static fn(IndexNow $indexNow): SubjectLoaderInterface => new ActiveRecordLoader($indexNow->namespaces()),
    ResultFormatterInterface::class => ResultRenderer::class,
    SampleOptions::class => SampleOptions::class,
];
