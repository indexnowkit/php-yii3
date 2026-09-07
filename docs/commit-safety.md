# Commit safety

URLs must not leave before the transaction that produced them commits, or a rolled-back write is announced to the
engines. `yiisoft/db` gives no signal at all — no commit event, no rollback event, and its `Command`, `Transaction`
and `Connection` are `final`, so nothing can be intercepted. The package therefore **verifies instead of listening**:
the core's `Transaction\VerifyingStaging`, the same mechanism `indexnowkit/yii2` uses for savepoints.

## What happens on a save

1. The `#[IndexNowEvents]` handler runs inside the ActiveRecord event (`AfterInsert`, `AfterUpdate`, `AfterDelete`;
   `BeforeUpdate` and `BeforeDelete` keep the old state) and resolves the URLs while the old values are live.
2. `ConnectionInterface::getTransaction()` says whether a transaction is open.
   - **No transaction**: autocommit already happened, the URLs go to the request collector.
   - **A transaction**: the URLs are staged on the connection together with a *verifier*, a closure that re-reads
     the row by primary key and answers whether the change landed (created / updated / renamed: the row exists and
     the written columns carry the written values; deleted: no row).
3. At the end of the unit of work — `AfterEmit` of yiisoft/yii-http after the response was sent, `ApplicationShutdown`
   of yiisoft/yii-console when the command ends, or an explicit `$indexNow->flush()` — the verifiers run and the URLs
   of the changes that landed go to the collector, which is then drained into the dispatcher. A change that did not
   land drops **every** URL it produced, including `via` pages and the old URL of a renamed page: announcing a page
   as deleted when it still exists is the one outcome to avoid. The discard is logged at `debug`.

One `SELECT ... WHERE pk = ?` per staged record, only for changes inside explicit transactions.

## Nested transactions

`beginTransaction()` inside a transaction is a savepoint in yiisoft/db. The re-read handles every combination
without a hook: inner rollback + outer commit drops the inner changes and keeps the outer ones (A05c), inner commit +
outer rollback drops everything (A05), both commit delivers everything in one batch (A05b).

## A transaction still open at the end

If a transaction is still open when the flush runs (a request that forgot to commit, a long command flushing between
batches inside a transaction), the staged URLs are **kept**, not verified: the verifier reads through the same
connection and would see uncommitted data. The package logs one `warning` naming the count. They leave at the next
flush after the transaction ended; at the end of a request they are lost with the connection — close the transaction.

## The compromise

An update inside a rolled-back transaction whose written values happen to equal the row's current values
(`UPDATE ... SET title = title`) passes the verification and is submitted as an update of an existing page:
harmless, one extra crawl. A verifier that throws (no primary key declared, a connection error) counts as landed and
is logged at `warning`: a stale URL costs one crawl, a lost one costs the update.

## Records without a primary key

The verifier needs a primary key. A record whose table declares none submits unverified with a `warning` naming the
class; declare a primary key or override `primaryKey()`.
