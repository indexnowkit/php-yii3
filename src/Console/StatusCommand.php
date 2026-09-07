<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Console;

use IndexNowKit\Console\ExitCode;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\History\Adapter\HistoryServices;
use IndexNowKit\History\Console\Definitions;
use IndexNowKit\Yii3\IndexNow;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * `./yii indexnow:status [--json]`, read-only: switches, dispatch, the debounce store (`<container id> (<class>)`),
 * the 403 counter of every host, the last successful submission, the history size. Nothing is fetched. Registered
 * by params-console.php when `indexnowkit/history` is installed.
 */
#[AsCommand(name: 'indexnow:status', description: 'Print the IndexNow status: switches, dispatch, debounce store, 403 counters per host, the last successful submission, history size')]
final class StatusCommand extends Command
{
    public function __construct(private readonly IndexNow $indexNow, private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::status()->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->indexNow->historyInstalled()) {
            $output->writeln('<error>' . $this->indexNow->historyPackage()->notInstalledMessage() . '</error>');

            return ExitCode::FAILURE;
        }
        $services = $this->indexNow->services();

        return HistoryServices::statusRunnerFor($services, $this->debounceDescription(), null)->run(new SymfonyStyle($input, $output), (bool) $input->getOption('json'));
    }

    /** `memory`, `none`, or `<container id> (<class>)` of the PSR-16 cache behind `debounce.store`. */
    private function debounceDescription(): string
    {
        $store = $this->indexNow->config()->debounceStore ?? IndexNow::DEFAULT_DEBOUNCE_STORE;
        if (\in_array($store, [DebounceStoreFactory::MEMORY, DebounceStoreFactory::NONE], true)) {
            return $store;
        }
        try {
            $cache = $this->container->has($store) ? $this->container->get($store) : null;
        } catch (Throwable) {
            $cache = null;
        }

        return \sprintf('%s (%s)', $store, \is_object($cache) ? (new ReflectionClass($cache))->getShortName() : 'missing');
    }
}
