<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Application;

use Phil\RedirectsBundle\Application\ChainInspector;
use Phil\RedirectsBundle\Application\Exception\RedirectRefused;
use Phil\RedirectsBundle\Application\LocationBuilder;
use Phil\RedirectsBundle\Application\RedirectTable;
use Phil\RedirectsBundle\Domain\Enum\RedirectStatus;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChainInspector::class)]
#[CoversClass(RedirectRefused::class)]
final class ChainInspectorTest extends TestCase
{
    public function testARedirectToItselfIsALoop(): void
    {
        $this->assertRefused('loop', $this->rule(SourcePattern::exact('/a'), '/a/'));
        $this->assertRefused('loop', $this->rule(SourcePattern::prefix('/old'), '/old/new'));
        $this->assertRefused('loop', $this->rule(SourcePattern::wildcard('/a/*'), '/a/$1'));
    }

    public function testATwoStepLoopIsRefusedEvenWhenChainsAreAllowed(): void
    {
        $existing = $this->rule(SourcePattern::exact('/b'), '/a');

        $this->assertRefused('loop', $this->rule(SourcePattern::exact('/a'), '/b'), [$existing], true);
        $this->assertRefused('chain', $this->rule(SourcePattern::exact('/a'), '/b'), [$existing]);
    }

    public function testAChainIsRefusedUnlessAllowedThenUpToALength(): void
    {
        $candidate = $this->rule(SourcePattern::exact('/a'), '/b');
        $existing = [$this->rule(SourcePattern::exact('/b'), '/c'), $this->rule(SourcePattern::exact('/c'), '/d'), $this->rule(SourcePattern::exact('/d'), '/e')];

        $this->assertRefused('chain', $candidate, $existing);
        $this->assertPasses($candidate, \array_slice($existing, 0, 2), true, 3);
        $this->assertRefused('chain_length', $candidate, $existing, true, 3);
        $this->assertPasses($candidate, $existing, true, 4);
        $this->assertRefused('chain_length', $candidate, \array_slice($existing, 0, 1), true, 1);
    }

    public function testARedirectThatLeadsToTheNewSourceMakesAChain(): void
    {
        $candidate = $this->rule(SourcePattern::exact('/b'), 'https://partner.example/');
        $into = $this->rule(SourcePattern::exact('/a'), '/b?x=1');

        $this->assertRefused('chain_into', $candidate, [$into]);
        $this->assertPasses($candidate, [$into], true);
        $this->assertRefused('chain_length', $candidate, [$into, $this->rule(SourcePattern::exact('/z'), '/a')], true, 2);
    }

    public function testWhatEndsTheWalk(): void
    {
        $candidate = $this->rule(SourcePattern::exact('/a'), '/b');

        $this->assertPasses($candidate, []);
        $this->assertPasses($this->rule(SourcePattern::exact('/a'), 'https://partner.example/a'), [$this->rule(SourcePattern::exact('/x'), '/y')]);
        $this->assertPasses(new RedirectRule(SourcePattern::exact('/a'), Target::none(), RedirectStatus::Gone), [$this->rule(SourcePattern::exact('/z'), '/zz')]);
        $this->assertPasses($this->rule(SourcePattern::regex('/a.*'), '/a'), []);
        $this->assertPasses(new RedirectRule(SourcePattern::exact('/a'), Target::route('unknown')), []);
        $this->assertPasses($candidate, [$this->rule(SourcePattern::regex('/z(.*)'), '/c$1')]);
        $this->assertRefused('chain', $candidate, [$this->rule(SourcePattern::regex('/(.*)'), '/c$1')]);
        $this->assertPasses($candidate, [new RedirectRule(SourcePattern::exact('/z'), Target::none(), RedirectStatus::Gone)]);
    }

    public function testHostsAndLocalesKeepRedirectsApart(): void
    {
        $candidate = $this->rule(SourcePattern::exact('/a', 'one.example'), '/b');

        $this->assertPasses($candidate, [$this->rule(SourcePattern::exact('/b', 'two.example'), '/c')]);
        $this->assertRefused('chain', $candidate, [$this->rule(SourcePattern::exact('/b'), '/c')]);
        $this->assertPasses($candidate, [$this->rule(SourcePattern::exact('/z', 'two.example'), '/a')]);
        $this->assertRefused('chain_into', $candidate, [$this->rule(SourcePattern::exact('/z'), '/a')]);
        $this->assertRefused('chain_into', $this->rule(SourcePattern::exact('/a'), '/b'), [$this->rule(SourcePattern::exact('/z', 'two.example'), '/a')]);

        $french = new RedirectRule(SourcePattern::exact('/a'), Target::url('/b'), locale: 'fr');
        $this->assertPasses($french, [new RedirectRule(SourcePattern::exact('/b'), Target::url('/c'), locale: 'en')]);
        $this->assertPasses($french, [new RedirectRule(SourcePattern::exact('/z'), Target::url('/a'), locale: 'en')]);
        $this->assertRefused('chain_into', $french, [new RedirectRule(SourcePattern::exact('/z'), Target::url('/a'), locale: 'fr')]);
    }

