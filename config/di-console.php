<?php

declare(strict_types=1);

use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Console\Command\HistoryNotInstalledCommand;
use IndexNowKit\Console\Command\KeyGenerateCommand;
use IndexNowKit\Console\Command\SitemapNotInstalledCommand;
use IndexNowKit\Console\Command\StatusNotInstalledCommand;
use IndexNowKit\Console\ConfigRunner;
use IndexNowKit\Console\ConfigSourceInterface;
use IndexNowKit\Console\ExplainRunner;
use IndexNowKit\Console\KeyGenerateRunner;
use IndexNowKit\Console\ResultFormatterInterface;
use IndexNowKit\Console\SubmitRunner;
use IndexNowKit\Console\SubmitSubjectsRunner;
use IndexNowKit\History\Adapter\HistoryServices;
use IndexNowKit\History\Console\HistoryCommand;
use IndexNowKit\History\Console\HistoryRunner;
use IndexNowKit\History\Console\StatusCommand;
use IndexNowKit\History\Console\StatusRunner;
use IndexNowKit\Sitemap\Adapter\SitemapServices;
use IndexNowKit\Sitemap\Console\SitemapCommand;
use IndexNowKit\Sitemap\Console\SitemapRunner;
use IndexNowKit\Yii3\Console\ConfigSource;
use IndexNowKit\Yii3\IndexNow;
use IndexNowKit\Yii3\Wiring;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;

/*
 * Console only: what the commands of indexnowkit/console, indexnowkit/sitemap and indexnowkit/history (the classes
 * of config/params-console.php, autowired from here) take by constructor. The bodies are the runners, as definitions,
 * so an application can decorate one (a tenant loop over SubmitSubjectsRunner is a ten-line command); the words
 * (`Vocabulary`), the record loader, the formatter and the sample holder are in di.php.
 *
 * The runners of the optional packages are defined only when the package is installed (the branch of
 * params-console.php); a service built with `sitemapInstalled: false` / `historyInstalled: false` while the package
 * is installed gets the stub of indexnowkit/console under the class the map names — the check is the container's,
 * never the command's.
 */
$sitemap = OptionalPackage::sitemap()->installed();
$history = OptionalPackage::history()->installed();

return [
    CheckRunner::class => CheckRunner::class,
    ConfigRunner::class => ConfigRunner::class,
    SubmitRunner::class => SubmitRunner::class,
    SubmitSubjectsRunner::class => SubmitSubjectsRunner::class,
    ExplainRunner::class => ExplainRunner::class,
    KeyGenerateRunner::class => KeyGenerateRunner::class,
    // what `indexnow:check` and `indexnow:config` read: the params block, its strict build, the package blocks
    ConfigSourceInterface::class => ConfigSource::class,
    KeyGenerateCommand::class => [
        'class' => KeyGenerateCommand::class,
        '__construct()' => [
            'envFileName' => '.env', // --write-env without a value: <current directory>/.env (a Yii3 application is run from its root)
            'envFile' => null,       // give an absolute path here to pin it
        ],
    ],
    // the stubs of the optional packages: the same names, the install line of the core's predicate, exit 1
    SitemapNotInstalledCommand::class => static fn(IndexNow $indexNow): SitemapNotInstalledCommand => new SitemapNotInstalledCommand($indexNow->sitemapPackage()->notInstalledMessage()),
    HistoryNotInstalledCommand::class => static fn(IndexNow $indexNow): HistoryNotInstalledCommand => new HistoryNotInstalledCommand($indexNow->historyPackage()->notInstalledMessage()),
    StatusNotInstalledCommand::class => static fn(IndexNow $indexNow): StatusNotInstalledCommand => new StatusNotInstalledCommand($indexNow->historyPackage()->notInstalledMessage()),
    ...$sitemap ? [
        SitemapRunner::class => static fn(IndexNow $indexNow, SubmitterFactoryInterface $submitters, ResultFormatterInterface $formatter): SitemapRunner => SitemapServices::runner($indexNow->kit(), $indexNow->sitemapSource(), $submitters, $indexNow->sitemapConfig(), $formatter, 'sitemap.url', $indexNow->unverifiedSubmitterFactory()),
        SitemapCommand::class => static function (IndexNow $indexNow, ContainerInterface $container): Command {
            if (!$indexNow->sitemapInstalled()) {
                $stub = $container->get(SitemapNotInstalledCommand::class);
                assert($stub instanceof Command);

                return $stub;
            }
            $runner = $container->get(SitemapRunner::class);
            assert($runner instanceof SitemapRunner);

            return SitemapServices::command($runner, 'sitemap.url');
        },
    ] : [],
    ...$history ? [
        HistoryRunner::class => static fn(IndexNow $indexNow): HistoryRunner => HistoryServices::historyRunnerFor($indexNow->historyConfig(), $indexNow->services()),
        // the debounce store described as `memory`, `none`, or `<container id> (<class>)` (Wiring); no queue facts: no queue mode yet
        StatusRunner::class => static fn(IndexNow $indexNow, Wiring $wiring): StatusRunner => HistoryServices::statusRunnerFor($indexNow->services(), $wiring->debounceStoreDescription(), null),
        HistoryCommand::class => static function (IndexNow $indexNow, ContainerInterface $container): Command {
            if (!$indexNow->historyInstalled()) {
                $stub = $container->get(HistoryNotInstalledCommand::class);
                assert($stub instanceof Command);

                return $stub;
            }
            $runner = $container->get(HistoryRunner::class);
            assert($runner instanceof HistoryRunner);

            return new HistoryCommand($runner);
        },
        StatusCommand::class => static function (IndexNow $indexNow, ContainerInterface $container): Command {
            if (!$indexNow->historyInstalled()) {
                $stub = $container->get(StatusNotInstalledCommand::class);
                assert($stub instanceof Command);

                return $stub;
            }
            $runner = $container->get(StatusRunner::class);
            assert($runner instanceof StatusRunner);

            return new StatusCommand($runner);
        },
    ] : [],
];
