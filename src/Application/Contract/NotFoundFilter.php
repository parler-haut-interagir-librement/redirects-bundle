<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application\Contract;

use Phil\RedirectsBundle\Application\NotFoundHit;

/**
 * Keeps a request that was not found out of the log: a crawler, a robot, a probe of a path the application knows is
 * noise. Every filter is asked; one that ignores the request is enough.
 */
interface NotFoundFilter
{
    public function ignores(NotFoundHit $hit): bool;
}
