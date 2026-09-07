# Changelog

All notable changes to `indexnowkit/yii3` are documented here. Versions follow [SemVer](https://semver.org); before
1.0 a minor version may break, every break is listed under "Changed" with a migration note.

## [0.1.0] — Unreleased

First release: the Yii3 adapter of the family (spec 15), on `indexnowkit/core` 0.13 and `indexnowkit/console` 0.4.

### Added

- **Config plugin** (`yiisoft/config`): `params.php` (the `indexnowkit/yii3` block), `di.php` (every piece of the
  core as a definition, replaceable in the application's `di/`), `di-web.php`, `di-console.php`, `params-console.php`
  (the `./yii indexnow:*` commands), `events-web.php` / `events-console.php` (the flush after the response and at the
  end of a command), `routes.php` (`GET /<key>.txt`), `bootstrap.php` (the observer). A `yiisoft/app` application
  picks all of it up on `composer require`.
- **`IndexNow`**: the service of the application (`submitRecord()`, `submitRecords()`, `urlsFor()`, `explain()`,
  `collect()`, `flush()`, `observe()`) and the accessors of the graph and of the optional packages; `Wiring` describes
  the graph with `Adapter\ServicesBuilder` — every node read from the container, the default of each node the core's
  factory over the other definitions, so a replaced `TransportInterface` reaches the client, the checker and the
  console submitters.
- **ActiveRecord hook**: `#[IndexNowEvents]` (an `AttributeHandlerProvider` of yiisoft/active-record, next to
  `EventsTrait`), `ObserverProvider`, `IndexNowObserver` over `Hook\ObserverHelper` and `Transaction\VerifyingStaging`:
  URLs are resolved in the event while the old state is live (`BeforeUpdate` snapshots the old values); outside a
  transaction they go to the collector, inside one they are staged with a verifier and re-read by primary key when
  the request or the command ends — yiisoft/db fires no commit or rollback events. Two updates of one row in one
  transaction merge into one staged change, so neither loses its URLs. `upsert()` raises `AfterUpsert` and is
  announced as an update (a rule with `events: [Created]` does not fire on it). `IndexNow::observe()` and
  `active_record.models` hook classes without the attribute (`Event\ObservedDispatcher`), skipping a class that
  already carries `#[IndexNowEvents]` and replacing an observed dispatcher of an earlier container instead of
  wrapping a second one around it; `IndexNowObserver::detach()` undoes that.
- **Router bridge** `Url\YiiRouteUrlResolver` over `UrlGeneratorInterface::generateAbsolute()`: the request host in a
  web request, `base_url` in the console, `hosts.<host>.base_url` for `host:` rules, the locale as the
  `router.locale_parameter` argument (`_language`).
- **Key file** `Http\KeyFileHandler` (PSR-15) at `key_file.pattern` (`/{key:[A-Za-z0-9-]{8,128}}.txt`), route
  `indexnow/key-file`, over the core's `Key\KeyFileRequestHandler` (core 0.13.0, wave L): the handler hands the `{key}`
  argument of the route to `respond()`, the core builds the PSR-7 response with `Config::keyFileHeaders()` (`Vary: Host`
  with a hosts map).
- **Commands**: the classes of `indexnowkit/console` 0.5 (`Console\Command\*`: `indexnow:check` with `--sample` /
  `--sample-class` through `indexnowkit/verify`, `indexnow:config`, `indexnow:submit`, `indexnow:submit-record` as
  `SubmitSubjectsCommand` named by the vocabulary, `indexnow:explain`, `indexnow:key:generate`), of `indexnowkit/sitemap`
  0.8 (`indexnow:sitemap`) and of `indexnowkit/history` 0.4 (`indexnow:history`, `indexnow:status`) — the same classes
  the Symfony bundle registers (wave L, spec 18). `config/params-console.php` maps the names, `config/di-console.php`
  wires what varies: the runners as definitions (`SitemapRunner`, `HistoryRunner`, `StatusRunner` when the package is
  installed), `Console\ConfigSource` (a `ConfigSourceInterface` over the facade: what `check` and `config` read), the
  `.env` of `key:generate`, and the stubs of `indexnowkit/console` under the same names without the package (the
  install line, exit 1). A service built with `sitemapInstalled: false` / `historyInstalled: false` while the package
  is installed gets the stub too — from the container, never from a check inside a command. `Check\SampleOptions` of
  `di.php` carries the `Console\SubjectSampler` of `indexnowkit/console` for `--sample-class`;
  `Wiring::debounceStoreDescription()` is the store line of `indexnow:status`, the text of
  `History\Adapter\HistoryServices::describeStore()` shared with Laravel and Yii2 (wave M, spec 19).
- **Wave M** (spec 19), before the first release: `ActiveRecord\ActiveRecordLoader` extends `Console\AbstractSubjectLoader`
  of `indexnowkit/console` (the batched `findMany()` is what is Yii's); `Url\YiiRouteUrlResolver` decides the locale
  expansion, the pinned origin and the exceptions through the core's `Url\RouteOrigin` and takes the graph's logger,
  so `locales: 'all'` over an empty `router.locales` is warned about once per process; `Check\CacheProbe` writes the
  core's `DebounceStoreCheck::PROBE_KEY` (the package's own `KEY` constant is gone); the transport is built over the
  container's PSR-17 factories when it has them (`TransportFactory::lazy(…, requestFactory:, streamFactory:)` — a
  `yiisoft/app` application binds `Psr\Http\Message\RequestFactoryInterface` and `StreamFactoryInterface`), so
  `php-http/discovery` is consulted only without them; `ActiveRecord\ObserverProvider::set()` takes the PSR-3 logger
  as an appended optional parameter (the bootstrap passes the package's), and a save without an observer after that
  is one PSR-3 warning instead of an E_USER_WARNING (which stays the path before any bootstrap ran);
  `Config\ConfigFactory` folds the packages' options through `OptionalPackage::ownedOptions()` / `ignoredBlocks()`.
- **Checks** in `indexnow:check`: `dispatch.mode`, `debounce.store` (the core check with a container probe),
  `router.key_file` / `router.route`, `router.base_url` (a web request whose host differs from `base_url`),
  `active_record.enabled`, plus the lines of the optional packages (`Check\SampleGateCheck` of the core for
  `--sample` without `indexnowkit/verify`) and the `checks` option (container ids of your own `CheckInterface`).
- **Optional packages** behind the core's predicates (`Adapter\OptionalPackage::sitemap()` / `verify()` / `history()`,
  core 0.13.0: the service asks about a package without loading a class of it; the CI job `optional-packages-absent`
  boots the container with the three removed) and wired through `Sitemap\Adapter\SitemapServices`,
  `Verify\Adapter\VerifyServices` and `History\Adapter\HistoryServices`: the history store over the container's
  `ConnectionInterface` (`history.pdo.service`) or PSR-16 cache, the pre-flight transport under the container id
  `IndexNow::VERIFY_TRANSPORT`.
- Documentation EN/RU, `docs/{bc,commit-safety,configuration,extending,multi-domain,testing,troubleshooting}.md`
  and `docs/troubleshooting.ru.md`.

### Not in this release

- `dispatch: queue`: `yiisoft/queue` has no stable release; `sync` and `none` only, a queue of your own replaces
  `DispatcherInterface` in the container. The value is rejected when the configuration is built (`ConfigFactory::DISPATCH_MODES`),
  so it is one `critical` line and a `check` error, never a URL that disappears into a queue nobody reads.

### Audit 0.13

The first draft of this adapter was reviewed before its release; what changed since, none of it a released
behaviour:

- **The hooks never throw into a `save()` any more** (R3): the change handler is built inside the guard, so an
  application whose graph cannot be built (no `UrlGeneratorInterface` in the container) gets one `error` line per
  process instead of an exception out of the first save.
- **URLs are no longer delivered past the staging** when the transaction state of a connection cannot be read (R5):
  they wait on the record and the flush verifies them, which is what the staging exists for. `flushStaging()` works
  per connection inside a `try/catch` (R6), and a connection whose transaction is still open is reported at every
  flush and dropped after three of them, so a worker does not carry one request's URLs into the next (R7); the
  warning no longer claims they are "never delivered", which was only true for a request that ends inside the
  transaction.
- **Verify-on-commit** compares only values with one unambiguous text form (R1, `Transaction\VerifyingStaging` of
  core 0.13.0): a `DECIMAL` or a `timestamptz` the driver spells its own way no longer gags the announcement of a
  new page, while the columns that do have one text form still decide whether the change landed. Two updates of one
  row in one transaction merge under the subject `class#primary-key` instead of the first being discarded when the
  second overwrites the values it expected (R4); an insert and an upsert stage under no subject at all and are
  verified by the columns they wrote, because a primary key freed by a rollback to a savepoint is handed to the next
  insert of the class and the two changes are not the same subject (conformance A05c). `upsert()` is hooked (R2), a
  class carrying `#[IndexNowEvents]` is not hooked twice (R9), and a class without a primary key is warned about
  once (L5).
- **`indexnow:check`**: the debounce probe writes `indexnowkit_check`, a key without the colon that `yiisoft/cache`
  rejects — the one command that must not lie about the store no longer reports a working one as broken (S2). A
  `check` run inside a web request whose host differs from `base_url` says so (R11).
- **The command map** of `config/params-console.php` decides between a real command and its stub through the core's
  `Adapter\OptionalPackage`, the predicate the service and `check` already used, and the package's tests resolve
  every command by name through that map (A6, T1).
- **Less of the core copied here**: `Check\SampleGateCheck` and `Check\SampleOptions` of the core replace the
  package's own copies (A2, the container id `IndexNowKit\Check\SampleOptions` now), `History\Adapter\HistoryServices::storeFor()`
  replaces the package's `history.store` branches (A4), `Services::requireRouter()` / `requireResolverLocator()`
  replace two dead `?? throw` branches (A9), the PSR-14 dispatcher is looked up lazily so describing the graph does
  no container work (A8), and the `resolver:` lookup leaves its error text to `Url\ArrayResolverLocator` (A10).
- `ObserverProvider::reset()` is documented as test support and marked `@internal` (A22); `minimum-stability: dev`
  stays in `composer.json` because `roave/security-advisories: dev-latest` in `require-dev` needs it, as in every
  other package of the family (A26).
- Documentation: the return types of `IndexNow`'s manual methods (D4), a Russian troubleshooting page (D6), the
  quickstart's `dry_run` compared against the same list as `production_environments` (D7), `MagicRelationsTrait`
  named wherever `via:` is (D8), `verify.*` and `history.*` as two rows (D10), and the check codes of the optional
  packages placed where they belong (D13).
