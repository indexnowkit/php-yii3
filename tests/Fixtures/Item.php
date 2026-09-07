<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii3\ActiveRecord\IndexNowEvents;
use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\Trait\EventsTrait;

/** `self` route parameter: the primary key value. */
#[IndexNow(route: 'item/view', params: ['id' => 'self'])]
#[IndexNowEvents]
final class Item extends ActiveRecord
{
    use EventsTrait;

    public ?int $id = null;
    public string $name = '';

    public function tableName(): string
    {
        return 'items';
    }
}
