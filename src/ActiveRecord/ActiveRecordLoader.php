<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\ActiveRecord;

use IndexNowKit\Console\ClassNameResolver;
use IndexNowKit\Console\SubjectLoaderInterface;
use IndexNowKit\Event;
use IndexNowKit\Exception\InvalidArgumentException;
use IndexNowKit\Yii3\IndexNow;
use Yiisoft\ActiveRecord\ActiveRecordInterface;

/**
 * Resolves the class argument of `indexnow:submit-record` and `indexnow:explain` (FQCN or a short name under the
 * namespaces of `active_record.namespaces`, `App\Model` and `App\Entity` by default) and loads records by primary
 * key through the class's own query. Replace the `SubjectLoaderInterface` definition for tenant scoping or another
 * id format.
 */
final class ActiveRecordLoader implements SubjectLoaderInterface
{
    private readonly ClassNameResolver $classes;

    /**
     * @param list<string> $namespaces namespaces a short class name is looked up in
     */
    public function __construct(array $namespaces = IndexNow::DEFAULT_NAMESPACES)
    {
        $this->classes = new ClassNameResolver($namespaces, static fn(string $class): bool => is_subclass_of($class, ActiveRecordInterface::class), 'an ActiveRecord class');
    }

    /**
     * @return class-string<ActiveRecordInterface>
     */
    public function resolveClass(string $class): string
    {
        return self::activeRecordClass($this->classes->resolve($class));
    }

    public function byIds(string $class, array $ids, Event $event): array
    {
        $class = self::activeRecordClass($class);
        $found = [];
        $missing = [];
        foreach ($ids as $id) {
            $record = $class::query()->findByPk($id);
            if ($record instanceof ActiveRecordInterface) {
                $found[] = $record;
            } else {
                $missing[] = $id;
            }
        }

        return [$found, $missing];
    }

    public function all(string $class, int $limit, Event $event): iterable
    {
        $class = self::activeRecordClass($class);
        $records = [];
        foreach ($class::query()->limit(max(1, $limit))->all() as $record) {
            if ($record instanceof ActiveRecordInterface) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @param class-string $class
     *
     * @return class-string<ActiveRecordInterface>
     */
    private static function activeRecordClass(string $class): string
    {
        if (!is_subclass_of($class, ActiveRecordInterface::class)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not an ActiveRecord class (it does not implement %s): the command loads records by primary key through ActiveRecord.', $class, ActiveRecordInterface::class));
        }

        return $class;
    }
}
