<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Application;

use Phil\RedirectsBundle\Application\OldDomain;
use Phil\RedirectsBundle\Application\OldDomains;
use Phil\RedirectsBundle\Domain\Enum\RedirectStatus;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OldDomain::class)]
#[CoversClass(OldDomains::class)]
final class OldDomainTest extends TestCase
{
    public function testThePathAndQueryAreKeptByDefault(): void
    {
        $domain = new OldDomain('New.Example');

        self::assertSame('new.example', $domain->host);
        self::assertSame(RedirectStatus::MovedPermanently, $domain->status);
        self::assertSame('https://new.example/a/b?x=1', $domain->locationFor('https', '/a/b', 'x=1'));
        self::assertSame('http://new.example/', $domain->locationFor('http', '/'));
    }

    public function testTheOptions(): void
    {
        $domain = new OldDomain('new.example', RedirectStatus::Found, false, false, 'https', '/fr');

        self::assertSame('https://new.example/fr', $domain->locationFor('http', '/a', 'x=1'));
        self::assertSame('https://new.example/fr/a', new OldDomain('new.example', basePath: '/fr')->locationFor('https', '/a'));
        self::assertSame('https://new.example/fr', new OldDomain('new.example', basePath: '/fr')->locationFor('https', '/'));
        self::assertSame('https://new.example/a', new OldDomain('new.example', keepQuery: false)->locationFor('https', '/a', 'x=1'));
    }

    public function testAnInvalidMapping(): void
    {
        foreach ([
            'old_domain_status' => static fn (): OldDomain => new OldDomain('new.example', RedirectStatus::Gone),
            'old_domain_scheme' => static fn (): OldDomain => new OldDomain('new.example', scheme: 'ftp'),
            'old_domain_path' => static fn (): OldDomain => new OldDomain('new.example', basePath: '/fr/'),
            'host' => static fn (): OldDomain => new OldDomain('new example'),
        ] as $reason => $build) {
            try {
                $build();
                self::fail('Expected '.$reason);
            } catch (InvalidRedirect $invalidRedirect) {
                self::assertSame($reason, $invalidRedirect->reason);
            }
        }

        $this->expectException(InvalidRedirect::class);
        new OldDomain('new.example', basePath: '/');
    }

    public function testTheDomainsAreFoundByHost(): void
    {
        $domain = new OldDomain('new.example');
        $domains = new OldDomains(['old.example' => $domain]);

        self::assertSame($domain, $domains->find('Old.Example.'));
        self::assertNull($domains->find('new.example'));
        self::assertFalse($domains->isEmpty());
        self::assertTrue(new OldDomains()->isEmpty());
    }
}
