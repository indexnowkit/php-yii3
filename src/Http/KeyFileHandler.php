<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Http;

use IndexNowKit\Config;
use IndexNowKit\Key\KeyFileResponder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * GET /<key>.txt -> the key itself, only for a key of the requested host (404 otherwise). The action of the route
 * `config/routes.php` registers under {@see ROUTE_NAME} with the pattern of `key_file.pattern`; no session, no CSRF
 * (a GET), the headers of `Config::keyFileHeaders()` (`text/plain`, a short `Cache-Control`, `Vary: Host` with a hosts map).
 * A middleware as well as a handler: every version of yiisoft/middleware-dispatcher accepts a middleware class as the
 * action of a route.
 */
final class KeyFileHandler implements RequestHandlerInterface, MiddlewareInterface
{
    public const ROUTE_NAME = 'indexnow/key-file';
    public const DEFAULT_PATTERN = '/{key:[A-Za-z0-9-]{8,128}}.txt';

    public function __construct(
        private readonly KeyFileResponder $responder,
        private readonly Config $config,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly CurrentRoute $currentRoute,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->handle($request);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $key = $this->currentRoute->getArgument('key') ?? '';
        $body = $key === '' ? null : $this->responder->bodyForKey($key, $request->getUri()->getHost());
        if ($body === null) {
            return $this->responses->createResponse(404);
        }
        $response = $this->responses->createResponse(200)->withBody($this->streams->createStream($body));
        foreach ($this->config->keyFileHeaders() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
