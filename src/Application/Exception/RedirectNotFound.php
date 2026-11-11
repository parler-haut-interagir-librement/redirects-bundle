<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application\Exception;

use RuntimeException;

final class RedirectNotFound extends RuntimeException
{
    public static function withId(int $id): self
    {
        return new self(\sprintf('No redirect has the identifier %d.', $id));
    }
}
