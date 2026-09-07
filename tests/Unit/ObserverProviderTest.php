<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Unit;

use IndexNowKit\Yii3\ActiveRecord\ObserverProvider;
use IndexNowKit\Yii3\Env;
use IndexNowKit\Yii3\Log\CategoryLogger;
use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;

final class ObserverProviderTest extends Yii3TestCase
{
    #[TestDox('without the bootstrap a save with #[IndexNowEvents] warns once (E_USER_WARNING) and submits nothing; the save succeeds')]
    public function testUnsetProviderWarnsOnce(): void
    {
        ObserverProvider::reset();
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = [$level, $message];

            return true;
        }, E_USER_WARNING);
        try {
            foreach (['one', 'two'] as $slug) {
                $post = new Post();
                $post->slug = $slug;
                $post->save();
                self::assertNotNull($post->id, 'the save went through');
            }
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $warnings, 'one warning per process, then silence');
        self::assertSame(E_USER_WARNING, $warnings[0][0]);
        self::assertStringContainsString('config/bootstrap.php did not run', $warnings[0][1]);
        $this->kit()->flush();
        self::assertSame([], $this->transport->posts);
    }

    #[TestDox('the category logger adds the category context and keeps one the line already names')]
    public function testCategoryLogger(): void
    {
        $logger = new CategoryLogger($this->logger, 'seo');
        $logger->info('a');
        $logger->info('b', ['category' => 'other']);

        self::assertSame('seo', $logger->category());
        self::assertSame('seo', $this->logger->records[0]['context']['category']);
        self::assertSame('other', $this->logger->records[1]['context']['category']);
    }

    #[TestDox('Env reads $_ENV, $_SERVER and getenv(), treats an empty string as unset, and names the environment from YII_ENV then APP_ENV')]
    public function testEnv(): void
    {
        self::assertSame('test', Env::name(), 'tests/bootstrap.php sets YII_ENV');
        $_ENV['INDEXNOWKIT_TEST_EMPTY'] = '';
        self::assertNull(Env::get('INDEXNOWKIT_TEST_EMPTY'));
        $_SERVER['INDEXNOWKIT_TEST_SERVER'] = 'srv';
        self::assertSame('srv', Env::get('INDEXNOWKIT_TEST_SERVER'));
        unset($_ENV['INDEXNOWKIT_TEST_EMPTY'], $_SERVER['INDEXNOWKIT_TEST_SERVER']);
        self::assertNull(Env::get('INDEXNOWKIT_TEST_NOPE'));
    }
}
