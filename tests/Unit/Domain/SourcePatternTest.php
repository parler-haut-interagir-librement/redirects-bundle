<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Domain;

use Phil\RedirectsBundle\Domain\Enum\MatchKind;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourcePattern::class)]
#[CoversClass(InvalidRedirect::class)]
final class SourcePatternTest extends TestCase
{
    public function testAnExactSourceMatchesItsPathOnlyATrailingSlashIgnored(): void
    {
        $source = SourcePattern::exact('/old/', 'Example.COM.');

        self::assertSame(MatchKind::Exact, $source->kind);
        self::assertSame('/old', $source->path);
        self::assertSame('example.com', $source->host);
        self::assertSame([], $source->matches('/old'));
        self::assertSame([], $source->matches(SourcePattern::requestPath('/old/')));
        self::assertNull($source->matches('/old/x'));
        self::assertNull($source->matches('/older'));
        self::assertSame(0, $source->captures());
        self::assertSame('/old', $source->sample());
        self::assertSame('old', $source->firstSegment());
    }

    public function testTheRootStaysTheRoot(): void
    {
        self::assertSame('/', SourcePattern::exact('/')->path);
        self::assertSame('/', SourcePattern::requestPath('/'));
        self::assertSame('/', SourcePattern::requestPath(''));
        self::assertSame('', SourcePattern::exact('/')->firstSegment());
    }

    public function testAPathIsKeptPercentEncodedWithCapitalEscapes(): void
    {
        self::assertSame('/caf%C3%A9%20noir', SourcePattern::exact('/café noir')->path);
        self::assertSame('/a%C3%A9', SourcePattern::exact('/a%c3%a9')->path);
        self::assertSame('/a%C3%A9', SourcePattern::requestPath('/a%c3%a9'));
        self::assertSame('/a%zz', SourcePattern::requestPath('/a%zz'));
    }

    public function testAPrefixMatchesSegmentBySegmentAndGivesTheRest(): void
    {
        $source = SourcePattern::prefix('/docs');

        self::assertSame([''], $source->matches('/docs'));
        self::assertSame(['/a/b'], $source->matches('/docs/a/b'));
        self::assertNull($source->matches('/docsx'));
        self::assertNull($source->matches('/other/docs'));
        self::assertSame(0, $source->captures());
        self::assertSame('/docs', $source->sample());
        self::assertSame('docs', SourcePattern::prefix('/docs/v1')->firstSegment());
    }

    public function testThePrefixOfTheRootMatchesEveryPath(): void
    {
        $source = SourcePattern::prefix('/');

        self::assertSame([''], $source->matches('/'));
        self::assertSame(['/a/b'], $source->matches('/a/b'));
    }

    public function testAWildcardCapturesOneSegmentPerStarAndTheRestForALastDoubleStar(): void
    {
        $source = SourcePattern::wildcard('/blog/*/posts/**');

        self::assertSame(['2024', 'a/b'], $source->matches('/blog/2024/posts/a/b'));
        self::assertSame(['2024', ''], $source->matches('/blog/2024/posts'));
        self::assertNull($source->matches('/blog/2024'));
        self::assertNull($source->matches('/blog/2024/other/a'));
        self::assertNull($source->matches('/news/2024/posts/a'));
        self::assertSame(2, $source->captures());
        self::assertSame('/blog/x/posts/x', $source->sample());
        self::assertSame('blog', $source->firstSegment());
    }

    public function testAStarIsOneNonEmptySegmentNeverMore(): void
    {
        $source = SourcePattern::wildcard('/a/*');

        self::assertSame(['b'], $source->matches('/a/b'));
        self::assertNull($source->matches('/a/b/c'));
        self::assertNull($source->matches('/a'));
        self::assertNull(SourcePattern::wildcard('/a/*/c')->matches('/a//c'));
        self::assertSame('*', SourcePattern::wildcard('/*/b')->firstSegment());
        self::assertSame(['x'], SourcePattern::wildcard('/*/b')->matches('/x/b'));
    }

    public function testADoubleStarAloneMatchesEveryPath(): void
    {
        $source = SourcePattern::wildcard('/**');

        self::assertSame([''], $source->matches('/'));
        self::assertSame(['a/b/c'], $source->matches('/a/b/c'));
        self::assertSame('*', $source->firstSegment());
    }

    public function testAWildcardWithoutAWildcardIsAnExactMatch(): void
    {
        $source = SourcePattern::wildcard('/a/b');

        self::assertSame([], $source->matches('/a/b'));
        self::assertNull($source->matches('/a/b/c'));
        self::assertNull($source->matches('/a/c'));
        self::assertSame(0, $source->captures());
    }

