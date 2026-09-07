<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Fixtures;

use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\Trait\EventsTrait;

/** No attribute, no rule: `IndexNow::observe()` hooks it at runtime in one test. */
final class Tag extends ActiveRecord
{
    use EventsTrait;

    public ?int $id = null;
    public string $name = '';

    public function tableName(): string
    {
        return 'tags';
    }
}
