<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\ActiveRecord;

use IndexNowKit\Hook\ObserverHelper;
use IndexNowKit\Transaction\VerifyingStaging;
use IndexNowKit\Url\ObjectChangeHandler;
use IndexNowKit\Url\ResolvedUrl;
use IndexNowKit\Yii3\Event\ObservedDispatcher;
use IndexNowKit\Yii3\IndexNow;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use SplObjectStorage;
use Throwable;
use WeakMap;
use Yiisoft\ActiveRecord\ActiveRecordInterface;
use Yiisoft\ActiveRecord\Event\AfterDelete;
use Yiisoft\ActiveRecord\Event\AfterInsert;
use Yiisoft\ActiveRecord\Event\AfterUpdate;
use Yiisoft\ActiveRecord\Event\BeforeDelete;
use Yiisoft\ActiveRecord\Event\BeforeUpdate;
use Yiisoft\ActiveRecord\Event\EventDispatcherProvider;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * ActiveRecord hooks. URLs are resolved in the event, while the old state is live (`BeforeUpdate` snapshots the old
 * values, since `AfterUpdate` already carries the new ones; `BeforeDelete` still sees the row and its relations).
 * Outside a transaction they go to the collector right away; inside one they are staged with a verifier and handed
 * over at the end of the request or command ({@see flushStaging()}), after a primary-key re-read confirmed the
 * change landed: yiisoft/db fires no commit or rollback events, the re-read replaces them.
 *
 * Nothing here throws into the application: the core's ObjectChangeHandler logs and yields nothing on a bad rule,
 * and every hand-off is guarded by `Hook\ObserverHelper`. What is Yii3's: the change set from the old-value
 * snapshot, the previous state, the verify-on-commit staging keyed by the connection.
 */
final class IndexNowObserver
{
    private ?ObserverHelper $helper = null;

    /** @var WeakMap<object, array<array-key, mixed>> the old values of a record between BeforeUpdate and AfterUpdate */
    private WeakMap $before;

    /** @var SplObjectStorage<ConnectionInterface, true> connections with staged changes (application-long objects) */
    private SplObjectStorage $connections;

    /** @var array<class-string, true> classes hooked through observe() / active_record.models */
    private array $attached = [];

    public function __construct(
        private readonly IndexNow $indexNow,
        private readonly VerifyingStaging $staging,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->before = new WeakMap();
        $this->connections = new SplObjectStorage();
    }

    /**
     * Hook for a class without `#[IndexNowEvents]` (`active_record.models`, `IndexNow::observe()`): the class's
     * dispatcher of yiisoft/active-record (its attribute handlers included) gets this observer appended.
     *
     * @param class-string<ActiveRecordInterface> $class
     */
    public function attachTo(string $class): void
    {
        if (isset($this->attached[$class])) {
            return;
        }
        $this->attached[$class] = true;
        EventDispatcherProvider::set($class, new ObservedDispatcher(EventDispatcherProvider::get($class), $this));
    }

    /**
     * @param class-string $class
     */
    public function isAttachedTo(string $class): bool
    {
        return isset($this->attached[$class]);
    }

    public function afterInsert(AfterInsert $event): void
    {
        $record = $event->model;
        // columns left null get their database default: only what the record set is compared
        $written = array_filter($record->propertyValues(), static fn(mixed $v): bool => $v !== null);
        $this->guard($record, static fn(ObjectChangeHandler $changes): array => $changes->created($record), fn(): bool => $this->rowMatches($record, $written));
    }

    /** The old values are overwritten by the update: keep them until AfterUpdate. */
    public function beforeUpdate(BeforeUpdate $event): void
    {
        if (!$this->enabled()) {
            return;
        }
        $this->before[$event->model] = $event->model->oldValues();
    }

    public function afterUpdate(AfterUpdate $event): void
    {
        $record = $event->model;
        $snapshot = $this->before[$record] ?? null;
        unset($this->before[$record]);
        if ($snapshot === null || $event->count === 0) {
            return; // no BeforeUpdate seen, or the row was not updated (nothing changed, optimistic lock)
        }
        $changeSet = [];
        $expected = [];
        // after the update the old values are the written ones: a column whose old value moved was written
        foreach ($record->oldValues() as $field => $written) {
            if (\array_key_exists($field, $snapshot) && $snapshot[$field] !== $written) {
                $changeSet[$field] = [$snapshot[$field], $written];
                $expected[$field] = $written;
            }
        }
        if ($changeSet === []) {
            return;
        }
        $this->guard($record, fn(ObjectChangeHandler $changes): array => [
            ...$changes->renamed($record, $changeSet, $this->previousState($record, $changeSet), self::primaryKeyFields($record)),
            ...$changes->updated($record, array_keys($changeSet), $changeSet),
        ], fn(): bool => $this->rowMatches($record, $expected));
    }

    /** Before the row disappears: resolve now, deliver in afterDelete(). */
    public function beforeDelete(BeforeDelete $event): void
    {
        if (!$this->enabled()) {
            return;
        }
        $record = $event->model;
        $urls = $this->helper()->guard($record, static fn(ObjectChangeHandler $changes): array => $changes->deleted($record));
        if ($urls !== null) {
            $this->helper()->rememberDeletion($record, $urls);
        }
    }