    public function testTheNewRedirectIsNotItsOwnInboundChain(): void
    {
        $candidate = $this->rule(SourcePattern::exact('/a'), 'https://partner.example/a');

        $this->assertPasses($candidate, [$this->rule(SourcePattern::exact('/a'), '/a-old')]);
    }

    public function testTheMessageNamesTheSource(): void
    {
        try {
            new ChainInspector(new LocationBuilder())->inspect($this->rule(SourcePattern::exact('/a'), '/b'), RedirectTable::of([$this->rule(SourcePattern::exact('/a'), '/b'), $this->rule(SourcePattern::exact('/b'), '/c')]));
            self::fail('Expected a chain');
        } catch (RedirectRefused $redirectRefused) {
            self::assertSame(['%source%' => '/b'], $redirectRefused->parameters);
            self::assertSame('The target is itself redirected by "/b": the two would make a chain.', $redirectRefused->getMessage());
        }
    }

    public function testTheRefusalsNameWhatTheyMet(): void
    {
        $this->assertRefused('loop', self::rule(SourcePattern::exact('/a'), '/b'), [self::rule(SourcePattern::exact('/b'), '/a')], true, parameters: ['%source%' => '/a']);
        $this->assertRefused('chain_into', self::rule(SourcePattern::exact('/b'), '/z'), [self::rule(SourcePattern::exact('/a'), '/b')], parameters: ['%source%' => '/a']);
        $this->assertRefused('chain_length', self::rule(SourcePattern::exact('/a'), '/b'), [self::rule(SourcePattern::exact('/b'), '/c')], true, 1, ['%max%' => '1']);
    }

    public function testAWildcardThatLeadsToTheNewSourceIsFollowedWithItsCaptures(): void
    {
        $candidate = self::rule(SourcePattern::exact('/a/x'), 'https://partner.example/');

        $this->assertRefused('chain_into', $candidate, [self::rule(SourcePattern::wildcard('/old/*'), '/a/$1')]);
        $this->assertPasses($candidate, [self::rule(SourcePattern::wildcard('/old/*'), '/b/$1')]);
    }

    public function testThePredecessorsOfPredecessorsAreFollowed(): void
    {
        $candidate = self::rule(SourcePattern::exact('/c'), 'https://partner.example/');
        $existing = [self::rule(SourcePattern::exact('/b'), '/c'), self::rule(SourcePattern::exact('/a'), '/b'), self::rule(SourcePattern::exact('/z'), '/a')];

        $this->assertPasses($candidate, \array_slice($existing, 0, 2), true, 3);
        $this->assertRefused('chain_length', $candidate, $existing, true, 3);
        $this->assertPasses($candidate, $existing, true, 4);
    }

    /**
     * @param list<RedirectRule>    $existing
     * @param array<string, string> $parameters
     */
    private function assertRefused(string $reason, RedirectRule $candidate, array $existing = [], bool $allowChains = false, int $maxLength = ChainInspector::DEFAULT_MAX_LENGTH, ?array $parameters = null): void
    {
        try {
            new ChainInspector(new LocationBuilder(), $allowChains, $maxLength)->inspect($candidate, RedirectTable::of([...$existing, $candidate]));
        } catch (RedirectRefused $redirectRefused) {
            self::assertSame($reason, $redirectRefused->reason, $redirectRefused->getMessage());

            if (null !== $parameters) {
                self::assertSame($parameters, $redirectRefused->parameters);
            }

            return;
        }

        self::fail('Expected the reason '.$reason);
    }

    /**
     * @param list<RedirectRule> $existing
     */
    private function assertPasses(RedirectRule $candidate, array $existing, bool $allowChains = false, int $maxLength = ChainInspector::DEFAULT_MAX_LENGTH): void
    {
        $table = RedirectTable::of([...$existing, $candidate]);

        try {
            new ChainInspector(new LocationBuilder(), $allowChains, $maxLength)->inspect($candidate, $table);
        } catch (RedirectRefused $redirectRefused) {
            self::fail('Refused: '.$redirectRefused->getMessage());
        }

        self::assertSame(\count($existing) + 1, $table->count());
    }

    private function rule(SourcePattern $source, string $target): RedirectRule
    {
        return new RedirectRule($source, Target::url($target));
    }
}
