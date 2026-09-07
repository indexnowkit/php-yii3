<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii3\ActiveRecord\IndexNowEvents;
use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\Trait\EventsTrait;

#[IndexNow(route: 'post/view', params: ['slug' => 'slug'], when: 'published', fields: ['slug', 'title', 'published'])]
#[IndexNowEvents]
final class Post extends ActiveRecord
{
    use EventsTrait;

    public ?int $id = null;
    public string $slug = '';
    public string $title = 'title';
    public ?string $body = null;
    public bool $published = true;
    public bool $amp = false;
    public int $views = 0;
    public ?int $category_id = null;

    public function tableName(): string
    {
        return 'posts';
    }
}
