<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Fixtures;

use DateTimeImmutable;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii3\ActiveRecord\IndexNowEvents;
use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\Trait\EventsTrait;

/**
 * A record with two columns whose text form the database decides: a `DECIMAL(10,2)` price (the driver hands back
 * `19.90` for the `19.9` the application wrote) and a `datetime` (a zone suffix, another separator, another
 * precision). Both are what verify-on-commit must not compare, or one such column would gag the announcement of
 * every new page of the class.
 */
#[IndexNow(route: 'page/view', params: ['slug' => 'slug'])]
#[IndexNowEvents]
final class PricedPost extends ActiveRecord
{
    use EventsTrait;

    public ?int $id = null;
    public string $slug = '';
    public float $price = 0.0;
    public ?DateTimeImmutable $published_at = null;

    public function tableName(): string
    {
        return 'priced_posts';
    }
}
