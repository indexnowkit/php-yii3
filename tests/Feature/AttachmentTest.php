<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Attribute\IndexNow as IndexNowRule;
use IndexNowKit\Yii3\ActiveRecord\IndexNowObserver;
use IndexNowKit\Yii3\ActiveRecord\ObserverProvider;
use IndexNowKit\Yii3\Console\CheckCommand;
use IndexNowKit\Yii3\Event\ObservedDispatcher;
use IndexNowKit\Yii3\IndexNow;
use IndexNowKit\Yii3\Tests\Fixtures\ModelPost;
use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Fixtures\Tag;
use IndexNowKit\Yii3\Tests\Support\Fixtures;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Yiisoft\ActiveRecord\Event\EventDispatcherProvider;

/**
 * How a class reaches the observer: through its own `#[IndexNowEvents]` handlers, or through the dispatcher the
 * observer wraps around it (`active_record.models`, `IndexNow::observe()`) — never through both, and never through
 * two wrappers stacked on each other.
 */
final class AttachmentTest extends Yii3TestCase
{
    protected function console(): bool
    {
        return true;
    }

    #[TestDox('a class that carries #[IndexNowEvents] is not wrapped a second time by active_record.models; check names it')]
    public function testAnAttributedClassIsHookedOnce(): void
    {
        $container = Fixtures::container($this->transport, $this->logger, ['active_record' => ['models' => [ModelPost::class, Post::class]]], [], [], 'console');
        $indexNow = $container->get(IndexNow::class);
        \assert($indexNow instanceof IndexNow);

        self::assertTrue(IndexNowObserver::carriesEventsAttribute(Post::class));
        self::assertFalse($indexNow->observer()->isAttachedTo(Post::class), 'the attribute already reaches the observer');
        self::assertNotInstanceOf(ObservedDispatcher::class, EventDispatcherProvider::get(Post::class));
        self::assertTrue($indexNow->observer()->isAttachedTo(ModelPost::class), 'a class without the attribute is wrapped');

        $post = new Post();
        $post->slug = 'once';
        $post->save();
        $indexNow->flush();

        self::assertSame(['https://www.example.com/posts/once'], $this->sentUrls());

        $command = $container->get(CheckCommand::class);
        \assert($command instanceof Command);
        $tester = new CommandTester($command);
        $tester->execute([]);
        self::assertStringContainsString(Post::class . ' carry #[IndexNowEvents] and are listed in active_record.models: they are hooked once, through the attribute', $tester->getDisplay());
    }

    #[TestDox('attaching twice keeps one wrapper; a second container replaces it instead of stacking a second one')]
    public function testAttachingReplacesInsteadOfStacking(): void
    {
        $observer = $this->indexNow()->observer();
        $observer->attachTo(Tag::class);
        $first = EventDispatcherProvider::get(Tag::class);
        self::assertInstanceOf(ObservedDispatcher::class, $first);

        $observer->attachTo(Tag::class);
        self::assertSame($first, EventDispatcherProvider::get(Tag::class), 'attaching twice does nothing');

        // a second container in the same process (a per-request container, the next test) hooks the same class
        $second = Fixtures::container($this->transport, $this->logger, [], [], [], 'console');
        $indexNow = $second->get(IndexNow::class);
        \assert($indexNow instanceof IndexNow);
        $indexNow->observe(Tag::class, [new IndexNowRule(route: 'page/view', params: ['slug' => 'name'])]);

        $wrapper = EventDispatcherProvider::get(Tag::class);
        self::assertInstanceOf(ObservedDispatcher::class, $wrapper);
        self::assertNotSame($first, $wrapper);
        self::assertNotInstanceOf(ObservedDispatcher::class, $wrapper->inner(), 'the first wrapper was replaced, not wrapped');
    }

    #[TestDox('ObserverProvider::reset() gives every wrapped class its own dispatcher back')]
    public function testResetDetachesTheObserver(): void
    {
        $plain = EventDispatcherProvider::get(Tag::class);
        $observer = $this->indexNow()->observer();
        $observer->attachTo(Tag::class);
        self::assertInstanceOf(ObservedDispatcher::class, EventDispatcherProvider::get(Tag::class));

        ObserverProvider::reset();

        self::assertSame($plain, EventDispatcherProvider::get(Tag::class), 'the class keeps its own dispatcher, the observer is not held by the static provider');
        self::assertFalse($observer->isAttachedTo(Tag::class));
        self::assertFalse(ObserverProvider::isSet());
    }
}
