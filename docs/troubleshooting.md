# Troubleshooting

Start with `./yii indexnow:check`, then `./yii indexnow:explain 'App\Model\Post' <id>`, then the `indexnow` log
category at `debug` (a yiisoft/log target with `categories: ['indexnow']`, `levels: ['error', 'warning', 'info', 'debug']`).

## Nothing is sent

| Symptom | Cause | Fix |
|---|---|---|
| `check`: `configuration: ...` and exit 1 | a value of the block (usually from `.env`) is invalid; IndexNow runs disabled | fix the value; the exact error is printed and logged once at `critical` |
| log: `unknown option(s) in the indexnow configuration: ...` | a typo in the block (`debounce.per_urls`, `key_file.enabld`) | the dotted path names the key |
| `check`: `active record: the observer is not installed` | the package's `config/bootstrap.php` did not run | the runner must load the `bootstrap` group (`bootstrap-web` / `bootstrap-console` reference `$bootstrap` in the template) |
| PHP warning `a record with #[IndexNowEvents] was saved but the observer is not installed` | the same, seen from a save | the same |
| `explain` yields URLs, the log says nothing on save | the record has no `EventsTrait` (no events at all), or `active_record.enabled` / `enabled` is false | `use EventsTrait;` on the record; `check` prints the hook line |
| `explain`: `when: published -> false` right after `save()` | the `when` column only has a database default | give the typed property a default, or set it before `save()` |
| `explain`: `no #[IndexNow] rule` | the class has no attribute and was not registered | add `#[IndexNow]`, `active_record.models` or `observe()` |
| `debug` log: `change not committed` | the change was rolled back, or the verifier could not see the row | expected for a rollback; for a verifier problem see [commit-safety.md](commit-safety.md) |
| `warning` log: `staged URL(s) wait for a transaction that is still open` | the request or the command ended inside a transaction | commit before the response is sent; a long command flushes after the commit |
| `debug` log: `debounced` | the URL was sent within `debounce.per_url` | `--force` on a command, or lower the window |
| `warning`: `skipping ... unmanaged host` | the URL's host is neither `base_url` nor in `hosts` | add the host to `hosts`, or fix `base_url` |
| console: `set base_url` in a `ConfigurationException` | URLs are relative and there is no request | set `base_url` |
| `Cannot generate route "post/view"` | the route name is unknown to the router, or an argument is missing | `->name('post/view')` on the route; `params` must cover its arguments |

## The key file

| Symptom | Cause | Fix |
|---|---|---|
| `GET /<key>.txt` is 404 | `key_file.enabled` is false, the `routes` group of the package is not merged, or the key differs | `check` prints the route line; `key_file.pattern` must end in `.txt` with a `key` argument |
| the engines answer 403 | the served body is not the submitted key, a redirect, or a cached old file after a rotation | `curl -i https://host/<key>.txt`; `key_file.cache_max_age` is 300 s on purpose |
| `check`: `key file ... returned 200` but 403 persists | `hosts` and the submitted host differ (www vs apex) | list every host you submit under `hosts`, set `strict_hosts` |

## Dispatch

| Symptom | Cause | Fix |
|---|---|---|
| `check`: `"dispatch" must be one of sync, none` | `dispatch: queue` from another adapter's configuration | `sync`, and a `DispatcherInterface` definition for your queue ([extending.md](extending.md)) |
| the pre-flight of verify slows requests down | `verify.enabled` with `dispatch: sync` fetches pages after the response | expected; a queue dispatcher moves it to a worker |

## Sitemap

`./yii indexnow:sitemap --dry-run` lists what would be sent; `sitemap.enabled is false.` means the block is off or
invalid (the log has the reason). `check` prints where documents are spooled; on a read-only filesystem set
`sitemap.spool_dir` or `sitemap.spool: memory`. The reader belongs to
[`indexnowkit/sitemap`](https://github.com/indexnowkit/php/tree/main/packages/sitemap).

## Sent, but the engine answers

| Answer | Meaning | Fix |
|---|---|---|
| 403 (`invalid_key`) | `https://<host>/<key>.txt` is not reachable or has another body | `indexnow:check`; a CDN may cache the old file (`key_file.cache_max_age`) |
| 422 (`unprocessable`) | URLs of another host than `host`, or the key file on another host | one key per host (`hosts`), `strict_hosts: true`; console URLs need `base_url` on the right host |
| 429 (`rate_limited`) | too many requests | lower `throttle.max_requests_per_minute`; a queue dispatcher retries with `Retry-After` |
| 202 (`pending`) | accepted, key verification pending | normal for a new key; `check --live` later answers 200 |

The 403 counter that escalates to `critical` is shared through the cache behind `debounce.store`; with `memory` it
is per process.

## Duplicates, timing

- The same URL is not resubmitted within `debounce.per_url` (600 s). `--force` bypasses it; the container's PSR-16
  cache (the default `debounce.store`) shares the window between requests and workers, `memory` does not.
- Everything from one request leaves as one batch after the response (`AfterEmit`); a console command flushes when
  it ends (`ApplicationShutdown`).
- A rolled-back transaction submits nothing; a change re-read at the flush that the row does not show is dropped
  ([commit-safety.md](commit-safety.md)).

## Staging submitted its URLs

| Symptom | Cause | Fix |
|---|---|---|
| Bing/Yandex report URLs of `staging.example.com`, or `failed` / `unprocessable` (422) for them in the log | the staging copy runs with the production key and no `dry_run`; its URLs were generated on its own host | outside production set `INDEXNOW_DRY_RUN=1` (or `enabled: false`); `check` fails on such a copy |
| the staging host serves the production key file | `key_file.enabled` is on everywhere | `key_file.enabled: false` outside production, so no engine can verify the key on that host |
| the engines indexed staging pages | the staging host answered `200` for them and served the key | return `410` (or `noindex` + block in `robots.txt`) on staging, and rotate the key if it was exposed |
| a preview environment must submit on purpose | — | say `dry_run: false` explicitly in that environment; `check` then warns instead of failing |
