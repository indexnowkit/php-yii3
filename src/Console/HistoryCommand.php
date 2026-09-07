<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Console;

use IndexNowKit\Console\ExitCode;
use IndexNowKit\History\Adapter\HistoryServices;
use IndexNowKit\History\Console\Definitions;
use IndexNowKit\History\Console\HistoryOptions;
use IndexNowKit\Yii3\IndexNow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `./yii indexnow:history [--host=] [--status=] [--url=] [--since=] [--limit=] [--json] [--purge[=days]]`: the
 * recorded submissions of the graph's submission store (the store of `history.store`, or the application's own),
 * newest first. Registered by params-console.php when `indexnowkit/history` is installed.
 */
#[AsCommand(name: 'indexnow:history', description: 'List the recorded IndexNow submissions (what was sent, when, with what answer), newest first')]
final class HistoryCommand extends Command
{
    public function __construct(private readonly IndexNow $indexNow)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::history()->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->indexNow->historyInstalled()) {
            $output->writeln('<error>' . $this->indexNow->historyPackage()->notInstalledMessage() . '</error>'); // one line: a cron log greps it

            return ExitCode::FAILURE;
        }
        $limit = $input->getOption('limit');
        $purge = $input->getOption('purge'); // false = absent, null = --purge without a value, a string = --purge=<days>
        $runner = HistoryServices::historyRunnerFor($this->indexNow->historyConfig(), $this->indexNow->services());

        return $runner->run(new SymfonyStyle($input, $output), new HistoryOptions(
            host: self::str($input->getOption('host')),
            status: self::str($input->getOption('status')),
            url: self::str($input->getOption('url')),
            since: self::str($input->getOption('since')),
            limit: \is_string($limit) || \is_int($limit) ? $limit : 50,
            json: (bool) $input->getOption('json'),
            purge: $purge === false ? null : ($purge === null ? true : (\is_string($purge) ? $purge : true)),
        ));
    }

    private static function str(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
