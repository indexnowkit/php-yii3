# Диагностика

[English version](troubleshooting.md)

Начните с `./yii indexnow:check`, затем `./yii indexnow:explain 'App\Model\Post' <id>`, затем категория лога
`indexnow` на уровне `debug` (target yiisoft/log с `categories: ['indexnow']`,
`levels: ['error', 'warning', 'info', 'debug']`).

## Ничего не отправляется

| Симптом | Причина | Что делать |
|---|---|---|
| `check`: `configuration: ...` и код возврата 1 | значение блока (обычно из `.env`) недопустимо; IndexNow работает выключенным | исправьте значение; точная ошибка печатается и один раз логируется на уровне `critical` |
| лог: `unknown option(s) in the indexnow configuration: ...` | опечатка в блоке (`debounce.per_urls`, `key_file.enabld`) | путь через точку называет ключ |
| `check`: `active record: the observer is not installed` | `config/bootstrap.php` пакета не выполнился | раннер должен грузить группу `bootstrap` (`bootstrap-web` / `bootstrap-console` ссылаются на `$bootstrap` в шаблоне) |
| PHP warning `a record with #[IndexNowEvents] was saved but the observer is not installed` | то же самое, увиденное со стороны сохранения | то же самое |
| `explain` отдаёт URL, а в логе на сохранении тишина | у записи нет `EventsTrait` (событий нет вовсе) либо `active_record.enabled` / `enabled` равно false | `use EventsTrait;` на записи; `check` печатает строку хука |
| `explain`: `when: published -> false` сразу после `save()` | у колонки `when` есть только умолчание в базе | задайте умолчание типизированному свойству или установите его до `save()` |
| `explain`: `no #[IndexNow] rule` | у класса нет атрибута и он не зарегистрирован | добавьте `#[IndexNow]`, `active_record.models` или `observe()` |
| `explain` отдаёт URL, а `via:` или путь через точку по отношению даёт ошибку | у записи нет `MagicRelationsTrait` | `use MagicRelationsTrait;` рядом с `EventsTrait`: отношения читаются через него |
| лог `debug`: `change not committed` | изменение откатили либо проверка не увидела строку | для отката это ожидаемо; про проблему проверки — [commit-safety.md](commit-safety.md) |
| лог `warning`: `staged URL(s) wait for a transaction that is still open` | flush произошёл внутри транзакции; проверка прочитала бы незакоммиченные данные | закоммитьте до отправки ответа либо делайте flush после коммита в длинной команде. URL уходят на первом flush после конца транзакции; запрос, который закончился внутри неё, до такого flush не доживает |
| лог `warning`: `dropping ... staged URL(s) of a connection whose transaction was still open` | одно и то же соединение было внутри транзакции на трёх flush подряд (воркер, который её не закрывает) | закройте транзакцию; URL отбрасываются, а не переносятся в чужой запрос |
| лог `debug`: `debounced` | URL уже отправляли внутри окна `debounce.per_url` | `--force` у команды либо уменьшите окно |
| `warning`: `skipping ... unmanaged host` | хост URL не совпадает ни с `base_url`, ни с `hosts` | добавьте хост в `hosts` либо исправьте `base_url` |
| консоль: `set base_url` в `ConfigurationException` | URL относительные, а запроса нет | задайте `base_url` |
| `Cannot generate route "post/view"` | имя маршрута неизвестно роутеру либо не хватает аргумента | `->name('post/view')` на маршруте; `params` должны покрывать его аргументы |

## Файл ключа

