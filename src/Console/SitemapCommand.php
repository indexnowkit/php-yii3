<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Console;

use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Console\ResultFormatterInterface;
use IndexNowKit\Sitemap\Adapter\SitemapServices;
use IndexNowKit\Sitemap\Console\Definitions;
use IndexNowKit\Sitemap\Console\SitemapOptions;
use IndexNowKit\Yii3\IndexNow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `./yii indexnow:sitemap [sitemap] [--changed-since=] [--allow-foreign-hosts] [--force] [--dry-run] [--json] [--no-verify]`:
 * the only command that reads `IndexNowKit\Sitemap\*`, registered by params-console.php when `indexnowkit/sitemap`
 * is installed ({@see SitemapNotInstalledCommand} otherwise).
 */
#[AsCommand(name: 'indexnow:sitemap', description: 'Submit every URL of a sitemap (or only those with lastmod after --changed-since)')]
final class SitemapCommand extends Command
{
    public function __construct(private readonly IndexNow $indexNow, private readonly SubmitterFactoryInterface $submitters, private readonly ResultFormatterInterface $formatter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::sitemap()->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->indexNow->sitemapInstalled()) {
            $output->writeln('<error>' . $this->indexNow->sitemapPackage()->notInstalledMessage() . '</error>'); // one line: a cron log greps it

            return ExitCode::FAILURE;
        }
        $config = $this->indexNow->sitemapConfig();
        if (!$config->enabled) {
            $io->error('sitemap.enabled is false.');

            return ExitCode::INVALID;
        }
        $sitemap = $input->getArgument('sitemap');
        $since = $input->getOption('changed-since');
        $runner = SitemapServices::runner($this->indexNow->kit(), $this->indexNow->sitemapSource(), $this->submitters, $config, $this->formatter, 'sitemap.url', $this->indexNow->unverifiedSubmitterFactory());

        return $runner->run($io, new SitemapOptions(
            sitemap: \is_string($sitemap) ? $sitemap : null,
            changedSince: \is_string($since) ? $since : null,
            allowForeignHosts: (bool) $input->getOption('allow-foreign-hosts'),
            force: (bool) $input->getOption('force'),
            dryRun: (bool) $input->getOption('dry-run'),
            json: (bool) $input->getOption('json'),
            noVerify: (bool) $input->getOption('no-verify'),
        ));
    }
}
