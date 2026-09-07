<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\ActiveRecord;

use IndexNowKit\Attribute\SubjectReaderInterface;
use Throwable;
use Yiisoft\ActiveRecord\ActiveRecordInterface;

/**
 * Reads #[IndexNow] accessors off ActiveRecord instances, whose properties are columns behind `get()` and whose
 * relations are queries behind `relation()`. Claims an accessor when it is a property of the record or a relation
 * (populated, or declared through a `get<Name>Query()` method); everything else (a helper such as `isPublished()`,
 * a plain PHP property) stays with the core DSL, so a typo still raises the core's "no property, getter or method"
 * error instead of a silent null.
 */
final class ActiveRecordSubjectReader implements SubjectReaderInterface
{
    public function supports(object $subject): bool
    {
        return $subject instanceof ActiveRecordInterface;
    }

    public function has(object $subject, string $accessor): bool
    {
        if (!$subject instanceof ActiveRecordInterface) {
            return false;
        }
        if ($subject->hasProperty($accessor) || $subject->isRelationPopulated($accessor)) {
            return true;
        }
        try {
            $subject->relationQuery($accessor);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function read(object $subject, string $accessor): mixed
    {
        \assert($subject instanceof ActiveRecordInterface);
        if ($subject->hasProperty($accessor)) {
            return $subject->get($accessor);
        }

        // a relation: populated, or loaded now through its query
        return $subject->relation($accessor);
    }
}
