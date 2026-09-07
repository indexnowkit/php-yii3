<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Unit;

use Composer\InstalledVersions;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Yii3\Tests\Fixtures\Item;
use IndexNowKit\Yii3\Tests\Support\Web;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use IndexNowKit\Yii3\Url\YiiRouteUrlResolver;
use PHPUnit\Framework\Attributes\TestDox;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;

final class RouteUrlResolverTest extends Yii3TestCase
{
    #[TestDox('outside a request URLs are generated on base_url; a pinned host is generated on its base_url or https; the locale is a route argument; a self parameter is the primary key')]
    public function testConsoleGeneration(): void
    {
        $resolver = $this->resolver();

        self::assertSame('https://www.example.com/posts/hello', $resolver->generate('post/view', ['slug' => 'hello']));
        self::assertSame('https://example.de/posts/hello', $resolver->generate('post/view', ['slug' => 'hello'], null, 'example.de'));
        self::assertSame('https://www.example.com/de/articles/hallo', $resolver->generate('article/view', ['slug' => 'hallo'], 'de'));
        // a route whose pattern does not declare the locale argument: the generator moves it to the query string
        // (router-fastroute 4.0.1 and later) or drops it (4.0.0). Both are exact URLs, so a lost locale is caught.
        $withoutTheArgument = $resolver->generate('post/view', ['slug' => 'hello'], 'de');
        $version = InstalledVersions::getVersion('yiisoft/router-fastroute') ?? '0.0.0';
        self::assertSame(
            version_compare($version, '4.0.1', '>=') ? 'https://www.example.com/posts/hello?_language=de' : 'https://www.example.com/posts/hello',
            $withoutTheArgument,
            'yiisoft/router-fastroute ' . $version,
        );

        $item = new Item();
        $item->name = 'x';
        $item->save();
        self::assertSame('https://www.example.com/items/' . $item->id, $resolver->generate('item/view', ['id' => $item]));

        self::assertSame(['en', 'de'], $resolver->locales('all'));
        self::assertSame([null], $resolver->locales('current'));
        self::assertSame(['fr'], $resolver->locales(['fr']));
    }

    #[TestDox('inside a web request the host is the request\'s, whatever base_url says')]
    public function testRequestHost(): void
    {
        Web::get($this->container, 'https://staging.example.com/posts/any'); // the router sets the current URI
        $resolver = $this->resolver();

        self::assertSame('https://staging.example.com/posts/hello', $resolver->generate('post/view', ['slug' => 'hello']));
        self::assertSame('https://example.de/posts/hello', $resolver->generate('post/view', ['slug' => 'hello'], null, 'example.de'), 'a pinned host still wins');
    }

    #[TestDox('an unknown route and a missing argument are configuration errors naming the route; a missing base_url outside a request too')]
    public function testErrors(): void
    {
        $resolver = $this->resolver();
        try {
            $resolver->generate('nope/view', ['x' => 1]);
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('Cannot generate route "nope/view"', $e->getMessage());
        }
        try {
            $resolver->generate('post/view', []);
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('slug', $e->getMessage());
        }

        $urls = $this->container->get(UrlGeneratorInterface::class);
        \assert($urls instanceof UrlGeneratorInterface);
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/set base_url/');
        (new YiiRouteUrlResolver($urls, Config::fromArray(['key' => self::KEY]), new CurrentRoute()))->generate('post/view', ['slug' => 'a']);
    }

    private function resolver(): RouteUrlResolverInterface
    {
        $resolver = $this->container->get(RouteUrlResolverInterface::class);
        \assert($resolver instanceof RouteUrlResolverInterface);

        return $resolver;
    }
}
