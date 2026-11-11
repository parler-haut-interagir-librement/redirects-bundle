<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

use Phil\RedirectsBundle\Application\Contract\RedirectProvider;
use Phil\RedirectsBundle\Domain\Enum\MatchKind;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The table of redirects, built from the providers in their order and kept: for the rest of the request in memory,
 * and between requests in a PSR-6 pool when the application gives one. `reset()` ends the request, so that a worker
 * never serves one request with the table of another; `invalidate()` drops both copies after a change.
 *
 * Without a pool, the table is built again on the first request of each process that is not found: the only requests
 * that need it.
 */
final class RedirectTableCache
{
    public const string DEFAULT_KEY = 'phil_redirects.table';

    private ?RedirectTable $table = null;

    /**
     * @param iterable<RedirectProvider> $providers
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly bool $regex = false,
        private readonly ?CacheItemPoolInterface $pool = null,
        private readonly string $key = self::DEFAULT_KEY,
        private readonly ?int $lifetime = null,
    ) {
    }

    public function table(): RedirectTable
    {
        return $this->table ??= $this->load();
    }

    /**
     * The table read from the providers now, whatever is kept.
     */
    public function fresh(): RedirectTable
    {
        $rules = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->redirects() as $rule) {
                if ($this->regex || MatchKind::Regex !== $rule->source->kind) {
                    $rules[] = $rule;
                }
            }
        }

        return RedirectTable::of($rules);
    }

    public function invalidate(): void
    {
        $this->table = null;
        $this->pool?->deleteItem($this->key);
    }

    public function reset(): void
    {
        $this->table = null;
    }

    private function load(): RedirectTable
    {
        if (!$this->pool instanceof CacheItemPoolInterface) {
            return $this->fresh();
        }

        $item = $this->pool->getItem($this->key);
        $table = $item->isHit() ? $item->get() : null;

        if ($table instanceof RedirectTable) {
            return $table;
        }

        $table = $this->fresh();
        $this->pool->save($item->set($table)->expiresAfter($this->lifetime));

        return $table;
    }
}