| Симптом | Причина | Что делать |
|---|---|---|
| `GET /<key>.txt` отвечает 404 | `key_file.enabled` равно false, группа `routes` пакета не смёржена, либо ключ другой | `check` печатает строку маршрута; `key_file.pattern` должен заканчиваться на `.txt` и содержать аргумент `key` |
| движки отвечают 403 | отдаётся не тот ключ, редирект либо закешированный старый файл после ротации | `curl -i https://host/<key>.txt`; `key_file.cache_max_age` равен 300 с намеренно |
| `check`: `key file ... returned 200`, но 403 остаётся | `hosts` и хост отправки различаются (www против apex) | перечислите в `hosts` каждый хост, под которым отправляете, включите `strict_hosts` |
| за прокси отдаётся файл ключа только одного хоста, остальные отвечают 404 | до origin каждый запрос доходит с `Host` прокси | middleware доверенных прокси перед роутером ([multi-domain.md](multi-domain.md#behind-a-proxy-or-a-cdn)) |

## Dispatch

| Симптом | Причина | Что делать |
|---|---|---|
| `check`: `"dispatch" must be one of sync, none` | `dispatch: queue` из конфигурации другого адаптера | `sync` плюс определение `DispatcherInterface` для вашей очереди ([extending.md](extending.md)) |
| pre-flight пакета verify замедляет запросы | `verify.enabled` вместе с `dispatch: sync` тянет страницы после ответа | это ожидаемо; диспетчер очереди переносит работу в воркер |

## Sitemap

`./yii indexnow:sitemap --dry-run` печатает, что было бы отправлено; `sitemap.enabled is false.` означает, что блок
выключен или недопустим (причина в логе). `check` печатает, куда спулятся документы; на файловой системе только для
чтения задайте `sitemap.spool_dir` либо `sitemap.spool: memory`. Сам ридер живёт в
[`indexnowkit/sitemap`](https://github.com/indexnowkit/php/tree/main/packages/sitemap).

## Отправили, а движок отвечает

| Ответ | Смысл | Что делать |
|---|---|---|
| 403 (`invalid_key`) | `https://<host>/<key>.txt` недоступен или отдаёт другое тело | `indexnow:check`; CDN может кешировать старый файл (`key_file.cache_max_age`) |
| 422 (`unprocessable`) | URL хоста, отличного от `host`, либо файл ключа на другом хосте | один ключ на хост (`hosts`), `strict_hosts: true`; консольным URL нужен `base_url` нужного хоста |
| 429 (`rate_limited`) | слишком много запросов | уменьшите `throttle.max_requests_per_minute`; диспетчер очереди повторяет с учётом `Retry-After` |
| 202 (`pending`) | принято, проверка ключа впереди | нормально для нового ключа; `check --live` позже ответит 200 |

Счётчик 403, эскалирующий до `critical`, общий через кэш за `debounce.store`; с `memory` он живёт в одном процессе.

## Дубли и тайминг

- Один и тот же URL не отправляется повторно внутри `debounce.per_url` (600 с). `--force` обходит окно; PSR-16-кэш
  контейнера (умолчание `debounce.store`) делит окно между запросами и воркерами, `memory` — нет.
- Всё, что собрано за один запрос, уходит одним батчем после ответа (`AfterEmit`); консольная команда делает flush,
  когда заканчивается (`ApplicationShutdown`).
- Откаченная транзакция не отправляет ничего; изменение, перечитанное на flush и не найденное в строке, отбрасывается
  ([commit-safety.md](commit-safety.md)).

## Staging отправил свои URL

| Симптом | Причина | Что делать |
|---|---|---|
| Bing/Yandex сообщают про URL `staging.example.com` либо `failed` / `unprocessable` (422) по ним в логе | staging-копия работает с продовым ключом и без `dry_run`; её URL сгенерированы на её собственном хосте | вне production задайте `INDEXNOW_DRY_RUN=1` (или `enabled: false`); `check` на такой копии падает |
| staging-хост отдаёт продовый файл ключа | `key_file.enabled` включён везде | `key_file.enabled: false` вне production, чтобы ни один движок не смог проверить ключ на этом хосте |
| движки проиндексировали staging-страницы | staging-хост ответил `200` по ним и отдал ключ | отвечайте `410` (или `noindex` плюс запрет в `robots.txt`) на staging и ротируйте ключ, если он засветился |
| preview-окружение должно отправлять намеренно | — | скажите `dry_run: false` явно в этом окружении; тогда `check` предупреждает, а не падает |
