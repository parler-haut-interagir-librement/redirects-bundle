<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Infrastructure\Http;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * What every page of the administration shares: the role that opens it, the role that may write a regular
 * expression, the CSRF token of its forms, its layout, and the `303` that follows a change.
 *
 * A user without the role meets the firewall, as on any denied page; a form without a valid token is refused.
 */
final readonly class AdminGuard
{
    public const string CSRF_TOKEN_ID = 'phil_redirects_admin';

    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private CsrfTokenManagerInterface $csrfTokens,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private string $role,
        private string $regexRole,
        private string $layout,
    ) {
    }

    public function denyUnlessGranted(): void
    {
        if (!$this->authorizationChecker->isGranted($this->role)) {
            $exception = new AccessDeniedException();
            $exception->setAttributes([$this->role]);

            throw $exception;
        }
    }

    /**
     * Whether the user may write a regular expression, on top of the role of the administration.
     */
    public function mayWriteRegex(): bool
    {
        return $this->authorizationChecker->isGranted($this->regexRole);
    }

    /**
     * The role, then the token of a posted form.
     */
    public function guardForm(Request $request): void
    {
        $this->denyUnlessGranted();

        if (!$this->csrfTokens->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_token')))) {
            throw new BadRequestHttpException('The CSRF token is not valid.');
        }
    }

    /**
     * The search of a list, trimmed and kept to 200 characters.
     */
    public function search(Request $request): string
    {
        return mb_substr(trim($request->query->getString('q')), 0, 200);
    }

    /**
     * The page of a list, the first for anything else than a whole number from 1.
     */
    public function page(Request $request): int
    {
        return max(1, $request->query->getInt('page'));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context = [], int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render('@PhilRedirects/admin/'.$template.'.html.twig', $context + [
            'layout' => $this->layout,
            'csrf_token' => $this->csrfTokens->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]), $status, ['Cache-Control' => 'no-store']);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    public function seeOther(string $route, array $parameters = []): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate($route, $parameters), Response::HTTP_SEE_OTHER);
    }
}
