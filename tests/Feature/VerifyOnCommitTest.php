<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Yiisoft\Yii\Console\Event\ApplicationShutdown;

/**
 * yiisoft/db fires no commit or rollback events: changes made inside a transaction are re-read by primary key when
 * the request or the command ends, and dropped when the row does not show them.
 */
final class VerifyOnCommitTest extends Yii3TestCase
{
    protected function console(): bool
    {
        return true;
    }

    #[TestDox('an update inside a savepoint that rolls back is dropped at the flush after the outer commit (the row still has the old title)')]
    public function testRolledBackUpdateIsNotSubmitted(): void
    {
        $post = $this->post('stable', 'v1');
        $this->transport->posts = [];

        $outer = $this->db()->beginTransaction();
        $inner = $this->db()->beginTransaction();
        $post->title = 'v2';
        $post->save();
        $inner->rollBack();
        self::assertSame([], $this->transport->posts);
        $outer->commit();
        $this->indexNow()->flush();

        self::assertSame([], $this->sentUrls(), 'the re-read row carries v1, the change did not land');
        self::assertContains('indexnow: discarding 1 staged URL(s) of ' . Post::class . '#' . $post->id . ', change not committed', $this->logger->messages('debug'));
    }

    #[TestDox('a rename inside a rolled-back savepoint announces nothing: the old page still exists')]
    public function testRolledBackRenameDoesNotDeleteTheOldUrl(): void
    {
        $post = $this->post('before');
        $this->transport->posts = [];

        $outer = $this->db()->beginTransaction();
        $inner = $this->db()->beginTransaction();
        $post->slug = 'after';
        $post->save();
        $inner->rollBack();
        $outer->commit();
        $this->indexNow()->flush();

        self::assertSame([], $this->sentUrls());
    }

    #[TestDox('a committed update inside a transaction is delivered at the end of the command (ApplicationShutdown), verified against the row')]
    public function testCommittedUpdateIsSubmittedAtShutdown(): void
    {
        $post = $this->post('live');
        $this->transport->posts = [];

        $tx = $this->db()->beginTransaction();
        $post->title = 'changed';
        $post->save();
        $tx->commit();
        self::assertSame([], $this->sentUrls(), 'nothing leaves before the end of the unit of work');
        $this->events()->dispatch(new ApplicationShutdown(0));

        self::assertSame(['https://www.example.com/posts/live'], $this->sentUrls());
    }

    #[TestDox('a rollback of the outer transaction drops the staged create: the re-read finds no row')]
    public function testRollbackDiscards(): void
    {
        $tx = $this->db()->beginTransaction();
        $this->post('gone');
        $tx->rollBack();
        $this->indexNow()->flush();

        self::assertSame([], $this->sentUrls());
        $discards = array_values(array_filter($this->logger->messages('debug'), static fn(string $m): bool => str_contains($m, 'discarding')));
        self::assertCount(1, $discards, 'one discard line');
        self::assertStringContainsString('change not committed', $discards[0]);
    }

    #[TestDox('a flush while the transaction is still open keeps the staged URLs (the verifier would read uncommitted data) and warns; they leave after the commit')]
    public function testOpenTransactionKeepsTheStagedUrls(): void
    {
        $tx = $this->db()->beginTransaction();
        $this->post('held');
        $this->indexNow()->flush();

        self::assertSame([], $this->sentUrls(), 'nothing leaves while the transaction is open');
        self::assertCount(1, $this->logger->messages('warning'));
        self::assertStringContainsString('1 staged URL(s) wait for a transaction that is still open', $this->logger->messages('warning')[0]);

        $tx->commit();
        $this->indexNow()->flush();
        self::assertSame(['https://www.example.com/posts/held'], $this->sentUrls());
    }

    #[TestDox('a delete inside a rolled-back transaction is not announced: the row is still there')]
    public function testRolledBackDeleteIsNotSubmitted(): void
    {
        $post = $this->post('kept');
        $this->transport->posts = [];

        $tx = $this->db()->beginTransaction();
        $post->delete();
        $tx->rollBack();
        $this->indexNow()->flush();

        self::assertSame([], $this->sentUrls());
    }

    #[TestDox('two saves of one record in one transaction keep both changes: the URLs are joined and the last verifier decides')]
    public function testTwoChangesOfOneRecordInOneTransactionAreMerged(): void
    {
        $post = $this->post('one');
        $this->transport->posts = [];

        $tx = $this->db()->beginTransaction();
        $post->slug = 'two';
        $post->save();
        $post->slug = 'three';
        $post->save();
        $tx->commit();
        $this->indexNow()->flush();

        // the first change expected slug "two", which the second overwrote: without merging by subject its URLs
        // (the new page and the deleted "one") would be dropped as "not committed"
        self::assertEqualsCanonicalizing([
            'https://www.example.com/posts/one',
            'https://www.example.com/posts/two',
            'https://www.example.com/posts/three',
        ], $this->sentUrls());
        self::assertStringNotContainsString('change not committed', implode("\n", $this->logger->messages('debug')));
    }

    private function post(string $slug, string $title = 'title'): Post
    {
        $post = new Post();
        $post->slug = $slug;
        $post->title = $title;
        $post->save();
        $this->kit()->flush();

        return $post;
    }
}
