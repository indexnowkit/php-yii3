# Configuration

Everything lives in the `indexnowkit/yii3` block of your params (`config/common/params.php`), merged over the
package's [config/params.php](https://github.com/indexnowkit/php/blob/main/packages/yii3/config/params.php). Keys mirror the family-wide schema of `indexnowkit/core`
([configuration.md there](https://github.com/indexnowkit/php/blob/main/packages/core/docs/configuration.md)); the
blocks marked **Yii3** are handled by this package. Verify with `./yii indexnow:check`; `./yii indexnow:config`
prints the effective result with the keys masked.

## Core keys

The package reads four variables itself (`$_ENV`, `$_SERVER`, `getenv()`): `INDEXNOW_KEY`, `INDEXNOW_PREVIOUS_KEY`,
`INDEXNOW_BASE_URL`, `INDEXNOW_DRY_RUN`. Anything else is a literal in the block.

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `true` | kill switch; `false` = nothing is sent, changes are logged at debug |
| `key` | `INDEXNOW_KEY` | the IndexNow key, `[A-Za-z0-9-]{8,128}` (`./yii indexnow:key:generate`) |
| `previous_key` | `INDEXNOW_PREVIOUS_KEY` | the key before a rotation: its file is still served, nothing is submitted under it |
| `key_location` | — | full URL of the key file when it is not `https://<host>/<key>.txt` |
| `base_url` | `INDEXNOW_BASE_URL` | origin for URLs generated outside web requests (console commands) |
| `hosts` | `[]` | `host => key` or `host => ['key', 'key_location', 'base_url', 'engines', 'previous_key']` |
| `strict_hosts` | `false` | skip URLs of hosts outside `base_url` / `hosts` instead of sending them under the default key |
| `environment` | `YII_ENV` (then `APP_ENV`) | feeds the non-production dry-run safety net and the `check` staging error |
| `production_environments` | `['prod', 'production']` | environments where a missing key is an error, not dry-run |
| `dry_run` | `INDEXNOW_DRY_RUN`, else auto | log the request instead of sending it; unset outside production with a key = `check` fails |
| `max_url_length` | `2048` | longer URLs are `invalid_url` |
| `engines` | `['api']` | `api`, `yandex`, `bing`, `naver`, `seznam`, `yep`, `internetarchive`, `amazon`, an endpoint URL or an alias |
| `engine_aliases` | `[]` | `alias => endpoint URL` |
| `locale_hosts` | `[]` | `locale => host` for rules with `locales` and no `host` |
| `dispatch` | `sync` | `sync` (after the response), `none` (collect, never send); no `queue` until `yiisoft/queue` is stable |
| `debounce.per_url` | `600` | seconds a URL is not resubmitted; `0` = off |
| `debounce.store` | `Psr\SimpleCache\CacheInterface` | a container id of a PSR-16 cache, `memory`, `none` |
| `debounce.key_prefix` | `indexnowkit_` | cache key prefix (also the 403 counter and the robots cache of verify) |
| `throttle.max_requests_per_minute` | `60` | per process |
| `http.timeout` | `10` | seconds |
| `http.client` | `null` | a container id of a PSR-18 client; `null` = discovery |
| `key_file.enabled` | `true` | serve `/<key>.txt` through the package's route |
| `key_file.cache_max_age` | `300` | `Cache-Control: max-age` of the key file |
| `retry.*`, `batch.max_urls`, `logging.*`, `normalizer.*`, `collector.*`, `resolver.*`, `forbidden_escalation` | core defaults | see the core reference |

## Yii3 keys

| Key | Default | Meaning |
|---|---|---|
| `key_file.pattern` | `/{key:[A-Za-z0-9-]{8,128}}.txt` | the route pattern of the key file (`yiisoft/router`); the `key` argument is the key |
| `router.locales` | `[]` | the list `locales: 'all'` on a rule expands to |
| `router.locale_parameter` | `_language` | the route argument the locale is passed as; a route whose pattern lacks it gets it as a query parameter |
| `active_record.enabled` | `true` | `false` = the `#[IndexNowEvents]` hooks and the `models` list are inert; `submit()` and the commands still work |
| `active_record.namespaces` | `['App\Model', 'App\Entity']` | where a short class name of `indexnow:submit-record` / `indexnow:explain` is looked up |
| `active_record.models` | `[]` | classes hooked without `#[IndexNowEvents]` (they still need `EventsTrait`); the package's bootstrap calls `IndexNow::observe()` for each |
| `logging.category` | `indexnow` | the `category` context of every log line (what yiisoft/log targets filter on) |
| `checks` | `[]` | container ids of `Check\CheckInterface` implementations to append to `indexnow:check` |
| `sitemap.*` | — | the block of `indexnowkit/sitemap` (its [configuration](https://github.com/indexnowkit/php/blob/main/packages/sitemap/README.md)); ignored without the package |
| `verify.*` | — | the block of `indexnowkit/verify` (the pre-flight GET before every submission); ignored without the package |
| `history.*` | — | the block of `indexnowkit/history`; `history.pdo.service` names a `ConnectionInterface` id of the container, `history.pdo.dsn` a database of its own; ignored without the package |

## What is not an option

Replacing a piece of the core (the transport, the debounce store, the dispatcher, the URL resolver, the submission
store, the clock) is a container definition, not a params key: every interface is defined by the package's
`config/di.php` and the application's `di/` wins ([extending.md](extending.md)).

## Invalid values

The block is validated at runtime (values come from `.env`): an unknown key is a `warning` naming the dotted path
(`debounce.per_urls`), an invalid value is one `critical` line naming `./yii indexnow:check` and IndexNow runs
disabled until it is fixed. Nothing throws from a save or a listener. `indexnow:check` and `indexnow:config` use
the strict path and print the exact error.

## Merging

`yiisoft/config` replaces a whole top-level key of a group unless the application lists the group in
`RecursiveMerge` (`configuration.php` of the template does for `params`). Without the recursive merge your block
replaces the package's entirely; the package then applies its own defaults (`dispatch`, `key_file.pattern`,
`router.locale_parameter`, `logging.category`) to what your block leaves out, so a minimal block works either way.
