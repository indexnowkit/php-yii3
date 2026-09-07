<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\IndexNowDefaults;
use IndexNowKit\Yii3\ActiveRecord\IndexNowEvents;
use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\Trait\EventsTrait;

/**
 * Two rules on one record (article page and AMP page) plus the homepage, each classified separately.
 */
#[IndexNowDefaults(when: 'published')]
#[IndexNow(route: 'post/view', params: ['slug' => 'slug'])]
#[IndexNow(route: 'post/amp', params: ['slug' => 'slug'], when: 'amp', name: 'amp')]
#[IndexNow(urls: ['/'])]
#[IndexNowEvents]
final class MultiPost extends ActiveRecord
{
    use EventsTrait;

    public ?int $id = null;
    public string $slug = '';
    public bool $published = true;
    public bool $amp = false;

    public function tableName(): string
    {
        return 'multi_posts';
    }
}
