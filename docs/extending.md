# Extending

## Replacing pieces

Every piece of the core is a definition of the container (`config/di.php` of the package), keyed by its interface.
A definition with the same key in your application's `di/` wins, and the package builds every dependent piece over
yours:

```php
// config/common/di/indexnow.php
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Http\TransportInterface;

return [
    TransportInterface::class => App\IndexNow\RecordingTransport::class,   // a proxy, a recorder in tests
    DispatcherInterface::class => App\IndexNow\QueueDispatcher::class,     // your queue: see below
];
```

| Definition | Default | Replace it for |
|---|---|---|
| `IndexNowKit\Http\TransportInterface` | `http.client` through the core's lazy transport, else PSR-18 discovery | a proxy, a recording transport |
| `IndexNowKit\Debounce\DebounceStoreInterface` | `debounce.store` (the container's PSR-16 cache) | a store the cache cannot express |
| `IndexNowKit\Dispatch\DispatcherInterface` | `sync` / `none` of `dispatch` | a queue (see below) |
| `IndexNowKit\Url\UrlResolverInterface` | the attribute resolver over the router bridge | replacing the whole "object → URLs" step |
| `IndexNowKit\Url\RouteUrlResolverInterface` | `Url\YiiRouteUrlResolver` over `UrlGeneratorInterface` | another URL generator |
| `IndexNowKit\Submission\SubmissionStoreInterface` | the store of `history.store`, else the null store | your own submission log |
| `IndexNowKit\Attribute\ParamExtractor` | `new ParamExtractor(new ActiveRecordSubjectReader())` | more `SubjectReaderInterface`s (`->with()`) |
| `IndexNowKit\Attribute\AttributeReaderInterface` | `RuleRegistry` over `AttributeReader` | rules from another source |
| `Psr\Clock\ClockInterface` | the system clock | `FrozenClock` in tests |
| `IndexNowKit\Key\KeyProviderInterface`, `UrlNormalizerInterface`, `ThrottleInterface`, `ClientInterface`, `SubmitterInterface`, `CollectorInterface`, `ResolverLocatorInterface`, `ObjectChangeHandler` | the core's factories | rarely |

How it works: `Wiring` describes the graph with the core's `Adapter\ServicesBuilder`. The graph the application
runs on (`Adapter\Services`, also a definition) reads every node from the container; the default of each node
(`Wiring::transport()`, `Wiring::client()`, …) is the core's factory for that node over a graph whose *other* nodes
come from the container. Replace nothing and you get the graph `IndexNowKit::create()` builds; replace one thing and
it reaches every dependent piece, the checker and the command submitters included.

Injectable as they are: `IndexNowKit\IndexNowKit` (the core facade), `IndexNowKit\Yii3\IndexNow` (the service with
`submitRecord()` and the package accessors), `Config`, `RuleRegistry`, `GuardedUrlResolver`, `KeyFileResponder`,
`CheckerInterface`, `SubmitterFactoryInterface`, `VerifyingStaging`, `IndexNowObserver`, `SubjectLoaderInterface`,
`Vocabulary`, `ResultFormatterInterface`, the runners of `indexnowkit/console` (`di-console.php`).

## A queue

`yiisoft/queue` has no stable release, so the package ships no queue mode. A dispatcher over the queue you run is
one class and one definition:

```php
final class QueueDispatcher implements IndexNowKit\Dispatch\DispatcherInterface
{
    public function __construct(private readonly MyQueue $queue) {}

    /** @param list<string> $urls */
    public function dispatch(array $urls): void
    {
        $this->queue->push(new SubmitUrlsJob($urls, attempt: 1));   // never throw into the request
    }
}
```

The worker is the recipe of [retries-and-queues.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/retries-and-queues.md):
`SubmitterInterface::submit()` (inject it, it is the graph's submitter with the pre-flight of verify when enabled),
then `Retry\WorkerOutcome` decides what to re-enqueue and with what delay. Keep `dispatch: sync` in the params (it is
what `check` reports; the line names your class) and set `base_url`: a worker has no request to take the host from.

## Custom resolvers

```php
#[IndexNow(resolver: ProductUrlResolver::class)]      // a class the container can build (autowired)
#[IndexNow(resolver: 'app.product_urls')]             // or a container id
```

The class implements `IndexNowKit\Url\UrlResolverInterface`; constructor dependencies come from the container.

## Rules at runtime

```php
$indexNow->observe(Product::class, [new IndexNow(route: 'product/view', params: ['id' => 'self'])], new IndexNowDefaults(when: 'active'));
$indexNow->rules()->registerFor(Page::class, fn (Page $page): ?RuleSet => ...);   // decided per object
```

`observe()` appends the observer to the class's event dispatcher of yiisoft/active-record (`Event\ObservedDispatcher`:
the class's own attribute handlers run first), the same mechanism as the `active_record.models` list. The class
still needs `EventsTrait`.

## Manual submissions

`IndexNow::submit(iterable $urls)`, `submitRecord($record, Event $event)`, `submitRecords(iterable $records)` (one
request for many), `urlsFor()`, `urlsForAll()`, `explain()` return `Result`s; `collect()` parks URLs in the request
collector, `flush()` verifies the staged changes and sends now. Every `Result` is also dispatched to the container's
PSR-14 `EventDispatcherInterface`: listen to `IndexNowKit\Result` in your `events.php`.

## Checks

`'checks' => [App\IndexNow\CdnPurgeCheck::class]` in the params appends your `Check\CheckInterface` lines to
`indexnow:check`; the ids are resolved through the container.

## Console

`SubjectLoaderInterface` (how `submit-record` and `explain` find records: tenant scoping, another id format),
`ResultFormatterInterface` (your JSON envelope) and `SubmitterFactoryInterface` are definitions; the command bodies
are the `IndexNowKit\Console\*Runner` of `indexnowkit/console` (`di-console.php`), so a tenant loop over
`SubmitSubjectsRunner` is a ten-line command of your own.

## What is the core's

The observer keeps only what is Yii3's: the change set from the old-value snapshot of `BeforeUpdate`, the previous
state for renamed pages, the verify-on-commit staging keyed by the connection. Guarding, logging and the URLs of a
row about to be deleted are the core's `Hook\ObserverHelper`; the inputs of every command come from
`Console\Definitions` of `indexnowkit/console` and the definitions of the optional packages, so `./yii indexnow:submit-record --help`
matches the bundle and artisan.
