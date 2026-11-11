<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Application;

use Phil\RedirectsBundle\Application\LocationBuilder;
use Phil\RedirectsBundle\Application\RedirectResolver;
use Phil\RedirectsBundle\Application\RedirectTable;
use Phil\RedirectsBundle\Application\RedirectTableCache;
use Phil\RedirectsBundle\Application\Resolution;
use Phil\RedirectsBundle\Domain\Enum\QueryPolicy;
use Phil\RedirectsBundle\Domain\Enum\RedirectStatus;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use Phil\RedirectsBundle\Tests\Unit\Fixture\ListProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(RedirectResolver::class)]
#[CoversClass(RedirectTableCache::class)]
#[CoversClass(Resolution::class)]
final class RedirectResolverTest extends TestCase
{
    public function testARedirectIsResolvedWithItsLocation(): void
    {
        $rule = new RedirectRule(SourcePattern::exact('/a', 'h.example'), Target::url('/b'), RedirectStatus::Found, QueryPolicy::Keep, 'fr');
        $resolver = new RedirectResolver(new RedirectTableCache([new ListProvider([$rule])]), new LocationBuilder());

        $resolution = $resolver->resolve('H.Example', '/a', 'x=1', 'fr');
        self::assertNotNull($resolution);
        self::assertSame($rule, $resolution->rule);
        self::assertSame('/b?x=1', $resolution->location);
        self::assertSame(RedirectStatus::Found, $resolution->status());
        self::assertNull($resolver->resolve('h.example', '/a', '', 'en'));
        self::assertNull($resolver->resolve('h.example', '/c'));
        self::assertSame('/b', $resolver->resolve('h.example', '/a', '', 'fr')?->location);
    }

    public function testAGoneResolvesWithoutALocation(): void
    {
        $rule = new RedirectRule(SourcePattern::exact('/a'), Target::none(), RedirectStatus::Gone);
        $resolution = new RedirectResolver(new RedirectTableCache([new ListProvider([$rule])]), new LocationBuilder())->resolve('h.example', '/a');

        self::assertNotNull($resolution);
        self::assertNull($resolution->location);
        self::assertSame(RedirectStatus::Gone, $resolution->status());
    }

    public function testATargetThatCannotBeBuiltResolvesToNothing(): void
    {
        $rule = new RedirectRule(SourcePattern::exact('/a'), Target::route('post'));

        self::assertNull(new RedirectResolver(new RedirectTableCache([new ListProvider([$rule])]), new LocationBuilder())->resolve('h.example', '/a'));
    }

    public function testTheTableIsKeptForTheRequestAndBuiltAgainAfterAReset(): void
    {
        $provider = new ListProvider([new RedirectRule(SourcePattern::exact('/a'), Target::url('/b'))]);
        $tables = new RedirectTableCache([$provider]);

        self::assertSame($tables->table(), $tables->table());
        self::assertSame(1, $provider->reads);

        $tables->reset();
        $tables->table();
        self::assertSame(2, $provider->reads);

        $tables->invalidate();
        $tables->table();
        self::assertSame(3, $provider->reads);
        self::assertSame(1, $tables->fresh()->count());
        self::assertSame(4, $provider->reads);
    }

    public function testTheProvidersAreReadInOrderAndTheFirstWins(): void
    {
        $first = new RedirectRule(SourcePattern::exact('/a'), Target::url('/first'));
        $second = new RedirectRule(SourcePattern::exact('/a'), Target::url('/second'));
        $tables = new RedirectTableCache([new ListProvider([$first]), new ListProvider([$second])]);

        self::assertSame($first, $tables->table()->match(null, '/a')?->rule);
        self::assertSame(2, $tables->table()->count());
    }

    public function testRegularExpressionsAreLeftOutUnlessAllowed(): void
    {
        $provider = new ListProvider([new RedirectRule(SourcePattern::regex('/a+'), Target::url('/b')), new RedirectRule(SourcePattern::exact('/c'), Target::url('/d'))]);

        self::assertSame(1, new RedirectTableCache([$provider])->table()->count());
        self::assertSame(2, new RedirectTableCache([$provider], true)->table()->count());
    }

    public function testThePoolKeepsTheTableBetweenRequestsUntilInvalidated(): void
    {
        $provider = new ListProvider([new RedirectRule(SourcePattern::exact('/a'), Target::url('/b'))]);
        $pool = new ArrayAdapter();
        $tables = new RedirectTableCache([$provider], false, $pool, 'key', 60);

        $tables->table();
        $tables->reset();
        self::assertSame(1, $tables->table()->count());
        self::assertSame(1, $provider->reads);
        self::assertTrue($pool->hasItem('key'));

        $other = new RedirectTableCache([$provider], false, $pool, 'key');
        self::assertInstanceOf(RedirectTable::class, $other->table());
        self::assertSame(1, $provider->reads);

        $tables->invalidate();
        self::assertFalse($pool->hasItem('key'));
        $tables->table();
        self::assertSame(2, $provider->reads);
    }

    public function testTheLifetimeIsGivenToThePool(): void
    {
        $clock = new MockClock();
        $pool = new ArrayAdapter(clock: $clock);
        $tables = new RedirectTableCache([new ListProvider()], false, $pool, 'key', 10);
        $tables->table();

        $clock->sleep(9);
        self::assertTrue($pool->hasItem('key'));
        $clock->sleep(2);
        self::assertFalse($pool->hasItem('key'));
    }

    public function testSomethingElseInThePoolIsReplaced(): void
    {
        $pool = new ArrayAdapter();
        $pool->save($pool->getItem(RedirectTableCache::DEFAULT_KEY)->set('stale'));

        $provider = new ListProvider();

        new RedirectTableCache([$provider], false, $pool)->table();

        self::assertSame(1, $provider->reads);
        self::assertInstanceOf(RedirectTable::class, $pool->getItem(RedirectTableCache::DEFAULT_KEY)->get());
    }
}
