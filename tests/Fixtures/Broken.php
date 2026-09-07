<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii3\ActiveRecord\IndexNowEvents;
use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\Trait\EventsTrait;

/** The rule reads a property the record does not have: the resolver must fail without breaking the save. */
#[IndexNow(route: 'page/view', params: ['slug' => 'missingProperty'])]
#[IndexNowEvents]
final class Broken extends ActiveRecord
{
    use EventsTrait;

    public ?int $id = null;
    public string $name = '';

    public function tableName(): string
    {
        return 'broken';
    }
}
