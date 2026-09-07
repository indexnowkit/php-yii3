<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii3\ActiveRecord\IndexNowEvents;
use Yiisoft\ActiveRecord\ActiveQueryInterface;
use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\Trait\EventsTrait;
use Yiisoft\ActiveRecord\Trait\MagicRelationsTrait;

/**
 * A post that resubmits its category's page (`via`). Tags live in a junction table: link()/unlink() fire no events
 * on the owner, so the conformance driver bumps `updated_at` afterwards (the documented recipe).
 */
#[IndexNow(route: 'post/view', params: ['slug' => 'slug'])]
#[IndexNow(via: 'category')]
#[IndexNowEvents]
final class CategorizedPost extends ActiveRecord
{
    use EventsTrait;
    use MagicRelationsTrait;

    public ?int $id = null;
    public string $slug = '';
    public int $views = 0;
    public ?int $category_id = null;
    public ?int $updated_at = null;

    public function tableName(): string
    {
        return 'categorized_posts';
    }

    public function getCategoryQuery(): ActiveQueryInterface
    {
        return $this->hasOne(Category::class, ['id' => 'category_id']);
    }

    public function getTagsQuery(): ActiveQueryInterface
    {
        return $this->hasMany(Tag::class, ['id' => 'tag_id'])->viaTable('categorized_post_tags', ['post_id' => 'id']);
    }
}
