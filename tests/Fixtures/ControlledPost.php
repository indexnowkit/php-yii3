<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii3\ActiveRecord\IndexNowEvents;
use RuntimeException;
use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\Trait\EventsTrait;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * A record whose connection and property reading can be broken on purpose: what a pooled connection handed back, a
 * worker whose socket died, or a data layer that refuses to answer looks like from inside a hook. The switches are
 * static because a public property of an ActiveRecord is a column; {@see reset()} puts them back.
 */
#[IndexNow(route: 'page/view', params: ['slug' => 'name'])]
#[IndexNowEvents]
final class ControlledPost extends ActiveRecord
{
    use EventsTrait;

    /** propertyValues() throws instead of answering. */
    public static bool $propertiesUnreadable = false;

    /** The connection db() answers with; null = the one of the ConnectionProvider. */
    public static ?ConnectionInterface $connection = null;

    public ?int $id = null;
    public string $name = '';

    public static function reset(): void
    {
        self::$propertiesUnreadable = false;
        self::$connection = null;
    }

    public function tableName(): string
    {
        return 'controlled_posts';
    }

    public function db(): ConnectionInterface
    {
        return self::$connection ?? parent::db();
    }

    /**
     * @param list<string>|null $names
     * @param list<string>      $except
     *
     * @return array<string, mixed>
     */
    public function propertyValues(?array $names = null, array $except = []): array
    {
        if (self::$propertiesUnreadable) {
            throw new RuntimeException('the properties of ' . self::class . ' cannot be read');
        }

        return parent::propertyValues($names, $except);
    }
}
