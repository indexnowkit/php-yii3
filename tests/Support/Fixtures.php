<?php

declare(strict_types=1);

namespace IndexNowKit\Yii3\Tests\Support;

use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Yii3\ActiveRecord\ObserverProvider;
use IndexNowKit\Yii3\IndexNow;
use IndexNowKit\Yii3\Tests\Fixtures\ControlledPost;
use IndexNowKit\Yii3\Tests\Fixtures\ModelPost;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Yiisoft\ActiveRecord\Event\EventDispatcherProvider;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ConnectionProvider;
use Yiisoft\Db\Schema\Column\ColumnBuilder;
use Yiisoft\Db\Sqlite\Connection;
use Yiisoft\Db\Sqlite\Driver;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\EventDispatcher\Dispatcher\Dispatcher;
use Yiisoft\EventDispatcher\Provider\Provider;
use Yiisoft\Injector\Injector;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\FastRoute\UrlGenerator;
use Yiisoft\Router\FastRoute\UrlMatcher;
use Yiisoft\Router\Route;
use Yiisoft\Router\RouteCollection;
use Yiisoft\Router\RouteCollectionInterface;
use Yiisoft\Router\RouteCollector;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Router\UrlMatcherInterface;
use Yiisoft\Yii\Event\CallableFactory;
use Yiisoft\Yii\Event\ListenerCollectionFactory;

/**
 * The package's test application: a yiisoft/di container over the package's own config/*.php (the way yiisoft/config
 * merges them into an application), a sqlite connection in memory with the fixture schema, a FakeTransport instead
 * of HTTP, an ArrayLogger instead of yiisoft/log, the routes of the conformance fixtures, and the PSR-14 dispatcher
 * built from the package's events-web.php / events-console.php.
 */
final class Fixtures
{
    public const KEY = 'abcdef1234567890abcdef1234567890';
    public const SECOND_KEY = 'fedcba0987654321fedcba0987654321';
    public const BASE_URL = 'https://www.example.com';

    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        $defaults = self::params()['indexnowkit/yii3'];
        \assert(\is_array($defaults));

