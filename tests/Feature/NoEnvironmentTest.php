<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Console\Command\CheckCommand;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Http\Response;
use IndexNowKit\Yii3\Env;
use IndexNowKit\Yii3\Tests\Support\Fixtures;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Neither `YII_ENV` nor `APP_ENV` is set — a bare container image, a cron without the application's environment.
 * The environment name is then unknown, so the non-production dry-run safety net cannot fire and `check` says
 * nothing about the environment: with a key and `dry_run` unset, submissions are real and the report is silent
 * about it. This is the branch the rest of the suite never reaches (tests/bootstrap.php sets `YII_ENV`).
 */
final class NoEnvironmentTest extends Yii3TestCase
{
    /** @var array<string, string|null> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (Env::NAME_VARIABLES as $variable) {
            $this->saved[$variable] = $_ENV[$variable] ?? null;
            unset($_ENV[$variable], $_SERVER[$variable]);
            putenv($variable);
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->saved as $variable => $value) {
            if ($value !== null) {
                $_ENV[$variable] = $value;
            }
        }
    }

    protected function console(): bool
    {
        return true;
    }

    protected function optionOverrides(): array
    {
        return ['dry_run' => null]; // the value the safety net would decide on
    }

    #[TestDox('with no YII_ENV and no APP_ENV the environment is unknown: no auto dry-run, and check says nothing about the environment')]
    public function testTheEnvironmentIsUnknown(): void
    {
        self::assertNull(Env::name());
        self::assertNull($this->indexNow()->environment());
        self::assertFalse($this->indexNow()->config()->dryRun, 'the safety net has no environment to compare and stays off');

        $this->transport->onGet(self::BASE_URL . '/' . self::KEY . '.txt', new Response(200, self::KEY));
        $this->transport->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY));

        [$code, $display] = $this->yii(CheckCommand::class);

        self::assertSame(ExitCode::SUCCESS, $code, $display);
        self::assertStringNotContainsString('production_environments', $display, 'nothing to say about an environment nobody named');
        self::assertStringNotContainsString('dry_run', $display);
    }

    #[TestDox('without an environment a saved record is submitted for real: nothing switches dry-run on behind the application\'s back')]
    public function testSubmissionsAreReal(): void
    {
        $container = Fixtures::container($this->transport, $this->logger, ['dry_run' => null], [], [], 'console');
        $indexNow = $container->get(\IndexNowKit\Yii3\IndexNow::class);
        \assert($indexNow instanceof \IndexNowKit\Yii3\IndexNow);

        $indexNow->submit(['/no-environment']);

        self::assertSame(['https://www.example.com/no-environment'], $this->sentUrls());
    }
}
