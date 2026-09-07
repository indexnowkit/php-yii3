# Changelog

All notable changes to `indexnowkit/yii3` are documented here. Versions follow [SemVer](https://semver.org); before
1.0 a minor version may break, every break is listed under "Changed" with a migration note.

## [0.1.0] — Unreleased

First release: the Yii3 adapter of the family (spec 15), on `indexnowkit/core` 0.12 and `indexnowkit/console` 0.4.

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
  the request or the command ends — yiisoft/db fires no commit or rollback events. `IndexNow::observe()` and
  `active_record.models` hook classes without the attribute (`Event\ObservedDispatcher`).
- **Router bridge** `Url\YiiRouteUrlResolver` over `UrlGeneratorInterface::generateAbsolute()`: the request host in a
  web request, `base_url` in the console, `hosts.<host>.base_url` for `host:` rules, the locale as the
  `router.locale_parameter` argument (`_language`).
- **Key file** `Http\KeyFileHandler` (PSR-15) at `key_file.pattern` (`/{key:[A-Za-z0-9-]{8,128}}.txt`), route
  `indexnow/key-file`; `Vary: Host` with a hosts map.
- **Commands** over the runners of `indexnowkit/console`: `indexnow:check` (with `--sample` / `--sample-class`
  through `indexnowkit/verify`), `indexnow:config`, `indexnow:submit`, `indexnow:submit-record`, `indexnow:explain`,
  `indexnow:key:generate`; `indexnow:sitemap` (`indexnowkit/sitemap`), `indexnow:history` and `indexnow:status`
  (`indexnowkit/history`), each with a stub that prints the install line and exits 1 without the package.
- **Checks** in `indexnow:check`: `dispatch.mode`, `debounce.store` (the core check with a container probe),
  `router.key_file` / `router.route`, `active_record.enabled`, plus the lines of the optional packages and the
  `checks` option (container ids of your own `CheckInterface`).
- **Optional packages** through `Sitemap\Adapter\SitemapServices`, `Verify\Adapter\VerifyServices` and
  `History\Adapter\HistoryServices`: the history store over the container's `ConnectionInterface` (`history.pdo.service`)
  or PSR-16 cache, the pre-flight transport under the container id `IndexNow::VERIFY_TRANSPORT`.
- Documentation EN/RU, `docs/{bc,commit-safety,configuration,extending,multi-domain,testing,troubleshooting}.md`.

### Not in this release

- `dispatch: queue`: `yiisoft/queue` has no stable release; `sync` and `none` only, a queue of your own replaces
  `DispatcherInterface` in the container.
