<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Unit;

use IndexNowKit\History\HistoryConfig;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Testing\Conformance\ReadmeAssertions;
use IndexNowKit\Verify\VerifyConfig;
use IndexNowKit\Yii3\Config\ConfigFactory;
use IndexNowKit\Yii3\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The "Notes for AI assistants" section of the README (EN and RU): present, with a complete snippet, naming only
 * commands and configuration keys that exist (spec 17 §3.1). The command list is the one `./yii` reads
 * (`config/params-console.php`), not a copy of it: a command added there is checked against both READMEs at once.
 */
final class ReadmeAiNotesTest extends TestCase
{
    #[TestDox('the notes for AI assistants name only the commands of params-console and only configuration keys that exist')]
    public function testTheNotesForAiAssistantsAreConsistentWithTheCode(): void
    {
        $commands = array_keys(Fixtures::consoleCommands());
        self::assertContains('indexnow:check', $commands);

        ReadmeAssertions::assertAiNotes(\dirname(__DIR__, 2), $commands, [...ConfigFactory::YII3_OPTIONS, ...SitemapConfig::OPTIONS, ...VerifyConfig::OPTIONS, ...HistoryConfig::OPTIONS]);
    }
}
