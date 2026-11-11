<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use Phil\RedirectsBundle\Application\ChainInspector;
use Phil\RedirectsBundle\Application\Contract\NotFoundFilter;
use Phil\RedirectsBundle\Application\Csv\RedirectCsv;
use Phil\RedirectsBundle\Application\Filter\DeclaredCrawlerFilter;
use Phil\RedirectsBundle\Application\LocationBuilder;
use Phil\RedirectsBundle\Application\NotFoundRecorder;
use Phil\RedirectsBundle\Application\OldDomain;
use Phil\RedirectsBundle\Application\OldDomains;
use Phil\RedirectsBundle\Application\RedirectResolver;
use Phil\RedirectsBundle\Application\Redirects;
use Phil\RedirectsBundle\Application\RedirectTableCache;
use Phil\RedirectsBundle\Application\StoredRedirects;
use Phil\RedirectsBundle\Application\TargetPolicy;
use Phil\RedirectsBundle\Domain\Contract\NotFoundLogRepository;
use Phil\RedirectsBundle\Domain\Contract\RedirectRepository;
use Phil\RedirectsBundle\Domain\Enum\RedirectStatus;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Phil\RedirectsBundle\Infrastructure\BotDetection\RobotVerdictFilter;
use Phil\RedirectsBundle\Infrastructure\Console\CheckRedirectsCommand;
use Phil\RedirectsBundle\Infrastructure\Console\ExportRedirectsCommand;
use Phil\RedirectsBundle\Infrastructure\Console\ImportRedirectsCommand;
use Phil\RedirectsBundle\Infrastructure\Console\PurgeNotFoundCommand;
use Phil\RedirectsBundle\Infrastructure\Doctrine\DbalNotFoundLog;
use Phil\RedirectsBundle\Infrastructure\Doctrine\DbalRedirectRepository;
use Phil\RedirectsBundle\Infrastructure\Doctrine\RedirectSchemaListener;
use Phil\RedirectsBundle\Infrastructure\Http\AdminGuard;
use Phil\RedirectsBundle\Infrastructure\Http\NotFoundAdminController;
use Phil\RedirectsBundle\Infrastructure\Http\NotFoundListener;
use Phil\RedirectsBundle\Infrastructure\Http\OldDomainListener;
use Phil\RedirectsBundle\Infrastructure\Http\RedirectAdminController;
use Phil\RedirectsBundle\Infrastructure\Http\TransferAdminController;
use Phil\RedirectsBundle\PhilRedirectsBundle;
use Phil\RedirectsBundle\Tests\Unit\Fixture\ListProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The wiring the bundle declares, read on the `ContainerBuilder`, and a compiled container whose services run.
 */
#[CoversClass(PhilRedirectsBundle::class)]
final class PhilRedirectsBundleTest extends TestCase
{
    public function testTheExtensionAliasIsPhilRedirects(): void
    {
        self::assertSame('phil_redirects', new PhilRedirectsBundle()->getContainerExtension()?->getAlias());
    }

