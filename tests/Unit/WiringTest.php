<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Unit;

use IndexNowKit\Adapter\Services;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\ClientInterface;
use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Debounce\Psr16DebounceStore;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Dispatch\SyncDispatcher;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Testing\RecordingDispatcher;
use IndexNowKit\Url\ObjectChangeHandler;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlResolverInterface;
use IndexNowKit\Yii3\ActiveRecord\ActiveRecordSubjectReader;
use IndexNowKit\Yii3\Console\CheckCommand;
use IndexNowKit\Yii3\IndexNow;
use IndexNowKit\Yii3\Tests\Fixtures\Post;
use IndexNowKit\Yii3\Tests\Support\Fixtures;
use IndexNowKit\Yii3\Tests\Yii3TestCase;
use IndexNowKit\Yii3\Wiring;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The graph is the container: every node of `Adapter\Services` is a definition, the default of each is the core's
 * factory over the other definitions, and a replaced definition reaches every dependent piece.
 */
final class WiringTest extends Yii3TestCase
{
    #[TestDox('every node of the graph is the container\'s instance (the same object), and the facade is built over them')]
    public function testTheGraphReadsTheContainer(): void
    {
        $services = $this->indexNow()->services();

        foreach (Wiring::NODES as $node => $id) {
            $fromContainer = $this->container->get($id);
            $fromGraph = match ($node) {
                Services::TRANSPORT => $services->transport(),
                Services::KEYS => $services->keys(),
                Services::NORMALIZER => $services->normalizer(),
                Services::THROTTLE => $services->throttle(),
                Services::DEBOUNCE_STORE => $services->debounceStore(),
                Services::SUBMISSION_STORE => $services->submissionStore(),
                Services::CLIENT => $services->client(),
                Services::SUBMITTER => $services->submitter(),
                Services::COLLECTOR => $services->collector(),
                Services::DISPATCHER => $services->dispatcher(),
                Services::READER => $services->reader(),
                Services::ROUTER => $services->router(),
                Services::RESOLVER_LOCATOR => $services->resolverLocator(),
                Services::CLOCK => $services->clock(),
                Services::CHANGES => $services->changes(),
                Services::URL_RESOLVER => $services->urlResolver(),
                Services::PARAM_EXTRACTOR => $services->paramExtractor(),
                default => self::fail('unknown node ' . $node),
            };
            self::assertSame($fromContainer, $fromGraph, $id);
        }
        self::assertSame($this->transport, $this->container->get(TransportInterface::class), 'the test transport is the graph\'s');
        self::assertInstanceOf(MemoryDebounceStore::class, $this->container->get(DebounceStoreInterface::class));
        self::assertInstanceOf(SyncDispatcher::class, $this->container->get(DispatcherInterface::class));
        self::assertSame($services->kit(), $this->container->get(IndexNowKit::class));
        self::assertSame($services->kit()->changes(), $this->container->get(ObjectChangeHandler::class), 'the facade shares the change handler of the hooks');
        $extractor = $this->container->get(ParamExtractor::class);
        self::assertInstanceOf(ParamExtractor::class, $extractor);
        self::assertInstanceOf(ActiveRecordSubjectReader::class, $extractor->readers()[0] ?? null);
    }

    #[TestDox('a replaced DispatcherInterface definition is what the facade dispatches to, and check names it')]
    public function testReplacedDispatcher(): void
    {
        $recording = new RecordingDispatcher();
        $container = Fixtures::container($this->transport, $this->logger, [], [DispatcherInterface::class => $recording], [], 'console');
        $indexNow = $container->get(IndexNow::class);
        \assert($indexNow instanceof IndexNow);

        $post = new Post();
        $post->slug = 'queued';
        $post->save();
        $indexNow->flush();

        self::assertSame(['https://www.example.com/posts/queued'], $recording->urls());
        self::assertSame([], $this->transport->posts, 'nothing went through the sync dispatcher');
        $tester = new CommandTester($container->get(CheckCommand::class));
        $tester->execute([]);
        self::assertStringContainsString('dispatch "sync": URLs go to ' . RecordingDispatcher::class, $tester->getDisplay());
    }

    #[TestDox('a replaced TransportInterface reaches the client, the submitter, the checker and the command submitters; the other nodes keep their defaults')]
    public function testReplacedTransportReachesEveryDependent(): void
    {
        $other = new FakeTransport();
        $container = Fixtures::container(new FakeTransport(), new ArrayLogger(), [], [TransportInterface::class => $other]);
        $indexNow = $container->get(IndexNow::class);
        \assert($indexNow instanceof IndexNow);

        $indexNow->submit(['/via-other']);
        self::assertSame(['https://www.example.com/via-other'], array_merge(...array_map(static fn(array $p): array => $p['body']['urlList'], $other->posts)));
        self::assertInstanceOf(ClientInterface::class, $container->get(ClientInterface::class));
        self::assertInstanceOf(SubmitterInterface::class, $container->get(SubmitterInterface::class));
        self::assertInstanceOf(KeyProviderInterface::class, $container->get(KeyProviderInterface::class));
        self::assertInstanceOf(RouteUrlResolverInterface::class, $container->get(RouteUrlResolverInterface::class));
        self::assertInstanceOf(UrlResolverInterface::class, $container->get(UrlResolverInterface::class));
        self::assertInstanceOf(CollectorInterface::class, $container->get(CollectorInterface::class));
        self::assertInstanceOf(SubmissionStoreInterface::class, $container->get(SubmissionStoreInterface::class));
    }

    #[TestDox('debounce.store unset takes the PSR-16 cache of the container; the 403 counter shares it')]
    public function testDefaultDebounceStoreIsTheContainerCache(): void
    {
        $container = Fixtures::container($this->transport, $this->logger, ['debounce' => ['per_url' => 600, 'store' => null]]);
        $indexNow = $container->get(IndexNow::class);
        \assert($indexNow instanceof IndexNow);

        self::assertInstanceOf(Psr16DebounceStore::class, $container->get(DebounceStoreInterface::class));
        self::assertSame($container->get(CacheInterface::class), $indexNow->services()->failureCache());
        $indexNow->submit(['/once']);
        $indexNow->submit(['/once']);
        self::assertCount(1, $this->transport->posts, 'the second submission is debounced through the container cache');
    }

    #[TestDox('the http.client id is resolved through the container on the first request')]
    public function testHttpClientId(): void
    {
        $container = Fixtures::container($this->transport, $this->logger, ['http' => ['client' => 'my.client']], [
            TransportInterface::class => static fn(Wiring $wiring): TransportInterface => $wiring->transport(), // the package's default again, over the id
            'my.client' => static fn(): object => new class implements \Psr\Http\Client\ClientInterface {
                public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    return (new \Nyholm\Psr7\Factory\Psr17Factory())->createResponse(202);
                }
            },
        ]);
        $indexNow = $container->get(IndexNow::class);
        \assert($indexNow instanceof IndexNow);

        $results = $indexNow->submit(['/through-my-client']);
        self::assertSame('pending', $results[0]->status->value, 'the 202 of the container client');
    }
}
