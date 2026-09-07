<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Check;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Yii3\Http\KeyFileHandler;
use Yiisoft\Router\RouteCollectionInterface;
use Yiisoft\Router\RouteNotFoundException;

/**
 * The key file route: registered by the package's `config/routes.php` when `key_file.enabled`; in a console
 * container the route collection is absent and the line says where the file is served. When `check` itself runs
 * inside a web request, the host of that request is compared with `base_url`, because a rule resolved in a request
 * is generated on the request's host and not on `base_url` ({@see \IndexNowKit\Yii3\Url\YiiRouteUrlResolver}).
 */
final class RouterCheck implements CheckInterface
{
    public const CODE_KEY_FILE = 'router.key_file';
    public const CODE_ROUTE = 'router.route';
    public const CODE_BASE_URL = 'router.base_url';

    /**
     * @param array<string, mixed> $options     the `indexnowkit/yii3` params block
     * @param string|null          $baseUrl     the configured `base_url`
     * @param string|null          $requestHost the host `check` itself runs under; null outside a web request (the console)
     */
    public function __construct(
        private readonly array $options,
        private readonly ?RouteCollectionInterface $routes,
        private readonly ?string $baseUrl = null,
        private readonly ?string $requestHost = null,
    ) {}

    public function check(CheckReport $report): void
    {
        $this->checkRequestHost($report);
        try {
            $enabled = Config::serveKeyFileFrom($this->options);
        } catch (ConfigurationException $e) {
            $report->error(\sprintf('key file: %s', $e->getMessage()), self::CODE_KEY_FILE);

            return;
        }
        if (!$enabled) {
            $report->ok('key file: not served by this application (key_file.enabled: false); serve /<key>.txt yourself', self::CODE_KEY_FILE);

            return;
        }
        if ($this->routes === null) {
            $report->ok(\sprintf('key file: served by the web application at %s (route %s)', self::pattern(), KeyFileHandler::ROUTE_NAME), self::CODE_ROUTE);

            return;
        }
        try {
            $route = $this->routes->getRoute(KeyFileHandler::ROUTE_NAME);
        } catch (RouteNotFoundException) {
            $report->error(\sprintf('key file: the route %s is missing; is the "routes" config group of the package merged into the application\'s routes (yiisoft/config)?', KeyFileHandler::ROUTE_NAME), self::CODE_ROUTE);

            return;
        }
        $pattern = $route->getData('pattern');
        $report->ok(\sprintf('key file: served at %s (route %s)', \is_string($pattern) ? self::pretty($pattern) : self::pattern(), KeyFileHandler::ROUTE_NAME), self::CODE_ROUTE);
    }

    /**
     * In a web request the router bridge generates on the request's host, not on `base_url`: a host that differs
     * from the configured one is worth one line, since every URL announced from that request carries it.
     */
    private function checkRequestHost(CheckReport $report): void
    {
        if ($this->requestHost === null) {
            return;
        }
        $configured = \is_string($this->baseUrl) ? parse_url($this->baseUrl, PHP_URL_HOST) : null;
        if (!\is_string($configured) || $configured === '' || $configured === $this->requestHost) {
            return;
        }
        $report->warning(\sprintf('router: this request runs on "%s" while base_url names "%s"; URLs resolved in a web request are generated on the request host, so they are announced under the key of "%s" (base_url is used by console commands only)', $this->requestHost, $configured, $this->requestHost), self::CODE_BASE_URL);
    }

    private function pattern(): string
    {
        $keyFile = $this->options['key_file'] ?? null;
        $pattern = \is_array($keyFile) ? ($keyFile['pattern'] ?? null) : null;

        return self::pretty(\is_string($pattern) && $pattern !== '' ? $pattern : KeyFileHandler::DEFAULT_PATTERN);
    }

    private static function pretty(string $pattern): string
    {
        return (string) preg_replace('/\{key(?::.*)?\}(?=\.txt)/', '<key>', $pattern);
    }
}