        return self::merge($defaults, [
            'key' => self::KEY,
            'base_url' => self::BASE_URL,
            'hosts' => ['example.de' => self::SECOND_KEY],
            'dispatch' => 'sync',
            'dry_run' => false, // explicit: YII_ENV "test" is not production, an unset dry_run fails check
            'debounce' => ['per_url' => 0, 'store' => 'memory'],
            'router' => ['locales' => ['en', 'de']],
            'collector' => ['detect_leaks' => false],
            'sitemap' => ['spool' => 'memory'],
            'active_record' => ['namespaces' => ['IndexNowKit\\Yii3\\Tests\\Fixtures'], 'models' => [ModelPost::class]],
        ]);
    }

    /**
     * Overrides on top of the test options: nested arrays merge, an empty array (or a list) replaces.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $current = $base[$key] ?? null;
            $base[$key] = \is_array($value) && $value !== [] && !array_is_list($value) && \is_array($current) ? self::merge($current, $value) : $value;
        }

        return $base;
    }

    /**
     * The package's params.php as shipped.
     *
     * @return array<string, mixed>
     */
    public static function params(): array
    {
        return self::load('params', []);
    }

    /**
     * The routes of the conformance fixtures plus the package's key file route (config/routes.php).
     *
     * @param array<string, mixed> $params the merged params
     *
     * @return list<Route>
     */
    public static function routes(array $params): array
    {
        $own = [
            Route::get('/posts/{slug}')->name('post/view'),
            Route::get('/pages/{slug}')->name('page/view'),
            Route::get('/amp/{slug}')->name('post/amp'),
            Route::get('/categories/{slug}')->name('category/view'),
            Route::get('/{_language:en|de}/articles/{slug}')->name('article/view'),
            Route::get('/items/{id:\d+}')->name('item/view'),
        ];
        $package = self::load('routes', $params);

        return [...$own, ...array_values(array_filter($package, static fn(mixed $r): bool => $r instanceof Route))];
    }

    /**
     * A container over the package's definitions (di.php, di-web.php, di-console.php) plus the test doubles; the
     * package's bootstrap has run, the schema exists.
     *
     * @param array<string, mixed>  $optionOverrides     merged into {@see options()}
     * @param array<string, mixed>  $definitionOverrides container definitions that win over the package's
     * @param array<string, mixed>  $indexNowArguments   extra constructor arguments of `IndexNow` (`sitemapInstalled` => false, ...)
     * @param 'web'|'console'       $runtime             which events group the PSR-14 dispatcher is built from
     */
    public static function container(FakeTransport $transport, ArrayLogger $logger, array $optionOverrides = [], array $definitionOverrides = [], array $indexNowArguments = [], string $runtime = 'web'): Container
    {
        $params = ['indexnowkit/yii3' => self::merge(self::options(), $optionOverrides)];
        $db = self::database();
        $factory = new Psr17Factory();
        $definitions = array_merge(
            self::load('di', $params),
            self::load('di-web', $params),
            self::load('di-console', $params),
            [
                IndexNow::class => [
                    'class' => IndexNow::class,
                    '__construct()' => ['options' => $params['indexnowkit/yii3']] + $indexNowArguments,
                ],
                ConnectionInterface::class => $db,
                CacheInterface::class => new ArrayCache(),
                LoggerInterface::class => $logger,
                TransportInterface::class => $transport,
                IndexNow::VERIFY_TRANSPORT => $transport,
                ResponseFactoryInterface::class => $factory,
                StreamFactoryInterface::class => $factory,
                ServerRequestFactoryInterface::class => $factory,
                CurrentRoute::class => new CurrentRoute(),
                RouteCollectionInterface::class => static fn(): RouteCollectionInterface => new RouteCollection((new RouteCollector())->addRoute(...self::routes($params))),
                UrlGeneratorInterface::class => static fn(RouteCollectionInterface $routes, CurrentRoute $currentRoute): UrlGeneratorInterface => new UrlGenerator($routes, $currentRoute),
                UrlMatcherInterface::class => static fn(RouteCollectionInterface $routes): UrlMatcherInterface => new UrlMatcher($routes, null),
                EventDispatcherInterface::class => static fn(ContainerInterface $container): EventDispatcherInterface => self::dispatcher($container, $runtime),
            ],
            $definitionOverrides,
        );
        $container = new Container(ContainerConfig::create()->withDefinitions($definitions));
        ConnectionProvider::set($db);
        self::migrate($db);
        foreach (self::load('bootstrap', $params) as $callback) {
            \assert(\is_callable($callback));
            $callback($container);
        }

        return $container;
    }

    /**
     * The `indexnow:*` command map of `config/params-console.php` as yiisoft/yii-console reads it: command name =>
     * class. The two optional-package entries are the branch of the file, so a test that resolves a command by its
     * name is the only thing that executes them.
     *
     * @return array<string, class-string<Command>>
     */
    public static function consoleCommands(): array
    {
        $console = self::load('params-console', [])['yiisoft/yii-console'] ?? null;
        \assert(\is_array($console));
        $commands = $console['commands'] ?? null;
        \assert(\is_array($commands));

        /** @var array<string, class-string<Command>> $commands */
        return $commands;
    }

    /**
     * A Symfony console application over {@see consoleCommands()}, every command built by the container: what
     * `./yii <name>` resolves, names and stubs included.
     */
    public static function consoleApplication(ContainerInterface $container): Application
    {
        $application = new Application();
        $application->setAutoExit(false);
        foreach (self::consoleCommands() as $name => $class) {
            $command = $container->get($class);
            \assert($command instanceof Command);
            $command->setName($name);
            // addCommands() rather than add() or addCommand(): the first is deprecated in Symfony 7.4, the second
            // does not exist in 6.4, and this one is in both
            $application->addCommands([$command]);
        }

        return $application;
    }

    /** Undo what an application did to the process. */
    public static function destroy(): void
    {
        ControlledPost::reset();
        ObserverProvider::reset();
        EventDispatcherProvider::reset();
        ConnectionProvider::clear();
    }

    public static function database(): Connection
    {
        return new Connection(new Driver('sqlite::memory:'), new SchemaCache(new ArrayCache()));
    }

    public static function migrate(ConnectionInterface $db): void
    {
        $command = $db->createCommand();
        $command->createTable('posts', [
            'id' => ColumnBuilder::primaryKey(),
            'slug' => ColumnBuilder::string()->notNull(),
            'title' => ColumnBuilder::string()->notNull()->defaultValue('title'),
            'body' => ColumnBuilder::text(),
            'published' => ColumnBuilder::boolean()->notNull()->defaultValue(true),
            'amp' => ColumnBuilder::boolean()->notNull()->defaultValue(false),
            'views' => ColumnBuilder::integer()->notNull()->defaultValue(0),
            'category_id' => ColumnBuilder::integer(),
        ])->execute();
        $command->createTable('multi_posts', [
            'id' => ColumnBuilder::primaryKey(),
            'slug' => ColumnBuilder::string()->notNull(),
            'published' => ColumnBuilder::boolean()->notNull()->defaultValue(true),
            'amp' => ColumnBuilder::boolean()->notNull()->defaultValue(false),
        ])->execute();
        $command->createTable('categories', ['id' => ColumnBuilder::primaryKey(), 'slug' => ColumnBuilder::string()->notNull()])->execute();
        $command->createTable('categorized_posts', [
            'id' => ColumnBuilder::primaryKey(),
            'slug' => ColumnBuilder::string()->notNull(),
            'views' => ColumnBuilder::integer()->notNull()->defaultValue(0),
            'category_id' => ColumnBuilder::integer(),
            'updated_at' => ColumnBuilder::integer(),
        ])->execute();
        $command->createTable('tags', ['id' => ColumnBuilder::primaryKey(), 'name' => ColumnBuilder::string()->notNull()])->execute();
        $command->createTable('controlled_posts', ['id' => ColumnBuilder::primaryKey(), 'name' => ColumnBuilder::string()->notNull()])->execute();
        // a price and a timestamp the database keeps in its own spelling: what the change verifier must not compare
        $command->createTable('priced_posts', [
            'id' => ColumnBuilder::primaryKey(),
            'slug' => ColumnBuilder::string()->notNull(),
            'price' => ColumnBuilder::decimal(10, 2)->notNull()->defaultValue(0),
            'published_at' => ColumnBuilder::datetime(),
        ])->execute();
        // no primary key at all: verify-on-commit has nothing to re-read the row by
        $command->createTable('keyless_pages', ['name' => ColumnBuilder::string()->notNull()])->execute();
        $command->createTable('categorized_post_tags', ['post_id' => ColumnBuilder::integer()->notNull(), 'tag_id' => ColumnBuilder::integer()->notNull()])->execute();
        foreach (['untracked', 'broken', 'bad_attribute', 'model_posts', 'items'] as $name) {
            $command->createTable($name, ['id' => ColumnBuilder::primaryKey(), 'name' => ColumnBuilder::string()->notNull()])->execute();
        }
    }

    /**
     * The package's config/<group>.php, with `$params` in scope the way yiisoft/config loads it.
     *
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    public static function load(string $group, array $params): array
    {
        $file = \dirname(__DIR__, 2) . '/config/' . $group . '.php';

        return (static function (string $file, array $params): array {
            $loaded = require $file;
            \assert(\is_array($loaded));

            return $loaded;
        })($file, $params);
    }

    /**
     * The PSR-14 dispatcher an application builds from the package's events group (yiisoft/yii-event).
     *
     * @param 'web'|'console' $runtime
     */
    private static function dispatcher(ContainerInterface $container, string $runtime): EventDispatcherInterface
    {
        $listeners = self::load('events-' . $runtime, []);
        $collection = (new ListenerCollectionFactory(new Injector($container), new CallableFactory($container)))->create($listeners);

        return new Dispatcher(new Provider($collection));
    }
}
