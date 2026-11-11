<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Functional\App;

use Override;
use Phil\RedirectsBundle\Tests\Unit\Fixture\ArrayTokenStorage;
use Phil\RedirectsBundle\Tests\Unit\Fixture\RoleChecker;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Csrf\CsrfTokenManager;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * An application as small as the bundle needs: FrameworkBundle, DoctrineBundle on SQLite — or on the server
 * `PHIL_REDIRECTS_TEST_DATABASE_URL` names —, TwigBundle with the translator, the administration under
 * `/admin/redirects`, one page of its own at `/page/{slug}`, an old domain and an allowed external host. The roles of
 * the user come from the `X-Roles` header, and the CSRF tokens are kept in memory.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public static function databaseUrl(): string
    {
        $url = getenv('PHIL_REDIRECTS_TEST_DATABASE_URL');

        return \is_string($url) && '' !== $url ? $url : 'sqlite:///%kernel.cache_dir%/redirects.sqlite';
    }

    #[Override]
    public function getProjectDir(): string
    {
        return __DIR__;
    }

    #[Override]
    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/phil-redirects-bundle-test/'.hash('xxh128', __DIR__.self::databaseUrl()).'/cache';
    }

    #[Override]
    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/phil-redirects-bundle-test/'.hash('xxh128', __DIR__.self::databaseUrl()).'/log';
    }

    public function page(string $slug): Response
    {
        return new Response('page '.$slug);
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
            'default_locale' => 'en',
            'translator' => ['default_path' => __DIR__.'/translations', 'fallbacks' => ['en']],
        ]);

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set('security.authorization_checker', RoleChecker::class)->args([service('request_stack')])->public();
        $services->set(ArrayTokenStorage::class);
        $services->set('security.csrf.token_manager', CsrfTokenManager::class)->args([null, service(ArrayTokenStorage::class)])->public();

        $container->extension('doctrine', [
            'dbal' => ['url' => self::databaseUrl()],
            'orm' => [],
        ]);

        $container->extension('twig', ['strict_variables' => true]);

        $container->extension('phil_redirects', [
            'allowed_hosts' => ['partner.example'],
            'regex' => true,
            'old_domains' => ['old.example' => ['host' => 'new.example', 'base_path' => '/en']],
            'admin' => ['enabled' => true, 'role' => 'ROLE_EDITOR'],
            'not_found_log' => ['robot_verdict_header' => 'X-Robot-Verdict'],
        ]);
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@PhilRedirectsBundle/config/routes/admin.php')->prefix('/admin/redirects');
        $routes->add('page', '/page/{slug}')->controller($this->page(...));
    }
}
