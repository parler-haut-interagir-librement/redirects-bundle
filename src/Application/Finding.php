<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

/**
 * A stored redirect that would no longer be saved as it is: the reason, `error.<reason>` in the `phil_redirects`
 * translation domain, and the sentence in English.
 */
final readonly class Finding
{
    public function __construct(
        public int $id,
        public string $source,
        public string $reason,
        public string $message,
    ) {
    }
}
