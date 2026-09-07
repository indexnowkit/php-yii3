# Yii3-пакет IndexNow — `indexnowkit/yii3`

Сообщайте поисковым системам о новых, изменённых и удалённых страницах в момент, когда строка ActiveRecord
закоммичена. Один атрибут на модели, один `composer require` — готово.

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/yii3)](https://packagist.org/packages/indexnowkit/yii3)
[![Downloads](https://img.shields.io/packagist/dt/indexnowkit/yii3)](https://packagist.org/packages/indexnowkit/yii3)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
![PHPStan](https://img.shields.io/badge/phpstan-level%209-4c1)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4) ![Yii](https://img.shields.io/badge/yiisoft%2Factive--record-%5E1.0-1a73e8)
[![License](https://img.shields.io/packagist/l/indexnowkit/yii3)](LICENSE)

[English version](README.md) · Issues и pull requests: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (репозитории `php-*` — read-only сплиты)

## Кого уведомляем

**Яндекс, Bing (и DuckDuckGo через Bing), Naver, Seznam, Yep, Internet Archive, Amazon** — все участники
[реестра](https://www.indexnow.org/searchengines.json) протокола [IndexNow](https://www.indexnow.org). Один запрос на
общий endpoint доходит до всех; перечислять движки явно нужно только чтобы отправить в один.
**Google — нет**: Google не поддерживает IndexNow, пакет не будет делать вид, что это не так.

**Уведомление, не индексация.** IndexNow сообщает поисковику, что URL изменился; обойти и проиндексировать страницу — его
решение и его сроки. Результат виден в Bing Webmaster Tools (IndexNow Insights) и в Яндекс.Вебмастере (Индексирование →
Переобход страниц); полезная метрика — доля отправленных URL в индексе через несколько дней. Удалённые страницы: отдавайте
410 (навсегда) или 404 (временно); при переезде — 301 и отправка обоих URL; soft-404 и редирект на главную вредят.
Bing URL Submission API и Google Indexing API — другие протоколы, здесь не покрываются.

## Почему это, а не X

Большинство пакетов IndexNow — тонкий HTTP-клиент: URL собираете вы, вызываете вы, ответ читаете вы. Это семейство делает
то, что на практике ломается:

- **Объявлено на модели** (`#[IndexNow]`) и отправляется из событий ActiveRecord — нет кода в контроллере, который можно забыть.
- **После commit**, не на save: откатившаяся транзакция ничего не объявляет.
- **Дебаунс** (10 минут на URL, через ваш кэш), **батчи** до 10 000 URL, ключ на host из env.
- **Ответы обработаны**: 202 (ключ проверяется), 422, 429 с `Retry-After`, эскалация 403.
- **`check` до первой отправки** говорит, что не так (файл ключа, движки, кэш, окружение, маршрут, хук); `explain` — почему URL ушёл или не ушёл.
- **Одно ядро** под адаптерами Symfony, Laravel, Yii2, Yii3 и Doctrine с общим conformance-набором: поведение одинаковое везде и описано один раз.


## Установка

```bash
composer require indexnowkit/yii3 symfony/http-client nyholm/psr7   # подойдёт любой PSR-18 клиент + PSR-17 фабрики
composer require indexnowkit/sitemap                                # опционально: команда indexnow:sitemap
```

Приложение на `yiisoft/app` (или любое, чей runner загружает группы `yiisoft/config` `params`, `di`, `di-web`,
`di-console`, `params-console`, `events-web`, `events-console`, `routes` и `bootstrap`) подключает пакет его
`config/*.php`: definitions контейнера, маршрут файла ключа, консольные команды, flush после ответа и наблюдатель
ActiveRecord. Настройте его в params:

```php
// config/common/params.php
'indexnowkit/yii3' => [
    'key' => $_ENV['INDEXNOW_KEY'] ?? null,     // или не указывайте: пакет сам читает INDEXNOW_KEY
    'base_url' => 'https://www.example.com',    // для консольных команд (нет запроса, откуда взять host)
    'dry_run' => $_ENV['YII_ENV'] !== 'prod',   // dev/staging: логировать, не отправлять (без этого вне production check падает)
],
```

```bash
./yii indexnow:key:generate --write-env      # пишет INDEXNOW_KEY=… в .env (или печатает ключ)
./yii indexnow:check                         # params, доступность файла ключа, маршрут, хук, кэш, dispatch
```

Пакет читает `INDEXNOW_KEY`, `INDEXNOW_PREVIOUS_KEY`, `INDEXNOW_BASE_URL` и `INDEXNOW_DRY_RUN` из `$_ENV`, `$_SERVER`
и `getenv()` (`vlucas/phpdotenv` шаблона приложения наполняет `$_ENV`), имя окружения — из `YII_ENV`. Маршруту файла
ключа нужны `yiisoft/router` с `yiisoft/router-fastroute` (в шаблоне есть); URL для правил `route:` генерируются
через `UrlGeneratorInterface` контейнера. Пакету нужен PSR-18 клиент (`symfony/http-client` + `nyholm/psr7`, как
выше, или Guzzle): он находит его сам или берёт id контейнера из `http.client`. Слияние `params` в приложении должно
быть рекурсивным для блока (`RecursiveMerge::groups('params', ...)` в `configuration.php`, как в шаблоне); без него
умолчания пакета всё равно применяются к тому, чего в вашем блоке нет.

## Объявите, у чего есть публичная страница

`#[IndexNow]` повторяемый: один атрибут на семейство публичных URL. `#[IndexNowEvents]` регистрирует хуки, а
`EventsTrait` из yiisoft/active-record — то, из-за чего запись вообще диспетчит события. Сохраните пример как
`src/Model/Post.php` в `namespace App\Model;` — он читает колонки `slug`, `title`, `body`, `published`, `amp`
(AMP-страница существует, пока true) и `category_id`; `Category` — ваша запись со своим правилом `#[IndexNow]`
(уберите строку `via: 'category'`, если её нет). `route:` — имя маршрута из вашего `routes.php`
(`Route::get('/posts/{slug}')->name('post/view')`).

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
#[IndexNow(via: 'category')]      // изменённый пост обновляет и страницу категории
#[IndexNow(urls: ['/'])]          // и главную
#[IndexNowEvents]                 // хук: yiisoft/active-record диспетчит события только с EventsTrait
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

| Опция | Смысл |
|---|---|
| `route` / `params` | имя маршрута и `аргумент => свойство, метод, "self", путь.через.точку` (`self` = первичный ключ) |
| `resolver` | класс `UrlResolverInterface` или id контейнера для чего угодно нестандартного |
| `via` | отношение (или путь через точку), чьи страницы переотправляются |
| `url` / `urls` | метод, возвращающий URL, или буквальные URL |
| `when` / `whenFields` | bool-свойство или метод; черновики пропускаются, `published → draft` уходит как удаление |
| `fields` | для обновлений: отправлять только если изменилось одно из этих свойств |
| `events`, `locales`, `host`, `name` | подмножество событий; `current`/`all`/список (`router.locales`); другой host; стабильный id правила |

Аксессоры читают свойства ActiveRecord и отношения (`category.slug`, отношение `get<Name>Query()`) и падают на методы.
Колонка `when` только с умолчанием **в базе** равна null у свежей записи до `loadDefaultValues()`: задайте типизированному
свойству умолчание (`public bool $published = true;`), как выше.

Классы, которые нельзя аннотировать: `'active_record' => ['models' => [Product::class]]` в params (классу всё равно нужен
`EventsTrait`) или `$indexNow->observe(Product::class, [new IndexNow(...)])` в рантайме.

Полная модель, типизированные параметры, наследование и таблица семантики:
[справочник по атрибуту в core](https://github.com/indexnowkit/php/blob/main/packages/core/docs/attribute-reference.md).

## Проверьте

```bash
./yii indexnow:check          # params, доступность файла ключа, движки, маршрут, хук, кэш, dispatch, спул
./yii indexnow:check --live   # плюс реальный пробный запрос в каждый движок
```

Запускайте после каждой ротации ключа и после каждого деплоя, затрагивающего конфигурацию.

## Как это работает

- URL резолвятся **в событии ActiveRecord**, пока старое состояние живо (`BeforeUpdate` сохраняет старые значения для
  `AfterUpdate`, `BeforeDelete` ещё видит строку и отношения). Переименованная страница объявляет старый URL удалённым.
- Вне транзакции они сразу идут в коллектор запроса. Внутри транзакции yiisoft/db не даёт событий commit/rollback вообще,
  поэтому они держатся с проверяющим замыканием и **перечитываются по первичному ключу в конце запроса** (или команды):
  изменение, которого строка не показывает (откатившаяся транзакция, вложенный `beginTransaction()`, откатившийся к
  savepoint), отбрасывается со всеми своими URL. Один `SELECT` на изменённую запись, только внутри явных транзакций.
  Подробности: [docs/commit-safety.md](docs/commit-safety.md).
- Всё собранное за запрос уходит **после ответа** (`AfterEmit` yiisoft/yii-http) одним батчем; консольная команда сбрасывает
  при завершении (`ApplicationShutdown`); долгая команда зовёт `$indexNow->flush()` между своими единицами работы.
- `dispatch: sync` (по умолчанию) отправляет сразу после ответа; `none` собирает и никогда не отправляет. Режима очереди
  нет, пока `yiisoft/queue` не выпущен стабильно: замените `DispatcherInterface` в своём `di/` диспетчером над вашей
  очередью ([docs/extending.md](docs/extending.md)).
- Ничего брошенное из правила, резолвера или HTTP-слоя не доходит до приложения: пишется в лог под категорией `indexnow`
  (`logging.category`), save проходит. Невалидная конфигурация выключает IndexNow одной строкой `critical`;
  `./yii indexnow:check` печатает точную ошибку.

## Команды

| Команда | Опции |
|---|---|
| `indexnow:check` | `--live` реальный пробный запрос · `--host=` один host (повторяемо) · `--probe-url=` страница для пробы · `--json` · `--strict` · `--sample=` / `--sample-class=` (нужен `indexnowkit/verify`) |
| `indexnow:config` | `--json` — действующая конфигурация, ключи и DSN замаскированы |
| `indexnow:submit <urls...>` | `--force` игнорировать дебаунс · `--dry-run` · `--json` |
| `indexnow:submit-record <class> [ids...]` | `--event=` · `--limit=` · `--explain` · `--force` · `--dry-run` · `--json` |
| `indexnow:explain <class> <id>` | `--event=` · `--json` — правила, `when`, URL, ключ, дебаунс; ничего не отправляет |
| `indexnow:sitemap [sitemap]` | `--changed-since="1 day"` · `--allow-foreign-hosts` · `--force` · `--dry-run` · `--json` · `--no-verify` |
| `indexnow:history` | `--host=` · `--status=ok|pending|failed|skipped` · `--url=` · `--since=2h|3d|2026-09-01` · `--limit=` (по умолчанию 50) · `--json` · `--purge[=days]` |
| `indexnow:status` | `--json` |
| `indexnow:key:generate` | `--length` · `--alphanumeric` · `--write-env[=FILE]` · `--force` ротация · `--no-previous` · `--yes` |

`<class>` — FQCN или короткое имя в `active_record.namespaces` (по умолчанию `App\Model`, `App\Entity`).

### Sitemap

`composer require indexnowkit/sitemap   # опционально: команда indexnow:sitemap`

`indexnow:sitemap` без аргумента читает `sitemap.url`, иначе `<base_url>/sitemap.xml`; локальный путь тоже подходит.
Без пакета всё остальное работает как прежде: `indexnow:sitemap` говорит `indexnowkit/sitemap is not installed:
composer require indexnowkit/sitemap` и выходит с 1, `indexnow:check` печатает `sitemap: not installed (…)`, блок
`sitemap` в params игнорируется, `sitemapConfig()` / `sitemapSource()` бросают `LogicException` с тем же текстом.
В лог об этом не пишется ничего.

### Verify

`composer require indexnowkit/verify   # опционально: один GET перед каждой отправкой`

С `'verify' => ['enabled' => true]` каждый URL запрашивается до отправки: `noindex`, `robots.txt`, canonical на другую
страницу, редирект или ошибка origin пропускают его с причиной в логе, а `indexnow:check --sample=<url>` /
`--sample-class=<class>` показывают, что увидел бы движок. С `dispatch: sync` GET'ы идут внутри веб-запроса после
отправки ответа; `check` об этом предупреждает. Без пакета блок `verify` игнорируется, `check` печатает
`verify: not installed (…)`.

### История

`composer require indexnowkit/history   # опционально: что отправили, когда, с каким ответом`

```php
'indexnowkit/yii3' => [
    // ...
    'history' => [
        'store' => 'pdo',                                   // null (по умолчанию, ничего не хранится) | psr16 (кэш дебаунса) | pdo
        'pdo' => ['service' => ConnectionInterface::class], // id соединения yiisoft/db с таблицей — или 'dsn' => 'sqlite:/var/data/indexnow.sqlite'
    ],
],
```

Каждый `Result` сабмиттера — flush после ответа, команды, URL, пропущенный `indexnowkit/verify`, — записывается:
нормализованные URL, host, движок, статус, причина, HTTP-код, текст ошибки (никогда тело ответа или ключ).
`./yii indexnow:history` показывает их от новых к старым (`--host`, `--status`, `--url`, `--since`, `--json`);
`indexnow:history --purge` удаляет старше `history.retention_days` (строка в cron); `./yii indexnow:status` печатает
переключатели, режим dispatch, стор дебаунса, счётчик 403 каждого host, последнюю успешную отправку и размер истории
(`--json` для машин). `pdo` нужна таблица: миграция — в
[docs/migrations.md](https://github.com/indexnowkit/php/blob/main/packages/history/docs/migrations.md) пакета
(`Schema::sql()`); пока её нет, `indexnow:check` печатает ошибку `history.store`, сабмиттер пишет сбой в лог, не ломая
flush. `psr16` — кольцевой буфер на `history.limit` записей для одного процесса и маленьких сайтов. Собственное
definition `SubmissionStoreInterface` в `di/` имеет приоритет над обоими. Без пакета `indexnow:history` и
`indexnow:status` говорят `indexnowkit/history is not installed: composer require indexnowkit/history` и выходят с 1,
`indexnow:check` печатает `history: not installed (…)`, `historyConfig()` бросает `LogicException` с тем же текстом.

## Конфигурация и документация

Каждая опция, её умолчание и смысл: [docs/configuration.md](docs/configuration.md). Commit-safety:
[docs/commit-safety.md](docs/commit-safety.md). Замена частей в контейнере, свои резолверы, проверки, очередь:
[docs/extending.md](docs/extending.md). Несколько хостов, www и apex, локали: [docs/multi-domain.md](docs/multi-domain.md).
Тестирование интеграции: [docs/testing.md](docs/testing.md).

## Эксплуатация

- [Чеклист production](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md#production-checklist)
  — ключ и base URL, `check` в пайплайне деплоя, `strict_hosts`, общий стор дебаунса, staging, который не может
  отправлять, три строки для алертов.
- [Правила мониторинга и фильтр Sentry](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md#monitoring-rules),
  [удалённые страницы](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md#deleted-pages-what-your-site-must-return),
  [что не отправлять](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md#what-not-to-submit).
- [Мультидомен: хосты, www и apex, локали](docs/multi-domain.md) · [commit-safety](docs/commit-safety.md) · [troubleshooting](docs/troubleshooting.md).

## Отладка

`./yii indexnow:check` валидирует params, запрашивает файл ключа и сообщает, как подключены отправки (dispatch, кэш,
маршрут, хук ActiveRecord, спул sitemap); `./yii indexnow:explain 'App\Model\Post' 1` показывает правила, условия и
URL одной записи, ничего не отправляя; категория лога `indexnow` на уровне `debug` объясняет, почему URL ушёл или
не ушёл. Симптомы и решения: [docs/troubleshooting.md](docs/troubleshooting.md).

## Ограничения

- `updateAll()`, `deleteAll()`, `updateCounters()` не порождают событий (conformance A13): после них зовите
  `$indexNow->submitRecords(Post::query()->where(...)->all())` или `./yii indexnow:submit-record`.
- `link()` / `unlink()` пишут строку junction-таблицы обычной командой, без события у владельца: сохраните владельца с
  обновлённой меткой времени (`$post->updated_at = time(); $post->save();`) или зовите `submitRecord($post)`.
- Запись без `EventsTrait` не диспетчит событий вообще: один атрибут ничего не подключает.
- Транзакция, всё ещё открытая в конце запроса, ничего не доставляет (проверка прочитала бы незакоммиченные данные):
  пакет пишет warning с количеством; закройте транзакцию.
- `dispatch: queue` нет, пока не выпущен `yiisoft/queue`; своя очередь заменяет `DispatcherInterface`.

## Совместимость

Публичный API: блок params `indexnowkit/yii3`, имена и опции команд, definitions контейнера из `config/di.php`, методы
`IndexNow`, `ActiveRecord\IndexNowEvents`. Действуют правила ядра:
[bc.md](https://github.com/indexnowkit/php-core/blob/main/docs/bc.md); что стабильно держит сам пакет: [docs/bc.md](docs/bc.md).
До 1.0 минорная версия может ломать; каждый разрыв перечислен в «Changed» в [CHANGELOG.md](CHANGELOG.md).
`yiisoft/active-record ^1.0`, `yiisoft/db ^2.0`, `yiisoft/router ^4.0`, PHP 8.2–8.5.

## Заметки для AI-ассистентов

- Composer-пакет `indexnowkit/yii3` (Yii3: `yiisoft/active-record ^1.0`, `yiisoft/db ^2.0`, `yiisoft/router ^4.0`, поверх `indexnowkit/core`); команде `indexnow:sitemap` нужен `indexnowkit/sitemap`; pre-flight проверкам — `indexnowkit/verify`; `indexnow:history` / `indexnow:status` — `indexnowkit/history` (`history.store: psr16|pdo`). Конфигурация: блок params `indexnowkit/yii3`; `config/*.php` пакета подхватывает `yiisoft/config` (di, routes, events, bootstrap, команды). `./yii indexnow:key:generate --write-env` пишет свежий `INDEXNOW_KEY`; `./yii indexnow:submit <url>…` отправляет URL вручную, `./yii indexnow:explain <Record> <id>` показывает, почему URL получается или нет, `./yii indexnow:config --json` печатает действующую конфигурацию с замаскированными ключами.
- Минимальный полный сниппет (все `use` включены):

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

- Проверка: `./yii indexnow:check` (exit 1 при любой ошибке; `--strict` падает и на предупреждениях, `--json` для машин), `./yii indexnow:config --json` (вставьте в баг-репорт), `./yii indexnow:explain 'App\\Model\\Post' 1` (почему URL получился или нет), `./yii indexnow:submit-record 'App\\Model\\Post' 1 --dry-run`.
- Подводные камни:
  - Записи нужны **оба**: `#[IndexNowEvents]` и `use EventsTrait;` — yiisoft/active-record диспетчит события только через трейт, атрибут лишь даёт обработчики.
  - `dispatch` в Yii3 — `sync` или `none` (очереди нет, пока `yiisoft/queue` не стабилен; замените `DispatcherInterface` в `di/`); `dispatch: auto` есть в Symfony (`auto` | `messenger` | `sync` | `none`) и Yii2 (`auto` | `queue` | `sync` | `none`), **не** в Laravel (`queue` | `sync` | `none`).
  - Локали: `router.locales` в Laravel, Yii2 и Yii3 (`router.locale_parameter` — имя аргумента маршрута, в Yii3 `_language`), `framework.enabled_locales` в Symfony; `locales: 'all'` у правила берёт этот список.
  - `route:` — **имя** маршрута (`->name('post/view')`), не его шаблон; `route: 'post/view'` требует `Route::get('/posts/{slug}')->name('post/view')` в конфигурации маршрутов.
  - `url:` называет аксессор (метод или свойство), возвращающий URL; `urls:` — список буквальных URL. Никогда не пишите литерал в `url:`.
  - Строка в `when:` — аксессор, читаемый как truthy (`published`, `isPublished`). Строковому статусу нужен `Equals`: `when: new Equals('status', 'published')` (`IndexNowKit\Attribute\Param\Equals`).
  - Ручная отправка — `submitEntity()` в Symfony, `submitModel()` в Laravel, `submitRecord()` в Yii2 и Yii3 (инжектируйте `IndexNowKit\Yii3\IndexNow`); команды — `indexnow:submit-entity`, `indexnow:submit-model`, `indexnow/submit-record` (Yii2), `indexnow:submit-record` (Yii3). Массовые запросы (`update()`, `DB::table()`, `updateAll()`) хуков не порождают: отправляйте после них этими командами.
  - В Laravel два класса с именем `IndexNowKit`: фасад `IndexNowKit\Laravel\Facades\IndexNowKit` и сервис ядра `IndexNowKit\IndexNowKit` (инжектируйте по типу). Yii2 отдаёт ядро через `Yii::$app->indexnow->kit()`; Yii3 определяет `IndexNowKit\IndexNowKit` и каждый интерфейс ядра в контейнере.
  - Вне production сконфигурированный ключ с неуказанным `dry_run` роняет `check` (staging-копия отправила бы настоящие URL): задайте там `dry_run: true`, либо явно `dry_run: false`, если отправка намеренная.
  - Неизвестные ключи конфигурации получают warning при загрузке (опечатки вроде debounce.per_urls); список ключей — `Config::OPTIONS` плюс собственные ключи адаптера.


## Другие фреймворки

| | |
|---|---|
| PHP | [core](https://github.com/indexnowkit/php/tree/main/packages/core), [symfony-bundle](https://github.com/indexnowkit/php/tree/main/packages/symfony-bundle), [doctrine](https://github.com/indexnowkit/php/tree/main/packages/doctrine), [laravel](https://github.com/indexnowkit/php/tree/main/packages/laravel), [yii2](https://github.com/indexnowkit/php/tree/main/packages/yii2) |
| JS/TS | @indexnowkit/core, next, prisma (скоро) |
| Python | indexnowkit, indexnowkit-django (скоро) |

MIT. IndexNow — торговая марка её владельца; проект независимый и не связан с Microsoft, Яндексом или indexnow.org.
