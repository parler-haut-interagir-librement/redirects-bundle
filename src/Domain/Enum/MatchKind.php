<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Domain\Enum;

/**
 * How the source of a redirect matches the path of a request.
 *
 * - `Exact`: the path itself, a trailing slash ignored;
 * - `Prefix`: the path and every path under it, segment by segment; the rest of the path is appended to the target;
 * - `Wildcard`: segments where `*` stands for exactly one segment and a last `**` for any number of them, each
 *   wildcard captured for `$1` to `$9` of the target;
 * - `Regex`: a regular expression, off unless the application allows it, its length bounded.
 */
enum MatchKind: string
{
    case Exact = 'exact';
    case Prefix = 'prefix';
    case Wildcard = 'wildcard';
    case Regex = 'regex';
}
