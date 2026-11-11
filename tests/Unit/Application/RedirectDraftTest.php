<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Application;

use Phil\RedirectsBundle\Application\RedirectDraft;
use Phil\RedirectsBundle\Domain\Enum\MatchKind;
use Phil\RedirectsBundle\Domain\Enum\QueryPolicy;
use Phil\RedirectsBundle\Domain\Enum\RedirectStatus;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RedirectDraft::class)]
final class RedirectDraftTest extends TestCase
{
    public function testTheDefaults(): void
    {
        $draft = new RedirectDraft('/a');

        self::assertSame('', $draft->target);
        self::assertSame('301', $draft->status);
        self::assertSame('exact', $draft->kind);
        self::assertSame('', $draft->host);
        self::assertSame('drop', $draft->query);
        self::assertSame('', $draft->locale);
        self::assertTrue($draft->enabled);
    }

    public function testADraftIsReadIntoARuleItsSpacesTrimmed(): void
    {
        $rule = new RedirectDraft(' /a/* ', ' /b/$1 ', ' 308 ', ' wildcard ', ' H.example ', ' keep ', ' fr ')->rule();

        self::assertSame(MatchKind::Wildcard, $rule->source->kind);
        self::assertSame('/a/*', $rule->source->path);
        self::assertSame('h.example', $rule->source->host);
        self::assertSame('/b/$1', $rule->target->url);
        self::assertSame(RedirectStatus::PermanentRedirect, $rule->status);
        self::assertSame(QueryPolicy::Keep, $rule->query);
        self::assertSame('fr', $rule->locale);
        self::assertNull(new RedirectDraft('/a', '/b')->rule()->source->host);
    }

    public function testATargetOnLocalhostIsReadOnlyWhenAllowed(): void
    {
        $draft = new RedirectDraft('/a', 'http://localhost:8000/b');

        try {
            $draft->rule();
            self::fail('A target on localhost was read.');
        } catch (InvalidRedirect $invalidRedirect) {
            self::assertSame('host', $invalidRedirect->reason);
        }

        self::assertSame('localhost', $draft->rule(allowLocalhost: true)->target->host);
    }

    public function testARegularExpressionIsReadWithTheLengthLimit(): void
    {
        self::assertSame('/a+', new RedirectDraft('/a+', '/b', kind: 'regex')->rule(3)->source->path);

        $this->expectException(InvalidRedirect::class);
        new RedirectDraft('/a+', '/b', kind: 'regex')->rule(2);
    }

    /**
     * @return iterable<string, array{RedirectDraft, string}>
     */
    public static function invalidDrafts(): iterable
    {
        yield 'kind' => [new RedirectDraft('/a', '/b', kind: 'fuzzy'), 'kind'];
        yield 'status' => [new RedirectDraft('/a', '/b', '303'), 'status'];
        yield 'status text' => [new RedirectDraft('/a', '/b', '301x'), 'status'];
        yield 'status sign' => [new RedirectDraft('/a', '/b', '+301'), 'status'];
        yield 'query' => [new RedirectDraft('/a', '/b', query: 'merge'), 'query'];
    }

    #[DataProvider('invalidDrafts')]
    public function testAnInvalidDraftIsRefused(RedirectDraft $draft, string $reason): void
    {
        try {
            $draft->rule();
            self::fail('Expected a refusal');
        } catch (InvalidRedirect $invalidRedirect) {
            self::assertSame($reason, $invalidRedirect->reason);
            self::assertSame(['%'.$reason.'%' => match ($reason) {
                'kind' => $draft->kind,
                'status' => $draft->status,
                default => $draft->query,
            }], $invalidRedirect->parameters);
        }
    }

    public function testARuleIsWrittenBackAsADraft(): void
    {
        $rule = new RedirectRule(SourcePattern::prefix('/a', 'h.example'), Target::route('home', ['x' => '1']), RedirectStatus::Found, QueryPolicy::Keep, 'fr');
        $draft = RedirectDraft::fromRule($rule, false);

        self::assertEquals(new RedirectDraft('/a', 'route:home?x=1', '302', 'prefix', 'h.example', 'keep', 'fr', false), $draft);
        self::assertEquals($rule, $draft->rule());
        self::assertEquals(new RedirectDraft('/a', '', '410'), RedirectDraft::fromRule(new RedirectRule(SourcePattern::exact('/a'), Target::none(), RedirectStatus::Gone)));
    }
}