    public function afterDelete(AfterDelete $event): void
    {
        if (!$this->enabled()) {
            return;
        }
        $record = $event->model;
        $urls = $this->helper()->takeDeletion($record);
        if ($event->count === 0) {
            return; // the row was not there
        }
        $pk = self::primaryKey($record);
        $verifier = fn(): bool => $this->rowByPrimaryKey($record, $pk) === null;
        if ($urls === null) {
            // beforeDelete() was not seen; the record still carries its property values after the delete
            $this->guard($record, static fn(ObjectChangeHandler $changes): array => $changes->deleted($record), $verifier);

            return;
        }
        $this->handOff($record, $urls, $verifier);
    }

    /**
     * The end of the unit of work: the changes staged on connections whose transaction ended are verified against
     * the database and handed to the collector. A connection whose transaction is still open keeps its changes
     * (a long-running command flushing between batches inside a transaction) and is reported once at warning when
     * it is still open at the end of the request, since the verifier would only see uncommitted data.
     */
    public function flushStaging(): void
    {
        foreach (iterator_to_array($this->connections) as $db) {
            if ($db->getTransaction() !== null) {
                $this->logger->warning('indexnow: {count} staged URL(s) wait for a transaction that is still open on the connection; they are verified and submitted once it ends (a transaction left open at the end of a request is never delivered)', ['count' => $this->staging->pendingCount($db)]);

                continue;
            }
            $this->connections->detach($db);
            $this->deliver($this->staging->flush($db));
        }
    }

    private function enabled(): bool
    {
        return $this->indexNow->activeRecordEnabled();
    }

    private function helper(): ObserverHelper
    {
        if ($this->helper === null) {
            $services = $this->indexNow->services();
            // the hook resolves URLs without building the client; the sink builds the collector on the first URL
            $this->helper = ObserverHelper::forChanges($services->changes(), static function (array $urls) use ($services): void {
                $services->kit()->collect($urls);
            }, $this->logger);
        }

        return $this->helper;
    }

    /**
     * @param callable(ObjectChangeHandler): list<ResolvedUrl> $resolve
     * @param callable(): bool                                 $verifier
     */
    private function guard(ActiveRecordInterface $record, callable $resolve, callable $verifier): void
    {
        if (!$this->enabled()) {
            return;
        }
        $urls = $this->helper()->guard($record, $resolve);
        if ($urls !== null) {
            $this->handOff($record, $urls, $verifier);
        }
    }

    /**
     * Inside a transaction the URLs wait for the end of the request (and their verification); outside they go to
     * the collector now.
     *
     * @param list<string>     $urls
     * @param callable(): bool $verifier
     */
    private function handOff(ActiveRecordInterface $record, array $urls, callable $verifier): void
    {
        if ($urls === []) {
            return;
        }
        try {
            $db = $record->db();
            if ($db->getTransaction() !== null) {
                $this->connections->attach($db, true);
                $this->staging->stage($db, $verifier, $urls, self::describe($record));

                return;
            }
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot inspect the transaction state of {class}: {error}', ['class' => $record::class, 'error' => $e->getMessage(), 'exception' => $e]);
        }
        $this->deliver($urls);
    }

    /**
     * @param list<string> $urls
     */
    private function deliver(array $urls): void
    {
        $this->helper()->deliver($urls);
    }

    /**
     * @param array<string, mixed> $expected
     */
    private function rowMatches(ActiveRecordInterface $record, array $expected): bool
    {
        return VerifyingStaging::rowMatches($this->rowByPrimaryKey($record, self::primaryKey($record)), $expected);
    }

    /**
     * The row as it is in the database now: a plain query on the table, bypassing the record's query (soft delete
     * conditions, default scopes) and the identity of the record.
     *
     * @param array<string, mixed> $pk
     *
     * @return array<string, mixed>|null
     */
    private function rowByPrimaryKey(ActiveRecordInterface $record, array $pk): ?array
    {
        if ($pk === []) {
            throw new RuntimeException(\sprintf('%s has no primary key to verify the change by (verify-on-commit re-reads the row by its primary key). Declare a primary key on the table or override primaryKey(); until then its changes count as landed and are submitted unverified.', $record::class));
        }
        $row = $record->db()->createQuery()->from($record->tableName())->where($pk)->one();

        return \is_array($row) ? $row : null;
    }

    /**
     * A copy of the record as it was before the update (old property values, relations dropped so they reload for
     * the old foreign keys), used to resolve the URLs a renamed page had.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private function previousState(ActiveRecordInterface $record, array $changeSet): ActiveRecordInterface
    {
        $previous = clone $record;
        foreach ($changeSet as $field => [$old]) {
            $previous->set($field, $old);
        }
        foreach (array_keys($record->relatedRecords()) as $relation) {
            $previous->resetRelation((string) $relation);
        }

        return $previous;
    }

    /**
     * Fields a `self` route parameter depends on: Yii has no route model binding, so `self` is the primary key.
     *
     * @return list<string>
     */
    private static function primaryKeyFields(ActiveRecordInterface $record): array
    {
        try {
            return array_values(array_map(strval(...), $record->primaryKey()));
        } catch (Throwable) {
            return [];
        }
    }

    private static function describe(ActiveRecordInterface $record): string
    {
        return $record::class . '#' . implode(',', array_map(static fn(mixed $v): string => \is_scalar($v) ? (string) $v : get_debug_type($v), self::primaryKey($record)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function primaryKey(ActiveRecordInterface $record): array
    {
        try {
            $keys = [];
            foreach ($record->primaryKeyValues() as $column => $value) {
                $keys[(string) $column] = $value;
            }

            return $keys;
        } catch (LogicException) {
            return []; // no primary key declared: rowByPrimaryKey() says what to do
        }
    }
}
