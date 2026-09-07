<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Config;
use IndexNowKit\Console\Command\CheckCommand;
use IndexNowKit\Console\Command\HistoryNotInstalledCommand;
use IndexNowKit\Console\Command\SitemapNotInstalledCommand;
use IndexNowKit\Console\Command\StatusNotInstalledCommand;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\History\Console\HistoryCommand;
use IndexNowKit\History\Console\StatusCommand;
use IndexNowKit\Sitemap\Console\SitemapCommand;
use IndexNowKit\Testing\Conformance\OptionalPackageAssertions;
use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The optional packages left to detection (`sitemapInstalled` / `verifyInstalled` / `historyInstalled` of the
 * service at their default null): the container builds, the config builds, `indexnow:check` names exactly the
 * packages that are absent, a hook submits. With the packages in `vendor/` this is the ordinary boot; the CI job
 * `optional-packages-absent` runs it after `composer remove` of the three packages.
 */
final class OptionalPackagesDetectionTest extends Yii3TestCase
{
    protected function console(): bool
    {
        return true;
    }

    #[TestDox('the config builds with detection; check names the absent packages; a hook submits')]
    public function testDetection(): void
    {
        self::assertInstanceOf(Config::class, $this->indexNow()->config());
        self::assertInstanceOf(Config::class, $this->indexNow()->buildConfig());
        self::assertTrue($this->indexNow()->config()->enabled);

        // by name, through the map of config/params-console.php: that map is what `./yii` reads
        self::assertInstanceOf(CheckCommand::class, $this->commandNamed('indexnow:check'));
        [$code, $display] = $this->yiiNamed('indexnow:check');
        OptionalPackageAssertions::assertDetected($display);
        self::assertContains($code, [ExitCode::SUCCESS, ExitCode::FAILURE], $display);

        $post = new Post();
        $post->slug = 'detected';
        $post->save();
        $this->kit()->flush();
        self::assertSame(['https://www.example.com/posts/detected'], $this->sentUrls());
        self::assertSame([], $this->logger->messages('critical'));
        self::assertSame([], $this->logger->messages('error'));
    }

    #[TestDox('the commands of the optional packages resolve to the stubs when the packages were removed, to the real ones otherwise')]
    public function testTheCommandMapFollowsTheDetection(): void
    {
        $absent = OptionalPackageAssertions::expectAbsent();

        self::assertInstanceOf($absent ? SitemapNotInstalledCommand::class : SitemapCommand::class, $this->commandNamed('indexnow:sitemap'));
        self::assertInstanceOf($absent ? HistoryNotInstalledCommand::class : HistoryCommand::class, $this->commandNamed('indexnow:history'));
        self::assertInstanceOf($absent ? StatusNotInstalledCommand::class : StatusCommand::class, $this->commandNamed('indexnow:status'));
    }
}
