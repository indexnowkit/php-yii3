<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Yii3\Console\CheckCommand;
use IndexNowKit\Yii3\Console\HistoryCommand;
use IndexNowKit\Yii3\Console\HistoryNotInstalledCommand;
use IndexNowKit\Yii3\Console\SitemapCommand;
use IndexNowKit\Yii3\Console\SitemapNotInstalledCommand;
use IndexNowKit\Yii3\Console\StatusCommand;
use IndexNowKit\Yii3\Console\StatusNotInstalledCommand;
use IndexNowKit\Yii3\Tests\Support\Fixtures;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Command\Command;

/**
 * `config/params-console.php` is what `./yii` reads: the map is executed, every name resolves to a command the
 * container can build, and the three entries of the optional packages pick the real command or its stub through the
 * core's `OptionalPackage` predicate — the same one the service and `check` use.
 */
final class ConsoleCommandMapTest extends Yii3TestCase
{
    protected function console(): bool
    {
        return true;
    }

    #[TestDox('every name of params-console resolves to a command the container builds, and the names match the commands\' own')]
    public function testEveryCommandOfTheMapResolves(): void
    {
        $map = Fixtures::consoleCommands();
        self::assertArrayHasKey('indexnow:check', $map);

        foreach (array_keys($map) as $name) {
            $command = $this->commandNamed($name);
            self::assertInstanceOf(Command::class, $command);
            self::assertSame($name, $command->getName());
        }
    }

    #[TestDox('indexnow:check resolved by name is the check command and runs')]
    public function testCheckByName(): void
    {
        self::assertInstanceOf(CheckCommand::class, $this->commandNamed('indexnow:check'));

        [$code, $display] = $this->yiiNamed('indexnow:check');

        self::assertContains($code, [ExitCode::SUCCESS, ExitCode::FAILURE], $display);
        self::assertStringContainsString('active record:', $display);
    }

    #[TestDox('the optional-package entries follow OptionalPackage: the real command with the package, the stub without it')]
    public function testOptionalPackageBranches(): void
    {
        $expected = [
            'indexnow:sitemap' => [OptionalPackage::sitemap(), SitemapCommand::class, SitemapNotInstalledCommand::class],
            'indexnow:history' => [OptionalPackage::history(), HistoryCommand::class, HistoryNotInstalledCommand::class],
            'indexnow:status' => [OptionalPackage::history(), StatusCommand::class, StatusNotInstalledCommand::class],
        ];

        foreach ($expected as $name => [$package, $installed, $absent]) {
            self::assertInstanceOf($package->installed() ? $installed : $absent, $this->commandNamed($name), $name);
        }
    }

    #[TestDox('each stub carries the name of the command it replaces and answers with the install line and exit 1')]
    public function testStubsAnswerUnderTheNameTheyReplace(): void
    {
        $stubs = [
            'indexnow:sitemap' => [SitemapNotInstalledCommand::class, OptionalPackage::sitemap()],
            'indexnow:history' => [HistoryNotInstalledCommand::class, OptionalPackage::history()],
            'indexnow:status' => [StatusNotInstalledCommand::class, OptionalPackage::history()],
        ];

        foreach ($stubs as $name => [$class, $package]) {
            // the stub is built here whether or not the package is installed: the map picks it only in the second case
            $command = $this->container->get($class);
            self::assertInstanceOf(Command::class, $command);
            self::assertSame($name, $command->getName(), $name);

            [$code, $display] = $this->yii($class);
            self::assertSame(ExitCode::FAILURE, $code, $name);
            self::assertStringContainsString($package->notInstalledMessage(), $display);
        }
    }
}
