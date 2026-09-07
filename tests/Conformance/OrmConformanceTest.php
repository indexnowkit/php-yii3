<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Conformance;

use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\Conformance\OrmConformanceTestCase;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Yii3\IndexNow;
use IndexNowKit\Yii3\Tests\Fixtures\BadAttribute;
use IndexNowKit\Yii3\Tests\Fixtures\Broken;
use IndexNowKit\Yii3\Tests\Fixtures\CategorizedPost;
use IndexNowKit\Yii3\Tests\Fixtures\Category;
use IndexNowKit\Yii3\Tests\Fixtures\MultiPost;
use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Fixtures\Tag;
use IndexNowKit\Yii3\Tests\Fixtures\Untracked;
use IndexNowKit\Yii3\Tests\Support\Fixtures;
use Yiisoft\ActiveRecord\ActiveRecordInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Transaction\TransactionInterface;
use Yiisoft\Di\Container;

/**
 * The core ORM conformance kit (A01-A21) driven through yiisoft/active-record: #[IndexNowEvents] + verify-on-commit
 * staging for commit safety (yiisoft/db has no transaction events at all), nested `beginTransaction()` through
 * savepoints, the `updated_at` recipe for the junction-table scenario. `flush()` is what the end of a request does.
 */
final class OrmConformanceTest extends OrmConformanceTestCase
{
    private Container $container;
    private FakeTransport $transport;
    private ArrayLogger $logger;

    /** @var list<TransactionInterface> */
    private array $transactions = [];

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->logger = new ArrayLogger();
        $this->container = Fixtures::container($this->transport, $this->logger);
    }

    protected function tearDown(): void
    {
        Fixtures::destroy();
    }

    protected function transport(): FakeTransport
    {
        return $this->transport;
    }

    protected function logger(): ArrayLogger
    {
        return $this->logger;
    }

    protected function flush(): void
    {
        $this->indexNow()->flush();
    }

    protected function collectedCount(): int
    {
        return \count($this->indexNow()->kit()->collector->all());
    }

    protected function begin(): void
    {
        $this->transactions[] = $this->db()->beginTransaction();
    }

    protected function commit(): void
    {
        $transaction = array_pop($this->transactions);
        $transaction?->commit();
    }

    protected function rollback(): void
    {
        $transaction = array_pop($this->transactions);
        $transaction?->rollBack();
    }

    protected function createPost(string $slug, bool $published = true): object
    {
        $post = new Post();
        $post->slug = $slug;
        $post->published = $published;

        return $this->save($post);
    }

    protected function createMultiPost(string $slug, bool $published, bool $amp): object
    {
        $post = new MultiPost();
        $post->slug = $slug;
        $post->published = $published;
        $post->amp = $amp;

        return $this->save($post);
    }

    protected function createCategory(string $slug): object
    {
        $category = new Category();
        $category->slug = $slug;

        return $this->save($category);
    }

    protected function createCategorizedPost(string $slug, ?object $category = null): object
    {
        \assert($category === null || $category instanceof Category);
        $post = new CategorizedPost();
        $post->slug = $slug;
        $post->category_id = $category?->id;
        $post->updated_at = 1;

        return $this->save($post);
    }

    protected function createTag(string $name): object
    {
        $tag = new Tag();
        $tag->name = $name;

        return $this->save($tag);
    }

    protected function createUntracked(): object
    {
        $record = new Untracked();
        $record->name = 'x';

        return $this->save($record);
    }

    protected function createBroken(): object
    {
        $record = new Broken();
        $record->name = 'x';

        return $this->save($record);
    }

    protected function createBadAttribute(): object
    {
        $record = new BadAttribute();
        $record->name = 'x';

        return $this->save($record);
    }

    protected function update(object $model, array $fields): void
    {
        \assert($model instanceof ActiveRecordInterface);
        foreach ($fields as $field => $value) {
            $model->set($field, $value);
        }
        $model->save();
    }

    protected function delete(object $model): void
    {
        \assert($model instanceof ActiveRecordInterface);
        $model->delete();
    }

    /** link() writes the junction row with a plain command; the owner is saved with a bumped timestamp (the documented recipe). */
    protected function attachTag(object $post, object $tag): void
    {
        \assert($post instanceof CategorizedPost && $tag instanceof Tag);
        $post->link('tags', $tag);
        $post->updated_at = ($post->updated_at ?? 0) + 1;
        $post->save();
    }

    protected function bulkUpdateTitle(string $title): void
    {
        (new Post())->updateAll(['title' => $title]);
    }

    private function save(ActiveRecordInterface $record): ActiveRecordInterface
    {
        $record->save();

        return $record;
    }

    private function indexNow(): IndexNow
    {
        $indexNow = $this->container->get(IndexNow::class);
        \assert($indexNow instanceof IndexNow);

        return $indexNow;
    }

    private function db(): ConnectionInterface
    {
        $db = $this->container->get(ConnectionInterface::class);
        \assert($db instanceof ConnectionInterface);

        return $db;
    }
}
