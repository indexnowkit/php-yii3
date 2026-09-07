<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii3\ActiveRecord\IndexNowEvents;
use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\Trait\EventsTrait;

#[IndexNow(route: 'category/view', params: ['slug' => 'slug'])]
#[IndexNowEvents]
final class Category extends ActiveRecord
{
    use EventsTrait;

    public ?int $id = null;
    public string $slug = '';

    public function tableName(): string
    {
        return 'categories';
    }
}
