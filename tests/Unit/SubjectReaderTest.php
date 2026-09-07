<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Unit;

use IndexNowKit\Yii3\ActiveRecord\ActiveRecordSubjectReader;
use IndexNowKit\Yii3\Tests\Fixtures\CategorizedPost;
use IndexNowKit\Yii3\Tests\Fixtures\Category;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use stdClass;

final class SubjectReaderTest extends Yii3TestCase
{
    #[TestDox('properties and relations are claimed and read; methods and unknown names are left to the core DSL')]
    public function testReader(): void
    {
        $reader = new ActiveRecordSubjectReader();
        $category = new Category();
        $category->slug = 'news';
        $category->save();
        $post = new CategorizedPost();
        $post->slug = 'p';
        $post->category_id = $category->id;
        $post->save();

        self::assertTrue($reader->supports($post));
        self::assertFalse($reader->supports(new stdClass()));
        self::assertTrue($reader->has($post, 'slug'));
        self::assertSame('p', $reader->read($post, 'slug'));
        self::assertTrue($reader->has($post, 'category'), 'a relation declared by getCategoryQuery()');
        $related = $reader->read($post, 'category');
        self::assertInstanceOf(Category::class, $related);
        self::assertSame('news', $related->slug);
        self::assertFalse($reader->has($post, 'nope'));
        self::assertFalse($reader->has($post, 'tableName'), 'methods stay with the core DSL');
    }
}
