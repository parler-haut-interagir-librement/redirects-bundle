<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Application;

use Phil\RedirectsBundle\Application\LocationBuilder;
use Phil\RedirectsBundle\Application\RedirectMatch;
use Phil\RedirectsBundle\Domain\Enum\QueryPolicy;
use Phil\RedirectsBundle\Domain\Enum\RedirectStatus;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

#[CoversClass(LocationBuilder::class)]
final class LocationBuilderTest extends TestCase
{
    public function testThePlaceholdersTakeTheCaptures(): void
    {
        $rule = new RedirectRule(SourcePattern::regex('/(\w+)/(\w+)/(.*)'), Target::url('/$2/$1?rest=$3&none=$4'));

        self::assertSame('/b/a?rest=c/d&none=', new LocationBuilder()->build(new RedirectMatch($rule, ['a', 'b', 'c/d'])));
    }

    public function testOnlyTheNineDigitsArePlaceholders(): void
    {
        $rule = new RedirectRule(SourcePattern::regex('/(a)(b)(c)(d)(e)(f)(g)(h)(i)'), Target::url('/$0/$9/$10'));

        self::assertSame('/$0/i/a0', new LocationBuilder()->build(new RedirectMatch($rule, ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'])));
    }

    public function testACaptureIsEncodedAndCannotLeaveTheSite(): void
    {
        $rule = new RedirectRule(SourcePattern::wildcard('/**'), Target::url('/$1'));
        $builder = new LocationBuilder();

        self::assertSame('/a%20b%3Cc%3E%22%5C', $builder->build(new RedirectMatch($rule, ['a b<c>"\\'])));
        self::assertSame('/~a-b_c.d!$&\'()*+,;=:@%41', $builder->build(new RedirectMatch($rule, ['~a-b_c.d!$&\'()*+,;=:@%41'])));
        self::assertNull($builder->build(new RedirectMatch($rule, ['/evil.example'])));
        self::assertSame('/%0D%0ASet-Cookie:x', $builder->build(new RedirectMatch($rule, ["\r\nSet-Cookie:x"])));
    }

    public function testAnAbsoluteTargetKeepsItsHost(): void
    {
        $rule = new RedirectRule(SourcePattern::wildcard('/**'), Target::url('https://partner.example/$1'));
        $builder = new LocationBuilder();

        self::assertSame('https://partner.example/a', $builder->build(new RedirectMatch($rule, ['a'])));
        self::assertSame('https://partner.example/@evil.example', $builder->build(new RedirectMatch($rule, ['@evil.example'])));
        self::assertSame('https://Partner.example.:8443', $builder->build(new RedirectMatch(new RedirectRule(SourcePattern::exact('/a'), Target::url('https://Partner.example.:8443')), [])));
    }

    public function testAnAbsoluteUrlThatDoesNotParseIsRefused(): void
    {
        self::assertNull(new LocationBuilder()->build(new RedirectMatch(new RedirectRule(SourcePattern::exact('/a'), Target::url('https://partner.example:99999/')))));
    }

    public function testAPrefixAppendsTheRestToThePath(): void
    {
        $builder = new LocationBuilder();
        $prefix = static fn (string $target): RedirectRule => new RedirectRule(SourcePattern::prefix('/old'), Target::url($target));

        self::assertSame('/new/a/b?x=1#f', $builder->build(new RedirectMatch($prefix('/new?x=1#f'), ['/a/b'])));
        self::assertSame('/new/a', $builder->build(new RedirectMatch($prefix('/new/'), ['/a'])));
        self::assertSame('/new/', $builder->build(new RedirectMatch($prefix('/new/'), [''])));
        self::assertSame('/new', $builder->build(new RedirectMatch($prefix('/new'), [])));
        self::assertSame('https://partner.example/a', $builder->build(new RedirectMatch($prefix('https://partner.example/'), ['/a'])));
    }

    public function testTheQueryStringIsKeptWhenAsked(): void
    {
        $builder = new LocationBuilder();
        $keep = static fn (string $target): RedirectRule => new RedirectRule(SourcePattern::exact('/a'), Target::url($target), query: QueryPolicy::Keep);

        self::assertSame('/b?utm=1&q=a%20b?', $builder->build(new RedirectMatch($keep('/b')), 'utm=1&q=a b?'));
        self::assertSame('/b?x=1&utm=1#top', $builder->build(new RedirectMatch($keep('/b?x=1#top')), 'utm=1'));
        self::assertSame('/b#top', $builder->build(new RedirectMatch($keep('/b#top')), ''));
        self::assertSame('/b?x=%22', $builder->build(new RedirectMatch($keep('/b')), 'x="'));
        self::assertSame('/b', $builder->build(new RedirectMatch(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b'))), 'utm=1'));
    }

    public function testARouteIsGeneratedWithTheCaptures(): void
    {
        $routes = new RouteCollection();
        $routes->add('post', new Route('/posts/{slug}'));

        $builder = new LocationBuilder(new UrlGenerator($routes, new RequestContext()));
        $rule = new RedirectRule(SourcePattern::wildcard('/blog/*'), Target::route('post', ['slug' => '$1']));

        self::assertSame('/posts/caf%C3%A9', $builder->build(new RedirectMatch($rule, ['caf%C3%A9'])));
        self::assertSame('/posts/a/x', $builder->build(new RedirectMatch(new RedirectRule(SourcePattern::prefix('/b'), Target::route('post', ['slug' => 'a'])), ['/x'])));
        self::assertNull($builder->build(new RedirectMatch(new RedirectRule(SourcePattern::exact('/a'), Target::route('missing')))));
        self::assertNull($builder->build(new RedirectMatch(new RedirectRule(SourcePattern::exact('/a'), Target::route('post')))));
        self::assertNull(new LocationBuilder()->build(new RedirectMatch($rule, ['x'])));
    }

    public function testAGoneHasNoLocation(): void
    {
        self::assertNull(new LocationBuilder()->build(new RedirectMatch(new RedirectRule(SourcePattern::exact('/a'), Target::none(), RedirectStatus::Gone))));
    }
}
