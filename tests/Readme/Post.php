<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Readme;

use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};
use IndexNowKit\Yii3\ActiveRecord\IndexNowEvents;
use Yiisoft\ActiveRecord\ActiveQueryInterface;
use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\Trait\EventsTrait;
use Yiisoft\ActiveRecord\Trait\MagicRelationsTrait;

#[IndexNowDefaults(when: 'published', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(route: 'post/view', params: ['slug' => 'slug'])]
#[IndexNow(route: 'post/amp', params: ['slug' => 'slug'], when: 'amp')]
#[IndexNow(via: 'category')]      // a changed post also refreshes its category page
#[IndexNow(urls: ['/'])]          // and the homepage
#[IndexNowEvents]                 // the hook: yiisoft/active-record dispatches events only with EventsTrait
final class Post extends ActiveRecord
{
    use EventsTrait;
    use MagicRelationsTrait;

    public ?int $id = null;
    public string $slug = '';
    public string $title = '';
    public ?string $body = null;
    public bool $published = true;
    public bool $amp = false;
    public ?int $category_id = null;

    public function tableName(): string
    {
        return 'posts';
    }

    public function getCategoryQuery(): ActiveQueryInterface
    {
        return $this->hasOne(Category::class, ['id' => 'category_id']);
    }
}
