<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Domain\ValueObject;

use Phil\RedirectsBundle\Domain\Enum\QueryPolicy;
use Phil\RedirectsBundle\Domain\Enum\RedirectStatus;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;

/**
 * One redirect as the table resolves it: its source, its target, its status, its query policy and the locale it is
 * limited to. A stored redirect gives its identifier, so that its hits are counted; a provider's redirect has none.
 *
 * A `410 Gone` has no target and every other status has one; a target refers to no more values than its source
 * captures.
 */
final readonly class RedirectRule
{
    private const string LOCALE = '/^[a-z]{2,3}(?:[_-][A-Za-z0-9]{2,8}){0,3}$/D';

    public ?string $locale;

    public function __construct(
        public SourcePattern $source,
        public Target $target,
        public RedirectStatus $status = RedirectStatus::MovedPermanently,
        public QueryPolicy $query = QueryPolicy::Drop,
        ?string $locale = null,
        public ?int $id = null,
    ) {
        if ($status->isGone() !== $target->isNone()) {
            throw $status->isGone() ? InvalidRedirect::because('gone_target', 'A 410 Gone has no target.') : InvalidRedirect::because('missing_target', 'A redirect has a target, except a 410 Gone.');
        }

        if ($target->highestPlaceholder() > $source->captures()) {
            throw InvalidRedirect::because('placeholder', 'The target refers to $%placeholder%, which the source does not capture.', ['%placeholder%' => (string) $target->highestPlaceholder()]);
        }

        if (null !== $locale && '' !== $locale && 1 !== preg_match(self::LOCALE, $locale)) {
            throw InvalidRedirect::because('locale', 'The locale "%locale%" is not a locale.', ['%locale%' => $locale]);
        }

        $this->locale = '' === $locale ? null : $locale;
    }

    public function withId(?int $id): self
    {
        return new self($this->source, $this->target, $this->status, $this->query, $this->locale, $id);
    }

    /**
     * Whether a request in this locale may use the redirect: one without a locale applies to every request.
     */
    public function appliesTo(?string $locale): bool
    {
        return null === $this->locale || $this->locale === $locale;
    }

    /**
     * Whether both redirects have the same source and locale, so that one of them would never be used.
     */
    public function overlaps(self $other): bool
    {
        return $this->source->equals($other->source) && $this->locale === $other->locale;
    }
}
