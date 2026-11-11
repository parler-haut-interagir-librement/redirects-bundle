<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

use Phil\RedirectsBundle\Domain\ValueObject\Target;

/**
 * The open-redirect guard: a path of the site and a route are always allowed; an absolute URL only when its host is
 * one the application lists — its own hosts, those of a site it moved to. The list is empty by default, so that no
 * redirect leaves the site unless the application said where it may go.
 */
final readonly class TargetPolicy
{
    /** @var list<string> */
    private array $hosts;

    /**
     * @param list<string> $allowedHosts
     */
    public function __construct(array $allowedHosts = [])
    {
        $this->hosts = array_map(static fn (string $host): string => strtolower(rtrim($host, '.')), $allowedHosts);
    }

    public function allows(Target $target): bool
    {
        return !$target->isExternal() || \in_array($target->host, $this->hosts, true);
    }
}
