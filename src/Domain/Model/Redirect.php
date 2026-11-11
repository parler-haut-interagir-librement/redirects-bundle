<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Domain\Model;

use DateTimeImmutable;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;

/**
 * A redirect kept by the bundle and managed from the administration: its rule, whether it is enabled, how many
 * requests it answered and when it last did, and when it was created and changed. Its identifier is given by the
 * store when it is first saved.
 */
final class Redirect
{
    private RedirectRule $rule;

    private DateTimeImmutable $updatedAt;

    public function __construct(
        RedirectRule $rule,
        private bool $enabled,
        private readonly DateTimeImmutable $createdAt,
        private ?int $id = null,
        private readonly int $hits = 0,
        private readonly ?DateTimeImmutable $lastHitAt = null,
        ?DateTimeImmutable $updatedAt = null,
    ) {
        $this->rule = $rule->withId($id);
        $this->updatedAt = $updatedAt ?? $createdAt;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    /**
     * The identifier the store gives a new redirect, once.
     */
    public function identify(int $id): void
    {
        $this->id ??= $id;
        $this->rule = $this->rule->withId($this->id);
    }

    public function rule(): RedirectRule
    {
        return $this->rule;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function hits(): int
    {
        return $this->hits;
    }

    public function lastHitAt(): ?DateTimeImmutable
    {
        return $this->lastHitAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function change(RedirectRule $rule, bool $enabled, DateTimeImmutable $now): void
    {
        $this->rule = $rule->withId($this->id);
        $this->enabled = $enabled;
        $this->updatedAt = $now;
    }
}
