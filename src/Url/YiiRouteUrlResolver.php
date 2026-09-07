<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Url;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Yii3\IndexNow;
use LogicException;
use Stringable;
use Throwable;
use Yiisoft\ActiveRecord\ActiveRecordInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * Router bridge: `#[IndexNow(route: 'post/view', params: ['slug' => 'slug'])]` -> `UrlGeneratorInterface::generateAbsolute('post/view', ['slug' => ...])`.
 *
 * - A `self` parameter is the record's primary key value (Yii has no route model binding).
 * - Inside a web request the host is the request's (`CurrentRoute` carries the URI); outside one (console) the
 *   generator has no host to inherit, so scheme and host come from `base_url`.
 * - A rule with `host:` is generated on `hosts.<host>.base_url`, else `https://<host>`.
 * - `$locale` is passed as the `router.locale_parameter` argument (`_language` by default, the convention of yii-demo);
 *   a route whose pattern declares it puts it in the path, otherwise it becomes a query parameter.
 */
final class YiiRouteUrlResolver implements RouteUrlResolverInterface
{
    /**
     * @param list<string> $locales the locales of `locales: 'all'` (`router.locales`)
     */
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly Config $config,
        private readonly ?CurrentRoute $currentRoute = null,
        private readonly array $locales = [],
        private readonly string $localeParameter = IndexNow::DEFAULT_LOCALE_PARAMETER,
    ) {}

    public function locales(array|string $locales): array
    {
        if (\is_array($locales)) {
            return $locales === [] ? [null] : $locales;
        }
        if ($locales === 'all' && $this->locales !== []) {
            return $this->locales;
        }

        return [null];
    }

    public function generate(string $route, array $params, ?string $locale = null, ?string $host = null): string
    {
        $arguments = [];
        foreach ($params as $name => $value) {
            $arguments[(string) $name] = $value instanceof ActiveRecordInterface ? self::primaryKeyOf($value, $route, (string) $name) : $value;
        }
        if ($locale !== null) {
            $arguments[$this->localeParameter] = $locale;
        }
        [$scheme, $origin] = $this->origin($host);
        try {
            /** @var array<string, scalar|Stringable|null> $arguments */
            return $this->urls->generateAbsolute($route, $arguments, [], null, $scheme, $origin);
        } catch (Throwable $e) {
            throw new ConfigurationException(\sprintf('Cannot generate route "%s": %s', $route, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Scheme and host to generate on: the rule's host, else the request's (null: the generator inherits it), else `base_url`.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function origin(?string $host): array
    {
        if ($host !== null) {
            return self::split($this->config->baseUrlFor($host) ?? 'https://' . $host);
        }
        if ($this->currentRoute?->getUri() !== null) {
            return [null, null];
        }
        $base = $this->config->baseUrl;
        if ($base === null) {
            throw new ConfigurationException('No request to take the host from: set base_url to generate URLs in a console command.');
        }

        return self::split($base);
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private static function split(string $url): array
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['host'])) {
            return [null, rtrim($url, '/')];
        }

        return [$parts['scheme'] ?? null, $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')];
    }

    private static function primaryKeyOf(ActiveRecordInterface $record, string $route, string $param): mixed
    {
        try {
            return $record->primaryKeyValue();
        } catch (LogicException $e) {
            throw new ConfigurationException(\sprintf('Route "%s": parameter "%s" is "self" but %s has no single primary key column (%s); name the columns explicitly in params.', $route, $param, $record::class, $e->getMessage()), 0, $e);
        }
    }
}
