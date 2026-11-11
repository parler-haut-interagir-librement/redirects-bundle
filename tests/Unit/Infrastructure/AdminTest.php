<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Phil\RedirectsBundle\Domain\ValueObject\NotFoundEntry;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use Phil\RedirectsBundle\Infrastructure\Http\AdminGuard;
use Phil\RedirectsBundle\Infrastructure\Http\NotFoundAdminController;
use Phil\RedirectsBundle\Infrastructure\Http\RedirectAdminController;
use Phil\RedirectsBundle\Tests\Unit\Fixture\AdminKit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;

#[CoversClass(AdminGuard::class)]
#[CoversClass(RedirectAdminController::class)]
#[CoversClass(NotFoundAdminController::class)]
final class AdminTest extends TestCase
{
    public function testTheListShowsTheRedirectsAndTheOutcome(): void
    {
        $kit = new AdminKit();
        $kit->redirects->create(new RedirectRule(SourcePattern::exact('/alpha', 'h.example'), Target::url('/one'), locale: 'fr'));
        $kit->redirects->create(new RedirectRule(SourcePattern::exact('/beta'), Target::url('/two')), false);

        $response = $kit->redirectsController()->list(Request::create('/?done=created'));
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('<code>/alpha</code>', $html);
        self::assertStringContainsString('h.example', $html);
        self::assertStringContainsString('· fr', $html);
        self::assertStringContainsString('The redirect was created.', $html);
        self::assertStringContainsString('disabled', $html);
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $html, $token));
        self::assertTrue($kit->csrf->isTokenValid(new CsrfToken(AdminGuard::CSRF_TOKEN_ID, $token[1])));

        $searched = (string) $kit->redirectsController()->list(Request::create('/?q=+bet&done=hacked'))->getContent();
        self::assertStringNotContainsString('/alpha', $searched);
        self::assertStringContainsString('/beta', $searched);
        self::assertStringContainsString('value="bet"', $searched);
        self::assertStringNotContainsString('hacked', $searched);
        self::assertStringNotContainsString('role="status"', $searched);
    }

    public function testTheListIsPaged(): void
    {
        $kit = new AdminKit();

        for ($index = 0; $index < RedirectAdminController::PAGE_SIZE + 1; ++$index) {
            $kit->redirects->create(new RedirectRule(SourcePattern::exact('/page-'.$index), Target::url('/x')));
        }

        $first = (string) $kit->redirectsController()->list(Request::create('/'))->getContent();
        self::assertStringContainsString('Page 1 of 2', $first);
        self::assertStringContainsString('/page-50<', $first);
        self::assertStringNotContainsString('/page-0<', $first);

        $second = (string) $kit->redirectsController()->list(Request::create('/?page=2'))->getContent();
        self::assertStringContainsString('/page-0<', $second);
        self::assertStringContainsString('rel="prev"', $second);
        self::assertStringContainsString('/page-0<', (string) $kit->redirectsController()->list(Request::create('/?page=-3&q=page-0'))->getContent());
        self::assertStringContainsString('Page 1 of 2', (string) $kit->redirectsController()->list(Request::create('/?page=0'))->getContent());
    }

    public function testTheAdministrationIsInFrench(): void
    {
        $html = (string) new AdminKit(locale: 'fr')->redirectsController()->list(Request::create('/'))->getContent();

        self::assertStringContainsString('Aucune redirection pour l’instant.', $html);
    }

    public function testAUserWithoutTheRoleMeetsTheFirewall(): void
    {
        $kit = new AdminKit();

        try {
            $kit->redirectsController([])->list(Request::create('/'));
            self::fail('Expected a denial');
        } catch (AccessDeniedException $accessDeniedException) {
            self::assertSame(['ROLE_EDITOR'], $accessDeniedException->getAttributes());
        }

        $this->expectException(AccessDeniedException::class);
        $kit->redirectsController([])->delete($kit->post('/1/delete'), 1);
    }

    public function testAFormWithoutAValidTokenIsRefused(): void
    {
        $kit = new AdminKit();

        $this->expectException(BadRequestHttpException::class);
        $kit->redirectsController()->create($kit->post('/new', ['source' => '/a', 'target' => '/b'], 'forged'));
    }

    public function testTheFormOfANewRedirect(): void
    {
        $kit = new AdminKit();
        $html = (string) $kit->redirectsController()->create(Request::create('/new'))->getContent();

        self::assertStringContainsString('New redirect', $html);
        self::assertStringContainsString('value="exact" selected', $html);
        self::assertStringContainsString('value="301" selected', $html);
        self::assertStringContainsString('value="drop" selected', $html);
        self::assertStringContainsString('name="enabled" value="1" checked', $html);
        self::assertStringNotContainsString('value="regex"', $html);
        self::assertStringContainsString('value="regex"', (string) $kit->redirectsController(['ROLE_EDITOR', 'ROLE_REGEX'])->create(Request::create('/new'))->getContent());
        self::assertStringNotContainsString('value="regex"', (string) new AdminKit(false)->redirectsController(['ROLE_EDITOR', 'ROLE_REGEX'])->create(Request::create('/new'))->getContent());
    }

    public function testARedirectIsCreatedFromThe404Log(): void
    {
        $kit = new AdminKit();
        $kit->log->entries[4] = new NotFoundEntry(4, 'h.example', '/gone-page', 12, new DateTimeImmutable(), new DateTimeImmutable());

        $form = (string) $kit->redirectsController()->create(Request::create('/new?from=4'))->getContent();
        self::assertStringContainsString('value="/gone-page"', $form);
        self::assertStringContainsString('value="h.example"', $form);
        self::assertStringContainsString('not found 12 times', $form);

        $response = $kit->redirectsController()->create($kit->post('/new?from=4', ['source' => '/gone-page', 'host' => 'h.example', 'kind' => 'exact', 'target' => '/new', 'status' => '308', 'query' => 'keep', 'locale' => 'fr', 'enabled' => '1']));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/?done=created', $response->getTargetUrl());
        self::assertSame([], $kit->log->entries);
        $rule = $kit->redirects->get(1)->rule();
        self::assertSame('h.example', $rule->source->host);
        self::assertSame(308, $rule->status->value);
        self::assertSame('keep', $rule->query->value);
        self::assertSame('fr', $rule->locale);
        self::assertTrue($kit->redirects->get(1)->isEnabled());
    }

    public function testAnUnknownEntryIsNoPrefill(): void
    {
        $kit = new AdminKit();
        $kit->log->entries[4] = new NotFoundEntry(4, 'h.example', '/gone-page', 12, new DateTimeImmutable(), new DateTimeImmutable());

        self::assertStringNotContainsString('/gone-page', (string) $kit->redirectsController()->create(Request::create('/new?from=5'))->getContent());
        self::assertStringNotContainsString('/gone-page', (string) $kit->redirectsController()->create(Request::create('/new'))->getContent());

        $kit->redirectsController()->create($kit->post('/new?from=5', ['source' => '/gone-page', 'target' => '/x']));
        self::assertCount(1, $kit->log->entries);
        self::assertFalse($kit->redirects->get(1)->isEnabled());
    }

    public function testEveryPageNeedsTheRoleAndEveryFormItsToken(): void
    {
        $kit = new AdminKit();
        $kit->redirects->create(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b')));

        foreach ([
            static fn (): Response => $kit->redirectsController([])->create(Request::create('/new')),
            static fn (): Response => $kit->redirectsController([])->edit(Request::create('/1/edit'), 1),
            static fn (): RedirectResponse => $kit->notFoundController([])->delete($kit->post('/not-found/1/delete'), 1),
        ] as $action) {
            try {
                $action();
                self::fail('Expected a denial');
            } catch (AccessDeniedException) {
            }
        }

        foreach ([
            static fn (): Response => $kit->redirectsController()->edit($kit->post('/1/edit', ['source' => '/a', 'target' => '/c'], 'forged'), 1),
            static fn (): Response => $kit->redirectsController()->toggle($kit->post('/1/toggle', ['enabled' => '0'], 'forged'), 1),
            static fn (): RedirectResponse => $kit->redirectsController()->delete($kit->post('/1/delete', [], 'forged'), 1),
            static fn (): RedirectResponse => $kit->notFoundController()->delete($kit->post('/not-found/1/delete', [], 'forged'), 1),
        ] as $action) {
            try {
                $action();
                self::fail('Expected a refused token');
            } catch (BadRequestHttpException) {
            }
        }

        self::assertSame('/b', $kit->redirects->get(1)->rule()->target->url);
        self::assertTrue($kit->redirects->get(1)->isEnabled());
    }

    public function testAnInvalidEditIsShownOnTheForm(): void
    {
        $kit = new AdminKit();
        $kit->redirects->create(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b')));

        $response = $kit->redirectsController()->edit($kit->post('/1/edit', ['source' => 'relative', 'target' => '/c']), 1);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertStringContainsString('A source path starts with one /.', (string) $response->getContent());
    }

    public function testTheSearchAndThePageOfAList(): void
    {
        $kit = new AdminKit();
        $guard = $kit->guard();

        self::assertSame('oth', $guard->search(Request::create('/?q=+oth+')));
        self::assertSame(str_repeat('é', 200), $guard->search(Request::create('/?q='.rawurlencode(str_repeat('é', 201)))));
        self::assertSame(1, $guard->page(Request::create('/')));
        self::assertSame(1, $guard->page(Request::create('/?page=0')));
        self::assertSame(1, $guard->page(Request::create('/?page=-4')));
        self::assertSame(3, $guard->page(Request::create('/?page=3')));
    }

    public function testARefusalIsShownOnTheForm(): void
    {
        $kit = new AdminKit();

        $response = $kit->redirectsController()->create($kit->post('/new', ['source' => '/a', 'target' => 'https://evil.example/', 'enabled' => '1']));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertStringContainsString('The host evil.example is not one a redirect may send to.', (string) $response->getContent());
        self::assertStringContainsString('value="https://evil.example/"', (string) $response->getContent());

        $invalid = $kit->redirectsController()->create($kit->post('/new', ['source' => 'a', 'target' => '/b']));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getStatusCode());
        self::assertStringContainsString('A source path starts with one /.', (string) $invalid->getContent());
        self::assertSame([], $kit->repository->all());
    }

    public function testARegularExpressionNeedsItsRole(): void
    {
        $kit = new AdminKit();
        $form = ['source' => '/a+', 'kind' => ' regex ', 'target' => '/b', 'enabled' => '1'];

        $refused = $kit->redirectsController()->create($kit->post('/new', $form));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $refused->getStatusCode());
        self::assertStringContainsString('Writing a regular expression needs a role you do not have.', (string) $refused->getContent());

        self::assertSame(303, $kit->redirectsController(['ROLE_EDITOR', 'ROLE_REGEX'])->create($kit->post('/new', $form))->getStatusCode());

        $edit = $kit->redirectsController()->edit($kit->post('/1/edit', ['source' => '/a', 'kind' => 'exact', 'target' => '/b']), 1);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $edit->getStatusCode());
        self::assertSame('/a+', $kit->redirects->get(1)->rule()->source->path);
    }

    public function testARegularExpressionIsLimitedInLength(): void
    {
        $kit = new AdminKit();

        $response = $kit->redirectsController(['ROLE_EDITOR', 'ROLE_REGEX'])->create($kit->post('/new', ['source' => '/'.str_repeat('a', 20), 'kind' => 'regex', 'target' => '/b']));

        self::assertStringContainsString('between 1 and 20 characters', (string) $response->getContent());
    }

    public function testARedirectIsEdited(): void
    {
        $kit = new AdminKit();
        $kit->redirects->create(new RedirectRule(SourcePattern::prefix('/a'), Target::url('/b')));

        $form = (string) $kit->redirectsController()->edit(Request::create('/1/edit'), 1)->getContent();
        self::assertStringContainsString('Edit the redirect', $form);
        self::assertStringContainsString('value="prefix" selected', $form);

        $response = $kit->redirectsController()->edit($kit->post('/1/edit', ['source' => '/a', 'kind' => 'prefix', 'target' => '/c', 'status' => '301', 'query' => 'drop']), 1);
        self::assertSame('/?done=updated', $response->headers->get('Location'));
        self::assertSame('/c', $kit->redirects->get(1)->rule()->target->url);
        self::assertFalse($kit->redirects->get(1)->isEnabled());

        $refused = $kit->redirectsController()->edit($kit->post('/1/edit', ['source' => '/a', 'kind' => 'prefix', 'target' => '/a/b', 'enabled' => '1']), 1);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $refused->getStatusCode());
        self::assertStringContainsString('makes a loop', (string) $refused->getContent());
    }

    public function testAMissingRedirectIsNotFound(): void
    {
        $kit = new AdminKit();

        foreach ([
            static fn (): Response => $kit->redirectsController()->edit(Request::create('/9/edit'), 9),
            static fn (): Response => $kit->redirectsController()->toggle($kit->post('/9/toggle', ['enabled' => '1']), 9),
            static fn (): Response => $kit->redirectsController()->delete($kit->post('/9/delete'), 9),
        ] as $action) {
            try {
                $action();
                self::fail('Expected a 404');
            } catch (NotFoundHttpException $notFoundHttpException) {
                self::assertSame('No redirect has the identifier 9.', $notFoundHttpException->getMessage());
            }
        }
    }

    public function testARedirectIsEnabledDisabledAndDeleted(): void
    {
        $kit = new AdminKit();
        $kit->redirects->create(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b')));
        $kit->redirects->create(new RedirectRule(SourcePattern::exact('/b'), Target::url('/c')), false);

        self::assertSame('/?done=disabled', $kit->redirectsController()->toggle($kit->post('/1/toggle', ['enabled' => '0']), 1)->headers->get('Location'));
        self::assertFalse($kit->redirects->get(1)->isEnabled());
        self::assertSame('/?done=enabled', $kit->redirectsController()->toggle($kit->post('/2/toggle', ['enabled' => '1']), 2)->headers->get('Location'));

        $refused = $kit->redirectsController()->toggle($kit->post('/1/toggle', ['enabled' => '1']), 1);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $refused->getStatusCode());
        self::assertStringContainsString('would make a chain', (string) $refused->getContent());

        self::assertSame('/?done=deleted', $kit->redirectsController()->delete($kit->post('/1/delete'), 1)->headers->get('Location'));
        self::assertCount(1, $kit->repository->all());
    }

    public function testThe404Log(): void
    {
        $kit = new AdminKit();
        $kit->log->entries[1] = new NotFoundEntry(1, 'h.example', '/missing', 7, new DateTimeImmutable('2026-01-02 03:04'), new DateTimeImmutable('2026-02-03 04:05'), 'from.example');
        $kit->log->entries[2] = new NotFoundEntry(2, 'h.example', '/other', 1, new DateTimeImmutable(), new DateTimeImmutable());

        $html = (string) $kit->notFoundController()->list(Request::create('/not-found?done=deleted'))->getContent();
        self::assertStringContainsString('<code>/missing</code>', $html);
        self::assertStringContainsString('2026-01-02 03:04', $html);
        self::assertStringContainsString('2026-02-03 04:05', $html);
        self::assertStringContainsString('from.example', $html);
        self::assertStringContainsString('/new?from=1', $html);
        self::assertStringContainsString('The path was removed from the log.', $html);

        $searched = (string) $kit->notFoundController()->list(Request::create('/not-found?q=oth&page=-1&done=other'))->getContent();
        self::assertStringNotContainsString('/missing', $searched);
        self::assertStringNotContainsString('role="status"', $searched);

        self::assertSame('/not-found?done=deleted', $kit->notFoundController()->delete($kit->post('/not-found/1/delete'), 1)->headers->get('Location'));
        self::assertSame([2], array_keys($kit->log->entries));

        $this->expectException(NotFoundHttpException::class);
        $kit->notFoundController()->delete($kit->post('/not-found/1/delete'), 1);
    }

    public function testThe404LogNeedsTheRole(): void
    {
        $this->expectException(AccessDeniedException::class);
        new AdminKit()->notFoundController([])->list(Request::create('/not-found'));
    }

    public function testThe404LogIsPaged(): void
    {
        $kit = new AdminKit();

        for ($index = 1; $index <= RedirectAdminController::PAGE_SIZE + 1; ++$index) {
            $kit->log->entries[$index] = new NotFoundEntry($index, 'h.example', '/p'.$index.'/', 1, new DateTimeImmutable(), new DateTimeImmutable());
        }

        self::assertStringContainsString('/p51/', (string) $kit->notFoundController()->list(Request::create('/not-found?page=2'))->getContent());
        self::assertStringNotContainsString('/p51/', (string) $kit->notFoundController()->list(Request::create('/not-found'))->getContent());
    }
}
