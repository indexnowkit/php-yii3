<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Http;

use IndexNowKit\Key\KeyFileRequestHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * GET /<key>.txt -> the key itself, only for a key of the requested host (404 otherwise). The action of the route
 * `config/routes.php` registers under {@see ROUTE_NAME} with the pattern of `key_file.pattern`: the key is the `{key}`
 * argument the router extracted (an application pattern may put the file anywhere), the answer is the core's
 * `Key\KeyFileRequestHandler::respond()` — the headers of `Config::keyFileHeaders()`, no session, no CSRF (a GET).
 * A middleware as well as a handler: every version of yiisoft/middleware-dispatcher accepts a middleware class as the
 * action of a route.
 */
final class KeyFileHandler implements RequestHandlerInterface, MiddlewareInterface
{
    public const ROUTE_NAME = 'indexnow/key-file';
    public const DEFAULT_PATTERN = '/{key:[A-Za-z0-9-]{8,128}}.txt';

    public function __construct(private readonly KeyFileRequestHandler $handler, private readonly CurrentRoute $currentRoute) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->handle($request);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handler->respond($this->currentRoute->getArgument('key'), $request);
    }
}
