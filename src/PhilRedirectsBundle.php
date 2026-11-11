<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle;

use Override;
use Phil\BotDetectionBundle\Domain\Verdict\Verdict;
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
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
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
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\AbstractBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ReferenceConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Redirects for a Symfony application, wired from its configuration:
 *
 * ```yaml
 * phil_redirects:
 *     allowed_hosts: [www.example.com, shop.example.com]
 *     cache: { pool: cache.app }
 *     old_domains:
 *         old-example.com: { host: www.example.com }
 *     admin: { enabled: true, role: ROLE_EDITOR }
 * ```
 *
 * The table of redirects is read from the providers in their order — the redirects of the administration, kept in
 * `phil_redirect`, by default —, kept for the request and in the pool, and resolved only when a request was not found.
 * The old domains are read before the routing. The paths not found go to `phil_redirect_not_found`, without visitor
 * data. The administration, the commands and the robot verdict are wired when their packages are installed.
 */
final class PhilRedirectsBundle extends AbstractBundle
{
    public const string STORED_PROVIDER = 'phil_redirects.provider.stored';

    public const string FILTER_TAG = 'phil_redirects.not_found_filter';

    public const string DEFAULT_LAYOUT = '@PhilRedirects/admin/layout.html.twig';

    #[Override]
    protected string $extensionAlias = 'phil_redirects';

