<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii3\ActiveRecord\IndexNowEvents;
use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\Trait\EventsTrait;

/** A table without a primary key: verify-on-commit has nothing to re-read the row by. */
#[IndexNow(route: 'page/view', params: ['slug' => 'name'])]
#[IndexNowEvents]
final class KeylessPage extends ActiveRecord
{
    use EventsTrait;

    public string $name = '';

    public function tableName(): string
    {
        return 'keyless_pages';
    }
}
