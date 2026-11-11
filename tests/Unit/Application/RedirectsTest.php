<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Application;

use DateTimeImmutable;
use Phil\RedirectsBundle\Application\ChainInspector;
use Phil\RedirectsBundle\Application\Exception\RedirectNotFound;
use Phil\RedirectsBundle\Application\Exception\RedirectRefused;
use Phil\RedirectsBundle\Application\Finding;
use Phil\RedirectsBundle\Application\ImportReport;
use Phil\RedirectsBundle\Application\LocationBuilder;
use Phil\RedirectsBundle\Application\RedirectDraft;
use Phil\RedirectsBundle\Application\Redirects;
use Phil\RedirectsBundle\Application\RedirectTableCache;
use Phil\RedirectsBundle\Application\StoredRedirects;
use Phil\RedirectsBundle\Application\TargetPolicy;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Phil\RedirectsBundle\Domain\Model\Redirect;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use Phil\RedirectsBundle\Tests\Unit\Fixture\InMemoryRedirects;
use Phil\RedirectsBundle\Tests\Unit\Fixture\ListProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(Redirects::class)]
#[CoversClass(StoredRedirects::class)]
#[CoversClass(TargetPolicy::class)]
#[CoversClass(ImportReport::class)]
#[CoversClass(Finding::class)]
#[CoversClass(RedirectNotFound::class)]
final class RedirectsTest extends TestCase
{
    private InMemoryRedirects $repository;

    private ListProvider $provider;

