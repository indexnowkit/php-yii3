<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Check;

use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * The probe of the core's `Check\DebounceStoreCheck` for Yii3: the container id `debounce.store` names must exist,
 * be a PSR-16 cache and answer a write.
 */
final class CacheProbe
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function __invoke(string $store): string
    {
        if (!$this->container->has($store)) {
            throw new RuntimeException(\sprintf('container definition "%s" does not exist', $store));
        }
        try {
            $cache = $this->container->get($store);
        } catch (Throwable $e) {
            throw new RuntimeException(\sprintf('container definition "%s" cannot be built: %s', $store, $e->getMessage()), 0, $e);
        }
        if (!$cache instanceof CacheInterface) {
            throw new RuntimeException(\sprintf('container definition "%s" is a %s, not a Psr\SimpleCache\CacheInterface', $store, get_debug_type($cache)));
        }
        $cache->set('indexnowkit:check', 1, 5);

        return \sprintf('cache "%s" (%s)', $store, (new ReflectionClass($cache))->getShortName());
    }
}