    public function testTheDefaults(): void
    {
        $container = $this->load([]);

        self::assertEquals([new Reference('doctrine.dbal.default_connection'), false], $container->getDefinition(DbalRedirectRepository::class)->getArguments());
        self::assertEquals([new Reference('doctrine.dbal.default_connection')], $container->getDefinition(DbalNotFoundLog::class)->getArguments());
        self::assertSame(DbalRedirectRepository::class, (string) $container->getAlias(RedirectRepository::class));
        self::assertTrue($container->getAlias(RedirectRepository::class)->isPublic());
        self::assertSame(DbalNotFoundLog::class, (string) $container->getAlias(NotFoundLogRepository::class));
        self::assertTrue($container->getAlias(NotFoundLogRepository::class)->isPublic());
        self::assertSame([['event' => 'postGenerateSchema', 'connection' => 'default']], $container->getDefinition(RedirectSchemaListener::class)->getTag('doctrine.event_listener'));
        self::assertSame(StoredRedirects::class, $container->getDefinition(PhilRedirectsBundle::STORED_PROVIDER)->getClass());
        self::assertEquals([new Reference(RedirectRepository::class)], $container->getDefinition(PhilRedirectsBundle::STORED_PROVIDER)->getArguments());

        $tables = $container->getDefinition(RedirectTableCache::class);
        self::assertEquals([[new Reference(PhilRedirectsBundle::STORED_PROVIDER)], false, null, RedirectTableCache::DEFAULT_KEY, null], $tables->getArguments());
        self::assertSame([['method' => 'reset']], $tables->getTag('kernel.reset'));
        self::assertTrue($tables->isPublic());
        self::assertTrue($container->getDefinition(RedirectResolver::class)->isPublic());
        self::assertTrue($container->getDefinition(Redirects::class)->isPublic());
        self::assertEquals([[], false], $container->getDefinition(TargetPolicy::class)->getArguments());
        self::assertFalse($container->getDefinition(LocationBuilder::class)->getArgument(1));
        self::assertEquals([new Reference(LocationBuilder::class), false, ChainInspector::DEFAULT_MAX_LENGTH], $container->getDefinition(ChainInspector::class)->getArguments());
        self::assertEquals([RedirectCsv::DEFAULT_MAX_LINES], $container->getDefinition(RedirectCsv::class)->getArguments());

        $redirects = $container->getDefinition(Redirects::class)->getArguments();
        self::assertEquals(new Reference('clock'), $redirects[4]);
        self::assertFalse($redirects[5]);
        self::assertSame(255, $redirects[6]);
        self::assertFalse($redirects[7]);

        $recorder = $container->getDefinition(NotFoundRecorder::class)->getArguments();
        self::assertEquals(new TaggedIteratorArgument(PhilRedirectsBundle::FILTER_TAG), $recorder[2]);
        self::assertSame(['/_wdt', '/_profiler', '/.well-known/'], $recorder[3]);
        self::assertSame([1024, 10000, false], [$recorder[4], $recorder[5], $recorder[6]]);
        self::assertEquals([[]], $container->getDefinition(DeclaredCrawlerFilter::class)->getArguments());
        self::assertTrue($container->getDefinition(DeclaredCrawlerFilter::class)->hasTag(PhilRedirectsBundle::FILTER_TAG));
        self::assertFalse($container->hasDefinition(RobotVerdictFilter::class));

        $listener = $container->getDefinition(NotFoundListener::class);
        self::assertEquals([
            new Reference(RedirectResolver::class),
            new Reference('clock'),
            new Reference(RedirectRepository::class),
            new Reference(NotFoundRecorder::class),
            60,
            null,
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ], $listener->getArguments());
        self::assertSame([
            ['event' => 'kernel.exception', 'method' => 'onKernelException'],
            ['event' => 'kernel.terminate', 'method' => 'onKernelTerminate'],
        ], $listener->getTag('kernel.event_listener'));
        self::assertSame([['method' => 'reset']], $listener->getTag('kernel.reset'));

        self::assertFalse($container->hasDefinition(OldDomainListener::class));
        self::assertFalse($container->hasDefinition(AdminGuard::class));
        self::assertEquals([new Reference(NotFoundLogRepository::class), new Reference('clock'), 90], $container->getDefinition(PurgeNotFoundCommand::class)->getArguments());

        foreach ([ImportRedirectsCommand::class, ExportRedirectsCommand::class, CheckRedirectsCommand::class, PurgeNotFoundCommand::class] as $command) {
            self::assertTrue($container->getDefinition($command)->hasTag('console.command'), $command);
        }

        self::assertEquals([new Reference(Redirects::class), new Reference(RedirectCsv::class)], $container->getDefinition(ImportRedirectsCommand::class)->getArguments());
        self::assertEquals([new Reference(RedirectRepository::class), new Reference(RedirectCsv::class)], $container->getDefinition(ExportRedirectsCommand::class)->getArguments());
        self::assertEquals([new Reference(Redirects::class)], $container->getDefinition(CheckRedirectsCommand::class)->getArguments());
    }

