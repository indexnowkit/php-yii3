<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii3\ActiveRecord\IndexNowEvents;
use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\Trait\EventsTrait;

/** #[IndexNow] without route or resolver: reading the rules throws. The save must survive. */
#[IndexNow(events: ['created'])]
#[IndexNowEvents]
final class BadAttribute extends ActiveRecord
{
    use EventsTrait;

    public ?int $id = null;
    public string $name = '';

    public function tableName(): string
    {
        return 'bad_attribute';
    }
}
