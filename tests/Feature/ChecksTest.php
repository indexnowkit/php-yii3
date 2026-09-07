<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Check\DebounceStoreCheck;
use IndexNowKit\Config;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Dispatch\NullDispatcher;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Testing\Conformance\CheckOutputAssertions;
use IndexNowKit\Testing\RecordingDispatcher;
use IndexNowKit\Yii3\Check\ActiveRecordCheck;
use IndexNowKit\Yii3\Check\CacheProbe;
use IndexNowKit\Yii3\Check\DispatchCheck;
use IndexNowKit\Yii3\Check\RouterCheck;
use IndexNowKit\Yii3\IndexNow;
use IndexNowKit\Yii3\Tests\Support\Fixtures;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use stdClass;
use Yiisoft\Cache\ArrayCache as YiiArrayCache;
use Yiisoft\Router\RouteCollection;
use Yiisoft\Router\RouteCollectionInterface;
use Yiisoft\Router\RouteCollector;

final class ChecksTest extends Yii3TestCase
{
    #[TestDox('dispatch: sync and none are ok lines; a replaced DispatcherInterface is named')]
    public function testDispatchCheck(): void
    {
        self::assertSame([CheckLevel::Ok], $this->levels(new DispatchCheck('sync', static fn(): DispatcherInterface => new NullDispatcher())));
        $replaced = new DispatchCheck('sync', static fn(): DispatcherInterface => new RecordingDispatcher());
        self::assertSame([CheckLevel::Ok], $this->levels($replaced));
        self::assertStringContainsString('URLs go to ' . RecordingDispatcher::class, $this->messages($replaced)[0]);
        self::assertStringContainsString('collected but never sent', $this->messages(new DispatchCheck('none', static fn(): DispatcherInterface => new NullDispatcher()))[0]);
    }

    #[TestDox('debounce: off is ok, memory is a warning, the container cache is ok (the default when unset), a missing id an error (core DebounceStoreCheck + the container probe)')]
    public function testDebounceStoreCheck(): void
    {
        $check = fn(array $debounce): DebounceStoreCheck => new DebounceStoreCheck(Config::fromArray(['key' => Fixtures::KEY, 'debounce' => $debounce]), (new CacheProbe($this->container))(...), IndexNow::DEFAULT_DEBOUNCE_STORE);

        self::assertSame([CheckLevel::Ok], $this->levels($check(['per_url' => 0])));
        self::assertSame([CheckLevel::Warning], $this->levels($check(['per_url' => 600, 'store' => 'memory'])));
        self::assertSame([CheckLevel::Ok], $this->levels($check(['per_url' => 600, 'store' => IndexNow::DEFAULT_DEBOUNCE_STORE])));
        self::assertSame([CheckLevel::Ok], $this->levels($check(['per_url' => 600])), 'unset = the container cache');
        self::assertStringContainsString('cache "Psr\SimpleCache\CacheInterface" (ArrayCache)', $this->messages($check(['per_url' => 600]))[0]);
        $failing = $check(['per_url' => 600, 'store' => 'missing']);
        self::assertSame([CheckLevel::Error], $this->levels($failing));
        self::assertStringContainsString('container definition "missing" does not exist', $this->messages($failing)[0]);
        $wrong = $check(['per_url' => 600, 'store' => IndexNow::class]);
        self::assertSame([CheckLevel::Error], $this->levels($wrong));
        self::assertStringContainsString('not a Psr\SimpleCache\CacheInterface', $this->messages($wrong)[0]);
    }

    #[TestDox('the debounce probe writes a key a strict PSR-16 cache accepts: yiisoft/cache reserves {}()/\\@: and would refuse a colon')]
    public function testTheProbeWritesAKeyAStrictCacheAccepts(): void
    {
        $strict = new YiiArrayCache();
        $container = Fixtures::container($this->transport, $this->logger, [], ['strict.cache' => $strict]);
        $check = new DebounceStoreCheck(
            Config::fromArray(['key' => Fixtures::KEY, 'debounce' => ['per_url' => 600, 'store' => 'strict.cache']]),
            (new CacheProbe($container))(...),
            IndexNow::DEFAULT_DEBOUNCE_STORE,
        );

        self::assertSame([CheckLevel::Ok], $this->levels($check), 'the real yiisoft/cache accepted the probe key');
        self::assertStringNotContainsString(':', DebounceStoreCheck::PROBE_KEY);
        self::assertSame(1, $strict->get(DebounceStoreCheck::PROBE_KEY));
    }

