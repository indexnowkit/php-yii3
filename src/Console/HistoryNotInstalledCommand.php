<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Console;

use IndexNowKit\Console\ExitCode;
use IndexNowKit\Yii3\IndexNow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `indexnow:history` while `indexnowkit/history` is not installed: a sentence and exit 1 instead of "command not
 * found" (a cron or a runbook that names the command keeps a readable answer). Every option is accepted and ignored.
 */
#[AsCommand(name: 'indexnow:history', description: 'List the recorded IndexNow submissions (needs indexnowkit/history, which is not installed)')]
final class HistoryNotInstalledCommand extends Command
{
    public function __construct(private readonly IndexNow $indexNow)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>' . $this->indexNow->historyPackage()->notInstalledMessage() . '</error>'); // one line: a cron log greps it

        return ExitCode::FAILURE;
    }
}
