<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Application;

use Phil\RedirectsBundle\Application\RedirectMatch;
use Phil\RedirectsBundle\Application\RedirectTable;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RedirectTable::class)]
#[CoversClass(RedirectMatch::class)]
final class RedirectTableTest extends TestCase
{
    public function testAnEmptyTableMatchesNothing(): void
    {
        $table = RedirectTable::of([]);

        self::assertSame(0, $table->count());
        self::assertSame([], $table->rules());
        self::assertNull($table->match('h.example', '/a'));
    }

    public function testTheKindsAreTriedInOrder(): void
    {
        $exact = $this->rule(SourcePattern::exact('/a/b'), '/exact');
        $prefix = $this->rule(SourcePattern::prefix('/a'), '/prefix');
        $wildcard = $this->rule(SourcePattern::wildcard('/a/*'), '/wildcard');
        $regex = $this->rule(SourcePattern::regex('/a/.*'), '/regex');
        $table = RedirectTable::of([$regex, $wildcard, $prefix, $exact]);

        self::assertSame(4, $table->count());
        self::assertSame([$exact, $prefix, $wildcard, $regex], $table->rules());
        self::assertSame($exact, $table->match(null, '/a/b/')?->rule);
        self::assertSame($prefix, $table->match(null, '/a/c')?->rule);
        self::assertSame(['/c'], $table->match(null, '/a/c')->captures);
        self::assertSame($wildcard, $table->without($prefix)->match(null, '/a/c')?->rule);
        self::assertSame(['c'], $table->without($prefix)->match(null, '/a/c')->captures);
        self::assertSame($regex, $table->without($prefix)->without($wildcard)->match(null, '/a/c')?->rule);
        self::assertNull($table->match(null, '/b'));
    }

    public function testTheLongestPrefixWinsWhateverTheOrder(): void
    {
        $short = $this->rule(SourcePattern::prefix('/docs'), '/short');
        $long = $this->rule(SourcePattern::prefix('/docs/v1'), '/long');
        $same = $this->rule(SourcePattern::prefix('/docs/v2'), '/same');
        $root = $this->rule(SourcePattern::prefix('/'), '/root');

        foreach ([[$short, $long, $same, $root], [$root, $same, $long, $short], [$long, $short, $root, $same]] as $rules) {
            $table = RedirectTable::of($rules);
            self::assertSame($long, $table->match(null, '/docs/v1/x')?->rule);
            self::assertSame($same, $table->match(null, '/docs/v2')?->rule);
            self::assertSame($short, $table->match(null, '/docs/v3')?->rule);
            self::assertSame($root, $table->match(null, '/other')?->rule);
            self::assertSame($root, $table->match(null, '/')?->rule);
        }

        $first = $this->rule(SourcePattern::prefix('/docs/aa'), '/first');
        $second = $this->rule(SourcePattern::prefix('/docs/bb'), '/second', 'fr');
        self::assertSame([$first, $second, $short], RedirectTable::of([$short, $first, $second])->rules());
    }

    public function testAPrefixIsPlacedAmongSeveralShorterOnes(): void
    {
        $long = self::rule(SourcePattern::prefix('/docs/aa/bb'), '/long');
        $middle = self::rule(SourcePattern::prefix('/docs/aa'), '/middle');
        $short = self::rule(SourcePattern::prefix('/docs'), '/short');
        $table = RedirectTable::of([$middle, $short])->with($long);

        self::assertSame([$long, $middle, $short], $table->rules());
        self::assertSame($long, $table->match(null, '/docs/aa/bb/x')?->rule);
    }

    public function testTheRootPrefixAndTheLeadingWildcardOfAHost(): void
    {
        $root = self::rule(SourcePattern::prefix('/', 'h.example'), '/root');
        $star = self::rule(SourcePattern::wildcard('/*/b', 'h.example'), '/star');
        $table = RedirectTable::of([$root, $star]);

        self::assertSame($root, $table->match('h.example', '/x/y')?->rule);
        self::assertSame($star, $table->without($root)->match('h.example', '/x/b')?->rule);
        self::assertNull($table->match('other.example', '/x/y'));
    }

