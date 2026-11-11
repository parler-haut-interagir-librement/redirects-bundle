<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Domain;

use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Target::class)]
final class TargetTest extends TestCase
{
    public function testAPathOfTheSite(): void
    {
        $target = Target::url('/new?a=1#top');

        self::assertSame('/new?a=1#top', $target->url);
        self::assertNull($target->route);
        self::assertNull($target->host);
        self::assertFalse($target->isExternal());
        self::assertFalse($target->isNone());
        self::assertSame('/new?a=1#top', $target->toString());
    }

    public function testAnAbsoluteUrlKeepsItsHost(): void
    {
        $target = Target::url('https://Partner.Example:8443/a');

        self::assertSame('partner.example', $target->host);
        self::assertTrue($target->isExternal());
        self::assertSame('http://partner.example', Target::url('http://partner.example')->url);
        self::assertSame('partner.example', Target::url('http://partner.example?x=1')->host);
        self::assertSame('xn--bcher-kva.example', Target::url('https://Bücher.example./$1')->host);
        self::assertSame('https://Bücher.example./$1', Target::url('https://Bücher.example./$1')->url);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'empty' => ['', 'target_url'];
        yield 'space' => ['/a b', 'target_url'];
        yield 'control' => ["/a\r\nLocation: x", 'target_url'];
        yield 'delete' => ["/a\x7F", 'target_url'];
        yield 'backslash' => ['/\evil.example', 'target_url'];
        yield 'too long' => ['/'.str_repeat('a', Target::MAX_LENGTH), 'target_url'];
        yield 'protocol relative' => ['//evil.example', 'target_relative'];
        yield 'javascript' => ['javascript:alert(1)', 'target_url'];
        yield 'relative' => ['new', 'target_url'];
        yield 'credentials' => ['https://user@evil.example', 'target_url'];
        yield 'placeholder in host' => ['https://$1.example/a', 'host'];
        yield 'empty host' => ['https://./a', 'host'];
        // The pattern took any dotted digits for a host name: a browser reads both as the loopback address.
        yield 'an IP address' => ['https://127.0.0.1/a', 'host'];
        yield 'an IP address in decimal' => ['https://2130706433/a', 'host'];
        yield 'a host of one name' => ['https://intranet/a', 'host'];
        yield 'a port out of range' => ['https://partner.example:99999/', 'host'];
        yield 'port 0' => ['https://partner.example:0/a', 'target_url'];
    }

    #[DataProvider('invalidUrls')]
    public function testAnUnsafeUrlIsRefused(string $url, string $reason): void
    {
        try {
            Target::url($url);
            self::fail('Expected a refusal');
        } catch (InvalidRedirect $invalidRedirect) {
            self::assertSame($reason, $invalidRedirect->reason);
        }
    }

    public function testTheRefusalsCarryTheirParameters(): void
    {
        foreach ([static fn (): Target => Target::url(''), static fn (): Target => Target::url('relative')] as $build) {
            try {
                $build();
                self::fail('Expected a refusal');
            } catch (InvalidRedirect $invalidRedirect) {
                self::assertSame(['%max%' => '2048'], $invalidRedirect->parameters);
            }
        }

        try {
            Target::route('a b');
            self::fail('Expected a refusal');
        } catch (InvalidRedirect $invalidRedirect) {
            self::assertSame(['%route%' => 'a b'], $invalidRedirect->parameters);
        }

        try {
            Target::url('https://intranet/a');
            self::fail('Expected a refusal');
        } catch (InvalidRedirect $invalidRedirect) {
            self::assertSame(['%host%' => 'intranet'], $invalidRedirect->parameters);
        }
    }

