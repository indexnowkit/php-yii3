<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Attribute\IndexNow as IndexNowRule;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Url\ResolverLocatorInterface;
use IndexNowKit\Url\UrlResolverInterface;
use IndexNowKit\Yii3\IndexNow;
use IndexNowKit\Yii3\Tests\Fixtures\Tag;
use IndexNowKit\Yii3\Tests\Support\Fixtures;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/**
 * `#[IndexNow(resolver: ...)]` over the container: an id the application registered, a class the container can
 * autowire, and an id it knows nothing about. The lookup itself lives in the core's `Url\ArrayResolverLocator`; what
 * this package adds is the container behind it.
 */
final class ResolverIdTest extends Yii3TestCase
{
    #[TestDox('a resolver named by container id produces the URLs of the record, dependencies injected')]
    public function testResolverByContainerId(): void
    {
        $container = Fixtures::container($this->transport, $this->logger, [], ['app.tag_urls' => new TagUrlResolver('/tags/')]);
        $indexNow = $container->get(IndexNow::class);
        \assert($indexNow instanceof IndexNow);
        $indexNow->observe(Tag::class, [new IndexNowRule(resolver: 'app.tag_urls')]);

        $tag = new Tag();
        $tag->name = 'php';
        $tag->save();
        $indexNow->flush();

        self::assertSame(['https://www.example.com/tags/php'], $this->sentUrls());
        self::assertSame([], $this->logger->messages('error'));
    }

    #[TestDox('a resolver class the container can build is accepted by its class name too')]
    public function testResolverByClassName(): void
    {
        $indexNow = $this->indexNow();
        $indexNow->observe(Tag::class, [new IndexNowRule(resolver: TagUrlResolver::class)]);

        self::assertSame(['/tags/hello'], $indexNow->urlsFor($this->tag('hello')), 'the rule resolved through the container-built class');
    }

    #[TestDox('an unknown resolver id is a ConfigurationException naming the id and the adapter\'s hint; the record still saves')]
    public function testUnknownResolverId(): void
    {
        $indexNow = $this->indexNow();
        $indexNow->observe(Tag::class, [new IndexNowRule(resolver: 'app.nowhere')]);

        $tag = new Tag();
        $tag->name = 'saved';
        $tag->save();
        $indexNow->flush();

        self::assertNotNull($tag->id, 'the save went through: nothing is thrown into the application');
        self::assertSame([], $this->transport->posts);
        self::assertStringContainsString('app.nowhere', implode("\n", $this->logger->messages('error')));

        $locator = $this->container->get(ResolverLocatorInterface::class);
        \assert($locator instanceof ResolverLocatorInterface);
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/IndexNow URL resolver "app\.nowhere" is neither a container id or a class name nor an instantiable class/');
        $locator->get('app.nowhere');
    }

    #[TestDox('an id the container cannot build is the core locator\'s one text, not a text of this adapter')]
    public function testAnIdTheContainerCannotBuild(): void
    {
        $container = Fixtures::container($this->transport, $this->logger, [], [
            'app.broken_resolver' => static fn(): UrlResolverInterface => throw new RuntimeException('the CDN client is missing'),
        ]);
        $locator = $container->get(ResolverLocatorInterface::class);
        \assert($locator instanceof ResolverLocatorInterface);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/IndexNow URL resolver "app\.broken_resolver" cannot be built by the container: .*the CDN client is missing/');
        $locator->get('app.broken_resolver');
    }

    private function tag(string $name): Tag
    {
        $tag = new Tag();
        $tag->name = $name;
        $tag->save();

        return $tag;
    }
}

/** A resolver of the application: a prefix from the container, the record's own accessor for the rest. */
final class TagUrlResolver implements UrlResolverInterface
{
    public function __construct(private readonly string $prefix = '/tags/') {}

    public function resolve(object $subject, Event $event): iterable
    {
        \assert($subject instanceof Tag);

        return [$this->prefix . $subject->name];
    }
}
