<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Check;

use Closure;

/**
 * The `--sample` and `--sample-class` values of the running `indexnow:check`, filled by the command before the
 * checker runs (the checks are built with the graph, the options are known at run time), and the sampler that turns
 * a class (and an id) into URLs through the record loader. One instance in the container.
 */
final class SampleOptions
{
    /** @var list<string> */
    public array $urls = [];
    /** @var list<string> */
    public array $classes = [];
    /** @var (Closure(string, string|null): list<string>)|null */
    public ?Closure $sampler = null;

    public function isEmpty(): bool
    {
        return $this->urls === [] && $this->classes === [];
    }
}
