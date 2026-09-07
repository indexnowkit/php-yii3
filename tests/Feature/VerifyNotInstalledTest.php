<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Console\Command\CheckCommand;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Http\Response;
use IndexNowKit\Submitter;
use IndexNowKit\Verify\VerifyingSubmitterFactory;
use IndexNowKit\Yii3\Config\ConfigFactory;
use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * indexnowkit/verify not installed (`verifyInstalled: false`) while a `verify` block is configured: the block is
 * ignored as a whole, `check` says so, `--sample` is an error naming the install line, `verifyConfig()` throws,
 * nothing is fetched before a submission.
 */
final class VerifyNotInstalledTest extends Yii3TestCase
{
    protected function console(): bool
    {
        return true;
    }

    protected function optionOverrides(): array
    {
        return ['verify' => ['enabled' => true, 'redirekt' => 'follow']];
    }

    protected function indexNowArguments(): array
    {
        return ['verifyInstalled' => false];
    }

    #[TestDox('check prints the ignored-block line; --sample is an error with the install line')]
    public function testCheck(): void
    {
        $this->transport
            ->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY, headers: ['Content-Type' => 'text/plain']));

        [$code, $output] = $this->yii(CheckCommand::class);
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('verify: not installed, the verify block in the configuration is ignored (composer require indexnowkit/verify) — pre-flight checks off', $output);

        [$code, $output] = $this->yii(CheckCommand::class, ['--sample' => ['https://www.example.com/x']]);
        self::assertSame(ExitCode::FAILURE, $code);
        self::assertStringContainsString('check --sample needs indexnowkit/verify (composer require indexnowkit/verify)', $output);
        self::assertNotContains('https://www.example.com/x', $this->transport->gets);
        self::assertSame([], $this->logger->messages('warning'), 'the verify block (with a typo) is ignored as a whole');
        $indexNow = $this->indexNow();
        self::assertSame([], ConfigFactory::factory($indexNow->options(), $indexNow->sitemapPackage(), $indexNow->verifyPackage(), $indexNow->historyPackage())->unknownOptions($indexNow->options()));
    }

    public function testThePlainSubmitterAndNothingFetched(): void
    {
        $indexNow = $this->indexNow();
        $post = new Post();
        $post->slug = 'plain';
        $post->save();
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/plain'], $this->sentUrls());
        self::assertSame([], $this->transport->gets);
        self::assertInstanceOf(Submitter::class, $indexNow->services()->submitter());
        self::assertNotInstanceOf(VerifyingSubmitterFactory::class, $indexNow->submitterFactory());
        self::assertFalse($indexNow->verifyEnabled());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('indexnowkit/verify is not installed: composer require indexnowkit/verify');
        $indexNow->verifyConfig();
    }
}
