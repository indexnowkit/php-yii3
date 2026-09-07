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
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use SplObjectStorage;
use Throwable;
use WeakMap;
use Yiisoft\ActiveRecord\ActiveRecordInterface;
use Yiisoft\ActiveRecord\Event\AfterDelete;
use Yiisoft\ActiveRecord\Event\AfterInsert;
use Yiisoft\ActiveRecord\Event\AfterUpdate;
use Yiisoft\ActiveRecord\Event\AfterUpsert;
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
 * every hand-off is guarded by `Hook\ObserverHelper`, and the helper itself is built inside a try/catch, so a graph
 * that cannot be built (no `UrlGeneratorInterface` in the container) is one error line, not a failing `save()`.
 * What is Yii3's: the change set from the old-value snapshot, the previous state, the verify-on-commit staging
 * keyed by the connection.
 */
final class IndexNowObserver
{
    /**
     * How often one connection whose transaction is still open is reported at the end of a unit of work before its
     * staged URLs are dropped: a worker that never closes the transaction would otherwise carry them (and announce
     * another request's pages) for the life of the process.
     */
    public const OPEN_TRANSACTION_REPORTS = 3;

    /** @var array<class-string, bool> whether the class carries `#[IndexNowEvents]`; one reflection per class per process */
    private static array $carriesAttribute = [];

    private ?ObserverHelper $helper = null;
    /** Whether building the helper failed once: the graph does not become buildable later in the same process. */
    private bool $helperFailed = false;

    /** @var WeakMap<object, array<array-key, mixed>> the old values of a record between BeforeUpdate and AfterUpdate */
    private WeakMap $before;

    /** @var SplObjectStorage<ConnectionInterface, int> connections with staged changes => how often an open transaction was reported */
    private SplObjectStorage $connections;

    /** @var SplObjectStorage<object, true> records staged as their own scope: the connection could not be asked about its transaction */
    private SplObjectStorage $detached;

    /** @var array<class-string, EventDispatcherInterface> classes hooked through observe() / active_record.models => the dispatcher to restore */
    private array $attached = [];

    /** @var array<class-string, true> classes already reported as having no primary key to verify by */
    private array $withoutPrimaryKey = [];

    public function __construct(
        private readonly IndexNow $indexNow,
        private readonly VerifyingStaging $staging,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->before = new WeakMap();
        $this->connections = new SplObjectStorage();
        $this->detached = new SplObjectStorage();
    }

    /**
     * Hook for a class without `#[IndexNowEvents]` (`active_record.models`, `IndexNow::observe()`): the class's
     * dispatcher of yiisoft/active-record (its attribute handlers included) gets this observer appended. A class
     * that already carries the attribute is left alone — its handlers reach the same observer, and appending a
     * second path would resolve, re-read and announce every change of it twice.
     *
     * @param class-string<ActiveRecordInterface> $class
     */
    public function attachTo(string $class): void
    {
        if (isset($this->attached[$class]) || self::carriesEventsAttribute($class)) {
            return;
        }
        $current = EventDispatcherProvider::get($class);
        // a second container in the same process (tests, a per-request container) replaces the observed dispatcher
        // instead of stacking another one on top of it, which would keep the old container alive and do the work twice
        $inner = $current instanceof ObservedDispatcher ? $current->inner() : $current;
        $this->attached[$class] = $inner;
        EventDispatcherProvider::set($class, new ObservedDispatcher($inner, $this));
    }

    /**
     * Undoes {@see attachTo()}: every class this observer wrapped gets its own dispatcher back, so the observer (and
     * the container behind it) is not held by the static provider of yiisoft/active-record. Called by
     * {@see ObserverProvider::reset()}; a class whose dispatcher was replaced by someone else afterwards is left as
     * it is.
     */
    public function detach(): void
    {
        foreach ($this->attached as $class => $inner) {
            $current = EventDispatcherProvider::get($class);
            if ($current instanceof ObservedDispatcher && $current->observes($this)) {
                EventDispatcherProvider::set($class, $inner);
            }
        }
        $this->attached = [];
    }

    /**
     * @param class-string $class
     */
    public function isAttachedTo(string $class): bool
    {
        return isset($this->attached[$class]);
    }

    /**
     * Whether the class carries `#[IndexNowEvents]` and is therefore already hooked through its own attribute
     * handlers ({@see attachTo()} skips it, `indexnow:check` names it). One reflection per class per process.
     *
     * @param class-string $class
     */
    public static function carriesEventsAttribute(string $class): bool
    {
        return self::$carriesAttribute[$class] ??= (new ReflectionClass($class))->getAttributes(IndexNowEvents::class) !== [];
    }

    public function afterInsert(AfterInsert $event): void
    {
        if (!$this->enabled()) {
            return;
        }
        $record = $event->model;
        // columns left null get their database default: only what the record set is compared, and of that only the
        // values with one unambiguous text form (VerifyingStaging::rowMatches() skips floats, dates, arrays and
        // objects, so a DECIMAL or a timestamptz no longer gags the announcement of a new page). The values matter
        // beyond the row's presence because a rolled-back insert frees its primary key and the next insert takes it:
        // existence alone would announce the page that never landed (conformance A05c). An insert is staged under no
        // subject key at all, for the same reason — the key it now holds may belong to another record by the flush.
        $written = array_filter($record->propertyValues(), static fn(mixed $v): bool => $v !== null);
        $this->guard($record, static fn(ObjectChangeHandler $changes): array => $changes->created($record), fn(): bool => $this->rowMatches($record, $written));
    }

