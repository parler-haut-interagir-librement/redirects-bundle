<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;

/**
 * The redirect the table found for a path, with the values its source captured.
 */
final readonly class RedirectMatch
{
    /**
     * @param list<string> $captures
     */
    public function __construct(
        public RedirectRule $rule,
        public array $captures = [],
    ) {
    }
}
