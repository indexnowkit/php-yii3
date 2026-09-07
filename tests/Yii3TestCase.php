<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests;

use IndexNowKit\IndexNowKit;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Yii3\IndexNow;
use IndexNowKit\Yii3\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;

/**
 * A Yii3 container in memory over the package's own config files, the package bootstrapped, sqlite with the
 * fixture schema, a FakeTransport instead of HTTP and an ArrayLogger instead of yiisoft/log.
 */
abstract class Yii3TestCase extends TestCase
{
    public const KEY = Fixtures::KEY;
    public const SECOND_KEY = Fixtures::SECOND_KEY;
    public const BASE_URL = Fixtures::BASE_URL;

    protected FakeTransport $transport;
    protected ArrayLogger $logger;
    protected Container $container;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->logger = new ArrayLogger();
        $this->container = Fixtures::container($this->transport, $this->logger, $this->optionOverrides(), $this->definitionOverrides(), $this->indexNowArguments(), $this->console() ? 'console' : 'web');
    }

    protected function tearDown(): void
    {
        Fixtures::destroy();
    }

    /** Whether the events group is the console one (`ApplicationShutdown`) instead of the web one (`AfterEmit`). */
    protected function console(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function optionOverrides(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function definitionOverrides(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed> extra constructor arguments of `IndexNow` (`sitemapInstalled` => false, ...)
     */
    protected function indexNowArguments(): array
    {
        return [];
    }

    protected function indexNow(): IndexNow
    {
        $indexNow = $this->container->get(IndexNow::class);
        \assert($indexNow instanceof IndexNow);

        return $indexNow;
    }

    protected function kit(): IndexNowKit
    {
        return $this->indexNow()->kit();
    }

    protected function db(): ConnectionInterface
    {
        $db = $this->container->get(ConnectionInterface::class);
        \assert($db instanceof ConnectionInterface);

        return $db;
    }

    protected function events(): EventDispatcherInterface
    {
        $events = $this->container->get(EventDispatcherInterface::class);
        \assert($events instanceof EventDispatcherInterface);

        return $events;
    }

    /**
     * @param class-string<Command> $class
     */
    protected function command(string $class): CommandTester
    {
        $command = $this->container->get($class);
        \assert($command instanceof Command);

        return new CommandTester($command);
    }

    /**
     * The command `./yii <name>` resolves: built by the container through the map of `config/params-console.php`,
     * so the optional-package branches of that file are what decides between the real command and its stub.
     */
    protected function commandNamed(string $name): Command
    {
        return Fixtures::consoleApplication($this->container)->find($name);
    }

    /**
     * Runs a command the way `./yii` does.
     *
     * @param class-string<Command> $class
     * @param array<string, mixed>  $input arguments and options (`'--json' => true`)
     *
     * @return array{0: int, 1: string} exit code and output
     */
    protected function yii(string $class, array $input = []): array
    {
        $tester = $this->command($class);
        $code = $tester->execute($input);

        return [$code, $tester->getDisplay()];
    }

    /**
     * Runs the command `./yii <name>` resolves, the way `./yii` does.
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: int, 1: string} exit code and output
     */
    protected function yiiNamed(string $name, array $input = []): array
    {
        $tester = new CommandTester($this->commandNamed($name));
        $code = $tester->execute($input);

        return [$code, $tester->getDisplay()];
    }

    /**
     * @return list<string>
     */
    protected function sentUrls(): array
    {
        $urls = [];
        foreach ($this->transport->posts as $post) {
            /** @var list<string> $list */
            $list = $post['body']['urlList'];
            $urls = [...$urls, ...$list];
        }

        return $urls;
    }
}
