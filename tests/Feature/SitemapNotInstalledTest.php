<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Console\ExitCode;
use IndexNowKit\Yii3\Config\ConfigFactory;
use IndexNowKit\Yii3\Console\CheckCommand;
use IndexNowKit\Yii3\Console\SitemapCommand;
use IndexNowKit\Yii3\Console\SitemapNotInstalledCommand;
use IndexNowKit\Yii3\Console\SubmitCommand;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * indexnowkit/sitemap not installed (`sitemapInstalled: false` on the service): `indexnow:sitemap` prints the install
 * line and exits 1 (the stub and the real command alike), `indexnow:check` prints one line about it, the `sitemap`
 * block of the fixtures warns about nothing, `sitemapConfig()` / `sitemapSource()` throw, everything else works and
 * nothing is logged.
 */
final class SitemapNotInstalledTest extends Yii3TestCase
{
    protected function console(): bool
    {
        return true;
    }

    protected function optionOverrides(): array
    {
        return ['sitemap' => ['spol' => 'disk']]; // a typo the package would warn about: ignored without it
    }

    protected function indexNowArguments(): array
    {
        return ['sitemapInstalled' => false];
    }

    #[TestDox('indexnow:sitemap (the stub, and the real command with the predicate off) accepts the arguments, prints the install line and exits 1')]
    public function testStubCommand(): void
    {
        foreach ([SitemapNotInstalledCommand::class, SitemapCommand::class] as $class) {
            [$code, $output] = $this->yii($class, ['sitemap' => 'https://www.example.com/sitemap.xml', '--dry-run' => true]);

            self::assertSame(ExitCode::FAILURE, $code);
            self::assertStringContainsString('indexnowkit/sitemap is not installed: composer require indexnowkit/sitemap', $output);
            self::assertSame([], $this->transport->posts);
        }
    }

    #[TestDox('indexnow:check says the block is ignored (the fixtures have one); the other commands work')]
    public function testCheckAndOtherCommands(): void
    {
        [, $output] = $this->yii(CheckCommand::class);
        self::assertStringContainsString('sitemap: not installed, the sitemap block in the configuration is ignored (composer require indexnowkit/sitemap)', $output);
        self::assertStringNotContainsString('spool', $output, 'no spool line, no unknown option line');
        self::assertSame('sitemap: not installed (composer require indexnowkit/sitemap)', $this->indexNow()->sitemapPackage()->checkLine([], []), 'no block: the plain line');

        [$code, $output] = $this->yii(SubmitCommand::class, ['urls' => ['/a'], '--dry-run' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        self::assertStringContainsString('dry_run', $output);
    }

    #[TestDox('sitemapConfig() and sitemapSource() throw the install line; the options with the sitemap block build without a warning; nothing is logged')]
    public function testSilentWithoutThePackage(): void
    {
        $indexNow = $this->indexNow();
        self::assertFalse($indexNow->sitemapInstalled());
        self::assertTrue($indexNow->config()->enabled);
        self::assertSame([], ConfigFactory::factory($indexNow->options(), $indexNow->sitemapPackage(), $indexNow->verifyPackage(), $indexNow->historyPackage())->unknownOptions($indexNow->options()));
        self::assertSame([], $indexNow->urlsForAll([]), 'the graph builds without the package');
        self::assertSame([], $this->logger->messages('warning'), 'the sitemap block (with a typo) is ignored as a whole');
        self::assertSame([], $this->logger->messages('critical'));
        self::assertSame([], $this->logger->messages('error'));

        try {
            $indexNow->sitemapConfig();
            self::fail('expected a LogicException');
        } catch (LogicException $e) {
            self::assertSame('indexnowkit/sitemap is not installed: composer require indexnowkit/sitemap', $e->getMessage());
        }
        $this->expectException(LogicException::class);
        $indexNow->sitemapSource();
    }
}
