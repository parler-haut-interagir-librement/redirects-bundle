<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Fixture;

use Phil\RedirectsBundle\Application\ChainInspector;
use Phil\RedirectsBundle\Application\Csv\RedirectCsv;
use Phil\RedirectsBundle\Application\LocationBuilder;
use Phil\RedirectsBundle\Application\Redirects;
use Phil\RedirectsBundle\Application\RedirectTableCache;
use Phil\RedirectsBundle\Application\StoredRedirects;
use Phil\RedirectsBundle\Application\TargetPolicy;
use Phil\RedirectsBundle\Infrastructure\Http\AdminGuard;
use Phil\RedirectsBundle\Infrastructure\Http\NotFoundAdminController;
use Phil\RedirectsBundle\Infrastructure\Http\RedirectAdminController;
use Phil\RedirectsBundle\Infrastructure\Http\TransferAdminController;
use Phil\RedirectsBundle\PhilRedirectsBundle;
use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Loader\PhpFileLoader as RoutesLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Translation\Loader\PhpFileLoader as MessagesLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The administration without a kernel: its controllers on repositories in memory, rendered by Twig with the bundle's
 * templates, routes and translations, for a user with the roles given.
 */
final readonly class AdminKit
{
    public InMemoryRedirects $repository;

    public InMemoryNotFoundLog $log;

    public CsrfTokenManager $csrf;

    public MockClock $clock;

    public Redirects $redirects;

    public Translator $translator;

    private Environment $twig;

    private UrlGenerator $urls;

    public function __construct(bool $regex = true, string $locale = 'en')
    {
        $root = \dirname(__DIR__, 3);
        $this->repository = new InMemoryRedirects();
        $this->log = new InMemoryNotFoundLog();
        $this->csrf = new CsrfTokenManager(null, new ArrayTokenStorage());
        $this->clock = new MockClock('2026-05-01 10:00:00');
        $this->urls = new UrlGenerator(new RoutesLoader(new FileLocator($root.'/config/routes'))->load('admin.php'), new RequestContext());
        $this->translator = new Translator($locale);
        $this->translator->addLoader('php', new MessagesLoader());
        $this->translator->addResource('php', $root.'/translations/phil_redirects.en.php', 'en', 'phil_redirects');
        $this->translator->addResource('php', $root.'/translations/phil_redirects.fr.php', 'fr', 'phil_redirects');

        $loader = new FilesystemLoader();
        $loader->addPath($root.'/templates', 'PhilRedirects');

        $this->twig = new Environment($loader, ['strict_variables' => true]);
        $this->twig->addExtension(new RoutingExtension($this->urls));
        $this->twig->addExtension(new TranslationExtension($this->translator));

        $this->redirects = new Redirects(
            $this->repository,
            new RedirectTableCache([new StoredRedirects($this->repository)], $regex),
            new ChainInspector(new LocationBuilder()),
            new TargetPolicy(['partner.example']),
            $this->clock,
            $regex,
            20,
        );
    }

    /**
     * @param list<string> $roles
     */
    public function guard(array $roles = ['ROLE_EDITOR']): AdminGuard
    {
        return new AdminGuard(new RoleChecker(null, $roles), $this->csrf, $this->twig, $this->urls, 'ROLE_EDITOR', 'ROLE_REGEX', PhilRedirectsBundle::DEFAULT_LAYOUT);
    }

    /**
     * @param list<string> $roles
     */
    public function redirectsController(array $roles = ['ROLE_EDITOR']): RedirectAdminController
    {
        return new RedirectAdminController($this->guard($roles), $this->redirects, $this->repository, $this->log);
    }

    /**
     * @param list<string> $roles
     */
    public function notFoundController(array $roles = ['ROLE_EDITOR']): NotFoundAdminController
    {
        return new NotFoundAdminController($this->guard($roles), $this->log);
    }

    /**
     * @param list<string> $roles
     */
    public function transferController(array $roles = ['ROLE_EDITOR'], int $maxBytes = 1000): TransferAdminController
    {
        return new TransferAdminController($this->guard($roles), $this->redirects, $this->repository, new RedirectCsv(), $this->clock, $maxBytes);
    }

    /**
     * A form posted with a valid token, or the token given.
     *
     * @param array<string, string> $form
     */
    public function post(string $uri, array $form = [], ?string $token = null): Request
    {
        return Request::create($uri, 'POST', $form + ['_token' => $token ?? $this->csrf->getToken(AdminGuard::CSRF_TOKEN_ID)->getValue()]);
    }
}
