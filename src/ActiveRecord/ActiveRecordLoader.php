<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\ActiveRecord;

use IndexNowKit\Console\AbstractSubjectLoader;
use IndexNowKit\Event;
use IndexNowKit\Yii3\IndexNow;
use Yiisoft\ActiveRecord\ActiveRecordInterface;

/**
 * Resolves the class argument of `indexnow:submit-record` and `indexnow:explain` (FQCN or a short name under the
 * namespaces of `active_record.namespaces`, `App\Model` and `App\Entity` by default) and loads records by primary
 * key through the class's own query. Replace the `SubjectLoaderInterface` definition for tenant scoping or another
 * id format. The skeleton is `Console\AbstractSubjectLoader` of `indexnowkit/console`; what is here is
 * yiisoft/active-record: the interface as the marker, `findByPk()` and the batched `query()`.
 */
final class ActiveRecordLoader extends AbstractSubjectLoader
{
    /** Rows read per round trip in {@see all()}; a smaller `--limit` reads that many. */
    public const BATCH_SIZE = 100;

    /**
     * @param list<string> $namespaces namespaces a short class name is looked up in
     */
    public function __construct(array $namespaces = IndexNow::DEFAULT_NAMESPACES)
    {
        parent::__construct($namespaces, ActiveRecordInterface::class, 'an ActiveRecord class');
    }

    protected function findOne(string $class, string $id, Event $event): ?object
    {
        \assert(is_subclass_of($class, ActiveRecordInterface::class));
        $record = $class::query()->findByPk($id);

        return $record instanceof ActiveRecordInterface ? $record : null;
    }

    /**
     * Records of the class up to $limit, read in batches and yielded one by one: `check --sample-class` takes three
     * of them and stops, and a large `--limit` never has the whole result set in memory at once.
     *
     * @return iterable<ActiveRecordInterface>
     */
    protected function findMany(string $class, int $limit, Event $event): iterable
    {
        \assert(is_subclass_of($class, ActiveRecordInterface::class));
        $yielded = 0;
        foreach ($class::query()->limit($limit)->batch(min($limit, self::BATCH_SIZE)) as $batch) {
            foreach ($batch as $record) {
                if (!$record instanceof ActiveRecordInterface) {
                    continue;
                }
                yield $record;
                if (++$yielded >= $limit) {
                    return;
                }
            }
        }
    }
}
