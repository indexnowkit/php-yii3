<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Console;

use IndexNowKit\Console\Definitions;
use IndexNowKit\Console\KeyGenerateRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `./yii indexnow:key:generate [--length=] [--alphanumeric] [--write-env[=FILE]] [--force] [--no-previous] [--yes]`.
 * `--write-env` without a value writes to `.env` of the current directory (a Yii3 application is run from its
 * root), or to the file the `envFile` definition argument pins.
 */
#[AsCommand(name: 'indexnow:key:generate', description: 'Generate a new IndexNow key (optionally write INDEXNOW_KEY to .env)')]
final class KeyGenerateCommand extends Command
{
    public function __construct(private readonly KeyGenerateRunner $runner, private readonly ?string $envFile = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::keyGenerate('.env')->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $length = $input->getOption('length');
        $writeEnv = $input->getOption('write-env');
        $envFile = match (true) {
            $writeEnv === false => null,
            \is_string($writeEnv) && $writeEnv !== '' => $writeEnv,
            default => $this->envFile ?? self::cwd() . '/.env',
        };

        return $this->runner->run(new SymfonyStyle($input, $output), is_numeric($length) ? (int) $length : 32, !(bool) $input->getOption('alphanumeric'), $envFile, (bool) $input->getOption('force'), (bool) $input->getOption('no-previous'), (bool) $input->getOption('yes'));
    }

    private static function cwd(): string
    {
        $cwd = getcwd();

        return $cwd === false ? '.' : $cwd;
    }
}
