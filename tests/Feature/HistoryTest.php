<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\History\Pdo\PdoSubmissionStore;
use IndexNowKit\History\Pdo\Schema;
use IndexNowKit\History\Psr16SubmissionStore;
use IndexNowKit\ResultStatus;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * indexnowkit/history installed with `history.store: pdo` over the container's `ConnectionInterface`: the graph's
 * submission store is the PDO store and a flush is recorded in the table.
 */
final class HistoryTest extends Yii3TestCase
{
    protected function optionOverrides(): array
    {
        return ['history' => ['store' => 'pdo']];
    }

    #[TestDox('a sync flush is recorded in the pdo store of the container connection')]
    public function testFlushIsRecorded(): void
    {
        foreach (Schema::sql('sqlite') as $sql) {
            $this->db()->createCommand($sql)->execute();
        }
        $indexNow = $this->indexNow();
        self::assertTrue($indexNow->historyEnabled());
        $store = $indexNow->services()->submissionStore();
        self::assertInstanceOf(PdoSubmissionStore::class, $store);
        self::assertSame($store, $this->container->get(SubmissionStoreInterface::class), 'the graph and the container agree');

        $post = new Post();
        $post->slug = 'recorded';
        $post->save();
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/recorded'], $this->sentUrls());
        self::assertSame(1, $store->count());
        $records = [...$store->recent(5, null, ResultStatus::Ok)];
        self::assertCount(1, $records);
        self::assertSame(['https://www.example.com/posts/recorded'], $records[0]->urls);
        self::assertSame('api', $records[0]->result->engine);
    }

    #[TestDox('without the table the flush still sends; the store failure is logged, not thrown')]
    public function testMissingTableDoesNotBreakTheFlush(): void
    {
        $post = new Post();
        $post->slug = 'untabled';
        $post->save();
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/untabled'], $this->sentUrls());
        self::assertNotSame([], $this->logger->messages('error'));
    }

    #[TestDox('history.store psr16 records in the PSR-16 cache of the container (debounce.store memory: the default cache)')]
    public function testPsr16Store(): void
    {
        $container = \IndexNowKit\Yii3\Tests\Support\Fixtures::container($this->transport, $this->logger, ['history' => ['store' => 'psr16']]);
        $store = $container->get(SubmissionStoreInterface::class);
        self::assertInstanceOf(Psr16SubmissionStore::class, $store);
        $indexNow = $container->get(\IndexNowKit\Yii3\IndexNow::class);
        \assert($indexNow instanceof \IndexNowKit\Yii3\IndexNow);
        $indexNow->submit(['/one']);
        self::assertSame(1, $store->count());
    }
}