    #[TestDox('router: a web request on a host other than base_url is a warning naming both')]
    public function testRouterCheckComparesTheRequestHostWithBaseUrl(): void
    {
        $routes = $this->container->get(RouteCollectionInterface::class);
        \assert($routes instanceof RouteCollectionInterface);

        $same = new RouterCheck(Fixtures::options(), $routes, Fixtures::BASE_URL, 'www.example.com');
        self::assertSame([CheckLevel::Ok], $this->levels($same), 'the same host says nothing extra');

        $console = new RouterCheck(Fixtures::options(), $routes, Fixtures::BASE_URL, null);
        self::assertSame([CheckLevel::Ok], $this->levels($console), 'no request, no line');

        $other = new RouterCheck(Fixtures::options(), $routes, Fixtures::BASE_URL, 'staging.example.com');
        self::assertSame([CheckLevel::Warning, CheckLevel::Ok], $this->levels($other));
        self::assertStringContainsString('this request runs on "staging.example.com" while base_url names "www.example.com"', $this->messages($other)[0]);
        self::assertSame(RouterCheck::CODE_BASE_URL, $this->codes($other)[0]);
    }

    #[TestDox('the checks option appends a container id of a CheckInterface; anything else is a configuration error')]
    public function testTheChecksOption(): void
    {
        $own = new class implements CheckInterface {
            public function check(CheckReport $report): void
            {
                $report->warning('cdn: the purge hook is not configured', 'app.cdn');
            }
        };
        $container = Fixtures::container($this->transport, $this->logger, ['checks' => ['app.cdn_check']], ['app.cdn_check' => $own]);
        $indexNow = $container->get(IndexNow::class);
        \assert($indexNow instanceof IndexNow);

        $items = $indexNow->checker()->run()->items();
        $codes = array_map(static fn($item): ?string => $item->code, $items);
        self::assertContains('app.cdn', $codes, 'the application\'s own line is part of the report');

        $broken = Fixtures::container($this->transport, $this->logger, ['checks' => ['app.not_a_check']], ['app.not_a_check' => new stdClass()]);
        $service = $broken->get(IndexNow::class);
        \assert($service instanceof IndexNow);
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"checks" must list container ids of ' . CheckInterface::class . ' implementations, got app.not_a_check');
        $service->checker()->run();
    }

    #[TestDox('every line of the whole check, adapter checks included, carries a code (the API of check --json)')]
    public function testEveryCheckLineHasACode(): void
    {
        $report = $this->indexNow()->checker()->run();

        CheckOutputAssertions::assertEveryItemHasCode($report, DispatchCheck::CODE, DebounceStoreCheck::CODE, RouterCheck::CODE_ROUTE, ActiveRecordCheck::CODE, 'sitemap.spool', 'key_file.status');
    }

    #[TestDox('key file: the route is reported from the collection; missing is an error; without a collection (console) the web application serves it; disabled serving is ok')]
    public function testRouterCheck(): void
    {
        $routes = $this->container->get(RouteCollectionInterface::class);
        \assert($routes instanceof RouteCollectionInterface);
        $check = new RouterCheck(Fixtures::options(), $routes);
        self::assertSame([CheckLevel::Ok], $this->levels($check));
        self::assertStringContainsString('served at /<key>.txt (route indexnow/key-file)', $this->messages($check)[0]);

        $missing = new RouterCheck(Fixtures::options(), new RouteCollection(new RouteCollector()));
        self::assertSame([CheckLevel::Error], $this->levels($missing));
        self::assertStringContainsString('routes', $this->messages($missing)[0]);

        self::assertSame([CheckLevel::Ok], $this->levels(new RouterCheck(Fixtures::options(), null)));
        self::assertStringContainsString('served by the web application at /<key>.txt', $this->messages(new RouterCheck(Fixtures::options(), null))[0]);
        self::assertSame([CheckLevel::Ok], $this->levels(new RouterCheck(['key_file' => ['enabled' => false]], $routes)));
        self::assertSame([CheckLevel::Error], $this->levels(new RouterCheck(['key_file' => ['enabled' => ['no']]], $routes)), 'an unreadable value is an error line');
    }

    #[TestDox('active record: enabled with the observer is ok, disabled a warning, no observer an error')]
    public function testActiveRecordCheck(): void
    {
        self::assertSame([CheckLevel::Ok], $this->levels(new ActiveRecordCheck(true, true)));
        self::assertSame([CheckLevel::Warning], $this->levels(new ActiveRecordCheck(false, true)));
        $unset = new ActiveRecordCheck(true, false);
        self::assertSame([CheckLevel::Error], $this->levels($unset));
        self::assertStringContainsString('bootstrap', $this->messages($unset)[0]);
    }

    /**
     * @return list<CheckLevel>
     */
    private function levels(CheckInterface $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn($item): CheckLevel => $item->level, $report->items());
    }

    /**
     * @return list<string>
     */
    private function messages(CheckInterface $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn($item): string => $item->message, $report->items());
    }

    /**
     * @return list<string|null>
     */
    private function codes(CheckInterface $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn($item): ?string => $item->code, $report->items());
    }
}