    public function testTheOptions(): void
    {
        $container = $this->load([
            'connection' => 'redirects',
            'providers' => ['app.provider', PhilRedirectsBundle::STORED_PROVIDER],
            'allowed_hosts' => ['partner.example'],
            'allow_localhost' => true,
            'regex' => ['max_length' => 100],
            'chains' => ['allowed' => true, 'max_length' => 5],
            'cache' => ['pool' => 'cache.app', 'key' => 'k', 'lifetime' => 300],
            'hits' => ['throttle' => 5],
            'not_found_log' => ['ignore_crawlers' => false, 'robot_verdict_header' => 'X-Verdict', 'ignored_paths' => ['/x'], 'retention' => 30, 'capacity' => 50, 'max_path_length' => 200, 'crawler_tokens' => ['a']],
            'csv' => ['max_lines' => 7],
            'clock' => 'app.clock',
        ]);

        self::assertEquals([new Reference('doctrine.dbal.redirects_connection'), true], $container->getDefinition(DbalRedirectRepository::class)->getArguments());
        self::assertSame([['event' => 'postGenerateSchema', 'connection' => 'redirects']], $container->getDefinition(RedirectSchemaListener::class)->getTag('doctrine.event_listener'));
        self::assertEquals([[new Reference('app.provider'), new Reference(PhilRedirectsBundle::STORED_PROVIDER)], true, new Reference('cache.app'), 'k', 300], $container->getDefinition(RedirectTableCache::class)->getArguments());
        self::assertEquals([['partner.example'], true], $container->getDefinition(TargetPolicy::class)->getArguments());
        self::assertTrue($container->getDefinition(LocationBuilder::class)->getArgument(1));
        self::assertSame([true, 5], \array_slice($container->getDefinition(ChainInspector::class)->getArguments(), 1));
        self::assertSame([true, 100, true], \array_slice($container->getDefinition(Redirects::class)->getArguments(), 5));
        self::assertEquals(new Reference('app.clock'), $container->getDefinition(Redirects::class)->getArgument(4));
        self::assertEquals([7], $container->getDefinition(RedirectCsv::class)->getArguments());
        self::assertFalse($container->hasDefinition(DeclaredCrawlerFilter::class));
        self::assertTrue($container->getDefinition(RobotVerdictFilter::class)->hasTag(PhilRedirectsBundle::FILTER_TAG));
        self::assertSame([['/x'], 200, 50, true], \array_slice($container->getDefinition(NotFoundRecorder::class)->getArguments(), 3));
        self::assertSame([5, 'X-Verdict'], \array_slice($container->getDefinition(NotFoundListener::class)->getArguments(), 4, 2));
        self::assertSame(30, $container->getDefinition(PurgeNotFoundCommand::class)->getArgument(2));
    }

    public function testTheCrawlerWordsOfTheApplication(): void
    {
        self::assertSame([['3', 'example-audit']], $this->load(['not_found_log' => ['crawler_tokens' => ['3', 'example-audit']]])->getDefinition(DeclaredCrawlerFilter::class)->getArguments());
        self::assertSame([['example-audit']], $this->load(['not_found_log' => ['crawler_tokens' => [3, 'example-audit']]])->getDefinition(DeclaredCrawlerFilter::class)->getArguments());
    }

    public function testAFilterOfTheApplicationIsTaggedByItsInterface(): void
    {
        $instanceof = $this->load([])->getAutoconfiguredInstanceof();

        self::assertArrayHasKey(NotFoundFilter::class, $instanceof);
        self::assertSame([PhilRedirectsBundle::FILTER_TAG => [[]]], $instanceof[NotFoundFilter::class]->getTags());
    }

    public function testTheWiringOfTheSchemaAndTheLocations(): void
    {
        $container = $this->load([]);

        self::assertEquals([new Reference('doctrine.dbal.default_connection')], $container->getDefinition(RedirectSchemaListener::class)->getArguments());
        self::assertEquals([new Reference('router', ContainerInterface::NULL_ON_INVALID_REFERENCE), false], $container->getDefinition(LocationBuilder::class)->getArguments());
        self::assertEquals([new Reference(RedirectTableCache::class), new Reference(LocationBuilder::class)], $container->getDefinition(RedirectResolver::class)->getArguments());
    }

