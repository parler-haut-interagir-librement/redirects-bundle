<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Fixture;

use DateTimeImmutable;
use Override;
use Phil\RedirectsBundle\Domain\Contract\RedirectRepository;
use Phil\RedirectsBundle\Domain\Model\Redirect;

final class InMemoryRedirects implements RedirectRepository
{
    /** @var array<int, Redirect> */
    public array $redirects = [];

    /** @var list<array{int, DateTimeImmutable, DateTimeImmutable}> */
    public array $hits = [];

    public int $saves = 0;

    private int $next = 1;

    /**
     * @return list<array{int, DateTimeImmutable, DateTimeImmutable}>
     */
    public function hitsRecorded(): array
    {
        return $this->hits;
    }

    #[Override]
    public function find(int $id): ?Redirect
    {
        return $this->redirects[$id] ?? null;
    }

    #[Override]
    public function all(): array
    {
        return array_values($this->redirects);
    }

    #[Override]
    public function page(int $offset, int $limit, string $search = ''): array
    {
        $found = array_filter($this->redirects, static fn (Redirect $redirect): bool => '' === $search || str_contains($redirect->rule()->source->path, $search));

        return \array_slice(array_reverse(array_values($found)), $offset, $limit);
    }

    #[Override]
    public function count(string $search = ''): int
    {
        return \count($this->page(0, \PHP_INT_MAX, $search));
    }

    #[Override]
    public function save(Redirect $redirect): void
    {
        ++$this->saves;
        $redirect->identify($this->next);
        $id = (int) $redirect->id();
        $this->next = max($this->next, $id + 1);
        $this->redirects[$id] = $redirect;
    }

    #[Override]
    public function remove(int $id): bool
    {
        $found = isset($this->redirects[$id]);
        unset($this->redirects[$id]);

        return $found;
    }

    #[Override]
    public function recordHit(int $id, DateTimeImmutable $at, DateTimeImmutable $throttle): void
    {
        $this->hits[] = [$id, $at, $throttle];
    }
}
