# Yii3 IndexNow package — `indexnowkit/yii3`

Tell search engines about new, changed and deleted pages the moment an ActiveRecord row is committed.
One attribute on the model, one `composer require`, done.

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/yii3)](https://packagist.org/packages/indexnowkit/yii3)
[![Downloads](https://img.shields.io/packagist/dt/indexnowkit/yii3)](https://packagist.org/packages/indexnowkit/yii3)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
[![Conformance](https://img.shields.io/badge/conformance-core%2022%2F22%20%C2%B7%20orm%2021%2F21%20%C2%B7%20http%206%2F6-brightgreen)](https://github.com/indexnowkit/spec)
![PHPStan](https://img.shields.io/badge/phpstan-level%209-4c1)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4) ![Yii](https://img.shields.io/badge/yiisoft%2Factive--record-%5E1.0-1a73e8)
[![License](https://img.shields.io/packagist/l/indexnowkit/yii3)](LICENSE)

[Русская версия](README.ru.md) · Issues and pull requests: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (the `php-*` repositories are read-only splits)

## Who gets notified

**Yandex, Bing (and DuckDuckGo via Bing), Naver, Seznam, Yep, Internet Archive, Amazon** — every engine in the
[IndexNow](https://www.indexnow.org) [registry](https://www.indexnow.org/searchengines.json). One request to the shared
endpoint reaches all of them; name engines explicitly only to reach a single one.

**Google: no.** Google does not support IndexNow; this package will not pretend otherwise.

**Notification, not indexing.** IndexNow tells an engine that a URL changed; whether and when the page is crawled and
indexed is the engine's decision. See the result in Bing Webmaster Tools (IndexNow Insights) and Yandex.Webmaster
(Indexing → Reindex pages); a useful metric is the share of submitted URLs in the index after a few days. Deleted
pages: answer 410 (gone for good) or 404 (temporarily); for a move answer 301 and submit both URLs; a soft-404 or a
redirect to the home page does harm. Bing's URL Submission API and Google's Indexing API are different protocols and
not covered here.

## Why this over X

Most IndexNow packages are a thin HTTP client: you collect the URLs, you call it, you read the answer. This family
does the part that goes wrong in practice:

- **Declared on the model** (`#[IndexNow]`) and submitted from the ActiveRecord events — no controller code to forget.
- **After the commit**, not on save: a rolled-back transaction announces nothing.
- **Debounce** (10 minutes per URL, shared through your cache), **batches** of up to 10 000 URLs, one key per host from env.
- **Answers handled**: 202 (key pending), 422, 429 with `Retry-After` back-off, 403 escalation.
- **`check` before the first submission** says what is wrong (key file, engines, cache, environment, the route, the hook); `explain` says why a URL was or was not sent.
- **One core** under the Symfony, Laravel, Yii2, Yii3 and Doctrine adapters with a shared conformance suite: the same behaviour everywhere, documented once.


## Install

```bash
composer require indexnowkit/yii3 symfony/http-client nyholm/psr7   # any PSR-18 client + PSR-17 factories work
composer require indexnowkit/sitemap                                # optional: the indexnow:sitemap command
```

An application on `yiisoft/app` (or any application that loads the `yiisoft/config` groups `params`, `di`,
`di-web`, `di-console`, `params-console`, `events-web`, `events-console`, `routes` and `bootstrap` through its
runner) is wired by the package's `config/*.php`: the container definitions, the key file route, the console
commands, the flush after the response and the ActiveRecord observer. Configure it in your params:

```php
// config/common/params.php
'indexnowkit/yii3' => [
    'key' => $_ENV['INDEXNOW_KEY'] ?? null,     // or leave it: the package reads INDEXNOW_KEY itself
    'base_url' => 'https://www.example.com',    // used by console commands (no request to take the host from)
    'dry_run' => $_ENV['YII_ENV'] !== 'prod',   // dev/staging: log the request, send nothing (check fails when this is unset outside production)
],
```

```bash
./yii indexnow:key:generate --write-env      # writes INDEXNOW_KEY=… to .env (or prints the key)
./yii indexnow:check                         # params, key file reachable, route, hook, cache, dispatch
```

The package reads `INDEXNOW_KEY`, `INDEXNOW_PREVIOUS_KEY`, `INDEXNOW_BASE_URL` and `INDEXNOW_DRY_RUN` from `$_ENV`,
`$_SERVER` and `getenv()` (`vlucas/phpdotenv` of the application template fills `$_ENV`), and takes the environment
name from `YII_ENV`. The key file route needs `yiisoft/router` with `yiisoft/router-fastroute` (the application
template has them); URL generation for `route:` rules goes through the container's `UrlGeneratorInterface`. The
package needs a PSR-18 client (`symfony/http-client` + `nyholm/psr7` as above, or Guzzle): it discovers one, or takes
the container id named in `http.client`. The `params` merge of your application must be recursive for the block
(`RecursiveMerge::groups('params', ...)` in `configuration.php`, as the template does); without it the package's
defaults still apply to what your block leaves out.

## Declare what has a public page

`#[IndexNow]` is repeatable: one attribute per family of public URLs. `#[IndexNowEvents]` registers the hooks, and
`EventsTrait` of yiisoft/active-record is what makes the record dispatch events at all. Save the example as
`src/Model/Post.php` under `namespace App\Model;` — it reads the columns `slug`, `title`, `body`, `published`, `amp`
(the AMP page exists while it is true) and `category_id`; `Category` is a record of your own with its own
`#[IndexNow]` rule (drop the `via: 'category'` line if you have none). `route:` names a route of your `routes.php`
(`Route::get('/posts/{slug}')->name('post/view')`).

<!-- test: quickstart-model -->
```php
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};
use IndexNowKit\Yii3\ActiveRecord\IndexNowEvents;
use Yiisoft\ActiveRecord\ActiveQueryInterface;
use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\Trait\EventsTrait;
use Yiisoft\ActiveRecord\Trait\MagicRelationsTrait;

#[IndexNowDefaults(when: 'published', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(route: 'post/view', params: ['slug' => 'slug'])]
#[IndexNow(route: 'post/amp', params: ['slug' => 'slug'], when: 'amp')]
#[IndexNow(via: 'category')]      // a changed post also refreshes its category page
#[IndexNow(urls: ['/'])]          // and the homepage
#[IndexNowEvents]                 // the hook: yiisoft/active-record dispatches events only with EventsTrait
final class Post extends ActiveRecord
{
    use EventsTrait;
    use MagicRelationsTrait;

    public ?int $id = null;
    public string $slug = '';
    public string $title = '';
    public ?string $body = null;
    public bool $published = true;
    public bool $amp = false;
    public ?int $category_id = null;

    public function tableName(): string
    {
        return 'posts';
    }

    public function getCategoryQuery(): ActiveQueryInterface
    {
        return $this->hasOne(Category::class, ['id' => 'category_id']);
    }
}
```
<!-- /test -->
| Option | Meaning |
|---|---|
| `route` / `params` | a route name and `argument => property, method, "self", dotted.path` (`self` = the primary key) |
| `resolver` | a `UrlResolverInterface` class or container id for anything custom |
| `via` | a relation (or dotted path) whose pages are resubmitted |
| `url` / `urls` | a method returning the URL(s), or literal URLs |
| `when` / `whenFields` | bool property or method; drafts are skipped and `published → draft` is sent as a deletion |
| `fields` | for updates, submit only when one of these properties changed |
| `events`, `locales`, `host`, `name` | subset of events; `current`/`all`/list (`router.locales`); another host; stable rule id |

Accessors read ActiveRecord properties and relations (`category.slug`, a `get<Name>Query()` relation) and fall back
to methods. A `when` column that only has a **database** default is null on a fresh record until `loadDefaultValues()`:
give the typed property a default (`public bool $published = true;`) as above.

Classes you cannot annotate: `'active_record' => ['models' => [Product::class]]` in the params (the class still needs
`EventsTrait`), or `$indexNow->observe(Product::class, [new IndexNow(...)])` at runtime.

Full model, typed parameters, inheritance and the semantics table:
[core attribute reference](https://github.com/indexnowkit/php/blob/main/packages/core/docs/attribute-reference.md).

## Verify

```bash
./yii indexnow:check          # params, key file reachable, engines, route, hook, cache, dispatch, spool
./yii indexnow:check --live   # also sends a real probe request to every engine
```

Run it after every key rotation and after every deployment that touches the configuration.

## How it works

- URLs are resolved **in the ActiveRecord event**, while the old state is live (`BeforeUpdate` keeps the old values
  for `AfterUpdate`, `BeforeDelete` still sees the row and its relations). A renamed page announces its old URL as deleted.
- Outside a transaction they go to the request collector right away. Inside one, yiisoft/db gives no commit or
  rollback events at all, so they are held with a verifier and **re-read by primary key at the end of the request**
  (or the command): a change the row does not show (a rolled-back transaction, an inner `beginTransaction()` that
  rolled back to its savepoint) is dropped with every URL it produced. One `SELECT` per changed record, only inside
  explicit transactions. Details: [docs/commit-safety.md](docs/commit-safety.md).
- Everything collected during one request is sent **after the response** (`AfterEmit` of yiisoft/yii-http), in one
  batch; a console command flushes when it ends (`ApplicationShutdown`); a long-running command calls
  `$indexNow->flush()` between its units of work.
- `dispatch: sync` (default) sends inline after the response; `none` collects and never sends. There is no queue
  mode until `yiisoft/queue` has a stable release: replace `DispatcherInterface` in your `di/` with a dispatcher over
  the queue you run ([docs/extending.md](docs/extending.md)).
- Nothing thrown from a rule, a resolver or the HTTP layer reaches your application: it is logged under the
  `indexnow` category (`logging.category`), the save succeeds. An invalid configuration disables IndexNow with one
  `critical` line; `./yii indexnow:check` prints the exact error.

## Commands

| Command | Options |
|---|---|
| `indexnow:check` | `--live` real probe · `--host=` one host (repeatable) · `--probe-url=` page for the probe · `--json` · `--strict` · `--sample=` / `--sample-class=` (needs `indexnowkit/verify`) |
| `indexnow:config` | `--json` — the effective configuration, keys and DSNs masked |
| `indexnow:submit <urls...>` | `--force` ignore debounce · `--dry-run` · `--json` |
| `indexnow:submit-record <class> [ids...]` | `--event=` · `--limit=` · `--explain` · `--force` · `--dry-run` · `--json` |
| `indexnow:explain <class> <id>` | `--event=` · `--json` — rules, `when`, URLs, key, debounce; sends nothing |
| `indexnow:sitemap [sitemap]` | `--changed-since="1 day"` · `--allow-foreign-hosts` · `--force` · `--dry-run` · `--json` · `--no-verify` |
| `indexnow:history` | `--host=` · `--status=ok|pending|failed|skipped` · `--url=` · `--since=2h|3d|2026-09-01` · `--limit=` (default 50) · `--json` · `--purge[=days]` |
| `indexnow:status` | `--json` |
| `indexnow:key:generate` | `--length` · `--alphanumeric` · `--write-env[=FILE]` · `--force` rotate · `--no-previous` · `--yes` |

`<class>` is an FQCN or a short name under `active_record.namespaces` (`App\Model`, `App\Entity` by default).

### Sitemaps

`composer require indexnowkit/sitemap   # optional: the indexnow:sitemap command`

`indexnow:sitemap` with no argument reads `sitemap.url`, else `<base_url>/sitemap.xml`; a local path works too.
Without the package everything else works unchanged: `indexnow:sitemap` says `indexnowkit/sitemap is not
installed: composer require indexnowkit/sitemap` and exits 1, `indexnow:check` prints `sitemap: not installed (…)`,
a `sitemap` block in the params is ignored, `sitemapConfig()` / `sitemapSource()` throw a `LogicException` with the
same sentence. Nothing is logged about it.

### Verify

`composer require indexnowkit/verify   # optional: one GET before every submission`

With `'verify' => ['enabled' => true]` every URL is fetched before it is submitted: `noindex`, `robots.txt`, a
canonical pointing elsewhere, a redirect or an origin error skip it with a logged reason, and `indexnow:check
--sample=<url>` / `--sample-class=<class>` report what an engine would see. With `dispatch: sync` the GETs run
inside the web request after the response was sent; `check` warns about it. Without the package a `verify` block
is ignored and `check` prints `verify: not installed (…)`.

### History

`composer require indexnowkit/history   # optional: what was submitted, when, with what answer`

```php
'indexnowkit/yii3' => [
    // ...
    'history' => [
        'store' => 'pdo',                                 // null (default, nothing kept) | psr16 (the debounce cache) | pdo
        'pdo' => ['service' => ConnectionInterface::class], // the yiisoft/db connection id holding the table — or 'dsn' => 'sqlite:/var/data/indexnow.sqlite'
    ],
],
```

Every `Result` the submitter produces — the flush after the response, the commands, a URL skipped by
`indexnowkit/verify` — is recorded: normalized URLs, host, engine, status, reason, HTTP code, the error message
(never the response body or the key). `./yii indexnow:history` lists them newest first (`--host`, `--status`,
`--url`, `--since`, `--json`); `indexnow:history --purge` removes what is older than `history.retention_days` (a cron
line); `./yii indexnow:status` prints the switches, the dispatch mode, the debounce store, the 403 counter of every
host, the last successful submission and the history size (`--json` for machines). `pdo` needs the table: the
migration is in the package's
[docs/migrations.md](https://github.com/indexnowkit/php/blob/main/packages/history/docs/migrations.md)
(`Schema::sql()`); until it exists `indexnow:check` prints a `history.store` error and the submitter logs the failure
without breaking the flush. `psr16` is a ring buffer of `history.limit` records for one process and small sites. A
`SubmissionStoreInterface` definition of your own in `di/` takes precedence over either. Without the package
`indexnow:history` and `indexnow:status` say `indexnowkit/history is not installed: composer require indexnowkit/history`
and exit 1, `indexnow:check` prints `history: not installed (…)`, `historyConfig()` throws a `LogicException` with the
same sentence.

## Configuration and docs

Every option, its default and what it does: [docs/configuration.md](docs/configuration.md). Commit safety:
[docs/commit-safety.md](docs/commit-safety.md). Replacing pieces in the container, custom resolvers, checks, a queue:
[docs/extending.md](docs/extending.md). Several hosts, www and apex, locales: [docs/multi-domain.md](docs/multi-domain.md).
Testing your integration: [docs/testing.md](docs/testing.md).

## Operations

- [Production checklist](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md#production-checklist)
  — key and base URL, `check` in the deploy pipeline, `strict_hosts`, a shared debounce store, staging that cannot
  submit, the three lines to alert on.
- [Monitoring rules and the Sentry filter](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md#monitoring-rules),
  [deleted pages](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md#deleted-pages-what-your-site-must-return),
  [what not to submit](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md#what-not-to-submit).
- [Multi-domain: hosts, www and apex, locales](docs/multi-domain.md) · [commit safety](docs/commit-safety.md) · [troubleshooting](docs/troubleshooting.md).

## Debugging

`./yii indexnow:check` validates the params, fetches the key file and reports how submissions are wired (dispatch,
cache, the route, the ActiveRecord hook, sitemap spool); `./yii indexnow:explain 'App\Model\Post' 1` shows the
rules, guards and URLs of one record without sending anything; the `indexnow` log category at `debug` tells why a
URL was or was not submitted. Symptoms and fixes: [docs/troubleshooting.md](docs/troubleshooting.md).

## Limitations

- `updateAll()`, `deleteAll()`, `updateCounters()` fire no events (conformance A13): call
  `$indexNow->submitRecords(Post::query()->where(...)->all())` or `./yii indexnow:submit-record` afterwards.
- `link()` / `unlink()` write the junction row with a plain command, no event on the owner: save the owner with a
  bumped timestamp afterwards (`$post->updated_at = time(); $post->save();`), or call `submitRecord($post)`.
- A record without `EventsTrait` dispatches no events at all: the attribute alone hooks nothing.
- A transaction still open when the request ends delivers nothing (the verifier would read uncommitted data): the
  package logs a warning naming the count; close the transaction.
- No `dispatch: queue` until `yiisoft/queue` is released; a queue of your own replaces `DispatcherInterface`.

## Compatibility

Public API: the `indexnowkit/yii3` params block, command names and options, the container definitions of
`config/di.php`, `IndexNow` methods, `ActiveRecord\IndexNowEvents`. The core's rules apply:
[bc.md](https://github.com/indexnowkit/php-core/blob/main/docs/bc.md); what this package itself keeps stable: [docs/bc.md](docs/bc.md). Before 1.0 a minor version may break; every
break is listed under "Changed" in [CHANGELOG.md](CHANGELOG.md). `yiisoft/active-record ^1.0`, `yiisoft/db ^2.0`,
`yiisoft/router ^4.0`, PHP 8.2–8.5.

## Notes for AI assistants

- Composer package `indexnowkit/yii3` (Yii3: `yiisoft/active-record ^1.0`, `yiisoft/db ^2.0`, `yiisoft/router ^4.0`, on `indexnowkit/core`); the `indexnow:sitemap` command needs `indexnowkit/sitemap`; pre-flight checks need `indexnowkit/verify`; `indexnow:history` / `indexnow:status` need `indexnowkit/history` (`history.store: psr16|pdo`). Configuration: the `indexnowkit/yii3` params block; the package's `config/*.php` are picked up by `yiisoft/config` (di, routes, events, bootstrap, commands). `./yii indexnow:key:generate --write-env` writes a fresh `INDEXNOW_KEY`; `./yii indexnow:submit <url>…` submits URLs by hand, `./yii indexnow:explain <Record> <id>` shows why a URL is or is not produced, `./yii indexnow:config --json` prints the effective configuration with the keys masked.
- Minimal complete snippet (every `use` included):

```php
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};
use IndexNowKit\Yii3\ActiveRecord\IndexNowEvents;
use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\Trait\EventsTrait;

#[IndexNowDefaults(when: 'published', fields: ['slug', 'title', 'published'])]
#[IndexNow(route: 'post/view', params: ['slug' => 'slug'])]
#[IndexNow(urls: ['/'])]
#[IndexNowEvents]
final class Post extends ActiveRecord { use EventsTrait; public ?int $id = null; public string $slug = ''; public string $title = ''; public bool $published = true; public function tableName(): string { return 'posts'; } }
```

- Verify: `./yii indexnow:check` (exit 1 on any error; `--strict` fails on warnings too, `--json` for machines), `./yii indexnow:config --json` (paste it into a bug report), `./yii indexnow:explain 'App\\Model\\Post' 1` (why a URL was or was not produced), `./yii indexnow:submit-record 'App\\Model\\Post' 1 --dry-run`.
- Pitfalls:
  - The record needs **both** `#[IndexNowEvents]` and `use EventsTrait;`: yiisoft/active-record dispatches events only through the trait, and the attribute only provides the handlers.
  - `dispatch` is `sync` or `none` in Yii3 (no queue until `yiisoft/queue` is stable; replace `DispatcherInterface` in `di/` for one); `dispatch: auto` exists in Symfony (`auto` | `messenger` | `sync` | `none`) and Yii2 (`auto` | `queue` | `sync` | `none`), **not** in Laravel (`queue` | `sync` | `none`).
  - Locales: `router.locales` in Laravel, Yii2 and Yii3 (`router.locale_parameter` names the route argument, `_language` in Yii3), `framework.enabled_locales` in Symfony; `locales: 'all'` on a rule uses that list.
  - `route:` is the **name** of a route (`->name('post/view')`), not its pattern; `route: 'post/view'` needs `Route::get('/posts/{slug}')->name('post/view')` in the routes configuration.
  - `url:` names an accessor (method or property) that returns the URL; `urls:` is a list of literal URLs. Never put a literal in `url:`.
  - A string in `when:` is an accessor read as truthy (`published`, `isPublished`). A status string needs `Equals`: `when: new Equals('status', 'published')` (`IndexNowKit\Attribute\Param\Equals`).
  - Manual submission is `submitEntity()` in Symfony, `submitModel()` in Laravel, `submitRecord()` in Yii2 and Yii3 (inject `IndexNowKit\Yii3\IndexNow`); the commands are `indexnow:submit-entity`, `indexnow:submit-model`, `indexnow/submit-record` (Yii2), `indexnow:submit-record` (Yii3). Bulk queries (`update()`, `DB::table()`, `updateAll()`) fire no hooks: submit afterwards with those.
  - Laravel has two classes called `IndexNowKit`: the facade `IndexNowKit\Laravel\Facades\IndexNowKit` and the core service `IndexNowKit\IndexNowKit` (inject by type). Yii2 exposes the core through `Yii::$app->indexnow->kit()`; Yii3 defines `IndexNowKit\IndexNowKit` and every core interface in the container.
  - Outside production a configured key with `dry_run` unset makes `check` fail (a staging copy would submit real URLs): set `dry_run: true` there, or `dry_run: false` explicitly when it submits on purpose.
  - Unknown configuration keys are warned about at boot (typos such as debounce.per_urls); the key list is `Config::OPTIONS` plus the adapter's own keys.


## Other frameworks

| | |
|---|---|
| PHP | [core](https://github.com/indexnowkit/php/tree/main/packages/core), [symfony-bundle](https://github.com/indexnowkit/php/tree/main/packages/symfony-bundle), [doctrine](https://github.com/indexnowkit/php/tree/main/packages/doctrine), [laravel](https://github.com/indexnowkit/php/tree/main/packages/laravel), [yii2](https://github.com/indexnowkit/php/tree/main/packages/yii2) |
| JS/TS | @indexnowkit/core, next, prisma (soon) |
| Python | indexnowkit, indexnowkit-django (soon) |

MIT. IndexNow is a trademark of its owner; this project is independent and not affiliated with Microsoft, Yandex or indexnow.org.
