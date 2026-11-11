<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application\Contract;

use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;

/**
 * A source of redirects for the table: the redirects of the administration, and those another package contributes —
 * the old addresses of its own pages, say. The providers are listed in order in the configuration; when two of them
 * give the same source, the first one listed wins.
 *
 * A provider is read when the table is built, then the table is kept: a provider whose redirects change calls
 * `RedirectTableCache::invalidate()`.
 */
interface RedirectProvider
{
    /**
     * @return iterable<RedirectRule>
     */
    public function redirects(): iterable;
}
