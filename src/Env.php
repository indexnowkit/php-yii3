<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3;

/**
 * Environment variables the way a Yii3 application exposes them: `vlucas/phpdotenv` fills `$_ENV` (and `$_SERVER`),
 * a container or the web server sets the real environment (`getenv()`). The package reads all three, first hit wins,
 * and treats an empty string as unset (an `.env` line `INDEXNOW_KEY=` with nothing after it).
 */
final class Env
{
    /** Where the environment name comes from: `YII_ENV` (yiisoft/app), else `APP_ENV`. */
    public const NAME_VARIABLES = ['YII_ENV', 'APP_ENV'];

    private function __construct() {}

    public static function get(string $name): ?string
    {
        foreach ([$_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $value) {
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }
        $value = getenv($name); // only when the superglobals said nothing: getenv() is a system call

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /** The environment name for `production_environments` (`YII_ENV`, `APP_ENV`); null when none is set. */
    public static function name(): ?string
    {
        foreach (self::NAME_VARIABLES as $variable) {
            $value = self::get($variable);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
