<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

use Phil\RedirectsBundle\Domain\Enum\MatchKind;
use Phil\RedirectsBundle\Domain\Enum\QueryPolicy;
use Phil\RedirectsBundle\Domain\Enum\RedirectStatus;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;

/**
 * A redirect as text, the way a form or a line of CSV gives it, read into a rule by {@see self::rule()}.
 */
final readonly class RedirectDraft
{
    public function __construct(
        public string $source,
        public string $target = '',
        public string $status = '301',
        public string $kind = 'exact',
        public string $host = '',
        public string $query = 'drop',
        public string $locale = '',
        public bool $enabled = true,
    ) {
    }

    public static function fromRule(RedirectRule $rule, bool $enabled = true): self
    {
        return new self(
            $rule->source->path,
            $rule->target->toString(),
            (string) $rule->status->value,
            $rule->source->kind->value,
            $rule->source->host ?? '',
            $rule->query->value,
            $rule->locale ?? '',
            $enabled,
        );
    }

    /**
     * @throws InvalidRedirect
     */
    public function rule(int $regexMaxLength = SourcePattern::DEFAULT_REGEX_MAX_LENGTH): RedirectRule
    {
        $kind = MatchKind::tryFrom(trim($this->kind)) ?? throw InvalidRedirect::because('kind', 'The kind "%kind%" is not exact, prefix, wildcard or regex.', ['%kind%' => $this->kind]);
        $digits = trim($this->status);
        $status = ctype_digit($digits) ? RedirectStatus::tryFrom((int) $digits) : null;
        $query = QueryPolicy::tryFrom(trim($this->query)) ?? throw InvalidRedirect::because('query', 'The query policy "%query%" is not drop or keep.', ['%query%' => $this->query]);

        if (!$status instanceof RedirectStatus) {
            throw InvalidRedirect::because('status', 'The status "%status%" is not 301, 302, 307, 308 or 410.', ['%status%' => $this->status]);
        }

        return new RedirectRule(
            SourcePattern::of($kind, trim($this->source), trim($this->host), $regexMaxLength),
            Target::fromString($this->target),
            $status,
            $query,
            trim($this->locale),
        );
    }
}
