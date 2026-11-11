<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Domain\ValueObject;

use Phil\RedirectsBundle\Domain\Enum\MatchKind;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;

/**
 * Where a redirect applies: a host, or every host, and a path matched in one of the ways of {@see MatchKind}.
 *
 * A path is kept as a request carries it: percent-encoded, with a character outside printable ASCII encoded as it is
 * written, and the hexadecimal digits of an escape in capitals. A trailing slash is ignored on both sides. The match
 * is bounded: an exact source is a lookup, a prefix a comparison, and a wildcard source a walk over at most
 * {@see self::MAX_SEGMENTS} segments, without any regular expression. A regular expression is the one exception, kept
 * to a length the application chooses and run with the limits of PCRE: a pattern that fails at run time matches
 * nothing.
 */
final readonly class SourcePattern
{
    public const int MAX_PATH_LENGTH = 2048;

    public const int MAX_HOST_LENGTH = 253;

    public const int MAX_SEGMENTS = 32;

    public const int MAX_CAPTURES = 9;

    public const int DEFAULT_REGEX_MAX_LENGTH = 255;

    private const string ONE = '*';

    private const string REST = '**';

    private const string HOST = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D';

    /**
     * @param list<string> $segments the segments of a wildcard source, nothing for the other kinds
     */
    private function __construct(
        public MatchKind $kind,
        public string $path,
        public ?string $host,
        private array $segments = [],
        private string $regex = '',
    ) {
    }

    public static function of(MatchKind $kind, string $path, ?string $host = null, int $regexMaxLength = self::DEFAULT_REGEX_MAX_LENGTH): self
    {
        return match ($kind) {
            MatchKind::Exact => self::exact($path, $host),
            MatchKind::Prefix => self::prefix($path, $host),
            MatchKind::Wildcard => self::wildcard($path, $host),
            MatchKind::Regex => self::regex($path, $host, $regexMaxLength),
        };
    }

    public static function exact(string $path, ?string $host = null): self
    {
        return new self(MatchKind::Exact, self::normalizedPath($path), self::normalizedHost($host));
    }

    public static function prefix(string $path, ?string $host = null): self
    {
        return new self(MatchKind::Prefix, self::normalizedPath($path), self::normalizedHost($host));
    }

    public static function wildcard(string $path, ?string $host = null): self
    {
        $path = self::normalizedPath($path);
        $segments = explode('/', substr($path, 1));

        if (\count($segments) > self::MAX_SEGMENTS) {
            throw InvalidRedirect::because('too_many_segments', 'A wildcard source has at most %max% segments.', ['%max%' => (string) self::MAX_SEGMENTS]);
        }

        $captures = 0;
        $last = \count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if (self::ONE === $segment || self::REST === $segment) {
                if (self::REST === $segment && $index !== $last) {
                    throw InvalidRedirect::because('rest_not_last', 'In a wildcard source, "**" is the last segment only.');
                }

                ++$captures;

                continue;
            }

            if (str_contains($segment, self::ONE)) {
                throw InvalidRedirect::because('partial_wildcard', 'In a wildcard source, "*" stands for a whole segment, never for part of one.');
            }
        }

        if ($captures > self::MAX_CAPTURES) {
            throw InvalidRedirect::because('too_many_captures', 'A wildcard source has at most %max% wildcards.', ['%max%' => (string) self::MAX_CAPTURES]);
        }

        return new self(MatchKind::Wildcard, $path, self::normalizedHost($host), $segments);
    }

    public static function regex(string $pattern, ?string $host = null, int $maxLength = self::DEFAULT_REGEX_MAX_LENGTH): self
    {
        if ('' === $pattern || \strlen($pattern) > $maxLength) {
            throw InvalidRedirect::because('regex_length', 'A regular expression has between 1 and %max% characters.', ['%max%' => (string) $maxLength]);
        }

        if (1 === preg_match('/[\x00-\x20\x7F#]/', $pattern)) {
            throw InvalidRedirect::because('regex_characters', 'A regular expression has no space, no control character and no "#".');
        }

        $regex = '#^(?:'.$pattern.')$#D';
        // A pattern that does not compile warns: the warning is the refusal below, not a line of the log.
        set_error_handler(static fn (): bool => true);
        $compiled = preg_match($regex, '');
        restore_error_handler();

        if (false === $compiled) {
            throw InvalidRedirect::because('regex_invalid', 'The regular expression does not compile.');
        }

        return new self(MatchKind::Regex, $pattern, self::normalizedHost($host), regex: $regex);
    }

    /**
     * The path of a request as the sources compare it: no trailing slash but on the root, the escapes in capitals.
     * A path longer than {@see self::MAX_PATH_LENGTH} is returned as it is; no source matches it.
     */
    public static function requestPath(string $path): string
    {
        if (str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }

        if (str_contains($path, '%')) {
            $path = (string) preg_replace_callback('/%[0-9a-fA-F]{2}/', static fn (array $escape): string => strtoupper($escape[0]), $path);
        }

        return '' === $path ? '/' : $path;
    }

    /**
     * The values of the wildcards when the path, already through {@see self::requestPath()}, matches: nothing for an
     * exact source, the rest of the path for a prefix, one value per wildcard, one per group of a regular expression;
     * `null` when it does not match.
     *
     * @return list<string>|null
     */
    public function matches(string $path): ?array
    {
        if (\strlen($path) > self::MAX_PATH_LENGTH) {
            return null;
        }

        return match ($this->kind) {
            MatchKind::Exact => $path === $this->path ? [] : null,
            MatchKind::Prefix => $this->matchesPrefix($path),
            MatchKind::Wildcard => $this->matchesWildcard($path),
            MatchKind::Regex => $this->matchesRegex($path),
        };
    }

    /**
     * How many values a target may refer to as `$1` to `$9`.
     */
    public function captures(): int
    {
        return match ($this->kind) {
            MatchKind::Exact, MatchKind::Prefix => 0,
            MatchKind::Wildcard => \count(array_filter($this->segments, static fn (string $segment): bool => self::ONE === $segment || self::REST === $segment)),
            MatchKind::Regex => self::MAX_CAPTURES,
        };
    }

    /**
     * The first segment of the source, which the table indexes it by: `*` when a wildcard source starts with a
     * wildcard, nothing for a regular expression or the root.
     */
    public function firstSegment(): string
    {
        if (MatchKind::Regex === $this->kind) {
            return '';
        }

        $first = self::firstSegmentOf($this->path);

        return MatchKind::Wildcard === $this->kind && str_starts_with($first, self::ONE) ? self::ONE : $first;
    }

    public static function firstSegmentOf(string $path): string
    {
        $end = strpos($path, '/', 1);

        return false === $end ? substr($path, 1) : substr($path, 1, $end - 1);
    }

    /**
     * A path the source matches, each wildcard standing for `x`; none for a regular expression.
     */
    public function sample(): ?string
    {
        return match ($this->kind) {
            MatchKind::Exact, MatchKind::Prefix => $this->path,
            MatchKind::Wildcard => '/'.implode('/', array_map(static fn (string $segment): string => self::ONE === $segment || self::REST === $segment ? 'x' : $segment, $this->segments)),
            MatchKind::Regex => null,
        };
    }

    /**
     * Whether the two sources are the same rule: the same kind, host and path.
     */
    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->host === $other->host && $this->path === $other->path;
    }

    /**
     * @return list<string>|null
     */
    private function matchesPrefix(string $path): ?array
    {
        if ('/' === $this->path) {
            return ['/' === $path ? '' : $path];
        }

        if ($path === $this->path) {
            return [''];
        }

        return str_starts_with($path, $this->path.'/') ? [substr($path, \strlen($this->path))] : null;
    }

    /**
     * Walks the segments of the source: the request is split into as many parts at most, so that a long path costs
     * no more than a short one, and a last `**` takes whatever is left.
     *
     * @return list<string>|null
     */
    private function matchesWildcard(string $path): ?array
    {
        $count = \count($this->segments);
        $parts = explode('/', substr($path, 1), $count);
        $rest = self::REST === $this->segments[$count - 1];

        if ($rest && \count($parts) === $count - 1) {
            $parts[] = '';
        }

        if (\count($parts) !== $count) {
            return null;
        }

        $captures = [];

        foreach ($this->segments as $index => $segment) {
            $part = $parts[$index];

            if (self::REST === $segment) {
                $captures[] = $part;
            } elseif (self::ONE === $segment) {
                if ('' === $part || str_contains($part, '/')) {
                    return null;
                }

                $captures[] = $part;
            } elseif ($part !== $segment) {
                return null;
            }
        }

        return $captures;
    }

    /**
     * @return list<string>|null
     */
    private function matchesRegex(string $path): ?array
    {
        if (1 !== preg_match($this->regex, $path, $groups)) {
            return null;
        }

        /** @var list<string> $groups the pattern is wrapped in a group without a name: every group is numbered */
        return \array_slice($groups, 1, self::MAX_CAPTURES);
    }

    private static function normalizedPath(string $path): string
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw InvalidRedirect::because('path_start', 'A source path starts with one "/".');
        }

        if (1 === preg_match('/[\x00-\x1F\x7F?#\\\\]/', $path)) {
            throw InvalidRedirect::because('path_characters', 'A source path has no control character, no "?", no "#" and no "\\".');
        }

        $path = (string) preg_replace_callback('/[^\x21-\x7E]/', static fn (array $byte): string => rawurlencode($byte[0]), $path);
        $path = self::requestPath($path);

        if (\strlen($path) > self::MAX_PATH_LENGTH) {
            throw InvalidRedirect::because('path_length', 'A source path has at most %max% characters.', ['%max%' => (string) self::MAX_PATH_LENGTH]);
        }

        if (str_contains($path, '//')) {
            throw InvalidRedirect::because('empty_segment', 'A source path has no empty segment.');
        }

        return $path;
    }

    /**
     * A host in lower case, without its final dot; none for nothing.
     */
    public static function normalizedHost(?string $host): ?string
    {
        return null === $host || '' === $host ? null : self::hostName($host);
    }

    public static function hostName(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));

        if (\strlen($host) > self::MAX_HOST_LENGTH || 1 !== preg_match(self::HOST, $host)) {
            throw InvalidRedirect::because('host', 'The host "%host%" is not a host name.', ['%host%' => $host]);
        }

        return $host;
    }
}
