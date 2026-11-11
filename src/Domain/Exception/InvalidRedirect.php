<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Domain\Exception;

use InvalidArgumentException;

/**
 * A redirect the domain refuses: a source, a target, a locale or a combination that cannot be one. The reason is a
 * short key, `error.<reason>` in the `phil_redirects` translation domain, with its parameters; the message is the
 * same sentence in English.
 */
final class InvalidRedirect extends InvalidArgumentException
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
