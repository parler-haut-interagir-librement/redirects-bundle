<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Fixture;

use DateTimeImmutable;
use Override;
use Phil\RedirectsBundle\Domain\Contract\NotFoundLogRepository;
use Phil\RedirectsBundle\Domain\ValueObject\NotFoundEntry;
use Phil\RedirectsBundle\Tests\Unit\Fixture\Exception\DatabaseGone;
use Throwable;

final class InMemoryNotFoundLog implements NotFoundLogRepository
{
    /** @var array<int, NotFoundEntry> */
    public array $entries = [];

    /** @var list<array{string, string, ?string, DateTimeImmutable, int}> */
    public array $recorded = [];

    public ?DateTimeImmutable $purgedBefore = null;

    /** Whether a write fails as when the database is gone. */
    public bool $failing = false;

    /** A failure of another kind a write throws. */
    public ?Throwable $failure = null;

    #[Override]
    public function record(string $host, string $path, ?string $referrerHost, DateTimeImmutable $at, int $capacity): void
    {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        if ($this->failing) {
            throw new DatabaseGone('gone');
        }

        $this->recorded[] = [$host, $path, $referrerHost, $at, $capacity];
    }

    /**
     * @return list<array{string, string, ?string, DateTimeImmutable, int}>
     */
    public function recordedSoFar(): array
    {
        return $this->recorded;
    }

    #[Override]
    public function find(int $id): ?NotFoundEntry
    {
        return $this->entries[$id] ?? null;
    }

    #[Override]
    public function page(int $offset, int $limit, string $search = ''): array
    {
        return \array_slice(array_values(array_filter($this->entries, static fn (NotFoundEntry $entry): bool => '' === $search || str_contains($entry->path, $search))), $offset, $limit);
    }

    #[Override]
    public function count(string $search = ''): int
    {
        return \count($this->page(0, \PHP_INT_MAX, $search));
    }

    #[Override]
    public function remove(int $id): bool
    {
        $found = isset($this->entries[$id]);
        unset($this->entries[$id]);

        return $found;
    }

    #[Override]
    public function purge(DateTimeImmutable $notSeenSince): int
    {
        $this->purgedBefore = $notSeenSince;

        return 7;
    }
}
