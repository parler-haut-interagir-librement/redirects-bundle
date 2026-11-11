<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

use Phil\RedirectsBundle\Application\Exception\RedirectRefused;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;

/**
 * Refuses, when a redirect is saved, the loops and the chains it would make with the redirects in force.
 *
 * The redirect is followed from a path its source matches — itself for an exact source or a prefix, each wildcard
 * standing for `x` — through the table, as a browser would follow it: a redirect that comes back to one already met is
 * a loop, always refused. One that lands on another redirect is a chain: refused unless the application allows chains,
 * and then up to a length. The redirects that already lead to the new source are followed the same way. A regular
 * expression has no path to start from and is not followed; an absolute URL ends the walk, since it may leave the
 * site.
 */
final readonly class ChainInspector
{
    public const int DEFAULT_MAX_LENGTH = 3;

    public function __construct(
        private LocationBuilder $locations,
        private bool $allowChains = false,
        private int $maxLength = self::DEFAULT_MAX_LENGTH,
    ) {
    }

    /**
     * @param RedirectTable $table the redirects in force, the one inspected among them
     *
     * @throws RedirectRefused
     */
    public function inspect(RedirectRule $rule, RedirectTable $table): void
    {
        $this->follow($rule, $table);
        $reached = [$rule];
        $seen = [];

        // The redirects that lead to the new one, then those that lead to them, each followed to the end. The new one
        // never leads to itself: following it found that loop already.
        while ([] !== $reached) {
            $leading = [];

            foreach ($table->rules() as $other) {
                if ($this->among($other, $seen) || !$this->leadsToAny($other, $reached)) {
                    continue;
                }

                if (!$this->allowChains) {
                    throw RedirectRefused::because('chain_into', 'The redirect from "%source%" already leads to this source: the two would make a chain.', ['%source%' => $other->source->path]);
                }

                $this->follow($other, $table);
                $seen[] = $other;
                $leading[] = $other;
            }

            $reached = $leading;
        }
    }

    /**
     * @param list<RedirectRule> $rules
     */
    private function leadsToAny(RedirectRule $other, array $rules): bool
    {
        return array_any($rules, fn (RedirectRule $rule): bool => $this->leadsTo($other, $rule));
    }

    /**
     * @throws RedirectRefused
     */
    private function follow(RedirectRule $rule, RedirectTable $table): void
    {
        $sample = $rule->source->sample();

        if (null === $sample) {
            return;
        }

        $match = new RedirectMatch($rule, $rule->source->matches($sample) ?? []);
        $visited = [$rule];

        for ($length = 1;; ++$length) {
            $path = $this->nextPath($match);

            if (null === $path) {
                return;
            }

            $match = $table->match($rule->source->host, $path, $rule->locale);

            if (!$match instanceof RedirectMatch) {
                return;
            }

            if ($this->among($match->rule, $visited)) {
                throw RedirectRefused::because('loop', 'The redirect makes a loop through "%source%".', ['%source%' => $match->rule->source->path]);
            }

            if (!$this->allowChains) {
                throw RedirectRefused::because('chain', 'The target is itself redirected by "%source%": the two would make a chain.', ['%source%' => $match->rule->source->path]);
            }

            if ($length >= $this->maxLength) {
                throw RedirectRefused::because('chain_length', 'The chain of redirects would be longer than %max%.', ['%max%' => (string) $this->maxLength]);
            }

            $visited[] = $match->rule;
        }
    }

    private function leadsTo(RedirectRule $other, RedirectRule $rule): bool
    {
        $sample = $other->source->sample();

        if (null === $sample || (null !== $other->source->host && null !== $rule->source->host && $other->source->host !== $rule->source->host)) {
            return false;
        }

        $path = $this->nextPath(new RedirectMatch($other, $other->source->matches($sample) ?? []));

        return null !== $path && $rule->appliesTo($other->locale) && null !== $rule->source->matches(SourcePattern::requestPath($path));
    }

    /**
     * The path the redirect sends to, without its query; none when it ends the walk.
     */
    private function nextPath(RedirectMatch $match): ?string
    {
        $location = $this->locations->build($match);

        if (null === $location || !str_starts_with($location, '/')) {
            return null;
        }

        return substr($location, 0, strcspn($location, '?#'));
    }

    /**
     * Whether a redirect of the same source and locale is in the list: the table and the store may hold two copies
     * of one redirect.
     *
     * @param list<RedirectRule> $rules
     */
    private function among(RedirectRule $rule, array $rules): bool
    {
        return array_any($rules, static fn (RedirectRule $other): bool => $other->overlaps($rule));
    }
}
