<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Console\ExitCode;
use IndexNowKit\Http\Response;
use IndexNowKit\Submission\NullSubmissionStore;
use IndexNowKit\Yii3\Config\ConfigFactory;
use IndexNowKit\Yii3\Console\CheckCommand;
use IndexNowKit\Yii3\Console\ConfigCommand;
use IndexNowKit\Yii3\Console\HistoryCommand;
use IndexNowKit\Yii3\Console\HistoryNotInstalledCommand;
use IndexNowKit\Yii3\Console\StatusCommand;
use IndexNowKit\Yii3\Console\StatusNotInstalledCommand;
use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * indexnowkit/history not installed (`historyInstalled: false`) while a `history` block is configured: the block is
 * ignored as a whole, `check` says so, `indexnow:history` and `indexnow:status` (the stubs and the real commands)
 * print the install line and exit 1, `historyConfig()` throws, nothing is recorded.
 */
final class HistoryNotInstalledTest extends Yii3TestCase
{
    protected function console(): bool
    {
        return true;
    }

    protected function optionOverrides(): array
    {
        return ['history' => ['store' => 'pdo', 'pdo' => ['tabel' => 'x']]];
    }

    protected function indexNowArguments(): array
    {
        return ['historyInstalled' => false];
    }

    #[TestDox('check prints the ignored-block line; history and status print the install line and exit 1')]
    public function testCheckAndTheCommands(): void
    {
        $this->transport
            ->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY, headers: ['Content-Type' => 'text/plain']));

        [$code, $output] = $this->yii(CheckCommand::class);
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('history: not installed, the history block in the configuration is ignored (composer require indexnowkit/history)', $output);
        self::assertSame([], $this->logger->messages('warning'), 'the history block (with a typo) is ignored as a whole');
        $indexNow = $this->indexNow();
        self::assertSame([], ConfigFactory::factory($indexNow->options(), $indexNow->sitemapPackage(), $indexNow->verifyPackage(), $indexNow->historyPackage())->unknownOptions($indexNow->options()));
        [$code] = $this->yii(CheckCommand::class, ['--strict' => true]);
        self::assertSame(ExitCode::FAILURE, $code, 'an ignored block is a warning');

        foreach ([HistoryNotInstalledCommand::class, StatusNotInstalledCommand::class, HistoryCommand::class, StatusCommand::class] as $class) {
            [$code, $output] = $this->yii($class, ['--json' => true]);
            self::assertSame(ExitCode::FAILURE, $code);
            self::assertSame('indexnowkit/history is not installed: composer require indexnowkit/history', trim($output));
        }
    }

    public function testNothingIsRecordedAndHistoryConfigThrows(): void
    {
        $indexNow = $this->indexNow();
        $post = new Post();
        $post->slug = 'plain';
        $post->save();
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/plain'], $this->sentUrls());
        self::assertInstanceOf(NullSubmissionStore::class, $indexNow->services()->submissionStore());
        self::assertFalse($indexNow->historyEnabled());

        [, $output] = $this->yii(ConfigCommand::class, ['--json' => true]);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('history', $decoded);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('indexnowkit/history is not installed: composer require indexnowkit/history');
        $indexNow->historyConfig();
    }
}
