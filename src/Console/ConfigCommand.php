<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Console;

use IndexNowKit\Config;
use IndexNowKit\Console\ConfigRunner;
use IndexNowKit\Console\Definitions;
use IndexNowKit\Yii3\IndexNow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `./yii indexnow:config [--json]`: the effective configuration, keys and DSNs masked (`ConfigRunner` does the
 * masking), the blocks of the installed optional packages included.
 */
#[AsCommand(name: 'indexnow:config', description: 'Print the effective IndexNow configuration: defaults and environment applied, keys masked (paste it into a bug report)')]
final class ConfigCommand extends Command
{
    public function __construct(private readonly ConfigRunner $runner, private readonly IndexNow $indexNow)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::config()->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $packages = [
            ...$this->indexNow->verifyInstalled() ? ['verify' => $this->indexNow->verifyConfig()->toArray()] : [],
            ...$this->indexNow->historyInstalled() ? ['history' => $this->indexNow->historyConfig()->toArray()] : [],
        ];

        return $this->runner->run(new SymfonyStyle($input, $output), fn(): Config => $this->indexNow->buildConfig(), $this->indexNow->options(), (bool) $input->getOption('json'), $packages);
    }
}