    #[Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('connection')->info('The DBAL connection of the two tables.')->defaultValue('default')->cannotBeEmpty()->end()
                ->arrayNode('providers')
                    ->info(\sprintf('Service ids of the redirect providers, in order: when two give the same source, the first one wins. Defaults to "%s", the redirects of the administration.', self::STORED_PROVIDER))
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->arrayNode('allowed_hosts')
                    ->info('The hosts an absolute target URL may send to; none by default, so that no redirect leaves the site.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->booleanNode('allow_localhost')
                    ->info('Whether a target, an allowed host and a referrer may be on localhost or under .localhost: true in development and tests only.')
                    ->defaultFalse()
                ->end()
                ->arrayNode('regex')
                    ->info('Sources written as regular expressions: off by default, and only for the users of admin.regex_role.')
                    ->canBeEnabled()
                    ->children()
                        ->integerNode('max_length')->min(1)->max(1000)->defaultValue(SourcePattern::DEFAULT_REGEX_MAX_LENGTH)->end()
                    ->end()
                ->end()
                ->arrayNode('chains')
                    ->info('A redirect whose target is itself redirected: refused unless allowed, then up to a length. A loop is always refused.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('allowed')->defaultFalse()->end()
                        ->integerNode('max_length')->min(1)->max(10)->defaultValue(ChainInspector::DEFAULT_MAX_LENGTH)->end()
                    ->end()
                ->end()
                ->arrayNode('cache')
                    ->info('The PSR-6 pool that keeps the table between requests; without one, it is built on the first request not found of each process.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('pool')->defaultNull()->end()
                        ->scalarNode('key')->defaultValue(RedirectTableCache::DEFAULT_KEY)->cannotBeEmpty()->end()
                        ->integerNode('lifetime')->info('Seconds; none keeps the table until a change invalidates it.')->min(1)->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('hits')
                    ->info('The count of the requests each redirect answered, written after the response.')
                    ->canBeDisabled()
                    ->children()
                        ->integerNode('throttle')->info('Seconds between two writes of the last hit of a redirect.')->min(0)->defaultValue(60)->end()
                    ->end()
                ->end()
                ->arrayNode('not_found_log')
                    ->info('The log of the paths not found, aggregated by host and path, without visitor data.')
                    ->canBeDisabled()
                    ->children()
                        ->arrayNode('ignored_paths')->info('Prefixes of paths never logged.')->scalarPrototype()->end()->defaultValue(['/_wdt', '/_profiler', '/.well-known/'])->end()
                        ->booleanNode('ignore_crawlers')->info('Leaves out a user agent that declares itself a crawler, a script or nothing.')->defaultTrue()->end()
                        ->arrayNode('crawler_tokens')->info('More words of a user agent to leave out.')->scalarPrototype()->end()->end()
                        ->scalarNode('robot_verdict_header')->info('The request header carrying the robot verdict of phil/bot-detection-bundle, when it is installed.')->defaultNull()->end()
                        ->integerNode('retention')->info('Days without a request after which phil:redirects:purge-404 removes a path.')->min(0)->defaultValue(90)->end()
                        ->integerNode('capacity')->info('Entries at most; a new path is not logged beyond.')->min(1)->defaultValue(NotFoundRecorder::DEFAULT_CAPACITY)->end()
                        ->integerNode('max_path_length')->min(1)->max(SourcePattern::MAX_PATH_LENGTH)->defaultValue(NotFoundRecorder::DEFAULT_MAX_PATH_LENGTH)->end()
                    ->end()
                ->end()
                ->arrayNode('old_domains')
                    ->info('Old domains mapped to a new host before the routing, by old host.')
                    ->useAttributeAsKey('domain')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('host')->isRequired()->cannotBeEmpty()->end()
                            ->integerNode('status')->defaultValue(301)->end()
                            ->booleanNode('keep_path')->defaultTrue()->end()
                            ->booleanNode('keep_query')->defaultTrue()->end()
                            ->scalarNode('scheme')->defaultNull()->end()
                            ->scalarNode('base_path')->defaultValue('')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('csv')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_lines')->min(1)->defaultValue(RedirectCsv::DEFAULT_MAX_LINES)->end()
                    ->end()
                ->end()
                ->arrayNode('admin')
                    ->info('The administration, mounted from the routes of the bundle behind the firewall; it needs Twig, the translator, the security and its CSRF tokens.')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('role')->defaultValue('ROLE_ADMIN')->cannotBeEmpty()->end()
                        ->scalarNode('regex_role')->info('The role that may write a regular expression, on top of the role.')->defaultValue('ROLE_SUPER_ADMIN')->cannotBeEmpty()->end()
                        ->scalarNode('layout')->info('The Twig layout the pages extend; it defines the "title" and "content" blocks.')->defaultValue(self::DEFAULT_LAYOUT)->cannotBeEmpty()->end()
                        ->integerNode('import_max_bytes')->min(1)->defaultValue(TransferAdminController::DEFAULT_MAX_BYTES)->end()
                    ->end()
                ->end()
                ->scalarNode('clock')->info('Service id of the PSR-20 clock.')->defaultValue('clock')->cannotBeEmpty()->end()
            ->end();
    }

    /**
     * @param array<array-key, mixed> $config
     */
    #[Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->registerForAutoconfiguration(NotFoundFilter::class)->addTag(self::FILTER_TAG);
        $services = $container->services();
        $connection = $this->string($config, 'connection');
        $dbal = service(\sprintf('doctrine.dbal.%s_connection', $connection));
        $clock = service($this->string($config, 'clock'));
        $regex = $this->section($config, 'regex');
        $chains = $this->section($config, 'chains');
        $cache = $this->section($config, 'cache');
        $hits = $this->section($config, 'hits');
        $log = $this->section($config, 'not_found_log');
        $logEnabled = $this->bool($log, 'enabled');
        $localhost = $this->bool($config, 'allow_localhost');

        $services->set(DbalRedirectRepository::class)->args([$dbal, $localhost]);
        $services->alias(RedirectRepository::class, DbalRedirectRepository::class)->public();
        $services->set(DbalNotFoundLog::class)->args([$dbal]);
        $services->alias(NotFoundLogRepository::class, DbalNotFoundLog::class)->public();
        $services->set(RedirectSchemaListener::class)
            ->args([$dbal])
            ->tag('doctrine.event_listener', ['event' => 'postGenerateSchema', 'connection' => $connection]);

        $services->set(self::STORED_PROVIDER, StoredRedirects::class)->args([service(RedirectRepository::class)]);
        $providers = $this->strings($config, 'providers');

        // Kept for the request and dropped by `kernel.reset`: a worker never serves a request with another's table.
        $services->set(RedirectTableCache::class)
            ->args([
                array_map(service(...), [] === $providers ? [self::STORED_PROVIDER] : $providers),
                $this->bool($regex, 'enabled'),
                null === $cache['pool'] ? null : service($this->string($cache, 'pool')),
                $this->string($cache, 'key'),
                $cache['lifetime'],
            ])
            ->tag('kernel.reset', ['method' => 'reset'])
            ->public();

        $services->set(LocationBuilder::class)->args([service('router')->nullOnInvalid(), $localhost]);
        $services->set(RedirectResolver::class)->args([service(RedirectTableCache::class), service(LocationBuilder::class)])->public();
        $services->set(TargetPolicy::class)->args([$this->strings($config, 'allowed_hosts'), $localhost]);
        $services->set(ChainInspector::class)->args([service(LocationBuilder::class), $this->bool($chains, 'allowed'), $chains['max_length']]);
        $services->set(Redirects::class)
            ->args([
                service(RedirectRepository::class),
                service(RedirectTableCache::class),
                service(ChainInspector::class),
                service(TargetPolicy::class),
                $clock,
                $this->bool($regex, 'enabled'),
                $regex['max_length'],
                $localhost,
            ])
            ->public();
        $services->set(RedirectCsv::class)->args([$this->section($config, 'csv')['max_lines']]);

        if ($logEnabled) {
            $this->loadLog($log, $container, $clock, $localhost);
        }

        $services->set(NotFoundListener::class)
            ->args([
                service(RedirectResolver::class),
                $clock,
                $this->bool($hits, 'enabled') ? service(RedirectRepository::class) : null,
                $logEnabled ? service(NotFoundRecorder::class) : null,
                $hits['throttle'],
                $logEnabled ? $log['robot_verdict_header'] : null,
                service('logger')->nullOnInvalid(),
            ])
            ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException'])
            ->tag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onKernelTerminate'])
            ->tag('kernel.reset', ['method' => 'reset']);

        $this->loadOldDomains($this->section($config, 'old_domains'), $container);

        if (class_exists(Command::class)) {
            $services->set(ImportRedirectsCommand::class)->args([service(Redirects::class), service(RedirectCsv::class)])->tag('console.command');
            $services->set(ExportRedirectsCommand::class)->args([service(RedirectRepository::class), service(RedirectCsv::class)])->tag('console.command');
            $services->set(CheckRedirectsCommand::class)->args([service(Redirects::class)])->tag('console.command');
            $services->set(PurgeNotFoundCommand::class)->args([service(NotFoundLogRepository::class), $clock, $log['retention']])->tag('console.command');
        }

        $this->loadAdmin($this->section($config, 'admin'), $container, $clock);
    }

    /**
     * @param array<array-key, mixed> $log
     */
    private function loadLog(array $log, ContainerConfigurator $container, ReferenceConfigurator $clock, bool $allowLocalhost): void
    {
        $services = $container->services();

        if ($this->bool($log, 'ignore_crawlers')) {
            $services->set(DeclaredCrawlerFilter::class)->args([$this->strings($log, 'crawler_tokens')])->tag(self::FILTER_TAG);
        }

        if (null !== $log['robot_verdict_header'] && enum_exists(Verdict::class)) {
            $services->set(RobotVerdictFilter::class)->tag(self::FILTER_TAG);
        }

        $services->set(NotFoundRecorder::class)->args([
            service(NotFoundLogRepository::class),
            $clock,
            new TaggedIteratorArgument(self::FILTER_TAG),
            $this->strings($log, 'ignored_paths'),
            $log['max_path_length'],
            $log['capacity'],
            $allowLocalhost,
        ]);
    }

    /**
     * Each old domain built here, so that a bad one fails the container build rather than the first request.
     *
     * @param array<array-key, mixed> $domains
     */
    private function loadOldDomains(array $domains, ContainerConfigurator $container): void
    {
        if ([] === $domains) {
            return;
        }

        $services = $container->services();
        $mapped = [];

        foreach ($domains as $old => $domain) {
            $domain = $this->section($domains, (string) $old);

            // An integer node and a scalar node of the configuration tree.
            /** @var int $status */
            $status = $domain['status'];
            /** @var string|null $scheme */
            $scheme = $domain['scheme'];

            try {
                $host = SourcePattern::hostName((string) $old);
                $arguments = [
                    $this->string($domain, 'host'),
                    RedirectStatus::tryFrom($status) ?? throw InvalidRedirect::because('old_domain_status', 'An old domain redirects with 301, 302, 307 or 308.'),
                    $this->bool($domain, 'keep_path'),
                    $this->bool($domain, 'keep_query'),
                    $scheme,
                    $this->string($domain, 'base_path'),
                ];
                new OldDomain(...$arguments);
            } catch (InvalidRedirect $invalidRedirect) {
                throw new InvalidConfigurationException(\sprintf('phil_redirects.old_domains.%s: %s', $old, $invalidRedirect->getMessage()), $invalidRedirect->getCode(), previous: $invalidRedirect);
            }

            $services->set('phil_redirects.old_domain.'.$host, OldDomain::class)->args($arguments);
            $mapped[$host] = service('phil_redirects.old_domain.'.$host);
        }

        $services->set(OldDomains::class)->args([$mapped]);

        // Before the router's listener, so that no route of the new site answers for the old domain.
        $services->set(OldDomainListener::class)
            ->args([service(OldDomains::class)])
            ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 40]);
    }

    /**
     * @param array<array-key, mixed> $admin
     */
    private function loadAdmin(array $admin, ContainerConfigurator $container, ReferenceConfigurator $clock): void
    {
        if (!$this->bool($admin, 'enabled')) {
            return;
        }

        $services = $container->services();
        $services->set(AdminGuard::class)->args([
            service('security.authorization_checker'),
            service('security.csrf.token_manager'),
            service('twig'),
            service('router'),
            $this->string($admin, 'role'),
            $this->string($admin, 'regex_role'),
            $this->string($admin, 'layout'),
        ]);

        $services->set(RedirectAdminController::class)
            ->args([service(AdminGuard::class), service(Redirects::class), service(RedirectRepository::class), service(NotFoundLogRepository::class)])
            ->public()
            ->tag('controller.service_arguments');
        $services->set(NotFoundAdminController::class)
            ->args([service(AdminGuard::class), service(NotFoundLogRepository::class)])
            ->public()
            ->tag('controller.service_arguments');
        $services->set(TransferAdminController::class)
            ->args([service(AdminGuard::class), service(Redirects::class), service(RedirectRepository::class), service(RedirectCsv::class), $clock, $admin['import_max_bytes']])
            ->public()
            ->tag('controller.service_arguments');
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private function section(array $config, string $key): array
    {
        return \is_array($config[$key] ?? null) ? $config[$key] : [];
    }

    /**
     * A scalar node also accepts a number or a boolean; a service id, a host or a role is only ever a string.
     *
     * @param array<array-key, mixed> $config
     */
    private function string(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (!\is_string($value)) {
            throw new InvalidConfigurationException(\sprintf('phil_redirects: %s must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function bool(array $config, string $key): bool
    {
        return true === ($config[$key] ?? null);
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return list<string>
     */
    private function strings(array $config, string $key): array
    {
        return array_values(array_filter($this->section($config, $key), is_string(...)));
    }
}
