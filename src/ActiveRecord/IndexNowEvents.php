<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\ActiveRecord;

use Attribute;
use Yiisoft\ActiveRecord\Event\AfterDelete;
use Yiisoft\ActiveRecord\Event\AfterInsert;
use Yiisoft\ActiveRecord\Event\AfterUpdate;
use Yiisoft\ActiveRecord\Event\BeforeDelete;
use Yiisoft\ActiveRecord\Event\BeforeUpdate;
use Yiisoft\ActiveRecord\Event\Handler\AttributeHandlerProvider;

/**
 * Hooks an ActiveRecord class into IndexNow: changes of the record are submitted according to its #[IndexNow]
 * rules, after the surrounding transaction was verified as committed.
 *
 *   #[IndexNow(route: 'post/view', params: ['slug' => 'slug'], when: 'published')]
 *   #[IndexNowEvents]
 *   final class Post extends ActiveRecord
 *   {
 *       use EventsTrait;   // required: without it yiisoft/active-record dispatches no events
 *   }
 *
 * The idiom of yiisoft/active-record (`#[SoftDelete]` works the same way): the attribute provides the handlers of the
 * five events, and they forward to the one observer of the application ({@see ObserverProvider}, set by the
 * package's bootstrap). Nothing here throws into the save.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class IndexNowEvents extends AttributeHandlerProvider
{
    public function getEventHandlers(): array
    {
        return [
            AfterInsert::class => static function (AfterInsert $event): void {
                ObserverProvider::get()?->afterInsert($event);
            },
            BeforeUpdate::class => static function (BeforeUpdate $event): void {
                ObserverProvider::get()?->beforeUpdate($event);
            },
            AfterUpdate::class => static function (AfterUpdate $event): void {
                ObserverProvider::get()?->afterUpdate($event);
            },
            BeforeDelete::class => static function (BeforeDelete $event): void {
                ObserverProvider::get()?->beforeDelete($event);
            },
            AfterDelete::class => static function (AfterDelete $event): void {
                ObserverProvider::get()?->afterDelete($event);
            },
        ];
    }
}
