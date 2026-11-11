<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application\Exception;

use RuntimeException;

/**
 * A redirect that is valid on its own and refused with the others: a loop, a chain, a source already taken, an
 * external target to a host the application does not allow, a regular expression while they are off. The reason is
 * `error.<reason>` in the `phil_redirects` translation domain, with its parameters.
 */
final class RedirectRefused extends RuntimeException
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $parameters = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param array<string, string> $parameters
     */
    public static function because(string $reason, string $message, array $parameters = []): self
    {
        return new self(strtr($message, $parameters), $reason, $parameters);
    }
}
