<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Event;

use IndexNowKit\Yii3\ActiveRecord\IndexNowObserver;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use Yiisoft\ActiveRecord\Event\AfterDelete;
use Yiisoft\ActiveRecord\Event\AfterInsert;
use Yiisoft\ActiveRecord\Event\AfterUpdate;
use Yiisoft\ActiveRecord\Event\BeforeDelete;
use Yiisoft\ActiveRecord\Event\BeforeUpdate;

/**
 * The event dispatcher of an ActiveRecord class that carries no `#[IndexNowEvents]`, with the observer appended
 * (`IndexNow::observe()`, `active_record.models`): the class's own dispatcher of yiisoft/active-record runs first
 * (its attribute handlers, `#[SoftDelete]` included), then the five events reach the observer unless a handler
 * stopped the propagation.
 */
final class ObservedDispatcher implements EventDispatcherInterface
{
    public function __construct(private readonly EventDispatcherInterface $inner, private readonly IndexNowObserver $observer) {}

    public function dispatch(object $event): object
    {
        $event = $this->inner->dispatch($event);
        if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
            return $event;
        }
        match (true) {
            $event instanceof AfterInsert => $this->observer->afterInsert($event),
            $event instanceof BeforeUpdate => $this->observer->beforeUpdate($event),
            $event instanceof AfterUpdate => $this->observer->afterUpdate($event),
            $event instanceof BeforeDelete => $this->observer->beforeDelete($event),
            $event instanceof AfterDelete => $this->observer->afterDelete($event),
            default => null,
        };

        return $event;
    }
}