    public function testLocalhostIsATargetOnlyWhenAllowed(): void
    {
        foreach (['http://localhost:8000/a' => 'localhost', 'https://app.localhost/$1' => 'app.localhost'] as $url => $host) {
            foreach ([static fn (): Target => Target::url($url), static fn (): Target => Target::fromString($url)] as $read) {
                try {
                    $read();
                    self::fail($url.' was taken.');
                } catch (InvalidRedirect $invalidRedirect) {
                    self::assertSame('host', $invalidRedirect->reason);
                }
            }

            self::assertSame($host, Target::url($url, allowLocalhost: true)->host);
            self::assertSame($host, Target::fromString($url, allowLocalhost: true)->host);
        }

        foreach (['http://127.0.0.1/a', 'http://localhostx/a', 'http://intranet/a'] as $url) {
            try {
                Target::url($url, allowLocalhost: true);
                self::fail($url.' was taken.');
            } catch (InvalidRedirect $invalidRedirect) {
                self::assertSame('host', $invalidRedirect->reason);
            }
        }
    }

    public function testTheLongestUrlIsAccepted(): void
    {
        $url = '/'.str_repeat('a', Target::MAX_LENGTH - 1);

        self::assertSame($url, Target::url($url)->url);
    }

    public function testARouteWithItsParametersSorted(): void
    {
        $target = Target::route('blog_post', ['slug' => '$1', 'page' => '2']);

        self::assertSame('blog_post', $target->route);
        self::assertSame(['page' => '2', 'slug' => '$1'], $target->parameters);
        self::assertNull($target->url);
        self::assertFalse($target->isExternal());
        self::assertFalse($target->isNone());
        self::assertSame('route:blog_post?page=2&slug=%241', $target->toString());
        self::assertSame('route:home', Target::route('home')->toString());
        self::assertSame(1, $target->highestPlaceholder());
    }

    public function testARouteIsReadBackFromItsText(): void
    {
        $target = Target::fromString(' route:blog_post?slug=%241&page=2 ');

        self::assertTrue($target->equals(Target::route('blog_post', ['page' => '2', 'slug' => '$1'])));
        self::assertSame('home', Target::fromString('route:home')->route);
        self::assertSame([], Target::fromString('route:home')->parameters);
        self::assertTrue(Target::fromString('  ')->isNone());
        self::assertSame('/a', Target::fromString('/a')->url);
    }

    /**
     * @return iterable<string, array{string, array<array-key, mixed>}>
     */
    public static function invalidRoutes(): iterable
    {
        yield 'name' => ['blog post', []];
        yield 'empty name' => ['', []];
        yield 'long name' => [str_repeat('a', 201), []];
        yield 'list key' => ['home', ['x']];
        yield 'bad key' => ['home', ['1a' => 'x']];
        yield 'long key' => ['home', [str_repeat('a', 65) => 'x']];
        yield 'array value' => ['home', ['a' => ['x']]];
        yield 'long value' => ['home', ['a' => str_repeat('x', 513)]];
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    #[DataProvider('invalidRoutes')]
    public function testAnInvalidRouteIsRefused(string $name, array $parameters): void
    {
        $this->expectException(InvalidRedirect::class);

        Target::route($name, $parameters);
    }

    public function testTheLimitsOfARouteAreInclusive(): void
    {
        $target = Target::route(str_repeat('a', 200), ['_'.str_repeat('a', 63) => str_repeat('x', 512)]);

        self::assertSame(200, \strlen((string) $target->route));
        self::assertSame(['a' => 'x'], Target::fromString('route:r?a=x')->parameters);
    }

    public function testNone(): void
    {
        $target = Target::none();

        self::assertTrue($target->isNone());
        self::assertFalse($target->isExternal());
        self::assertSame('', $target->toString());
        self::assertSame(0, $target->highestPlaceholder());
    }

    public function testTheHighestPlaceholder(): void
    {
        self::assertSame(0, Target::url('/a')->highestPlaceholder());
        self::assertSame(3, Target::url('/$3/$1/$2')->highestPlaceholder());
        self::assertSame(9, Target::url('/$9')->highestPlaceholder());
        self::assertSame(2, Target::route('r', ['a' => '$1', 'b' => '$2'])->highestPlaceholder());
        self::assertSame(0, Target::url('/$0')->highestPlaceholder());
    }

    public function testEquality(): void
    {
        self::assertTrue(Target::url('/a')->equals(Target::url('/a')));
        self::assertFalse(Target::url('/a')->equals(Target::url('/b')));
    }
}
