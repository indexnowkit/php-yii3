<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;

final class DisabledTest extends Yii3TestCase
{
    protected function optionOverrides(): array
    {
        return ['active_record' => ['enabled' => false]];
    }

    #[TestDox('active_record.enabled false: the attribute hooks and the models list are inert, submit() still works')]
    public function testInert(): void
    {
        $post = new Post();
        $post->slug = 'silent';
        $post->save();
        $this->kit()->flush();
        self::assertSame([], $this->transport->posts);

        $this->indexNow()->submit(['/manual']);
        self::assertSame(['https://www.example.com/manual'], $this->sentUrls());
    }
}
