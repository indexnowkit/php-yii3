<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * The application's PSR-3 logger with the `category` context key every line of the package carries
 * (`logging.category`, `indexnow` by default): what yiisoft/log targets filter on, and what an operator greps.
 * A line that already names a category keeps it.
 */
final class CategoryLogger extends AbstractLogger
{
    public function __construct(private readonly LoggerInterface $inner, private readonly string $category = 'indexnow') {}

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, $context + ['category' => $this->category]);
    }

    public function category(): string
    {
        return $this->category;
    }
}
