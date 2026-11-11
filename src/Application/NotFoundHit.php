<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

/**
 * A request that was not found, as the filters see it before it is logged. The user agent and the robot verdict
 * serve the filters only; the log keeps the host, the path and the host of the referrer.
 */
final readonly class NotFoundHit
{
    public function __construct(
        public string $host,
        public string $path,
        public ?string $referrer = null,
        public ?string $userAgent = null,
        public ?string $verdict = null,
    ) {
    }
}
