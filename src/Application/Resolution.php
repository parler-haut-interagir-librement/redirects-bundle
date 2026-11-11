<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

use Phil\RedirectsBundle\Domain\Enum\RedirectStatus;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;

/**
 * What a request that was not found answers instead: the redirect, and the location it sends to — none for a
 * `410 Gone`.
 */
final readonly class Resolution
{
    public function __construct(
        public RedirectRule $rule,
        public ?string $location,
    ) {
    }

    public function status(): RedirectStatus
    {
        return $this->rule->status;
    }
}