    /**
     * @return iterable<string, array{list<string>, int, ?int}>
     */
    public static function bounds(): iterable
    {
        yield 'regex length' => [['regex', 'max_length'], 1, 1000];
        yield 'chain length' => [['chains', 'max_length'], 1, 10];
        yield 'cache lifetime' => [['cache', 'lifetime'], 1, null];
        yield 'hit throttle' => [['hits', 'throttle'], 0, null];
        yield 'retention' => [['not_found_log', 'retention'], 0, null];
        yield 'capacity' => [['not_found_log', 'capacity'], 1, null];
        yield 'path length' => [['not_found_log', 'max_path_length'], 1, 2048];
        yield 'lines' => [['csv', 'max_lines'], 1, null];
        yield 'upload' => [['admin', 'import_max_bytes'], 1, null];
    }

    /**
     * @param list<string> $path
     */
    #[DataProvider('bounds')]
    public function testTheBoundsOfTheNumbers(array $path, int $min, ?int $max): void
    {
        $config = static fn (int $value): array => [$path[0] => [$path[1] => $value]];
        $this->load($config($min));

        if (null !== $max) {
            $this->load($config($max));
            $this->assertRefusedConfiguration($config($max + 1));
        }

        $this->assertRefusedConfiguration($config($min - 1));
    }

    public function testTheHitsAndTheLogTurnedOff(): void
    {
        $container = $this->load(['hits' => false, 'not_found_log' => false]);
        $arguments = $container->getDefinition(NotFoundListener::class)->getArguments();

        self::assertNull($arguments[2]);
        self::assertNull($arguments[3]);
        self::assertNull($arguments[5]);
        self::assertFalse($container->hasDefinition(NotFoundRecorder::class));
        self::assertFalse($container->hasDefinition(DeclaredCrawlerFilter::class));
        self::assertNull($this->load(['not_found_log' => ['enabled' => false, 'robot_verdict_header' => 'X']])->getDefinition(NotFoundListener::class)->getArgument(5));
    }

