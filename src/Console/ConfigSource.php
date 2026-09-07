<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Console;

use IndexNowKit\Config;
use IndexNowKit\Console\ConfigSourceInterface;
use IndexNowKit\Yii3\IndexNow;

/**
 * What `./yii indexnow:check` and `indexnow:config` read in a Yii3 application: the `indexnowkit/yii3` params block
 * as given, its strict build through the facade ({@see IndexNow::buildConfig()}), and the blocks of the installed
 * optional packages. The one place the package hands its configuration to the commands of `indexnowkit/console`
 * (wave L, spec 18); `config/di-console.php` binds it to `Console\ConfigSourceInterface`.
 */
final class ConfigSource implements ConfigSourceInterface
{
    public function __construct(private readonly IndexNow $indexNow) {}

    public function raw(): array
    {
        return $this->indexNow->options();
    }

    public function build(): Config
    {
        return $this->indexNow->buildConfig();
    }

    public function packages(): array
    {
        return [
            ...$this->indexNow->verifyInstalled() ? ['verify' => $this->indexNow->verifyConfig()->toArray()] : [],
            ...$this->indexNow->historyInstalled() ? ['history' => $this->indexNow->historyConfig()->toArray()] : [],
        ];
    }
}