    public function testAWildcardSourceIsBounded(): void
    {
        $nine = '/'.implode('/', array_fill(0, 9, '*'));
        self::assertSame(9, SourcePattern::wildcard($nine)->captures());
        $this->assertRefused('too_many_captures', static fn (): SourcePattern => SourcePattern::wildcard($nine.'/*'));

        $segments = '/'.implode('/', array_fill(0, SourcePattern::MAX_SEGMENTS, 'a'));
        self::assertSame($segments, SourcePattern::wildcard($segments)->path);
        $this->assertRefused('too_many_segments', static fn (): SourcePattern => SourcePattern::wildcard($segments.'/a'));
        $this->assertRefused('rest_not_last', static fn (): SourcePattern => SourcePattern::wildcard('/**/a'));
        $this->assertRefused('partial_wildcard', static fn (): SourcePattern => SourcePattern::wildcard('/a*'));
        self::assertSame('/a*', SourcePattern::exact('/a*')->path);
    }

    public function testARegularExpressionIsAnchoredAndCaptures(): void
    {
        $source = SourcePattern::regex('/item-(\d+)(?:/(\w+))?', 'example.com');

        self::assertSame(MatchKind::Regex, $source->kind);
        self::assertSame('/item-(\d+)(?:/(\w+))?', $source->path);
        self::assertSame(['42', 'x'], $source->matches('/item-42/x'));
        self::assertSame(['42'], $source->matches('/item-42'));
        self::assertNull($source->matches('/prefix/item-42'));
        self::assertNull($source->matches('/item-42/x/y'));
        self::assertSame(SourcePattern::MAX_CAPTURES, $source->captures());
        self::assertNull($source->sample());
        self::assertSame('', $source->firstSegment());
        self::assertSame(['1', '2', '3', '4', '5', '6', '7', '8', '9'], SourcePattern::regex('/'.str_repeat('(\d)', 10))->matches('/1234567890'));
    }

    public function testARegularExpressionIsBoundedAndChecked(): void
    {
        self::assertSame('/a{3}', SourcePattern::regex('/a{3}', maxLength: 5)->path);
        $this->assertRefused('regex_length', static fn (): SourcePattern => SourcePattern::regex('/a{3}', maxLength: 4));
        $this->assertRefused('regex_length', static fn (): SourcePattern => SourcePattern::regex(''));
        $this->assertRefused('regex_characters', static fn (): SourcePattern => SourcePattern::regex('/a b'));
        $this->assertRefused('regex_characters', static fn (): SourcePattern => SourcePattern::regex('/a#b'));
        $this->assertRefused('regex_characters', static fn (): SourcePattern => SourcePattern::regex("/a\x7F"));
        $this->assertRefused('regex_invalid', static fn (): SourcePattern => SourcePattern::regex('/(a'));
    }

    public function testARegularExpressionThatFailsAtRunTimeMatchesNothing(): void
    {
        $source = SourcePattern::regex('/(?:a+)+$x');

        self::assertNull($source->matches('/'.str_repeat('a', 2000)));
    }

    public function testOfDispatchesOnTheKind(): void
    {
        self::assertSame(MatchKind::Exact, SourcePattern::of(MatchKind::Exact, '/a')->kind);
        self::assertSame(MatchKind::Prefix, SourcePattern::of(MatchKind::Prefix, '/a', 'h.example')->kind);
        self::assertSame('h.example', SourcePattern::of(MatchKind::Prefix, '/a', 'h.example')->host);
        self::assertSame(MatchKind::Wildcard, SourcePattern::of(MatchKind::Wildcard, '/a/*')->kind);
        self::assertSame(MatchKind::Regex, SourcePattern::of(MatchKind::Regex, '/a+')->kind);
        $this->assertRefused('regex_length', static fn (): SourcePattern => SourcePattern::of(MatchKind::Regex, '/a+', null, 2));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'relative' => ['old', 'path_start'];
        yield 'empty' => ['', 'path_start'];
        yield 'protocol relative' => ['//evil.example', 'path_start'];
        yield 'query' => ['/a?b', 'path_characters'];
        yield 'fragment' => ['/a#b', 'path_characters'];
        yield 'backslash' => ['/a\b', 'path_characters'];
        yield 'control' => ["/a\nb", 'path_characters'];
        yield 'delete' => ["/a\x7Fb", 'path_characters'];
        yield 'empty segment' => ['/a//b', 'empty_segment'];
        yield 'too long' => ['/'.str_repeat('a', SourcePattern::MAX_PATH_LENGTH), 'path_length'];
    }

    #[DataProvider('invalidPaths')]
    public function testAnInvalidPathIsRefused(string $path, string $reason): void
    {
        $this->assertRefused($reason, static fn (): SourcePattern => SourcePattern::exact($path));
    }

    public function testTheLongestPathIsAccepted(): void
    {
        $path = '/'.str_repeat('a', SourcePattern::MAX_PATH_LENGTH - 1);

        self::assertSame($path, SourcePattern::exact($path)->path);
        self::assertSame([], SourcePattern::exact($path)->matches($path));
        self::assertNull(SourcePattern::prefix('/a')->matches($path.'a'));
    }

