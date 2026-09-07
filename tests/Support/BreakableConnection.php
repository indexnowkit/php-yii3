<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Support;

use Closure;
use RuntimeException;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ServerInfoInterface;
use Yiisoft\Db\Expression\ExpressionInterface;
use Yiisoft\Db\Query\BatchQueryResultInterface;
use Yiisoft\Db\Query\QueryInterface;
use Yiisoft\Db\QueryBuilder\QueryBuilderInterface;
use Yiisoft\Db\Schema\Column\ColumnFactoryInterface;
use Yiisoft\Db\Schema\QuoterInterface;
use Yiisoft\Db\Schema\SchemaInterface;
use Yiisoft\Db\Schema\TableSchemaInterface;
use Yiisoft\Db\Transaction\TransactionInterface;

/**
 * A connection that can be made to fail on {@see getTransaction()}, the one call the observer makes on a staged
 * connection at the end of the unit of work. A pooled connection handed back, a worker whose socket died between
 * two requests: the request-end listener must not carry that exception out of itself.
 *
 * Everything else is the real connection. `Yiisoft\Db\Sqlite\Connection` is final, so this is a decorator and not a
 * subclass.
 */
final class BreakableConnection implements ConnectionInterface
{
    public bool $broken = false;

    public function __construct(private readonly ConnectionInterface $inner) {}

    public function getTransaction(): ?TransactionInterface
    {
        if ($this->broken) {
            throw new RuntimeException('the connection is gone');
        }

        return $this->inner->getTransaction();
    }

    public function beginTransaction(?string $isolationLevel = null): TransactionInterface
    {
        return $this->inner->beginTransaction($isolationLevel);
    }

    public function createBatchQueryResult(QueryInterface $query): BatchQueryResultInterface
    {
        return $this->inner->createBatchQueryResult($query);
    }

    public function createCommand(?string $sql = null, array $params = []): CommandInterface
    {
        return $this->inner->createCommand($sql, $params);
    }

    public function createQuery(): QueryInterface
    {
        return $this->inner->createQuery();
    }

    public function createTransaction(): TransactionInterface
    {
        return $this->inner->createTransaction();
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function getColumnBuilderClass(): string
    {
        return $this->inner->getColumnBuilderClass();
    }

    public function getColumnFactory(): ColumnFactoryInterface
    {
        return $this->inner->getColumnFactory();
    }

    public function getDriverName(): string
    {
        return $this->inner->getDriverName();
    }

    public function getLastInsertId(?string $sequenceName = null): string
    {
        return $this->inner->getLastInsertId($sequenceName);
    }

    public function getQueryBuilder(): QueryBuilderInterface
    {
        return $this->inner->getQueryBuilder();
    }

    public function getQuoter(): QuoterInterface
    {
        return $this->inner->getQuoter();
    }

    public function getSchema(): SchemaInterface
    {
        return $this->inner->getSchema();
    }

    public function getServerInfo(): ServerInfoInterface
    {
        return $this->inner->getServerInfo();
    }

    public function getTablePrefix(): string
    {
        return $this->inner->getTablePrefix();
    }

    public function getTableSchema(string $name, bool $refresh = false): ?TableSchemaInterface
    {
        return $this->inner->getTableSchema($name, $refresh);
    }

    public function isActive(): bool
    {
        return $this->inner->isActive();
    }

    public function isSavepointEnabled(): bool
    {
        return $this->inner->isSavepointEnabled();
    }

    public function open(): void
    {
        $this->inner->open();
    }

    public function quoteValue(mixed $value): mixed
    {
        return $this->inner->quoteValue($value);
    }

    public function setEnableSavepoint(bool $value): void
    {
        $this->inner->setEnableSavepoint($value);
    }

    public function select(array|bool|float|int|string|ExpressionInterface $columns = [], ?string $option = null): QueryInterface
    {
        return $this->inner->select($columns, $option);
    }

    public function setTablePrefix(string $value): void
    {
        $this->inner->setTablePrefix($value);
    }

    public function transaction(Closure $closure, ?string $isolationLevel = null): mixed
    {
        return $this->inner->transaction($closure, $isolationLevel);
    }
}
