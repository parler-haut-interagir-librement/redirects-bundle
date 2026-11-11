<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application\Filter;

use Override;
use Phil\RedirectsBundle\Application\Contract\NotFoundFilter;
use Phil\RedirectsBundle\Application\NotFoundHit;

/**
 * Keeps out of the log the requests whose user agent says it is not a person: a crawler, a script, a monitor, or no
 * user agent at all. It reads only what the client declares, a word at a time; a robot posing as a browser passes
 * here, and is left to the robot verdict.
 */
final readonly class DeclaredCrawlerFilter implements NotFoundFilter
{
    public const array TOKENS = [
        'bot', 'crawl', 'spider', 'slurp', 'scan', 'fetch', 'monitor', 'uptime', 'preview', 'headless', 'curl/',
        'wget/', 'python', 'go-http-client', 'java/', 'libwww', 'okhttp', 'httpclient', 'axios/', 'node-fetch',
        'facebookexternalhit', 'externalhit', 'feed', 'archiver',
    ];

    /** @var list<string> */
    private array $tokens;

    /**
     * @param list<string> $extraTokens more words, in lower case, that mark a client the log ignores
     */
    public function __construct(array $extraTokens = [])
    {
        $this->tokens = [...self::TOKENS, ...array_map(strtolower(...), $extraTokens)];
    }

    #[Override]
    public function ignores(NotFoundHit $hit): bool
    {
        $agent = strtolower(trim($hit->userAgent ?? ''));

        if ('' === $agent) {
            return true;
        }

        return array_any($this->tokens, static fn (string $token): bool => str_contains($agent, $token));
    }
}
