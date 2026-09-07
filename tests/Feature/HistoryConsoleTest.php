<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Console\Command\CheckCommand;
use IndexNowKit\Console\Command\ConfigCommand;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\History\Console\HistoryCommand;
use IndexNowKit\History\Console\StatusCommand;
use IndexNowKit\History\Pdo\Schema;
use IndexNowKit\Http\Response;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * `./yii indexnow:history`, `indexnow:status`, `indexnow:check` and `indexnow:config` with indexnowkit/history and
 * `history.store: pdo` over the container connection: the recorded submissions, the filters, --purge, the status
 * (text and JSON per the package's schema), the check lines, the config section — and a missing table as a check error.
 */
final class HistoryConsoleTest extends Yii3TestCase
{
    protected function console(): bool
    {
        return true;
    }

    protected function optionOverrides(): array
    {
        return ['history' => ['store' => 'pdo', 'retention_days' => 30]];
    }

    #[TestDox('history lists a recorded submission (table, --json, filters) and --purge runs the retention')]
    public function testHistory(): void
    {
        $this->createTable();
        $this->kit()->submit(['https://www.example.com/posts/one', 'https://www.example.com/posts/two']);
        self::assertCount(2, $this->sentUrls());

        [$code, $output] = $this->yii(HistoryCommand::class);
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('https://www.example.com/posts/one', $output);
        self::assertStringContainsString('https://www.example.com/posts/two', $output);
        self::assertStringContainsString('1 record(s)', $output);

        [$code, $output] = $this->yii(HistoryCommand::class, ['--json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded['records']);
        self::assertSame(['ok', 'api', 200], [$decoded['records'][0]['status'], $decoded['records'][0]['engine'], $decoded['records'][0]['http_status']]);
        self::assertCount(2, $decoded['records'][0]['urls']);

        [, $output] = $this->yii(HistoryCommand::class, ['--url' => 'https://www.example.com/posts/two?utm_source=x']);
        self::assertStringContainsString('1 record(s)', $output, '--url is normalized the way the submission was');
        [, $output] = $this->yii(HistoryCommand::class, ['--host' => 'example.de']);
        self::assertStringContainsString('No records match.', $output);
        [, $output] = $this->yii(HistoryCommand::class, ['--status' => 'skipped']);
        self::assertStringContainsString('No records match.', $output);
        [, $output] = $this->yii(HistoryCommand::class, ['--limit' => '1', '--json' => true]);
        self::assertCount(1, json_decode($output, true, flags: JSON_THROW_ON_ERROR)['records'] ?? []);

        [$code, $output] = $this->yii(HistoryCommand::class, ['--purge' => null]);
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertMatchesRegularExpression('/^purged 0 records older than \d{4}-/', trim($output));
        [, $output] = $this->yii(HistoryCommand::class, ['--purge' => '7']);
        self::assertStringContainsString('purged 0 records older than', $output);
    }

    public function testStatusCheckAndConfig(): void
    {
        $this->createTable();
        $this->stubKeyFiles();

        [$code, $output] = $this->yii(StatusCommand::class);
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('dispatch: sync', $output);
        self::assertStringContainsString('debounce: off, store memory', $output);
        self::assertStringContainsString('www.example.com: 0 consecutive 403', $output);
        self::assertStringContainsString('history: 0 records, no successful submission recorded', $output);

        [$code, $output] = $this->yii(StatusCommand::class, ['--json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertStatusFollowsTheSchema($decoded);
        self::assertSame(['mode' => 'sync', 'adapter' => []], $decoded['dispatch']);
        self::assertSame(['per_url' => 0, 'store' => 'memory'], $decoded['debounce']);
        self::assertSame(['store' => 'history', 'records' => 0, 'last_success' => null, 'error' => null], $decoded['history']);

        [$code, $output] = $this->yii(CheckCommand::class);
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('history: pdo store (indexnow_submissions)', $output);
        self::assertStringContainsString('history: no records yet', $output);

        [$code, $output] = $this->yii(ConfigCommand::class, ['--json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(['store' => 'pdo', 'limit' => 500, 'key_prefix' => null, 'pdo' => ['dsn' => null, 'service' => null, 'table' => 'indexnow_submissions'], 'retention_days' => 30], $decoded['history']);
        self::assertArrayNotHasKey('history', $decoded['adapter']);
    }

    #[TestDox('the debounce store of status names the container id and the class of the cache')]
    public function testStatusDescribesTheContainerCache(): void
    {
        $container = \IndexNowKit\Yii3\Tests\Support\Fixtures::container($this->transport, $this->logger, ['debounce' => ['per_url' => 600, 'store' => null], 'history' => ['store' => 'psr16']], [], [], 'console');
        $command = $container->get(StatusCommand::class);
        \assert($command instanceof StatusCommand);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertSame(ExitCode::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('store Psr\SimpleCache\CacheInterface (ArrayCache)', $tester->getDisplay());
    }

    #[TestDox('without the table, check prints the history.store error with the migration hint and status reports the error')]
    public function testMissingTableIsACheckError(): void
    {
        $this->stubKeyFiles();

        [$code, $output] = $this->yii(CheckCommand::class, ['--json' => true]);
        self::assertSame(ExitCode::FAILURE, $code);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $items = array_values(array_filter($decoded['items'], static fn(array $i): bool => $i['code'] === 'history.store'));
        self::assertCount(1, $items);
        self::assertSame('error', $items[0]['level']);
        self::assertStringContainsString('docs/migrations.md', $items[0]['message']);

        [$code, $output] = $this->yii(StatusCommand::class, ['--json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code, 'status stays read-only and exit 0; the error is a field');
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertNotNull($decoded['history']['error']);
    }

    private function createTable(): void
    {
        foreach (Schema::sql('sqlite') as $sql) {
            $this->db()->createCommand($sql)->execute();
        }
    }

    private function stubKeyFiles(): void
    {
        $this->transport
            ->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY, headers: ['Content-Type' => 'text/plain']));
    }

    /**
     * The required members of packages/history/docs/status.schema.json, top level and one level down (the schema
     * validator lives in the history package's tests; here the shape of what the adapter wires is enough).
     *
     * @param array<string, mixed> $status
     */
    public static function assertStatusFollowsTheSchema(array $status): void
    {
        $required = [
            '' => ['enabled', 'dry_run', 'environment', 'dispatch', 'debounce', 'engines', 'forbidden_escalation', 'hosts', 'history', 'core'],
            'dispatch' => ['mode', 'adapter'],
            'debounce' => ['per_url', 'store'],
            'history' => ['store', 'records', 'last_success', 'error'],
        ];
        foreach ($required[''] as $key) {
            self::assertArrayHasKey($key, $status);
        }
        foreach (['dispatch', 'debounce', 'history'] as $section) {
            self::assertIsArray($status[$section]);
            foreach ($required[$section] as $key) {
                self::assertArrayHasKey($key, $status[$section]);
            }
        }
        foreach ($status['hosts'] as $host) {
            self::assertSame(['host', 'forbidden', 'escalated'], array_keys($host));
        }
    }
}
