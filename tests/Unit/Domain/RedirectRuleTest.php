<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Domain;

use DateTimeImmutable;
use Phil\RedirectsBundle\Domain\Enum\QueryPolicy;
use Phil\RedirectsBundle\Domain\Enum\RedirectStatus;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Phil\RedirectsBundle\Domain\Model\Redirect;
use Phil\RedirectsBundle\Domain\ValueObject\NotFoundEntry;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RedirectRule::class)]
#[CoversClass(RedirectStatus::class)]
#[CoversClass(Redirect::class)]
#[CoversClass(NotFoundEntry::class)]
final class RedirectRuleTest extends TestCase
{
    public function testTheDefaults(): void
    {
        $rule = new RedirectRule(SourcePattern::exact('/a'), Target::url('/b'));

        self::assertSame(RedirectStatus::MovedPermanently, $rule->status);
        self::assertSame(QueryPolicy::Drop, $rule->query);
        self::assertNull($rule->locale);
        self::assertNull($rule->id);
        self::assertTrue($rule->appliesTo(null));
        self::assertTrue($rule->appliesTo('fr'));
    }

    public function testALocaleLimitsTheRule(): void
    {
        $rule = new RedirectRule(SourcePattern::exact('/a'), Target::url('/b'), locale: 'fr_CA');

        self::assertSame('fr_CA', $rule->locale);
        self::assertTrue($rule->appliesTo('fr_CA'));
        self::assertFalse($rule->appliesTo('fr'));
        self::assertFalse($rule->appliesTo(null));
        self::assertNull(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b'), locale: '')->locale);
        self::assertSame('zh-Hant-TW', new RedirectRule(SourcePattern::exact('/a'), Target::url('/b'), locale: 'zh-Hant-TW')->locale);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidLocales(): iterable
    {
        yield 'capital' => ['FR'];
        yield 'one letter' => ['f'];
        yield 'separator' => ['fr_'];
        yield 'script' => ['fr<script>'];
        yield 'subtags' => ['fr-aa-bb-cc-dd'];
        yield 'trailing' => ["fr\n"];
    }

    #[DataProvider('invalidLocales')]
    public function testAnInvalidLocaleIsRefused(string $locale): void
    {
        try {
            new RedirectRule(SourcePattern::exact('/a'), Target::url('/b'), locale: $locale);
            self::fail('Expected a refusal');
        } catch (InvalidRedirect $invalidRedirect) {
            self::assertSame(['%locale%' => $locale], $invalidRedirect->parameters);
        }
    }

    public function testAGoneHasNoTargetAndARedirectHasOne(): void
    {
        $gone = new RedirectRule(SourcePattern::exact('/a'), Target::none(), RedirectStatus::Gone);
        self::assertTrue($gone->status->isGone());

        try {
            new RedirectRule(SourcePattern::exact('/a'), Target::url('/b'), RedirectStatus::Gone);
            self::fail('A gone with a target');
        } catch (InvalidRedirect $invalidRedirect) {
            self::assertSame('gone_target', $invalidRedirect->reason);
        }

        try {
            new RedirectRule(SourcePattern::exact('/a'), Target::none());
            self::fail('A redirect without a target');
        } catch (InvalidRedirect $invalidRedirect) {
            self::assertSame('missing_target', $invalidRedirect->reason);
        }
    }

    public function testATargetRefersOnlyToWhatTheSourceCaptures(): void
    {
        self::assertSame(2, new RedirectRule(SourcePattern::wildcard('/*/*'), Target::url('/$2/$1'))->target->highestPlaceholder());

        try {
            new RedirectRule(SourcePattern::wildcard('/*'), Target::url('/$2'));
            self::fail('A placeholder without a capture');
        } catch (InvalidRedirect $invalidRedirect) {
            self::assertSame('placeholder', $invalidRedirect->reason);
            self::assertSame(['%placeholder%' => '2'], $invalidRedirect->parameters);
        }
    }

    public function testIdentityAndOverlap(): void
    {
        $rule = new RedirectRule(SourcePattern::exact('/a'), Target::url('/b'), RedirectStatus::Found, QueryPolicy::Keep, 'fr', 3);
        $copy = $rule->withId(7);

        self::assertSame(7, $copy->id);
        self::assertSame(RedirectStatus::Found, $copy->status);
        self::assertSame(QueryPolicy::Keep, $copy->query);
        self::assertSame('fr', $copy->locale);
        self::assertSame($rule->source, $copy->source);
        self::assertSame($rule->target, $copy->target);
        self::assertTrue($rule->overlaps(new RedirectRule(SourcePattern::exact('/a/'), Target::url('/c'), locale: 'fr')));
        self::assertFalse($rule->overlaps(new RedirectRule(SourcePattern::exact('/a'), Target::url('/c'))));
        self::assertFalse($rule->overlaps(new RedirectRule(SourcePattern::prefix('/a'), Target::url('/c'), locale: 'fr')));
    }

    public function testTheStatuses(): void
    {
        self::assertTrue(RedirectStatus::MovedPermanently->isPermanent());
        self::assertTrue(RedirectStatus::PermanentRedirect->isPermanent());
        self::assertTrue(RedirectStatus::Gone->isPermanent());
        self::assertFalse(RedirectStatus::Found->isPermanent());
        self::assertFalse(RedirectStatus::TemporaryRedirect->isPermanent());
        self::assertFalse(RedirectStatus::Found->isGone());
    }

    public function testARedirectIsIdentifiedOnceAndChanged(): void
    {
        $created = new DateTimeImmutable('2026-01-01');
        $redirect = new Redirect(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b'), id: 99), true, $created);

        self::assertNull($redirect->id());
        self::assertNull($redirect->rule()->id);
        self::assertSame($created, $redirect->updatedAt());
        self::assertSame($created, $redirect->createdAt());
        self::assertSame(0, $redirect->hits());
        self::assertNull($redirect->lastHitAt());
        self::assertTrue($redirect->isEnabled());

        $redirect->identify(5);
        $redirect->identify(6);
        self::assertSame(5, $redirect->id());
        self::assertSame(5, $redirect->rule()->id);

        $changed = new DateTimeImmutable('2026-02-01');
        $redirect->change(new RedirectRule(SourcePattern::exact('/c'), Target::url('/d')), false, $changed);
        self::assertSame('/c', $redirect->rule()->source->path);
        self::assertSame(5, $redirect->rule()->id);
        self::assertFalse($redirect->isEnabled());
        self::assertSame($changed, $redirect->updatedAt());
        self::assertSame($created, $redirect->createdAt());
    }

    public function testAStoredRedirectKeepsItsCounters(): void
    {
        $hit = new DateTimeImmutable('2026-03-01');
        $updated = new DateTimeImmutable('2026-02-01');
        $redirect = new Redirect(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b')), false, new DateTimeImmutable('2026-01-01'), 4, 12, $hit, $updated);

        self::assertSame(4, $redirect->rule()->id);
        self::assertSame(12, $redirect->hits());
        self::assertSame($hit, $redirect->lastHitAt());
        self::assertSame($updated, $redirect->updatedAt());

        $entry = new NotFoundEntry(1, 'h.example', '/a', 3, $updated, $hit);
        self::assertNull($entry->referrerHost);
    }
}
