<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Domain\Enum;

/**
 * What becomes of the query string of the request: dropped, so that only the target's own query is kept, or kept
 * and appended after the target's own parameters.
 */
enum QueryPolicy: string
{
    case Drop = 'drop';
    case Keep = 'keep';
}
