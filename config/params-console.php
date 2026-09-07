<?php

declare(strict_types=1);

use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Console\Command\CheckCommand;
use IndexNowKit\Console\Command\ConfigCommand;
use IndexNowKit\Console\Command\ExplainCommand;
use IndexNowKit\Console\Command\HistoryNotInstalledCommand;
use IndexNowKit\Console\Command\KeyGenerateCommand;
use IndexNowKit\Console\Command\SitemapNotInstalledCommand;
use IndexNowKit\Console\Command\StatusNotInstalledCommand;
use IndexNowKit\Console\Command\SubmitCommand;
use IndexNowKit\Console\Command\SubmitSubjectsCommand;
use IndexNowKit\History\Console\HistoryCommand;
use IndexNowKit\History\Console\StatusCommand;
use IndexNowKit\Sitemap\Console\SitemapCommand;

/*
 * The `./yii indexnow:*` commands (yiisoft/yii-console reads `commands`): the classes of indexnowkit/console,
 * indexnowkit/sitemap and indexnowkit/history, the same ones the Symfony bundle registers (wave L, spec 18); what
 * varies — the runners, the words, the configuration source, the env file — is wired in config/di-console.php. The
 * map needs a class per name: yiisoft/yii-console reads the description off the class and resolves the command
 * through the container by that name (`Yiisoft\Yii\Console\CommandLoader`, 2.4).
 *
 * The commands of the optional packages are registered only when the package is installed; without it the stub of
 * indexnowkit/console with the same name prints the install line and exits 1, so a cron that names the command keeps
 * a readable answer. The predicate is the core's `OptionalPackage`, the same one the service and `check` use — it
 * names the marker class as a string and therefore loads nothing of the package it asks about. (A service built
 * with `sitemapInstalled: false` while the package is installed gets the stub from di-console.php instead.)
 */
$sitemap = OptionalPackage::sitemap()->installed();
$history = OptionalPackage::history()->installed();

return [
    'yiisoft/yii-console' => [
        'commands' => [
            'indexnow:check' => CheckCommand::class,
            'indexnow:config' => ConfigCommand::class,
            'indexnow:submit' => SubmitCommand::class,
            'indexnow:submit-record' => SubmitSubjectsCommand::class,
            'indexnow:explain' => ExplainCommand::class,
            'indexnow:key:generate' => KeyGenerateCommand::class,
            'indexnow:sitemap' => $sitemap ? SitemapCommand::class : SitemapNotInstalledCommand::class,
            'indexnow:history' => $history ? HistoryCommand::class : HistoryNotInstalledCommand::class,
            'indexnow:status' => $history ? StatusCommand::class : StatusNotInstalledCommand::class,
        ],
    ],
];
