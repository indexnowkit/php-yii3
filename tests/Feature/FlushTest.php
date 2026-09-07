<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Yii3\Tests\Fixtures\Item;
use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Yiisoft\Yii\Http\Event\AfterEmit;

final class FlushTest extends Yii3TestCase
{
    #[TestDox('H06 record saved during a request -> nothing sent before the response, POST on AfterEmit (events-web.php)')]
    public function testFlushAfterResponseIsSent(): void
    {
        $post = new Post();
        $post->slug = 'hello';
        $post->save();

        self::assertCount(1, $this->kit()->collector->all(), 'the URL waits in the collector while the response is built');
        self::assertSame([], $this->transport->posts);

        $this->events()->dispatch(new AfterEmit(null));

        self::assertSame(['https://www.example.com/posts/hello'], $this->sentUrls());
        self::assertSame('www.example.com', $this->transport->posts[0]['body']['host']);
        self::assertSame(self::KEY, $this->transport->posts[0]['body']['key']);
    }

    #[TestDox('A04 delete -> the URL resolved in BeforeDelete is submitted after the row is gone')]
    public function testDelete(): void
    {
        $post = new Post();
        $post->slug = 'bye';
        $post->save();
        $this->kit()->flush();
        $post->delete();
        $this->kit()->flush();

        self::assertCount(2, $this->transport->posts);
        self::assertSame(['https://www.example.com/posts/bye'], $this->transport->posts[1]['body']['urlList']);
    }

    #[TestDox('a `self` parameter is the primary key value')]
    public function testSelfIsPrimaryKey(): void
    {
        $item = new Item();
        $item->name = 'thing';
        $item->save();
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/items/' . $item->id], $this->sentUrls());
    }

    #[TestDox('an idle request builds nothing: flushIfCollected() before any save touches no graph')]
    public function testIdleRequestBuildsNothing(): void
    {
        $this->indexNow()->flushIfCollected();

        self::assertSame([], $this->transport->posts);
        self::assertSame([], $this->logger->records);
    }
}
