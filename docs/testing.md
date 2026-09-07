# Testing your integration

Replace the transport and the logger in the test container and read what left:

```php
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Testing\{ArrayLogger, FakeTransport};
use IndexNowKit\Yii3\IndexNow;
use Psr\Log\LoggerInterface;

$transport = new FakeTransport();
$logger = new ArrayLogger();
$container = new Container(ContainerConfig::create()->withDefinitions([
    ...$config->get('di'),                                   // the application's definitions, the package's included
    TransportInterface::class => $transport,
    LoggerInterface::class => $logger,
]));
// run the bootstrap group the way the runner does: ObserverProvider::set() lives there
foreach ($config->get('bootstrap') as $callback) { $callback($container); }

$post = new Post();
$post->slug = 'hello';
$post->save();
$container->get(IndexNow::class)->flush();     // in a request this happens after the response is sent

$transport->posts[0]['body']['urlList'];       // ['https://www.example.com/posts/hello']
$logger->messages('error');                    // []
```

`FakeTransport::willRespond(new Response(429))` and `onGet($url, new Response(200, $key))` script the engines and the
key file; `ArrayLogger::messages($level)` returns interpolated lines. Put `'dry_run' => false` in the test params:
`YII_ENV` of a test run is not production, and without a key `dry_run` switches on.

The package's own suite (`tests/`) runs the core conformance kits (`OrmConformanceTestCase`, `CoreConformanceTestCase`)
through a container built from the package's `config/*.php`; `Yii3TestCase` and `Support\Fixtures` are a template
for an application test case: `Fixtures::container()` builds the container (sqlite in memory, the routes, the PSR-14
dispatcher from `events-web.php`), `Fixtures::destroy()` resets `ObserverProvider`, `EventDispatcherProvider` and
`ConnectionProvider` between tests — three static providers of the process.

## Several containers in one process

`ObserverProvider` and `EventDispatcherProvider` of yiisoft/active-record are process-wide, so a test suite (or a
per-request container in a worker) that builds a second container hands the same classes a second observer. Two
things keep that honest, and both are automatic:

- `IndexNow::observe()` and the `active_record.models` list **replace** the observed dispatcher of a class instead
  of wrapping another one around it, so a class is never observed twice and the old container is not held alive by
  the static provider.
- `ObserverProvider::reset()` (test support, `@internal`) gives every class its own dispatcher back before it drops
  the observer. Call it between tests — `Fixtures::destroy()` does.

Without the reset, the classes of the previous test keep pointing at the previous container's observer, and a save
in the next test submits through a graph whose transport you already stopped looking at.

## Without HTTP at all

`$container->get(IndexNow::class)->urlsFor($post)` (or `explain($post)`) returns the URLs a record would announce,
with the rule that produced each — the assertion for a rule test that should not build a transport.

## Transactions and verify-on-commit

Inside `$db->transaction()` (or an explicit `beginTransaction()`), URLs are held with a verifier and re-read by
primary key at the next flush after the transaction ended; a rollback leaves no row and drops them. In a test:

```php
$tx = $db->beginTransaction();
$post = new Post();
$post->slug = 'held';
$post->save();
$indexNow->flush();
self::assertSame([], $transport->posts);        // nothing until the commit (a warning says the URLs wait)
$tx->commit();
$indexNow->flush();
self::assertCount(1, $transport->posts);        // now it left
```

A test that wraps every case in a transaction it rolls back at the end therefore never sees a submission: assert on
`urlsFor()` there, or commit. Details: [commit-safety.md](commit-safety.md).

## The key file

Send a request through the router (`Yiisoft\Router\Middleware\Router` over the `UrlMatcherInterface` of the
container) and hand the response to `Testing\Conformance\KeyFileAssertions` — `tests/Support/Web.php` of the package
shows the ten lines.

## Commands

`new CommandTester($container->get(CheckCommand::class))` runs a command the way `./yii` does; the package's
`tests/Feature/CommandsTest.php` covers every one.

## dry_run

Outside `production_environments` a missing key enables `dry_run`: the whole pipeline runs — rules, guards, URL
generation, host and key selection — and the request is logged (`ArrayLogger::messages('info')` contains the body)
instead of sent. Set `dry_run: false` for a test that must reach the transport.

## Conformance

`tests/Conformance/` runs the core kits against this package (`CoreConformanceTestCase`: C01–C22 through the
container; `OrmConformanceTestCase`: A01–A21 through `#[IndexNowEvents]`; the H01–H06 assertions `KeyFileAssertions`
/ `CheckOutputAssertions` through the key file handler and the commands). An application that replaces a piece
(`UrlResolverInterface`, `DispatcherInterface`) can extend the same abstract cases to prove nothing regressed.
