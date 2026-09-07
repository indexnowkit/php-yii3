<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Middleware\Dispatcher\MiddlewareFactory;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\Middleware\Router;
use Yiisoft\Router\UrlMatcherInterface;

/**
 * One web request through the real router of yiisoft/router (the matcher of router-fastroute, the `Router`
 * middleware, the action of the matched route from the container), the way yiisoft/yii-http dispatches it; an
 * unmatched request answers 404 like the application's fallback handler.
 */
final class Web
{
    private function __construct() {}

    public static function get(ContainerInterface $container, string $url): ResponseInterface
    {
        $factory = new Psr17Factory();
        $matcher = $container->get(UrlMatcherInterface::class);
        $currentRoute = $container->get(CurrentRoute::class);
        \assert($matcher instanceof UrlMatcherInterface && $currentRoute instanceof CurrentRoute);
        // one CurrentRoute per request, as the application's state resetter does between requests
        (function (): void {
            $this->route = null;
            $this->uri = null;
            $this->arguments = [];
        })->call($currentRoute);
        $router = new Router($matcher, $factory, new MiddlewareFactory($container), $currentRoute);
        $notFound = new class ($factory) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseFactoryInterface $responses) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->responses->createResponse(404);
            }
        };

        return $router->process($factory->createServerRequest('GET', $url), $notFound);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function headers(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values(array_map(strval(...), $values));
        }

        return $headers;
    }
}
