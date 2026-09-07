<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use DateTimeImmutable;
use IndexNowKit\Yii3\ActiveRecord\IndexNowObserver;
use IndexNowKit\Yii3\Tests\Fixtures\ControlledPost;
use IndexNowKit\Yii3\Tests\Fixtures\KeylessPage;
use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Fixtures\PricedPost;
use IndexNowKit\Yii3\Tests\Support\BreakableConnection;
use IndexNowKit\Yii3\Tests\Support\Fixtures;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Yiisoft\ActiveRecord\Event\AfterInsert;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * What the hooks do when the world around them misbehaves: a graph that cannot be built, a connection that cannot
 * be asked about its transaction, a transaction nobody closes, a class without a primary key. Nothing of it may
 * reach the application: a `save()` succeeds and the request-end listener returns.
 */
final class ObserverResilienceTest extends Yii3TestCase
{
    protected function console(): bool
    {
        return true;
    }

    #[TestDox('a graph that cannot be built (no UrlGeneratorInterface) leaves save() intact and is logged once, not once per save')]
    public function testTheHelperIsBuiltInsideTheGuard(): void
    {
        $container = Fixtures::container($this->transport, $this->logger, [], [
            UrlGeneratorInterface::class => static fn(): UrlGeneratorInterface => throw new RuntimeException('the router is not wired'),
        ], [], 'console');

        foreach (['one', 'two'] as $slug) {
            $post = new Post();
            $post->slug = $slug;
            $post->save();
            self::assertNotNull($post->id, 'the save went through');
        }

        $errors = array_values(array_filter($this->logger->messages('error'), static fn(string $m): bool => str_contains($m, 'the URL graph cannot be built')));
        self::assertCount(1, $errors, 'one line per process, not one per save');
        self::assertStringContainsString('the router is not wired', $errors[0]);
        self::assertSame([], $this->transport->posts);
        self::assertInstanceOf(\IndexNowKit\Yii3\IndexNow::class, $container->get(\IndexNowKit\Yii3\IndexNow::class));
    }

    #[TestDox('a connection that cannot be asked about its transaction stages the URLs on the record instead of delivering them; the flush verifies and decides')]
    public function testAnUnreachableConnectionStagesInsteadOfDelivering(): void
    {
        $db = new BreakableConnection($this->db());
        ControlledPost::$connection = $db;
        $landed = new ControlledPost();
        $landed->name = 'landed';
        $landed->save();
        $this->indexNow()->flush();
        $this->transport->posts = [];

        // the transaction state cannot be read any more: the change may still be rolled back, so it waits
        $db->broken = true;
        $this->indexNow()->observer()->afterInsert(new AfterInsert($landed));

        self::assertSame([], $this->sentUrls(), 'nothing is delivered while the transaction state is unknown');
        $errors = array_values(array_filter($this->logger->messages('error'), static fn(string $m): bool => str_contains($m, 'cannot inspect the transaction state')));
        self::assertCount(1, $errors);
        self::assertStringContainsString('cannot inspect the transaction state of ' . ControlledPost::class, $errors[0]);

        $this->indexNow()->flush();

        self::assertSame(['https://www.example.com/pages/landed'], $this->sentUrls(), 'the row is there: the change is verified at the flush and only then handed over');
    }

    #[TestDox('a change staged that way whose row is gone is dropped at the flush, not announced')]
    public function testAStagedChangeWhoseRowIsGoneIsDropped(): void
    {
        $db = new BreakableConnection($this->db());
        ControlledPost::$connection = $db;
        $record = new ControlledPost();
        $record->id = 4242; // never inserted: no row answers this key
        $record->name = 'never-landed';

        $db->broken = true;
        $this->indexNow()->observer()->afterInsert(new AfterInsert($record));
        $this->indexNow()->flush();

        self::assertSame([], $this->sentUrls());
        self::assertStringContainsString('change not committed', implode("\n", $this->logger->messages('debug')));
    }