    public function testTheHostOfTheRequestComesFirstWithinAKind(): void
    {
        $any = $this->rule(SourcePattern::exact('/a'), '/any');
        $own = $this->rule(SourcePattern::exact('/a', 'h.example'), '/own');
        $table = RedirectTable::of([$any, $own]);

        self::assertSame($own, $table->match('h.example', '/a')?->rule);
        self::assertSame($any, $table->match('other.example', '/a')?->rule);
        self::assertSame($any, $table->match(null, '/a')?->rule);
        self::assertSame($any, $table->match('', '/a')?->rule);
        self::assertNull(RedirectTable::of([$own])->match(null, '/a'));

        foreach ([
            [SourcePattern::prefix('/p'), SourcePattern::prefix('/p', 'h.example'), '/p/x'],
            [SourcePattern::wildcard('/w/*'), SourcePattern::wildcard('/w/*', 'h.example'), '/w/x'],
            [SourcePattern::regex('/r.*'), SourcePattern::regex('/r.*', 'h.example'), '/rx'],
        ] as [$anySource, $ownSource, $path]) {
            $anyRule = $this->rule($anySource, '/any');
            $ownRule = $this->rule($ownSource, '/own');
            $table = RedirectTable::of([$anyRule, $ownRule]);
            self::assertSame($ownRule, $table->match('h.example', $path)?->rule);
            self::assertSame($anyRule, $table->match('x.example', $path)?->rule);
        }
    }

    public function testAWildcardStartingWithAStarIsFound(): void
    {
        $literal = $this->rule(SourcePattern::wildcard('/a/*'), '/literal');
        $star = $this->rule(SourcePattern::wildcard('/*/b'), '/star');
        $table = RedirectTable::of([$star, $literal]);

        self::assertSame($literal, $table->match(null, '/a/b')?->rule);
        self::assertSame($star, $table->match(null, '/c/b')?->rule);
        self::assertSame($star, RedirectTable::of([$star])->match(null, '/a/b')?->rule);
    }

    public function testARuleOfAnotherLocaleIsSkipped(): void
    {
        $french = $this->rule(SourcePattern::exact('/a'), '/fr', 'fr');
        $every = $this->rule(SourcePattern::exact('/a'), '/every');
        $table = RedirectTable::of([$french, $every]);

        self::assertSame($french, $table->match(null, '/a', 'fr')?->rule);
        self::assertSame($every, $table->match(null, '/a', 'en')?->rule);
        self::assertSame($every, $table->match(null, '/a')?->rule);
    }

    public function testWithAndWithoutGiveNewTables(): void
    {
        $one = $this->rule(SourcePattern::exact('/a'), '/one');
        $two = $this->rule(SourcePattern::exact('/b'), '/two');
        $table = RedirectTable::of([$one]);
        $more = $table->with($two);

        self::assertSame(1, $table->count());
        self::assertSame(2, $more->count());
        self::assertNull($table->match(null, '/b'));
        self::assertSame($two, $more->match(null, '/b')?->rule);

        $less = $more->without($this->rule(SourcePattern::exact('/a'), '/other'));
        self::assertSame(1, $less->count());
        self::assertNull($less->match(null, '/a'));
        self::assertSame($one, $more->match(null, '/a')?->rule);
        self::assertSame([$two], $less->rules());

        self::assertSame(1, $less->without($this->rule(SourcePattern::exact('/c'), '/x'))->count());
        self::assertSame(1, $less->without($this->rule(SourcePattern::exact('/b'), '/x', 'fr'))->count());

        $french = $this->rule(SourcePattern::exact('/b'), '/fr', 'fr');
        $both = $less->with($french);
        self::assertSame([$french], $both->without($two)->rules());
        self::assertSame(1, $both->without($two)->count());
    }

    private function rule(SourcePattern $source, string $target, ?string $locale = null): RedirectRule
    {
        return new RedirectRule($source, Target::url($target), locale: $locale);
    }
}
