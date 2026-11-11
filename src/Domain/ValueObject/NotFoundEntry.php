<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Domain\ValueObject;

use DateTimeImmutable;

/**
 * A path that answered `404 Not Found`, aggregated: how many times, when first and last, and the host of the last
 * page that linked to it. Nothing of the visitors is kept: no address, no user agent, no full referrer.
 */
final readonly class NotFoundEntry
{
    public function __construct(
        public int $id,
        public string $host,
        public string $path,
        public int $hits,
        public DateTimeImmutable $firstSeenAt,
        public DateTimeImmutable $lastSeenAt,
        public ?string $referrerHost = null,
    ) {
    }
}