    #[TestDox('a connection that throws at the flush is one error line, not an exception out of the request-end listener')]
    public function testAConnectionThatBreaksBeforeTheFlush(): void
    {
        $db = new BreakableConnection($this->db());
        ControlledPost::$connection = $db;

        $tx = $this->db()->beginTransaction();
        $record = new ControlledPost();
        $record->name = 'staged';
        $record->save();
        $tx->commit();

        $db->broken = true;
        $this->indexNow()->flush(); // must not throw

        $errors = array_values(array_filter($this->logger->messages('error'), static fn(string $m): bool => str_contains($m, 'cannot read the transaction state')));
        self::assertCount(1, $errors, $this->logger->messages('error') === [] ? 'nothing was logged' : implode("\n", $this->logger->messages('error')));
        self::assertStringContainsString('the connection is gone', $errors[0]);
        self::assertSame(['https://www.example.com/pages/staged'], $this->sentUrls(), 'the change is submitted rather than lost');
    }

    #[TestDox('a transaction nobody closes is reported at every flush and its staged URLs are dropped after the third report')]
    public function testAnOpenTransactionIsNotCarriedForever(): void
    {
        $this->db()->beginTransaction();
        $post = new Post();
        $post->slug = 'held';
        $post->save();

        for ($i = 0; $i < IndexNowObserver::OPEN_TRANSACTION_REPORTS; ++$i) {
            $this->indexNow()->flush();
        }
        $waiting = array_values(array_filter($this->logger->messages('warning'), static fn(string $m): bool => str_contains($m, 'wait for a transaction that is still open')));
        self::assertCount(IndexNowObserver::OPEN_TRANSACTION_REPORTS, $waiting);
        self::assertStringContainsString('1 staged URL(s) wait for a transaction that is still open', $waiting[0]);
        self::assertStringNotContainsString('never delivered', $waiting[0], 'a worker reaches the next flush; only a request that ends inside the transaction does not');

        $this->indexNow()->flush();

        $dropped = array_values(array_filter($this->logger->messages('warning'), static fn(string $m): bool => str_contains($m, 'dropping')));
        self::assertCount(1, $dropped);
        self::assertStringContainsString('dropping 1 staged URL(s) of a connection whose transaction was still open', $dropped[0]);
        self::assertSame([], $this->sentUrls());

        $this->indexNow()->flush();
        self::assertSame([], $this->sentUrls(), 'the connection is forgotten, nothing is reported again');
        self::assertCount(IndexNowObserver::OPEN_TRANSACTION_REPORTS + 1, $this->logger->messages('warning'));
    }

    #[TestDox('a class without a primary key is warned about once, and its change is still submitted')]
    public function testAClassWithoutAPrimaryKeyIsWarnedAboutOnce(): void
    {
        foreach (['first', 'second'] as $name) {
            $page = new KeylessPage();
            $page->name = $name;
            $page->save();
        }
        $this->indexNow()->flush();

        $warnings = array_values(array_filter($this->logger->messages('warning'), static fn(string $m): bool => str_contains($m, 'has no primary key')));
        self::assertCount(1, $warnings, 'one warning per class, not per save');
        self::assertStringContainsString(KeylessPage::class . ' has no primary key', $warnings[0]);
        self::assertSame(['https://www.example.com/pages/first', 'https://www.example.com/pages/second'], $this->sentUrls());
    }

    #[TestDox('inside a transaction a class without a primary key cannot be verified: the change is submitted with the warning that says why')]
    public function testAClassWithoutAPrimaryKeyInsideATransaction(): void
    {
        $tx = $this->db()->beginTransaction();
        $page = new KeylessPage();
        $page->name = 'unverifiable';
        $page->save();
        $tx->commit();
        $this->indexNow()->flush();

        self::assertSame(['https://www.example.com/pages/unverifiable'], $this->sentUrls(), 'announcing is the safer default');
        $warnings = implode("\n", $this->logger->messages('warning'));
        self::assertStringContainsString('cannot verify a staged change', $warnings);
        // the text of the exception says what actually happens, inside a transaction and outside one
        self::assertStringContainsString('has no primary key to verify the change by', $warnings);
        self::assertStringContainsString('made inside a transaction is submitted unverified, and one made outside a transaction is not verified at all', $warnings);
    }

