<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Domain\Contract;

use DateTimeImmutable;
use Phil\RedirectsBundle\Domain\Model\Redirect;

/**
 * Where the redirects of the administration are kept.
 */
interface RedirectRepository
{
    public function find(int $id): ?Redirect;

    /**
     * Every redirect, enabled or not, in the order they were created.
     *
     * @return list<Redirect>
     */
    public function all(): array;

    /**
     * A page of the redirects, the latest first, whose source or target contains the search.
     *
     * @return list<Redirect>
     */
    public function page(int $offset, int $limit, string $search = ''): array;

    public function count(string $search = ''): int;

    /**
     * Inserts a new redirect, giving it its identifier, or updates one.
     */
    public function save(Redirect $redirect): void;

    public function remove(int $id): bool;

    /**
     * Counts one more hit of the redirect; its last hit is written only when the one kept is older than the
     * throttle, so that a busy redirect does not rewrite it on every request.
     */
    public function recordHit(int $id, DateTimeImmutable $at, DateTimeImmutable $throttle): void;
}
