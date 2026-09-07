<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Feature;

use IndexNowKit\Http\Response;
use IndexNowKit\Result;
use IndexNowKit\Verify\VerifyingSubmitter;
use IndexNowKit\Verify\VerifyingSubmitterFactory;
use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * indexnowkit/verify installed and `verify.enabled: true` with `dispatch: sync`: the graph's submitter is the
 * decorator, a noindex post is skipped at flush, the container's PSR-14 dispatcher sees every Result once.
 */
final class VerifyTest extends Yii3TestCase
{
    /** @var list<string> */
    private array $seen = [];

    protected function optionOverrides(): array
    {
        return ['verify' => ['enabled' => true, 'redirect' => 'follow']];
    }

    protected function definitionOverrides(): array
    {
        $seen = &$this->seen;

        return [
            EventDispatcherInterface::class => new class ($seen) implements EventDispatcherInterface {
                /** @param list<string> $seen the test's own property, by reference: what the dispatcher saw */
                public function __construct(private array &$seen) {}

                public function dispatch(object $event): object
                {
                    if ($event instanceof Result) {
                        $this->seen[] = $event->status->value . ':' . ($event->reason->value ?? '-');
                    }

                    return $event;
                }

                /** @return list<string> */
                public function seen(): array
                {
                    return $this->seen;
                }
            },
        ];
    }

    #[TestDox('a noindex post is skipped at flush; the PSR-14 dispatcher sees ok and skipped once each; the command factory is decorated, the plain one stays apart')]
    public function testNoindexPostIsSkipped(): void
    {
        $this->transport
            ->onGet('https://www.example.com/posts/fine', new Response(200))
            ->onGet('https://www.example.com/posts/hidden', new Response(200, '<head><meta name="robots" content="noindex"></head>'));

        foreach (['fine', 'hidden'] as $slug) {
            $post = new Post();
            $post->slug = $slug;
            $post->save();
        }
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/fine'], $this->sentUrls());
        self::assertContains('https://www.example.com/robots.txt', $this->transport->gets);
        sort($this->seen);
        self::assertSame(['ok:-', 'skipped:noindex'], $this->seen);
        $indexNow = $this->indexNow();
        self::assertInstanceOf(VerifyingSubmitter::class, $indexNow->services()->submitter());
        self::assertInstanceOf(VerifyingSubmitterFactory::class, $indexNow->submitterFactory());
        self::assertNotInstanceOf(VerifyingSubmitterFactory::class, $indexNow->unverifiedSubmitterFactory());
        self::assertTrue($indexNow->verifyEnabled());
        self::assertContains('indexnow verify: skipped https://www.example.com/posts/hidden: noindex (meta robots)', $this->logger->messages('info'));
    }
}
