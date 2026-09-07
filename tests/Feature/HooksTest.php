<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii3\ActiveRecord\ObserverProvider;
use IndexNowKit\Yii3\Tests\Fixtures\ModelPost;
use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Fixtures\Tag;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;

final class HooksTest extends Yii3TestCase
{
    #[TestDox('a class listed in active_record.models is hooked at bootstrap, no attribute needed')]
    public function testModelsList(): void
    {
        $record = new ModelPost();
        $record->name = 'listed';
        $record->save();
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/pages/listed'], $this->sentUrls());
        self::assertTrue($this->indexNow()->observer()->isAttachedTo(ModelPost::class));
    }

    #[TestDox('observe() hooks a class and registers rules for it at runtime')]
    public function testObserve(): void
    {
        $this->indexNow()->observe(Tag::class, [new IndexNow(route: 'page/view', params: ['slug' => 'name'])]);
        $tag = new Tag();
        $tag->name = 'observed';
        $tag->save();
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/pages/observed'], $this->sentUrls());
    }

    #[TestDox('submitRecords() is the manual path after updateAll(): one request for many records')]
    public function testSubmitRecords(): void
    {
        foreach (['a', 'b'] as $name) {
            $record = new ModelPost();
            $record->name = $name;
            $record->save();
        }
        $this->kit()->flush();
        $this->transport->posts = [];
        (new ModelPost())->updateAll(['name' => 'z']);
        self::assertSame([], $this->transport->posts, 'updateAll() fires no events (A13)');

        $this->indexNow()->submitRecords(ModelPost::query()->all());

        self::assertCount(1, $this->transport->posts);
        self::assertSame(['https://www.example.com/pages/z'], $this->sentUrls());
    }

    #[TestDox('an update that changes nothing (save() without a change) submits nothing; an untracked column alone submits nothing')]
    public function testNoChangeNoSubmission(): void
    {
        $post = new Post();
        $post->slug = 'same';
        $post->save();
        $this->kit()->flush();
        $this->transport->posts = [];

        $post->save();
        $post->views = 5;
        $post->save();
        $this->kit()->flush();

        self::assertSame([], $this->transport->posts);
    }

    #[TestDox('the observer is the one the bootstrap installed; the events of the hooks reach it')]
    public function testBootstrapInstalledTheObserver(): void
    {
        self::assertTrue(ObserverProvider::isSet());
        self::assertSame($this->indexNow()->observer(), ObserverProvider::get());
    }
}