    private ArrayAdapter $pool;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->repository = new InMemoryRedirects();
        $this->provider = new ListProvider();
        $this->pool = new ArrayAdapter();
        $this->clock = new MockClock('2026-05-01 10:00:00');
    }

    public function testARedirectIsCreatedAndTheTableInvalidated(): void
    {
        $redirects = $this->redirects();
        $this->pool->save($this->pool->getItem('table')->set('cached'));

        $redirect = $redirects->create(self::rule('/a', '/b'), false);

        self::assertSame(1, $redirect->id());
        self::assertFalse($redirect->isEnabled());
        self::assertEquals($this->clock->now(), $redirect->createdAt());
        self::assertSame($redirect, $redirects->get(1));
        self::assertFalse($this->pool->hasItem('table'));
    }

    public function testAnUpdateChangesTheRuleAndKeepsTheIdentifier(): void
    {
        $redirects = $this->redirects();
        $redirects->create(self::rule('/a', '/b'));

        $this->clock->sleep(60);
        $this->pool->save($this->pool->getItem('table')->set('cached'));

        $saves = $this->repository->saves;
        $redirect = $redirects->update(1, self::rule('/a', '/c'), true);
        self::assertSame($saves + 1, $this->repository->saves);

        self::assertSame('/c', $redirect->rule()->target->url);
        self::assertSame(1, $redirect->id());
        self::assertEquals($this->clock->now(), $redirect->updatedAt());
        self::assertFalse($this->pool->hasItem('table'));
        self::assertSame('/c', $this->redirects()->get(1)->rule()->target->url);
    }

    public function testARedirectMayBeSavedAgainUnchanged(): void
    {
        $redirects = $this->redirects();
        $redirects->create(self::rule('/a', '/b'));

        self::assertTrue($redirects->update(1, self::rule('/a', '/b'), true)->isEnabled());
        self::assertFalse($redirects->enable(1, false)->isEnabled());
        self::assertTrue($redirects->enable(1, true)->isEnabled());
    }

    public function testAMissingRedirect(): void
    {
        $redirects = $this->redirects();

        try {
            $redirects->get(9);
            self::fail('Expected a missing redirect');
        } catch (RedirectNotFound $redirectNotFound) {
            self::assertSame('No redirect has the identifier 9.', $redirectNotFound->getMessage());
        }

        $this->expectException(RedirectNotFound::class);
        $redirects->update(9, self::rule('/a', '/b'), true);
    }

    public function testADeletion(): void
    {
        $redirects = $this->redirects();
        $redirects->create(self::rule('/a', '/b'));

        $this->pool->save($this->pool->getItem('table')->set('cached'));

        $redirects->delete(1);

        self::assertSame([], $this->repository->all());
        self::assertFalse($this->pool->hasItem('table'));
        $this->expectException(RedirectNotFound::class);
        $redirects->delete(1);
    }

    public function testADeletionOfAMissingRedirectKeepsTheTable(): void
    {
        $this->pool->save($this->pool->getItem('table')->set('cached'));

        try {
            $this->redirects()->delete(4);
        } catch (RedirectNotFound) {
        }

        self::assertTrue($this->pool->hasItem('table'));
    }

    public function testASourceIsTakenOnce(): void
    {
        $redirects = $this->redirects();
        $redirects->create(self::rule('/a', '/b'), false);

        $this->assertRefused('duplicate', static fn (): Redirect => $redirects->create(self::rule('/a/', '/c')));
        $redirects->create(new RedirectRule(SourcePattern::exact('/a'), Target::url('/c'), locale: 'fr'));
        $redirects->create(new RedirectRule(SourcePattern::prefix('/a'), Target::url('/d')));
        $redirects->create(new RedirectRule(SourcePattern::exact('/a', 'h.example'), Target::url('/c')));
        self::assertCount(4, $this->repository->all());
        $this->assertRefused('duplicate', static fn (): Redirect => $redirects->update(2, self::rule('/a', '/e'), true));
    }

    public function testTheGateOfASave(): void
    {
        $redirects = $this->redirects();

        $this->assertRefused('regex_disabled', static fn (): Redirect => $redirects->create(new RedirectRule(SourcePattern::regex('/a+'), Target::url('/b'))));
        $this->assertRefused('external_target', static fn (): Redirect => $redirects->create(self::rule('/a', 'https://evil.example/')), ['%host%' => 'evil.example']);
        $this->assertRefused('loop', static fn (): Redirect => $redirects->create(self::rule('/a', '/a')));
        self::assertSame(0, $this->repository->saves);

        self::assertSame('partner.example', $redirects->create(self::rule('/a', 'https://Partner.Example/'))->rule()->target->host);
        self::assertTrue($this->redirects(regex: true)->create(new RedirectRule(SourcePattern::regex('/a+'), Target::url('/b')))->isEnabled());
    }

    public function testALoopOrChainIsCheckedOnlyForAnEnabledRedirect(): void
    {
        $redirects = $this->redirects();
        $redirects->create(self::rule('/b', '/c'));

        $this->assertRefused('chain', static fn (): Redirect => $redirects->create(self::rule('/a', '/b')));
        self::assertFalse($redirects->create(self::rule('/a', '/b'), false)->isEnabled());
        $this->assertRefused('chain', static fn (): Redirect => $redirects->enable(2, true));
        $redirects->enable(1, false);
        self::assertTrue($redirects->enable(2, true)->isEnabled());
    }

    public function testAnUpdateIsCheckedWithoutTheRedirectItReplaces(): void
    {
        $redirects = $this->redirects();
        $redirects->create(self::rule('/a', '/b'));

        self::assertSame('/b', $redirects->update(1, self::rule('/b', '/c'), true)->rule()->source->path);
        self::assertSame('/a', $redirects->update(1, self::rule('/c', '/a'), true)->rule()->target->url);
    }

    public function testTheProvidersCountForTheChecks(): void
    {
        $this->provider->rules = [self::rule('/b', '/c')];

        $this->assertRefused('chain', fn (): Redirect => $this->redirects()->create(self::rule('/a', '/b')));
    }

    public function testTheRegexSettings(): void
    {
        self::assertFalse(new Redirects($this->repository, new RedirectTableCache([]), new ChainInspector(new LocationBuilder()), new TargetPolicy(), $this->clock)->regexAllowed());
        self::assertFalse($this->redirects()->regexAllowed());
        self::assertTrue($this->redirects(regex: true)->regexAllowed());
        self::assertSame(SourcePattern::DEFAULT_REGEX_MAX_LENGTH, $this->redirects()->regexMaxLength());
        self::assertSame(12, $this->redirects(maxLength: 12)->regexMaxLength());
    }

    public function testAnImportGoesLineByLineEachThroughTheGate(): void
    {
        $redirects = $this->redirects();
        $redirects->create(self::rule('/kept', '/x'));

        $this->pool->save($this->pool->getItem('table')->set('cached'));

        $report = $redirects->import([
            2 => new RedirectDraft('/a', '/b'),
            3 => new RedirectDraft('/kept', '/y'),
            4 => new RedirectDraft('/c', '/a'),
            5 => InvalidRedirect::because('csv_cell', 'Too long.'),
            6 => new RedirectDraft('bad', '/b'),
            7 => new RedirectDraft('/a', '/z'),
            8 => new RedirectDraft('/off', '/a', enabled: false),
        ]);

        self::assertSame(2, $report->created);
        self::assertSame(0, $report->updated);
        self::assertSame([3, 7], $report->skipped);
        self::assertSame([4, 5, 6], array_keys($report->rejected));
        self::assertSame('Too long.', $report->rejected[5]);
        self::assertStringContainsString('chain', $report->rejected[4]);
        self::assertFalse($report->isClean());
        self::assertFalse($report->dryRun);
        self::assertCount(3, $this->repository->all());
        self::assertFalse($this->redirects()->get(3)->isEnabled());
        self::assertFalse($this->pool->hasItem('table'));
    }

    public function testAnImportUpdatesWhenAsked(): void
    {
        $redirects = $this->redirects();
        $redirects->create(self::rule('/kept', '/x'));

        $report = $redirects->import([2 => new RedirectDraft('/kept', '/y'), 3 => new RedirectDraft('/kept', '/z')], true);

        self::assertSame(0, $report->created);
        self::assertSame(2, $report->updated);
        self::assertTrue($report->isClean());
        self::assertSame('/z', $redirects->get(1)->rule()->target->url);
        self::assertCount(1, $this->repository->all());
    }

    public function testAnImportThatOnlyUpdatesInvalidatesTheTable(): void
    {
        $redirects = $this->redirects();
        $redirects->create(self::rule('/kept', '/x'));

        $this->pool->save($this->pool->getItem('table')->set('cached'));

        $redirects->import([2 => new RedirectDraft('/kept', '/y')], true);

        self::assertFalse($this->pool->hasItem('table'));
    }

    public function testEveryStoredRedirectCountsForTheDuplicates(): void
    {
        $redirects = $this->redirects();
        $redirects->create(self::rule('/a', '/x'));
        $redirects->create(self::rule('/b', '/y'));

        $this->assertRefused('duplicate', static fn (): Redirect => $redirects->create(self::rule('/b', '/z')), ['%source%' => '/b']);
        self::assertSame([2 => [3]], [\count($this->repository->all()) => $redirects->import([3 => new RedirectDraft('/b', '/w')])->skipped]);
    }

    public function testADryRunWritesNothing(): void
    {
        $redirects = $this->redirects();
        $this->pool->save($this->pool->getItem('table')->set('cached'));

        $report = $redirects->import([2 => new RedirectDraft('/a', '/b'), 3 => new RedirectDraft('/b', '/c')], false, true);

        self::assertTrue($report->dryRun);
        self::assertSame(1, $report->created);
        self::assertSame([3], array_keys($report->rejected));
        self::assertSame([], $this->repository->all());
        self::assertTrue($this->pool->hasItem('table'));
    }

    public function testAnImportThatChangesNothingKeepsTheTable(): void
    {
        $this->pool->save($this->pool->getItem('table')->set('cached'));

        $report = $this->redirects()->import([2 => new RedirectDraft('bad')]);

        self::assertSame(0, $report->created);
        self::assertTrue($this->pool->hasItem('table'));
    }

    public function testAnImportReadsRegularExpressionsWithTheLengthLimit(): void
    {
        $report = $this->redirects(regex: true, maxLength: 4)->import([2 => new RedirectDraft('/a+', '/b', kind: 'regex'), 3 => new RedirectDraft('/a+bcd', '/b', kind: 'regex')]);

        self::assertSame(1, $report->created);
        self::assertSame([3], array_keys($report->rejected));
    }

    public function testTheCheckReportsWhatTheGateWouldRefuseToday(): void
    {
        $this->repository->save(new Redirect(self::rule('/a', 'https://gone.example/'), true, new DateTimeImmutable()));
        $this->repository->save(new Redirect(new RedirectRule(SourcePattern::regex('/r+'), Target::url('/x')), false, new DateTimeImmutable()));
        $this->repository->save(new Redirect(self::rule('/b', '/c'), true, new DateTimeImmutable()));
        $this->repository->save(new Redirect(self::rule('/c', '/d'), true, new DateTimeImmutable()));
        $this->repository->save(new Redirect(self::rule('/e', '/c'), false, new DateTimeImmutable()));
        $this->repository->save(new Redirect(self::rule('/f', '/g'), true, new DateTimeImmutable()));

        $findings = $this->redirects()->check();

        self::assertSame([1, 2, 3, 4], array_map(static fn (Finding $finding): int => $finding->id, $findings));
        self::assertSame(['external_target', 'regex_disabled', 'chain', 'chain_into'], array_map(static fn (Finding $finding): string => $finding->reason, $findings));
        self::assertSame('/a', $findings[0]->source);
        self::assertSame('The host "gone.example" is not one a redirect may send to.', $findings[0]->message);
    }

    public function testTheStoredProviderGivesTheEnabledRedirects(): void
    {
        $this->repository->save(new Redirect(self::rule('/a', '/b'), true, new DateTimeImmutable()));
        $this->repository->save(new Redirect(self::rule('/c', '/d'), false, new DateTimeImmutable()));

        $rules = iterator_to_array(new StoredRedirects($this->repository)->redirects(), false);

        self::assertCount(1, $rules);
        self::assertSame(1, $rules[0]->id);
    }

    public function testAReportIsNotADryRunByDefault(): void
    {
        self::assertFalse(new ImportReport()->dryRun);
    }

    public function testTheTargetPolicy(): void
    {
        $policy = new TargetPolicy(['Partner.Example.']);

        self::assertTrue($policy->allows(Target::url('/a')));
        self::assertTrue($policy->allows(Target::route('home')));
        self::assertTrue($policy->allows(Target::url('https://partner.example/')));
        self::assertFalse($policy->allows(Target::url('https://evil.example/')));
        self::assertFalse(new TargetPolicy()->allows(Target::url('https://partner.example/')));
        // An international host is allowed by its letters or by its ASCII form, as phil/url reads it.
        $international = new TargetPolicy(['Bücher.Example.']);
        self::assertTrue($international->allows(Target::url('https://bücher.example/')));
        self::assertTrue($international->allows(Target::url('https://xn--bcher-kva.example/')));
        // What is not a host name allows nothing: neither the host before a path or a port, nor another.
        self::assertFalse(new TargetPolicy(['partner.example/evil'])->allows(Target::url('https://partner.example/')));
        self::assertFalse(new TargetPolicy(['partner.example:8443'])->allows(Target::url('https://partner.example/')));
        self::assertFalse(new TargetPolicy(['user@partner.example'])->allows(Target::url('https://partner.example/')));
    }

    private function redirects(bool $regex = false, int $maxLength = SourcePattern::DEFAULT_REGEX_MAX_LENGTH): Redirects
    {
        return new Redirects(
            $this->repository,
            new RedirectTableCache([new StoredRedirects($this->repository), $this->provider], $regex, $this->pool, 'table'),
            new ChainInspector(new LocationBuilder()),
            new TargetPolicy(['partner.example']),
            $this->clock,
            $regex,
            $maxLength,
        );
    }

    /**
     * @param callable(): mixed          $action
     * @param array<string, string>|null $parameters
     */
    private function assertRefused(string $reason, callable $action, ?array $parameters = null): void
    {
        try {
            $action();
        } catch (RedirectRefused $redirectRefused) {
            self::assertSame($reason, $redirectRefused->reason, $redirectRefused->getMessage());

            if (null !== $parameters) {
                self::assertSame($parameters, $redirectRefused->parameters);
            }

            return;
        }

        self::fail('Expected the reason '.$reason);
    }

    private static function rule(string $source, string $target): RedirectRule
    {
        return new RedirectRule(SourcePattern::exact($source), Target::url($target));
    }
}
