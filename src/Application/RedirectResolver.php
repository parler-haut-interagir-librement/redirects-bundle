<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

/**
 * Resolves a request that was not found against the table: the redirect it matches and the location it then goes
 * to, or nothing.
 */
final readonly class RedirectResolver
{
    public function __construct(
        private RedirectTableCache $tables,
        private LocationBuilder $locations,
    ) {
    }

    public function resolve(string $host, string $path, string $query = '', ?string $locale = null): ?Resolution
    {
        $match = $this->tables->table()->match(strtolower($host), $path, $locale);

        if (!$match instanceof RedirectMatch) {
            return null;
        }

        if ($match->rule->status->isGone()) {
            return new Resolution($match->rule, null);
        }

        $location = $this->locations->build($match, $query);

        return null === $location ? null : new Resolution($match->rule, $location);
    }
}
