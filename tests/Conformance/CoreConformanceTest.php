<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Conformance;

use IndexNowKit\IndexNowKit;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\Conformance\CoreConformanceTestCase;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Yii3\Tests\Support\Fixtures;
use Yiisoft\Di\Container;

/**
 * The core conformance kit against the facade the container builds from the params block.
 */
final class CoreConformanceTest extends CoreConformanceTestCase
{
    private FakeTransport $transport;
    private Container $container;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->container = Fixtures::container($this->transport, new ArrayLogger());
    }

    protected function tearDown(): void
    {
        Fixtures::destroy();
    }

    protected function kit(): IndexNowKit
    {
        $kit = $this->container->get(IndexNowKit::class);
        \assert($kit instanceof IndexNowKit);

        return $kit;
    }

    protected function transport(): FakeTransport
    {
        return $this->transport;
    }

    protected function secondHost(): ?string
    {
        return 'example.de';
    }
}