    /**
     * `upsert()` inserts or updates and the data layer does not say which, so the change is announced as an update
     * over every property the record carries. A rule limited to `events: [Created]` therefore does not fire on an
     * upsert; one with `events: [Updated]` (the default set) does.
     *
     * The verifier compares the written columns, like the one of an insert, and for the same reason: an upsert may
     * have inserted, and a primary key freed by a rollback to a savepoint is handed to the next insert of the class.
     * No subject key either, for that same reason.
     */
    public function afterUpsert(AfterUpsert $event): void
    {
        if (!$this->enabled()) {
            return;
        }
        $record = $event->model;
        $written = array_filter($record->propertyValues(), static fn(mixed $v): bool => $v !== null);
        $fields = array_values(array_map(strval(...), array_keys($record->propertyValues())));
        $this->guard($record, static fn(ObjectChangeHandler $changes): array => $changes->updated($record, $fields), fn(): bool => $this->rowMatches($record, $written));
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
        // the subject key: a second update of this row in the same transaction merges into this change, so neither
        // loses its URLs when the later one overwrites the values this verifier expects
        $this->guard($record, fn(ObjectChangeHandler $changes): array => [
            ...$changes->renamed($record, $changeSet, $this->previousState($record, $changeSet), self::primaryKeyFields($record)),
            ...$changes->updated($record, array_keys($changeSet), $changeSet),
        ], fn(): bool => $this->rowMatches($record, $expected), self::describe($record));
    }

    /** Before the row disappears: resolve now, deliver in afterDelete(). */
    public function beforeDelete(BeforeDelete $event): void
    {
        if (!$this->enabled()) {
            return;
        }
        $helper = $this->helper();
        if ($helper === null) {
            return;
        }
        $record = $event->model;
        $urls = $helper->guard($record, static fn(ObjectChangeHandler $changes): array => $changes->deleted($record));
        if ($urls !== null) {
            $helper->rememberDeletion($record, $urls);
        }
    }

    public function afterDelete(AfterDelete $event): void
    {
        if (!$this->enabled()) {
            return;
        }
        $helper = $this->helper();
        if ($helper === null) {
            return;
        }
        $record = $event->model;
        $urls = $helper->takeDeletion($record);
        if ($event->count === 0) {
            return; // the row was not there
        }
        $pk = self::primaryKey($record);
        $verifier = fn(): bool => $this->rowByPrimaryKey($record, $pk) === null;
        $key = self::describe($record); // the row this delete was made to: an earlier update of it merges into this
        if ($urls === null) {
            // beforeDelete() was not seen; the record still carries its property values after the delete
            $this->guard($record, static fn(ObjectChangeHandler $changes): array => $changes->deleted($record), $verifier, $key);

            return;
        }
        $this->handOff($record, $urls, $verifier, $key);
    }

