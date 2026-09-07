<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\History\Pdo\PdoSubmissionStore;
use IndexNowKit\History\Pdo\Schema;
use IndexNowKit\History\Psr16SubmissionStore;
use IndexNowKit\ResultStatus;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\Yii3\IndexNow;
use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Support\Fixtures;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use IndexNowKit\Yii3\Wiring;
use PDO;
use PHPUnit\Framework\Attributes\TestDox;
use stdClass;
use Yiisoft\Di\Container;

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
        $container = Fixtures::container($this->transport, $this->logger, ['history' => ['store' => 'psr16']]);
        $store = $container->get(SubmissionStoreInterface::class);
        self::assertInstanceOf(Psr16SubmissionStore::class, $store);
        $indexNow = $container->get(IndexNow::class);
        \assert($indexNow instanceof IndexNow);
        $indexNow->submit(['/one']);
        self::assertSame(1, $store->count());
    }

    #[TestDox('history.pdo.dsn opens its own connection: the records land in that database, not in the application\'s')]
    public function testPdoDsn(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'indexnowkit-history-') . '.sqlite';
        $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (Schema::sql('sqlite') as $sql) {
            $pdo->exec($sql);
        }

        try {
            $container = Fixtures::container($this->transport, $this->logger, ['history' => ['store' => 'pdo', 'pdo' => ['dsn' => 'sqlite:' . $file]]]);
            $store = $container->get(SubmissionStoreInterface::class);
            self::assertInstanceOf(PdoSubmissionStore::class, $store);
            $indexNow = $container->get(IndexNow::class);
            \assert($indexNow instanceof IndexNow);

            $indexNow->submit(['/from-the-dsn']);

            self::assertSame(1, $store->count());
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM indexnow_submissions')->fetchColumn(), 'the rows are in the DSN database');
        } finally {
            unset($pdo);
            @unlink($file);
        }
    }

    #[TestDox('history.pdo.service naming a connection that is not PDO-backed is a configuration error naming the id')]
    public function testPdoServiceIsNotAConnection(): void
    {
        $container = Fixtures::container($this->transport, $this->logger, ['history' => ['store' => 'pdo', 'pdo' => ['service' => 'not.a.connection']]], ['not.a.connection' => new stdClass()]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/history\.pdo\.service "not\.a\.connection" does not give a PDO connection: .*not a PDO-backed yiisoft\/db connection/');
        self::wiring($container)->submissionStore();
    }

    #[TestDox('history.pdo.service naming an id the container does not know is the same error, naming the id')]
    public function testPdoServiceIsUnknown(): void
    {
        $container = Fixtures::container($this->transport, $this->logger, ['history' => ['store' => 'pdo', 'pdo' => ['service' => 'app.nowhere']]]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/history\.pdo\.service "app\.nowhere" does not give a PDO connection/');
        self::wiring($container)->submissionStore();
    }

    #[TestDox('history.store psr16 over a debounce.store that is not a PSR-16 cache is a configuration error naming the id')]
    public function testPsr16StoreIsNotACache(): void
    {
        $container = Fixtures::container($this->transport, $this->logger, [
            'history' => ['store' => 'psr16'],
            'debounce' => ['per_url' => 600, 'store' => 'not.a.cache'],
        ], ['not.a.cache' => new stdClass()]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/history\.store "psr16" needs a PSR-16 cache under "not\.a\.cache": .*not a Psr\\\\SimpleCache\\\\CacheInterface/');
        self::wiring($container)->submissionStore();
    }

    /** The node built by the package's own factory: the container wraps every exception of a definition in a BuildingException. */
    private static function wiring(Container $container): Wiring
    {
        $wiring = $container->get(Wiring::class);
        \assert($wiring instanceof Wiring);

        return $wiring;
    }
}
