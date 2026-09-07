<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Console;

use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Console\Definitions;
use IndexNowKit\Console\SubjectLoaderInterface;
use IndexNowKit\Yii3\Check\RecordSampler;
use IndexNowKit\Yii3\Check\SampleOptions;
use IndexNowKit\Yii3\IndexNow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `./yii indexnow:check [--live] [--host=] [--probe-url=] [--json] [--strict] [--sample=] [--sample-class=]`: the
 * shared command body of indexnowkit/console over the checker of the graph, with the Yii3 lines ({@see \IndexNowKit\Yii3\Wiring::checks()}).
 */
#[AsCommand(name: 'indexnow:check', description: 'Validate the IndexNow configuration, verify the key file is reachable, report how submissions are wired')]
final class CheckCommand extends Command
{
    public function __construct(
        private readonly CheckRunner $runner,
        private readonly IndexNow $indexNow,
        private readonly SampleOptions $samples,
        private readonly SubjectLoaderInterface $records,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::check()->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hosts = $input->getOption('host');
        $probeUrl = $input->getOption('probe-url');
        $this->samples->urls = self::strings($input->getOption('sample'));
        $this->samples->classes = self::strings($input->getOption('sample-class'));
        $this->samples->sampler = (new RecordSampler($this->records, $this->indexNow->kit()))(...);

        return $this->runner->run(
            new SymfonyStyle($input, $output),
            fn(): mixed => $this->indexNow->buildConfig(),
            (bool) $input->getOption('live'),
            \is_array($hosts) ? array_values(array_filter($hosts, 'is_string')) : (\is_string($hosts) ? $hosts : null),
            \is_string($probeUrl) ? $probeUrl : null,
            (bool) $input->getOption('json'),
            (bool) $input->getOption('strict'),
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $option): array
    {
        return \is_array($option) ? array_values(array_filter($option, static fn(mixed $v): bool => \is_string($v) && $v !== '')) : [];
    }
}
