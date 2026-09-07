<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Unit;

use IndexNowKit\History\HistoryConfig;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Testing\Conformance\ReadmeAssertions;
use IndexNowKit\Verify\VerifyConfig;
use IndexNowKit\Yii3\Config\ConfigFactory;
use PHPUnit\Framework\TestCase;

/**
 * The "Notes for AI assistants" section of the README (EN and RU): present, with a complete snippet, naming only
 * commands and configuration keys that exist (spec 17 §3.1).
 */
final class ReadmeAiNotesTest extends TestCase
{
    public function testTheNotesForAiAssistantsAreConsistentWithTheCode(): void
    {
        ReadmeAssertions::assertAiNotes(\dirname(__DIR__, 2), ['indexnow:check', 'indexnow:config', 'indexnow:key:generate', 'indexnow:submit', 'indexnow:submit-record', 'indexnow:explain', 'indexnow:sitemap', 'indexnow:history', 'indexnow:status'], [...ConfigFactory::YII3_OPTIONS, ...SitemapConfig::OPTIONS, ...VerifyConfig::OPTIONS, ...HistoryConfig::OPTIONS]);
    }
}
