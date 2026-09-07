<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Unit;

use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Yii3\Config\ConfigFactory;
use IndexNowKit\Yii3\Http\KeyFileHandler;
use IndexNowKit\Yii3\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class ConfigFactoryTest extends TestCase
{
    private const KEY = 'abcdef1234567890abcdef1234567890';

    #[TestDox('the Yii3 blocks are owned, typos inside them are warned about, the defaults survive a replaced block, dispatch is sync or none')]
    public function testBlocksAndDefaults(): void
    {
        $logger = new ArrayLogger();
        $config = ConfigFactory::create(['key' => self::KEY, 'key_file' => ['enabld' => true], 'router' => ['locales' => ['en']], 'active_record' => ['enabled' => false], 'checks' => ['a']], 'prod', $this->absent('sitemap'), $this->absent('verify'), $this->absent('history'), $logger);

        self::assertTrue($config->enabled);
        self::assertSame('sync', $config->dispatch);
        self::assertSame(['indexnow: unknown option(s) in the indexnow configuration: key_file.enabld'], $logger->messages('warning'));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/"dispatch" must be one of sync, none/');
        ConfigFactory::build(['key' => self::KEY, 'dispatch' => 'queue'], 'prod', $this->absent('sitemap'), $this->absent('verify'), $this->absent('history'));
    }

    #[TestDox('the shipped params.php validates as is and the blocks of the optional packages are ignored without them')]
    public function testShippedParams(): void
    {
        $shipped = Fixtures::params()['indexnowkit/yii3'];
        \assert(\is_array($shipped));
        $factory = ConfigFactory::factory(['key' => self::KEY, 'sitemap' => ['spol' => 'x']] + $shipped, $this->absent('sitemap'), $this->absent('verify'), $this->absent('history'));

        self::assertSame([], $factory->unknownOptions(['key' => self::KEY, 'sitemap' => ['spol' => 'x']] + $shipped));
        self::assertSame(KeyFileHandler::DEFAULT_PATTERN, $shipped['key_file']['pattern']);
        $config = $factory->build(['key' => self::KEY] + $shipped, 'prod');
        self::assertSame('sync', $config->dispatch);
        self::assertSame('Psr\SimpleCache\CacheInterface', $config->debounceStore);
    }

    #[TestDox('an invalid runtime value is one critical line and a disabled Config, never an exception')]
    public function testInvalidValueDisables(): void
    {
        $logger = new ArrayLogger();
        $config = ConfigFactory::create(['key' => 'short'], 'prod', $this->absent('sitemap'), $this->absent('verify'), $this->absent('history'), $logger);

        self::assertFalse($config->enabled);
        self::assertTrue($config->dryRun);
        self::assertCount(1, $logger->messages('critical'));
        self::assertStringContainsString('./yii indexnow:check', $logger->messages('critical')[0]);
    }

    private function absent(string $feature): OptionalPackage
    {
        return new OptionalPackage('indexnowkit/' . $feature, 'Nope\\Absent', $feature, false);
    }
}
