<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Domain\Enum;

/**
 * The status a redirect answers with: four redirections, and `410 Gone` for a page removed on purpose, which has no
 * target.
 */
enum RedirectStatus: int
{
    case MovedPermanently = 301;
    case Found = 302;
    case TemporaryRedirect = 307;
    case PermanentRedirect = 308;
    case Gone = 410;

    public function isGone(): bool
    {
        return self::Gone === $this;
    }

    public function isPermanent(): bool
    {
        return \in_array($this, [self::MovedPermanently, self::PermanentRedirect, self::Gone], true);
    }
}
