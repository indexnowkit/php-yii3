# Backward compatibility

`indexnowkit/yii3` follows SemVer. **Before 1.0, minor versions may contain breaking changes**; every one is listed
under "Changed" in [CHANGELOG.md](../CHANGELOG.md) with the migration. After 1.0 the rules below become the promise.
The core's tiers ("call", "implement", "may grow") apply to every core class you touch through this package:
[core bc.md](https://github.com/indexnowkit/php-core/blob/main/docs/bc.md).

## What the package keeps stable

| Surface | Promise |
|---|---|
| **The params block** `indexnowkit/yii3` ([configuration.md](configuration.md)) | Keys and their meaning stay; new keys are only added with a default. A rename ships the old key as deprecated for one minor and is listed in the changelog. |
| **Container definitions** of `config/di.php`: every core interface, `Services`, `Config`, `IndexNowKit`, `IndexNow`, `Wiring`, `VerifyingStaging`, `IndexNowObserver`, `SubjectLoaderInterface`, `Vocabulary`, `ResultFormatterInterface`, `SubmitterFactoryInterface`, `IndexNow::VERIFY_TRANSPORT` | Ids stay; replacing one in the application's `di/` keeps working; new ids are only added. |
| **`IndexNow`** methods `config()`, `services()`, `kit()`, `rules()`, `keys()`, `staging()`, `observer()`, `submit()`, `submitRecord()`, `submitRecords()`, `urlsFor()`, `urlsForAll()`, `explain()`, `collect()`, `flush()`, `flushIfCollected()`, `observe()`, the `*Package()` / `*Installed()` / `*Config()` accessors | Names and types stay; new methods are only added. |
| **Console commands and options** (`indexnow:check`, `indexnow:config`, `indexnow:submit`, `indexnow:submit-record`, `indexnow:explain`, `indexnow:key:generate`, `indexnow:sitemap`, `indexnow:history`, `indexnow:status`) | Names, arguments and options come from the `Definitions` of `indexnowkit/console` and the optional packages; new options are only added. Output is not a contract except the exit codes and the `--json` shapes. |
| **The config groups** the package ships (`params`, `params-console`, `di`, `di-web`, `di-console`, `events-web`, `events-console`, `routes`, `bootstrap`), the route name `indexnow/key-file` and the default pattern | Stay. |
| **`ActiveRecord\IndexNowEvents`**, **`ActiveRecord\ObserverProvider`** (`set()`, `get()`, `isSet()`, `reset()`), **`ActiveRecord\IndexNowObserver`** public hooks | The attribute stays a drop-in; the observer's public hooks keep their names. |
| **Check codes** `dispatch.mode`, `router.key_file`, `router.route`, `active_record.enabled` and the `checks` option | Stay ([core check-codes.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/check-codes.md)). |

Not a contract: log message texts (their `context` keys are), the exact wording the commands print (exit codes and
levels are), the `Wiring` helper's private graph construction, `Env`.

## Pinning

`composer require indexnowkit/yii3:^0.1` gets every 0.1.x patch. Read the changelog before a minor.