    public function testAHostIsAHostName(): void
    {
        self::assertNull(SourcePattern::normalizedHost(null));
        self::assertNull(SourcePattern::normalizedHost(''));
        self::assertSame('a-b.example', SourcePattern::normalizedHost('A-B.Example.'));
        self::assertSame(str_repeat('a', 63), SourcePattern::normalizedHost(str_repeat('a', 63)));

        $this->assertRefused('host', static fn (): ?string => SourcePattern::normalizedHost(str_repeat('a', 64)));
        $this->assertRefused('host', static fn (): ?string => SourcePattern::normalizedHost('-a.example'));
        $this->assertRefused('host', static fn (): ?string => SourcePattern::normalizedHost('a.example:80'));
        $this->assertRefused('host', static fn (): ?string => SourcePattern::normalizedHost('a..example'));
    }

    public function testTheHostLengthLimitIsInclusive(): void
    {
        $host = str_repeat('a.', 126).'a';

        self::assertSame(253, \strlen($host));
        self::assertSame($host, SourcePattern::normalizedHost($host));
        $this->assertRefused('host', static fn (): ?string => SourcePattern::normalizedHost('a'.$host));
    }

    public function testTwoSourcesAreEqualByKindHostAndPath(): void
    {
        $source = SourcePattern::exact('/a', 'h.example');

        self::assertTrue($source->equals(SourcePattern::exact('/a/', 'H.example')));
        self::assertFalse($source->equals(SourcePattern::prefix('/a', 'h.example')));
        self::assertFalse($source->equals(SourcePattern::exact('/a')));
        self::assertFalse($source->equals(SourcePattern::exact('/b', 'h.example')));
    }

    public function testTheFirstSegmentOfAPath(): void
    {
        self::assertSame('a', SourcePattern::firstSegmentOf('/a/b'));
        self::assertSame('a', SourcePattern::firstSegmentOf('/a'));
        self::assertSame('', SourcePattern::firstSegmentOf('/'));
    }

    public function testTheMessageCarriesItsParameters(): void
    {
        $exception = InvalidRedirect::because('host', 'The host "%host%" is not a host name.', ['%host%' => 'x']);

        self::assertSame('The host "x" is not a host name.', $exception->getMessage());
        self::assertSame('host', $exception->reason);
        self::assertSame(['%host%' => 'x'], $exception->parameters);
    }

    public function testTheRefusalsCarryTheirParameters(): void
    {
        $this->assertParameters(['%max%' => '32'], static fn (): SourcePattern => SourcePattern::wildcard('/'.implode('/', array_fill(0, 33, 'a'))));
        $this->assertParameters(['%max%' => '9'], static fn (): SourcePattern => SourcePattern::wildcard('/'.implode('/', array_fill(0, 10, '*'))));
        $this->assertParameters(['%max%' => '4'], static fn (): SourcePattern => SourcePattern::regex('/abcd', maxLength: 4));
        $this->assertParameters(['%max%' => '2048'], static fn (): SourcePattern => SourcePattern::exact('/'.str_repeat('a', 2048)));
        $this->assertParameters(['%host%' => 'a b'], static fn (): string => SourcePattern::hostName('A B'));
    }

    public function testAHostNameIsReadDirectly(): void
    {
        self::assertSame('a.example', SourcePattern::hostName('A.Example.'));
    }

    public function testARegularExpressionThatDoesNotCompileWarnsNobody(): void
    {
        error_clear_last();

        try {
            SourcePattern::regex('/(a');
        } catch (InvalidRedirect) {
        }

        self::assertNull(error_get_last());
    }

    public function testAPathLongerThanTheLimitMatchesNoPrefix(): void
    {
        self::assertNull(SourcePattern::prefix('/')->matches('/'.str_repeat('a', SourcePattern::MAX_PATH_LENGTH)));
        self::assertSame(['/'.str_repeat('a', SourcePattern::MAX_PATH_LENGTH - 1)], SourcePattern::prefix('/')->matches('/'.str_repeat('a', SourcePattern::MAX_PATH_LENGTH - 1)));
    }

    /**
     * @param array<string, string> $parameters
     * @param callable(): mixed     $build
     */
    private function assertParameters(array $parameters, callable $build): void
    {
        try {
            $build();
        } catch (InvalidRedirect $invalidRedirect) {
            self::assertSame($parameters, $invalidRedirect->parameters);

            return;
        }

        self::fail('Expected a refusal');
    }

    /**
     * @param callable(): mixed $build
     */
    private function assertRefused(string $reason, callable $build): void
    {
        try {
            $build();
        } catch (InvalidRedirect $invalidRedirect) {
            self::assertSame($reason, $invalidRedirect->reason, $invalidRedirect->getMessage());

            return;
        }

        self::fail('Expected the reason '.$reason);
    }
}
