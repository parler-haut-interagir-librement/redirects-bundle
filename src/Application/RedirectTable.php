<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

use Phil\RedirectsBundle\Domain\Enum\MatchKind;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;

/**
 * Every redirect in force, indexed so that a path is resolved without going through them all.
 *
 * An exact source is a key; a prefix or a wildcard source is filed under its first segment, so that a path only meets
 * the sources that start like it; the regular expressions are tried last. The order is fixed: exact, then prefix — the
 * longest first —, then wildcard and regular expression in the order they were added; within each kind, the sources of
 * the request's host come before those of every host. A redirect limited to a locale is skipped for a request in
 * another one.
 *
 * The table is a value: {@see self::with()} and {@see self::without()} give a new one.
 */
final class RedirectTable
{
    private const string ANY_HOST = '';

    /**
     * The redirects by kind, then by key: the host and the path of an exact source, the host and the first segment of
     * the others.
     *
     * @var array{exact: array<string, list<RedirectRule>>, prefix: array<string, list<RedirectRule>>, wildcard: array<string, list<RedirectRule>>, regex: array<string, list<RedirectRule>>}
     */
    private array $buckets = ['exact' => [], 'prefix' => [], 'wildcard' => [], 'regex' => []];

    private int $count = 0;

    /**
     * @param iterable<RedirectRule> $rules
     */
    public static function of(iterable $rules): self
    {
        $table = new self();

        foreach ($rules as $rule) {
            $table->insert($rule);
        }

        return $table;
    }

    public function with(RedirectRule $rule): self
    {
        $table = clone $this;
        $table->insert($rule);

        return $table;
    }

    /**
     * The table without the redirect of the same source and locale.
     */
    public function without(RedirectRule $rule): self
    {
        $table = clone $this;
        $bucket = $table->bucketOf($rule);
        $key = $this->keyOf($rule);
        $rules = $table->buckets[$bucket][$key] ?? [];
        $kept = [];

        foreach ($rules as $candidate) {
            if (!$candidate->overlaps($rule)) {
                $kept[] = $candidate;
            }
        }

        if ([] === $kept) {
            unset($table->buckets[$bucket][$key]);
        } else {
            $table->buckets[$bucket][$key] = $kept;
        }

        $table->count -= \count($rules) - \count($kept);

        return $table;
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * Every redirect of the table, kind by kind.
     *
     * @return list<RedirectRule>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->buckets as $buckets) {
            foreach ($buckets as $bucket) {
                array_push($rules, ...$bucket);
            }
        }

        return $rules;
    }

    /**
     * The redirect for a request, or none. The host is the request's, in lower case; the path is its path info, as
     * the request carries it.
     */
    public function match(?string $host, string $path, ?string $locale = null): ?RedirectMatch
    {
        $path = SourcePattern::requestPath($path);
        $hosts = null === $host || self::ANY_HOST === $host ? [self::ANY_HOST] : [$host, self::ANY_HOST];
        $first = SourcePattern::firstSegmentOf($path);

        foreach ($hosts as $candidate) {
            $found = $this->first($this->buckets['exact'][$candidate."\n".$path] ?? [], $path, $locale);

            if ($found instanceof RedirectMatch) {
                return $found;
            }
        }

        foreach ($hosts as $candidate) {
            $found = $this->first([...$this->buckets['prefix'][$candidate."\n".$first] ?? [], ...('' === $first ? [] : $this->buckets['prefix'][$candidate."\n"] ?? [])], $path, $locale);

            if ($found instanceof RedirectMatch) {
                return $found;
            }
        }

        foreach ($hosts as $candidate) {
            $found = $this->first([...$this->buckets['wildcard'][$candidate."\n".$first] ?? [], ...$this->buckets['wildcard'][$candidate."\n*"] ?? []], $path, $locale);

            if ($found instanceof RedirectMatch) {
                return $found;
            }
        }

        foreach ($hosts as $candidate) {
            $found = $this->first($this->buckets['regex'][$candidate."\n"] ?? [], $path, $locale);

            if ($found instanceof RedirectMatch) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param list<RedirectRule> $rules
     */
    private function first(array $rules, string $path, ?string $locale): ?RedirectMatch
    {
        foreach ($rules as $rule) {
            if (!$rule->appliesTo($locale)) {
                continue;
            }

            $captures = $rule->source->matches($path);

            if (null !== $captures) {
                return new RedirectMatch($rule, $captures);
            }
        }

        return null;
    }

    private function insert(RedirectRule $rule): void
    {
        $bucket = $this->bucketOf($rule);
        $key = $this->keyOf($rule);
        ++$this->count;

        if ('prefix' !== $bucket) {
            $this->buckets[$bucket][$key][] = $rule;

            return;
        }

        // Longest first, and after the prefixes of the same length already there.
        $rules = $this->buckets['prefix'][$key] ?? [];
        $length = \strlen($rule->source->path);
        $position = \count($rules);

        foreach ($rules as $index => $present) {
            if (\strlen($present->source->path) < $length) {
                $position = $index;

                break;
            }
        }

        array_splice($rules, $position, 0, [$rule]);
        $this->buckets['prefix'][$key] = $rules;
    }

    /**
     * @return 'exact'|'prefix'|'wildcard'|'regex'
     */
    private function bucketOf(RedirectRule $rule): string
    {
        return $rule->source->kind->value;
    }

    private function keyOf(RedirectRule $rule): string
    {
        $host = $rule->source->host ?? self::ANY_HOST;

        return MatchKind::Exact === $rule->source->kind ? $host."\n".$rule->source->path : $host."\n".$rule->source->firstSegment();
    }
}