    #[TestDox('with the hooks off afterInsert() reads nothing off the record: a record that cannot answer does not break the save')]
    public function testDisabledHooksTouchNothing(): void
    {
        $container = Fixtures::container($this->transport, $this->logger, ['active_record' => ['enabled' => false]], [], [], 'console');
        $indexNow = $container->get(\IndexNowKit\Yii3\IndexNow::class);
        \assert($indexNow instanceof \IndexNowKit\Yii3\IndexNow);

        $record = new ControlledPost();
        $record->id = 7;
        $record->name = 'unread';
        ControlledPost::$propertiesUnreadable = true;

        $indexNow->observer()->afterInsert(new AfterInsert($record)); // must not read the properties at all

        self::assertSame([], $this->transport->posts);
        self::assertSame([], $this->logger->messages('error'));
    }

    #[TestDox('a DECIMAL and a datetime the database keeps in its own spelling do not stop the announcement of a new page')]
    public function testAmbiguousColumnsAreNotVerified(): void
    {
        $tx = $this->db()->beginTransaction();
        $priced = new PricedPost();
        $priced->slug = 'priced';
        $priced->price = 19.99;
        $priced->published_at = new DateTimeImmutable('2026-09-07 12:00:00');
        $priced->save();
        // the row now carries the database's own values, not the ones the record wrote: this is what a DECIMAL(10,2)
        // and a timestamptz do on their own on MySQL and PostgreSQL, and what used to drop every insert of the class
        $this->db()->createCommand()->update('priced_posts', ['price' => 19.9, 'published_at' => '2026-09-07T12:00:00+00:00'], ['id' => $priced->id])->execute();
        $tx->commit();
        $this->indexNow()->flush();

        self::assertSame(['https://www.example.com/pages/priced'], $this->sentUrls(), 'neither the decimal nor the date is compared');
    }

    #[TestDox('a column with one text form is still verified: a slug the row does not carry means the change did not land')]
    public function testUnambiguousColumnsAreVerified(): void
    {
        $tx = $this->db()->beginTransaction();
        $other = new PricedPost();
        $other->slug = 'written';
        $other->save();
        $this->db()->createCommand()->update('priced_posts', ['slug' => 'rewritten'], ['id' => $other->id])->execute();
        $tx->commit();
        $this->indexNow()->flush();

        self::assertSame([], $this->sentUrls());
        self::assertStringContainsString('change not committed', implode("\n", $this->logger->messages('debug')));
    }

    #[TestDox('an insert rolled back to a savepoint is not announced under the primary key the next insert took (conformance A05c, per class)')]
    public function testARolledBackInsertDoesNotRideOnItsSuccessor(): void
    {
        $outer = $this->db()->beginTransaction();
        $kept = new PricedPost();
        $kept->slug = 'kept';
        $kept->save();

        $inner = $this->db()->beginTransaction();
        $rolledBack = new PricedPost();
        $rolledBack->slug = 'rolled-back';
        $rolledBack->save();
        $inner->rollBack();

        // the freed primary key goes to the next insert: the two changes are not the same subject and must not merge
        $successor = new PricedPost();
        $successor->slug = 'kept-too';
        $successor->save();
        self::assertSame($rolledBack->id, $successor->id, 'sqlite handed the freed key to the next insert');
        $outer->commit();
        $this->indexNow()->flush();

        self::assertEqualsCanonicalizing([
            'https://www.example.com/pages/kept',
            'https://www.example.com/pages/kept-too',
        ], $this->sentUrls());
    }
}
