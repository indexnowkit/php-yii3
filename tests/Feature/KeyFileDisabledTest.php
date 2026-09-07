<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Testing\Conformance\KeyFileAssertions;
use IndexNowKit\Yii3\Http\KeyFileHandler;
use IndexNowKit\Yii3\Tests\Support\Fixtures;
use IndexNowKit\Yii3\Tests\Support\Web;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Yiisoft\Router\RouteCollectionInterface;
use Yiisoft\Router\RouteNotFoundException;

final class KeyFileDisabledTest extends Yii3TestCase
{
    protected function optionOverrides(): array
    {
        return ['key_file' => ['enabled' => false]];
    }

    #[TestDox('H03 key_file.enabled false -> routes.php registers no route, /{key}.txt is a 404')]
    public function testNoRoute(): void
    {
        self::assertSame([], Fixtures::load('routes', ['indexnowkit/yii3' => Fixtures::merge(Fixtures::options(), $this->optionOverrides())]));
        $routes = $this->container->get(RouteCollectionInterface::class);
        \assert($routes instanceof RouteCollectionInterface);
        try {
            $routes->getRoute(KeyFileHandler::ROUTE_NAME);
            self::fail('the route must not exist');
        } catch (RouteNotFoundException) {
            // expected
        }

        KeyFileAssertions::assertNotServed(Web::get($this->container, self::BASE_URL . '/' . self::KEY . '.txt')->getStatusCode());
    }
}
