<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;

final class InvalidConfigTest extends Yii3TestCase
{
    protected function optionOverrides(): array
    {
        return ['key' => 'short', 'environment' => 'prod'];
    }

    #[TestDox('an invalid key does not throw from a save: IndexNow runs disabled, one critical line naming the check command')]
    public function testDisabledOnInvalidConfig(): void
    {
        $post = new Post();
        $post->slug = 'x';
        $post->save();
        $this->kit()->flush();

        self::assertSame([], $this->transport->posts);
        self::assertFalse($this->indexNow()->config()->enabled);
        self::assertCount(1, $this->logger->messages('critical'));
        self::assertStringContainsString('IndexNow is disabled until it is fixed', $this->logger->messages('critical')[0]);
        self::assertStringContainsString('./yii indexnow:check', $this->logger->messages('critical')[0]);
        self::assertSame('indexnow', $this->logger->records[0]['context']['category'] ?? null, 'every line carries the log category');
    }
}
