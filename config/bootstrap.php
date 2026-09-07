<?php

declare(strict_types=1);

use IndexNowKit\Yii3\ActiveRecord\IndexNowObserver;
use IndexNowKit\Yii3\ActiveRecord\ObserverProvider;
use IndexNowKit\Yii3\IndexNow;
use Psr\Container\ContainerInterface;

/*
 * The #[IndexNowEvents] handlers are created by yiisoft/active-record from the attribute, outside the container:
 * they reach the observer through the static ObserverProvider, set here once per process (the same shape as the
 * ConnectionProvider of yiisoft/db). Classes listed in `active_record.models` are hooked without the attribute.
 *
 * @psalm-var list<callable(ContainerInterface): void>
 */
return [
    static function (ContainerInterface $container): void {
        $observer = $container->get(IndexNowObserver::class);
        assert($observer instanceof IndexNowObserver);
        ObserverProvider::set($observer);

        $indexNow = $container->get(IndexNow::class);
        assert($indexNow instanceof IndexNow);
        foreach ($indexNow->modelClasses() as $class) {
            $indexNow->observe($class);
        }
    },
];
