<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Functional;

use Phil\RedirectsBundle\Application\Redirects;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use Phil\RedirectsBundle\PhilRedirectsBundle;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The bundle in an application: a page found never meets the table, a request not found is redirected or logged, an
 * old domain is mapped before the routing.
 */
#[CoversClass(PhilRedirectsBundle::class)]
final class RedirectsBundleTest extends FunctionalTestCase
{
    public function testAPageFoundIsServedAndARedirectAnswersOnlyWhenNothingElseDoes(): void
    {
        $redirects = $this->service(Redirects::class, Redirects::class);
        $redirects->create(new RedirectRule(SourcePattern::exact('/page/kept'), Target::url('/elsewhere')));
        $redirects->create(new RedirectRule(SourcePattern::wildcard('/blog/*/**'), Target::url('/page/$1?rest=$2')));

        self::assertSame('page kept', $this->request('GET', '/page/kept')->getContent());

        $response = $this->request('GET', '/blog/hello/a/b?utm=1');
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/page/hello?rest=a/b', $response->headers->get('Location'));
        self::assertSame(1, $this->number($this->connection()->fetchOne('SELECT hits FROM phil_redirect WHERE source = ?', ['/blog/*/**'])));
    }

    public function testARequestNotFoundIsLoggedWithoutVisitorData(): void
    {
        $this->request('GET', '/missing', ['Referer' => 'https://Search.Example/q?x=private']);
        $this->request('GET', '/missing');
        $this->request('GET', '/missing', ['User-Agent' => 'Googlebot/2.1']);
        $this->request('GET', '/missing', ['X-Robot-Verdict' => 'robot']);

        $row = $this->connection()->fetchAssociative('SELECT * FROM phil_redirect_not_found');
        self::assertIsArray($row);
        self::assertSame('/missing', $row['path']);
        self::assertEquals(2, $row['hits']);
        self::assertSame('search.example', $row['referrer_host']);
    }

    private function number(mixed $value): int
    {
        return \is_int($value) || \is_string($value) ? (int) $value : -1;
    }

    public function testAnOldDomainIsMappedBeforeTheRouting(): void
    {
        $response = $this->request('GET', 'http://old.example/page/kept?a=1');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('http://new.example/en/page/kept?a=1', $response->headers->get('Location'));
    }

    public function testTheAdministrationCreatesARedirectFromThe404Log(): void
    {
        $this->request('GET', '/old-page');
        $id = $this->number($this->connection()->fetchOne('SELECT id FROM phil_redirect_not_found'));

        $form = $this->request('GET', '/admin/redirects/new?from='.$id, ['X-Roles' => 'ROLE_EDITOR']);
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('value="/old-page"', (string) $form->getContent());

        $saved = $this->post('/admin/redirects/new?from='.$id, ['source' => '/old-page', 'kind' => 'exact', 'target' => '/page/new', 'status' => '301', 'query' => 'drop', 'enabled' => '1']);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame(0, $this->number($this->connection()->fetchOne('SELECT COUNT(*) FROM phil_redirect_not_found')));
        self::assertSame('/page/new', $this->request('GET', '/old-page')->headers->get('Location'));

        $list = $this->request('GET', '/admin/redirects/?done=created', ['X-Roles' => 'ROLE_EDITOR', 'Accept-Language' => 'fr']);
        self::assertStringContainsString('/old-page', (string) $list->getContent());
    }
}