    public function testTheOldDomainsAreReadBeforeTheRouter(): void
    {
        $container = $this->load(['old_domains' => [
            'Old.Example' => ['host' => 'new.example'],
            'older.example' => ['host' => 'new.example', 'status' => 302, 'keep_path' => false, 'keep_query' => false, 'scheme' => 'https', 'base_path' => '/fr'],
        ]]);

        self::assertEquals(['new.example', RedirectStatus::MovedPermanently, true, true, null, ''], $container->getDefinition('phil_redirects.old_domain.old.example')->getArguments());
        self::assertEquals(['new.example', RedirectStatus::Found, false, false, 'https', '/fr'], $container->getDefinition('phil_redirects.old_domain.older.example')->getArguments());
        self::assertSame(OldDomain::class, $container->getDefinition('phil_redirects.old_domain.older.example')->getClass());
        self::assertEquals([[
            'old.example' => new Reference('phil_redirects.old_domain.old.example'),
            'older.example' => new Reference('phil_redirects.old_domain.older.example'),
        ]], $container->getDefinition(OldDomains::class)->getArguments());
        self::assertEquals([new Reference(OldDomains::class)], $container->getDefinition(OldDomainListener::class)->getArguments());
        self::assertSame([['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 40]], $container->getDefinition(OldDomainListener::class)->getTag('kernel.event_listener'));
    }

    public function testABadOldDomainFailsTheBuild(): void
    {
        foreach ([
            ['bad host' => ['host' => 'new.example']],
            ['old.example' => ['host' => 'new example']],
            ['old.example' => ['host' => 'new.example', 'status' => 410]],
            ['old.example' => ['host' => 'new.example', 'status' => 200]],
            ['old.example' => ['host' => 'new.example', 'scheme' => 'ftp']],
        ] as $domains) {
            try {
                $this->load(['old_domains' => $domains]);
                self::fail('Expected a bad configuration');
            } catch (InvalidConfigurationException $invalidConfigurationException) {
                self::assertStringStartsWith('phil_redirects.old_domains.'.array_key_first($domains).': ', $invalidConfigurationException->getMessage());
                self::assertSame(0, $invalidConfigurationException->getCode());
                self::assertInstanceOf(InvalidRedirect::class, $invalidConfigurationException->getPrevious());
            }
        }
    }

    public function testTheAdministration(): void
    {
        $container = $this->load(['admin' => ['enabled' => true, 'role' => 'ROLE_EDITOR', 'regex_role' => 'ROLE_REGEX', 'layout' => 'admin.html.twig', 'import_max_bytes' => 10], 'clock' => 'app.clock']);

        self::assertEquals([
            new Reference('security.authorization_checker'),
            new Reference('security.csrf.token_manager'),
            new Reference('twig'),
            new Reference('router'),
            'ROLE_EDITOR',
            'ROLE_REGEX',
            'admin.html.twig',
        ], $container->getDefinition(AdminGuard::class)->getArguments());
        self::assertEquals([new Reference(AdminGuard::class), new Reference(Redirects::class), new Reference(RedirectRepository::class), new Reference(NotFoundLogRepository::class)], $container->getDefinition(RedirectAdminController::class)->getArguments());
        self::assertEquals([new Reference(AdminGuard::class), new Reference(NotFoundLogRepository::class)], $container->getDefinition(NotFoundAdminController::class)->getArguments());
        self::assertEquals([new Reference(AdminGuard::class), new Reference(Redirects::class), new Reference(RedirectRepository::class), new Reference(RedirectCsv::class), new Reference('app.clock'), 10], $container->getDefinition(TransferAdminController::class)->getArguments());

        foreach ([RedirectAdminController::class, NotFoundAdminController::class, TransferAdminController::class] as $controller) {
            self::assertTrue($container->getDefinition($controller)->isPublic(), $controller);
            self::assertTrue($container->getDefinition($controller)->hasTag('controller.service_arguments'), $controller);
        }

        $defaults = $this->load(['admin' => true, 'not_found_log' => false])->getDefinition(AdminGuard::class)->getArguments();
        self::assertSame(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', PhilRedirectsBundle::DEFAULT_LAYOUT], \array_slice($defaults, 4));
        self::assertEquals(new Reference(NotFoundLogRepository::class), $this->load(['admin' => true, 'not_found_log' => false])->getDefinition(RedirectAdminController::class)->getArgument(3));
        self::assertSame(TransferAdminController::DEFAULT_MAX_BYTES, $this->load(['admin' => true])->getDefinition(TransferAdminController::class)->getArgument(5));
    }

    public function testAStringIsRequiredWhereOneIsExpected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('phil_redirects: connection must be a string.');

        $this->load(['connection' => 3]);
    }

    public function testTheCompiledServicesRun(): void
    {
        $container = $this->load(['providers' => ['app.provider'], 'not_found_log' => false]);
        $container->register('doctrine.dbal.default_connection')->setSynthetic(true);
        $container->register('clock')->setSynthetic(true);
        $container->register('app.provider')->setSynthetic(true);
        $container->compile(true);
        $container->set('doctrine.dbal.default_connection', DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $container->set('clock', new MockClock());
        $container->set('app.provider', new ListProvider());

        self::assertInstanceOf(RedirectResolver::class, $container->get(RedirectResolver::class));
        self::assertNull($container->get(RedirectResolver::class)->resolve('h.example', '/a'));
        self::assertInstanceOf(Redirects::class, $container->get(Redirects::class));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function assertRefusedConfiguration(array $config): void
    {
        try {
            $this->load($config);
        } catch (InvalidConfigurationException) {
            return;
        }

        self::fail('Expected a refused configuration');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $extension = new PhilRedirectsBundle()->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        return $container;
    }
}
