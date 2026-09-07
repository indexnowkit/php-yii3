<?php

declare(strict_types=1);

use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Console\ConfigRunner;
use IndexNowKit\Console\ExplainRunner;
use IndexNowKit\Console\KeyGenerateRunner;
use IndexNowKit\Console\SubmitRunner;
use IndexNowKit\Console\SubmitSubjectsRunner;
use IndexNowKit\Yii3\Console\KeyGenerateCommand;

/*
 * Console only: the bodies of the commands (indexnowkit/console) as definitions, so an application can decorate
 * one (a tenant loop over SubmitSubjectsRunner is a ten-line command). The commands themselves are autowired from
 * the command map of params-console.php.
 */
return [
    CheckRunner::class => CheckRunner::class,
    ConfigRunner::class => ConfigRunner::class,
    SubmitRunner::class => SubmitRunner::class,
    SubmitSubjectsRunner::class => SubmitSubjectsRunner::class,
    ExplainRunner::class => ExplainRunner::class,
    KeyGenerateRunner::class => KeyGenerateRunner::class,
    KeyGenerateCommand::class => [
        'class' => KeyGenerateCommand::class,
        '__construct()' => [
            'envFile' => null,   // --write-env without a value: <current directory>/.env; give an absolute path here to pin it
        ],
    ],
];
