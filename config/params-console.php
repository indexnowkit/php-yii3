<?php

declare(strict_types=1);

use IndexNowKit\Yii3\Console\CheckCommand;
use IndexNowKit\Yii3\Console\ConfigCommand;
use IndexNowKit\Yii3\Console\ExplainCommand;
use IndexNowKit\Yii3\Console\HistoryCommand;
use IndexNowKit\Yii3\Console\HistoryNotInstalledCommand;
use IndexNowKit\Yii3\Console\KeyGenerateCommand;
use IndexNowKit\Yii3\Console\SitemapCommand;
use IndexNowKit\Yii3\Console\SitemapNotInstalledCommand;
use IndexNowKit\Yii3\Console\StatusCommand;
use IndexNowKit\Yii3\Console\StatusNotInstalledCommand;
use IndexNowKit\Yii3\Console\SubmitCommand;
use IndexNowKit\Yii3\Console\SubmitRecordCommand;

/*
 * The `./yii indexnow:*` commands (yiisoft/yii-console reads `commands`). The commands of the optional packages are
 * registered only when the package is installed; without it a stub with the same name prints the install line and
 * exits 1, so a cron that names the command keeps a readable answer. A `::class` on an absent class is a string, so
 * the two markers below are safe to name without the packages.
 */
$sitemap = class_exists(\IndexNowKit\Sitemap\SitemapReader::class);
$history = class_exists(\IndexNowKit\History\HistoryConfig::class);

return [
    'yiisoft/yii-console' => [
        'commands' => [
            'indexnow:check' => CheckCommand::class,
            'indexnow:config' => ConfigCommand::class,
            'indexnow:submit' => SubmitCommand::class,
            'indexnow:submit-record' => SubmitRecordCommand::class,
            'indexnow:explain' => ExplainCommand::class,
            'indexnow:key:generate' => KeyGenerateCommand::class,
            'indexnow:sitemap' => $sitemap ? SitemapCommand::class : SitemapNotInstalledCommand::class,
            'indexnow:history' => $history ? HistoryCommand::class : HistoryNotInstalledCommand::class,
            'indexnow:status' => $history ? StatusCommand::class : StatusNotInstalledCommand::class,
        ],
    ],
];
