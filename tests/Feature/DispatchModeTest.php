<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Console\Command\CheckCommand;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Yii3\Config\ConfigFactory;
use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * `dispatch` is validated against the modes this adapter delivers, at the moment the configuration is built —
 * not when a URL is about to be dispatched. A `dispatch: queue` copied from the Yii2 or Laravel configuration is
 * therefore a configuration error that `check` prints and one `critical` line, never a URL that quietly disappears
 * into a queue nobody reads.
 */
final class DispatchModeTest extends Yii3TestCase
{
    protected function console(): bool
    {
        return true;
    }

    protected function optionOverrides(): array
    {
        return ['dispatch' => 'queue'];
    }

    #[TestDox('dispatch: queue is rejected when the configuration is built, naming the modes this adapter has')]
    public function testQueueIsNotAMode(): void
    {
        self::assertSame(['sync', 'none'], ConfigFactory::DISPATCH_MODES);

        try {
            $this->indexNow()->buildConfig();
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('"dispatch" must be one of sync, none', $e->getMessage());
        }
    }

    #[TestDox('at runtime it is one critical line and a disabled IndexNow: a save neither throws nor loses the URL silently')]
    public function testTheRuntimeDisablesItself(): void
    {
        $post = new Post();
        $post->slug = 'queued-nowhere';
        $post->save();
        $this->kit()->flush();

        self::assertFalse($this->indexNow()->config()->enabled);
        self::assertSame([], $this->transport->posts);
        self::assertCount(1, $this->logger->messages('critical'));
        self::assertStringContainsString('"dispatch" must be one of sync, none', $this->logger->messages('critical')[0]);
    }

    #[TestDox('check is where it is read: exit 1 and the line naming the modes')]
    public function testCheckPrintsIt(): void
    {
        [$code, $display] = $this->yii(CheckCommand::class);

        self::assertSame(ExitCode::FAILURE, $code, $display);
        self::assertStringContainsString('"dispatch" must be one of sync, none', $display);
    }
}
