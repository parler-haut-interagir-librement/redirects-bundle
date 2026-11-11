<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

use Phil\RedirectsBundle\Application\Contract\NotFoundFilter;
use Phil\RedirectsBundle\Domain\Contract\NotFoundLogRepository;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Psr\Clock\ClockInterface;

/**
 * Logs a request that was not found, aggregated by host and path: unless its path is too long, under a path the
 * application ignores, or kept out by a filter. Of the referrer, only its host is kept.
 */
final readonly class NotFoundRecorder
{
    public const int DEFAULT_MAX_PATH_LENGTH = 1024;

    public const int DEFAULT_CAPACITY = 10000;

    /**
     * @param iterable<NotFoundFilter> $filters
     * @param list<string>             $ignoredPaths the prefixes of the paths never logged
     */
    public function __construct(
        private NotFoundLogRepository $log,
        private ClockInterface $clock,
        private iterable $filters = [],
        private array $ignoredPaths = [],
        private int $maxPathLength = self::DEFAULT_MAX_PATH_LENGTH,
        private int $capacity = self::DEFAULT_CAPACITY,
    ) {
    }

    /**
     * Whether the request was logged.
     */
    public function record(NotFoundHit $hit): bool
    {
        $path = SourcePattern::requestPath($hit->path);

        if (\strlen($path) > $this->maxPathLength || !str_starts_with($path, '/') || 1 === preg_match('/[\x00-\x20\x7F]/', $path)) {
            return false;
        }

        foreach ($this->ignoredPaths as $ignored) {
            if (str_starts_with($path, $ignored)) {
                return false;
            }
        }

        foreach ($this->filters as $filter) {
            if ($filter->ignores($hit)) {
                return false;
            }
        }

        $this->log->record(strtolower($hit->host), $path, $this->hostOf($hit->referrer), $this->clock->now(), $this->capacity);

        return true;
    }

    private function hostOf(?string $referrer): ?string
    {
        $host = null === $referrer ? null : parse_url($referrer, \PHP_URL_HOST);

        if (!\is_string($host)) {
            return null;
        }

        try {
            return SourcePattern::normalizedHost($host);
        } catch (InvalidRedirect) {
            return null;
        }
    }
}
