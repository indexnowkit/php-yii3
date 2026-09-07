<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\Trait\EventsTrait;

/** No #[IndexNowEvents]: hooked through the `active_record.models` list (the class's dispatcher gets the observer appended). */
#[IndexNow(route: 'page/view', params: ['slug' => 'name'])]
final class ModelPost extends ActiveRecord
{
    use EventsTrait;

    public ?int $id = null;
    public string $name = '';

    public function tableName(): string
    {
        return 'model_posts';
    }
}