    /**
     * The end of the unit of work: the changes staged on connections whose transaction ended are verified against
     * the database and handed to the collector. A connection whose transaction is still open keeps its changes (a
     * long-running command flushing between batches inside a transaction) and is reported at warning, since the
     * verifier would only see uncommitted data; after {@see OPEN_TRANSACTION_REPORTS} such reports they are dropped,
     * so a worker that leaves a transaction open does not carry one request's URLs into the next.
     */
    public function flushStaging(): void
    {
        foreach (iterator_to_array($this->detached) as $scope) {
            $this->detached->offsetUnset($scope);
            $this->flushScope($scope);
        }
        foreach (iterator_to_array($this->connections) as $db) {
            try {
                $open = $db->getTransaction() !== null;
            } catch (Throwable $e) {
                $this->forget($db);
                $this->logger->error('indexnow: cannot read the transaction state of a connection with staged URL(s), submitting them: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
                $this->flushScope($db);

                continue;
            }
            if ($open) {
                $this->reportOpenTransaction($db);

                continue;
            }
            $this->forget($db);
            $this->flushScope($db);
        }
    }

    private function enabled(): bool
    {
        return $this->indexNow->activeRecordEnabled();
    }

    /**
     * The core's hook helper over the graph's change handler, built once. Building it touches the container (the
     * router bridge of `route:` rules), so it happens here inside a try/catch: an application whose graph cannot be
     * built gets one error line and silent hooks instead of an exception out of the first `save()`.
     */
    private function helper(): ?ObserverHelper
    {
        if ($this->helper !== null || $this->helperFailed) {
            return $this->helper;
        }
        try {
            $services = $this->indexNow->services();
            // the hook resolves URLs without building the client; the sink builds the collector on the first URL
            $this->helper = ObserverHelper::forChanges($services->changes(), static function (array $urls) use ($services): void {
                $services->kit()->collect($urls);
            }, $this->logger);
        } catch (Throwable $e) {
            $this->helperFailed = true; // one line per process, not one per save
            $this->logger->error('indexnow: the URL graph cannot be built, ActiveRecord changes are not submitted in this process: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
        }

        return $this->helper;
    }

    /**
     * @param callable(ObjectChangeHandler): list<ResolvedUrl> $resolve
     * @param callable(): bool                                 $verifier
     * @param string|null                                      $key      the subject a later change in the same transaction merges into; null = never merged
     */
    private function guard(ActiveRecordInterface $record, callable $resolve, callable $verifier, ?string $key = null): void
    {
        if (!$this->enabled()) {
            return;
        }
        $urls = $this->helper()?->guard($record, $resolve);
        if ($urls !== null) {
            $this->handOff($record, $urls, $verifier, $key);
        }
    }

    /**
     * Inside a transaction the URLs wait for the end of the request (and their verification); outside they go to
     * the collector now. With a $key a later change of the same subject in the same transaction merges into this
     * one: the URLs are joined (the old URL of a rename stays announced) and the verifier is the latest, which is
     * the only one the row can still answer. That key is `class#primary-key` for an update and a delete, where the
     * key names the row the change was made to; an insert passes none, because a primary key freed by a rollback to
     * a savepoint is handed to the next insert of the class and the two changes are not the same subject at all.
     *
     * @param list<string>     $urls
     * @param callable(): bool $verifier
     */
    private function handOff(ActiveRecordInterface $record, array $urls, callable $verifier, ?string $key = null): void
    {
        if ($urls === []) {
            return;
        }
        $subject = self::describe($record);
        try {
            $db = $record->db();
            if ($db->getTransaction() !== null) {
                if (!$this->connections->offsetExists($db)) {
                    $this->connections->offsetSet($db, 0);
                }
                $this->staging->stage($db, $verifier, $urls, $subject, $key);

                return;
            }
        } catch (Throwable $e) {
            // the very case staging exists for: the transaction may roll back, so the URLs are held on the record
            // itself and the verifier decides at the flush (a verifier that throws there submits with a warning)
            $this->logger->error('indexnow: cannot inspect the transaction state of {class}, the URLs are staged until the end of the unit of work: {error}', ['class' => $record::class, 'error' => $e->getMessage(), 'exception' => $e]);
            $this->detached->offsetSet($record, true);
            $this->staging->stage($record, $verifier, $urls, $subject, $key);

            return;
        }
        $this->warnWithoutPrimaryKey($record);
        $this->deliver($urls);
    }

    /** One scope's staged URLs: verified, delivered, and never an exception out of the request-end listener. */
    private function flushScope(object $scope): void
    {
        try {
            $this->deliver($this->staging->flush($scope));
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot flush the staged URL(s) of {scope}: {error}', ['scope' => get_debug_type($scope), 'error' => $e->getMessage(), 'exception' => $e]);
        }
    }

    private function reportOpenTransaction(ConnectionInterface $db): void
    {
        $reports = ($this->connections->offsetExists($db) ? $this->connections[$db] : 0) + 1;
        $count = $this->staging->pendingCount($db);
        if ($reports > self::OPEN_TRANSACTION_REPORTS) {
            $this->forget($db);
            $this->staging->discard($db);
            $this->logger->warning('indexnow: dropping {count} staged URL(s) of a connection whose transaction was still open at {reports} flushes; commit or roll back before the end of the unit of work', ['count' => $count, 'reports' => self::OPEN_TRANSACTION_REPORTS]);

            return;
        }
        $this->connections[$db] = $reports;
        $this->logger->warning('indexnow: {count} staged URL(s) wait for a transaction that is still open on the connection; they are verified and submitted at the first flush after it ends, and dropped when it is still open after {reports} flushes', ['count' => $count, 'reports' => self::OPEN_TRANSACTION_REPORTS]);
    }

    private function forget(ConnectionInterface $db): void
    {
        $this->connections->offsetUnset($db);
    }

    /**
     * A class whose changes are announced outside a transaction is never re-read, so a missing primary key costs
     * nothing there — but it means verify-on-commit cannot work for it inside one. One warning per class.
     */
    private function warnWithoutPrimaryKey(ActiveRecordInterface $record): void
    {
        $class = $record::class;
        if (isset($this->withoutPrimaryKey[$class]) || self::primaryKey($record) !== []) {
            return;
        }
        $this->withoutPrimaryKey[$class] = true;
        $this->logger->warning('indexnow: {class} has no primary key, so a change of it made inside a transaction cannot be verified against the row and is submitted unverified; declare a primary key on the table or override primaryKey()', ['class' => $class]);
    }

    /**
     * @param list<string> $urls
     */
    private function deliver(array $urls): void
    {
        $this->helper()?->deliver($urls);
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
            throw new RuntimeException(\sprintf('%s has no primary key to verify the change by (verify-on-commit re-reads the row by its primary key). Declare a primary key on the table or override primaryKey(); until then a change of it made inside a transaction is submitted unverified, and one made outside a transaction is not verified at all.', $record::class));
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
