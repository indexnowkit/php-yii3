# Multiple domains and locales

## One application, several hosts

Every host gets its own key (engines verify `https://<host>/<key>.txt` on the submitted host):

```php
'indexnowkit/yii3' => [
    'key' => $_ENV['INDEXNOW_KEY'] ?? null,               // www.example.com, the base_url host
    'base_url' => 'https://www.example.com',
    'hosts' => [
        'example.de' => $_ENV['INDEXNOW_KEY_DE'] ?? null,
        'shop.example.com' => [
            'key' => $_ENV['INDEXNOW_KEY_SHOP'] ?? null,
            'base_url' => 'https://shop.example.com',    // origin for this host's URLs outside requests
            'engines' => ['yandex', 'bing'],             // per-host engine list
        ],
    ],
    'strict_hosts' => true,                             // hosts not listed are skipped, not sent under the default key
],
```

The key file handler serves each host's own key only (a request for `example.de`'s key on `www.example.com` is 404)
and answers with `Vary: Host`, so a shared CDN never caches one host's file for another. `./yii indexnow:check`
fetches every host's key file; `--host=example.de` limits it to one.

### Behind a proxy or a CDN

The handler takes the host from the request URI of the PSR-7 request the application hands it — the host the
application itself believes it is answering under. **Making that host trustworthy is the application's middleware,
not this package**: behind a load balancer or a CDN the origin sees the proxy's `Host` (or the client's, forged)
until a trusted-proxy middleware has rewritten the request from `X-Forwarded-Host` / `Forwarded`. In a Yii3
application that is `Yiisoft\ProxyMiddleware\TrustedHostsNetworkResolver`, and it belongs **before** the router in
the middleware stack, so every downstream handler — the key file route included — sees the same host.

Two consequences worth knowing:

- Without such a middleware behind a proxy, every host reaches the handler as the proxy's, so only that host's key
  file is served and the others answer 404. `./yii indexnow:check` fetches the files over HTTP from outside and
  therefore reports exactly what an engine will see.
- The same host is what URLs generated during a web request are built on (see the table below), so a proxy that is
  not resolved makes a request announce its pages under the proxy's hostname. `indexnow:check` run inside a web
  request warns when that host and `base_url` differ.

## Rules on another host

```php
#[IndexNow(route: 'product/view', params: ['slug' => 'slug'], host: 'shop.example.com')]
```

`host` can also be an accessor (`host: 'tenant.domain'`) for multi-tenant records. The URL is generated through
`UrlGeneratorInterface::generateAbsolute()` with the scheme and host of `hosts.<host>.base_url`, else `https://<host>`.

## Locales

```php
'router' => ['locales' => ['en', 'de'], 'locale_parameter' => '_language'],
'locale_hosts' => ['de' => 'example.de'],           // optional: one host per locale
```

```php
#[IndexNow(route: 'article/view', params: ['slug' => 'slug'], locales: 'all')]
```

- `locales: 'current'` (default) generates one URL; `'all'` one per `router.locales`; a list as given.
- The locale is passed as the `router.locale_parameter` argument (`_language`, the convention of yii-demo), so a
  route whose pattern declares it (`Route::get('/{_language:en|de}/articles/{slug}')->name('article/view')`) puts it
  in the path; without such a route it becomes a query parameter — declare the argument in the route.
- With `locale_hosts`, a rule without `host` generates each locale on that locale's host and under that host's key.

## Origin of generated URLs

| Context | Origin |
|---|---|
| web request | the request's scheme and host (`CurrentRoute` carries the URI) |
| console command | `base_url` (no request to take the host from; `check` says so when it is unset) |
| rule with `host:` | `hosts.<host>.base_url`, else `https://<host>` |

A staging copy reached under another hostname would otherwise submit its URLs under the production key; that is what
`strict_hosts: true` prevents, and why `indexnow:check` warns when it is off in production.

## www and apex

`example.com` and `www.example.com` are two hosts to IndexNow: each needs its own key file, and a URL submitted
under the other one's key answers 422. Pick the canonical one (the one your pages link to and `<link
rel="canonical">` names), put it in `base_url`, redirect the other with `301`, and do not list it in `hosts` —
listing both would announce two copies of every page. With `strict_hosts: true` a request that reached the
application under the non-canonical name submits nothing instead of announcing duplicates.

## hreflang clusters

Localized pages that point at each other with `hreflang` are one cluster to the engines: when one changes, announce
the cluster. A rule with `locales: 'all'` does that for the locales of one record; for locales living on other hosts
`locale_hosts` sends each locale to its host under that host's key. When translations are separate records, `via:`
walks to them:

```php
#[IndexNow(route: 'article/view', params: ['slug' => 'slug'], locales: 'all')]   // every locale of this article
#[IndexNow(via: 'translations')]                                                 // or: the sibling records' own rules
```
