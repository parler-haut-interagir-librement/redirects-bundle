<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Domain\Contract;

use DateTimeImmutable;
use Phil\RedirectsBundle\Domain\ValueObject\NotFoundEntry;

/**
 * Where the paths not found are aggregated, one entry per host and path.
 */
interface NotFoundLogRepository
{
    /**
     * Counts one more request of the path: the entry is created on its first one, unless the log already holds
     * `$capacity` entries.
     */
    public function record(string $host, string $path, ?string $referrerHost, DateTimeImmutable $at, int $capacity): void;

    public function find(int $id): ?NotFoundEntry;

    /**
     * A page of the entries, the most requested first, whose path contains the search.
     *
     * @return list<NotFoundEntry>
     */
    public function page(int $offset, int $limit, string $search = ''): array;

    public function count(string $search = ''): int;

    public function remove(int $id): bool;

    /**
     * Removes the entries not seen since the moment given, and says how many.
     */
    public function purge(DateTimeImmutable $notSeenSince): int;
}
