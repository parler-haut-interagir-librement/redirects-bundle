<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

/**
 * The old domains of the application, each mapped to its new host, read before the routing.
 */
final readonly class OldDomains
{
    /**
     * @param array<string, OldDomain> $domains by old host, in lower case
     */
    public function __construct(
        private array $domains = [],
    ) {
    }

    public function find(string $host): ?OldDomain
    {
        return $this->domains[strtolower(rtrim($host, '.'))] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->domains;
    }
}
