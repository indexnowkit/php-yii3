<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Unit;

use IndexNowKit\Yii3\Env;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The three places a Yii3 application keeps its environment: `$_ENV` (vlucas/phpdotenv), `$_SERVER` (the web
 * server, FPM) and the real environment (`getenv()`, a container image). First hit wins, an empty string counts as
 * unset, and the environment name is `YII_ENV` before `APP_ENV`.
 */
final class EnvTest extends TestCase
{
    private const NAME = 'INDEXNOWKIT_TEST_VARIABLE';

    protected function tearDown(): void
    {
        foreach ([self::NAME, ...Env::NAME_VARIABLES] as $variable) {
            unset($_ENV[$variable], $_SERVER[$variable]);
            putenv($variable);
        }
        $_ENV['YII_ENV'] = 'test'; // tests/bootstrap.php sets it for every other test
    }

    #[TestDox('$_ENV wins over $_SERVER, which wins over getenv()')]
    public function testTheOrderOfTheThreeSources(): void
    {
        putenv(self::NAME . '=from-getenv');
        self::assertSame('from-getenv', Env::get(self::NAME));

        $_SERVER[self::NAME] = 'from-server';
        self::assertSame('from-server', Env::get(self::NAME));

        $_ENV[self::NAME] = 'from-env';
        self::assertSame('from-env', Env::get(self::NAME));
    }

    #[TestDox('an empty value is unset in every source: an .env line with nothing after the "=" does not count')]
    public function testAnEmptyValueIsUnset(): void
    {
        $_ENV[self::NAME] = '';
        $_SERVER[self::NAME] = '';
        putenv(self::NAME . '=');
        self::assertNull(Env::get(self::NAME));

        $_SERVER[self::NAME] = 'set';
        self::assertSame('set', Env::get(self::NAME), 'the empty $_ENV entry is skipped, not returned');
    }

    #[TestDox('an unknown variable is null in all three sources')]
    public function testAnUnknownVariable(): void
    {
        self::assertNull(Env::get('INDEXNOWKIT_TEST_NOTHING_SETS_THIS'));
    }

    #[TestDox('the environment name is YII_ENV, then APP_ENV, and null when neither is set')]
    public function testTheEnvironmentName(): void
    {
        unset($_ENV['YII_ENV'], $_SERVER['YII_ENV'], $_ENV['APP_ENV'], $_SERVER['APP_ENV']);
        putenv('YII_ENV');
        putenv('APP_ENV');
        self::assertNull(Env::name());

        $_ENV['APP_ENV'] = 'staging';
        self::assertSame('staging', Env::name());

        $_ENV['YII_ENV'] = 'prod';
        self::assertSame('prod', Env::name(), 'YII_ENV comes first');
        self::assertSame(['YII_ENV', 'APP_ENV'], Env::NAME_VARIABLES);
    }
}
