<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Fixture;

use Override;
use Phil\RedirectsBundle\Application\Contract\RedirectProvider;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;

final class ListProvider implements RedirectProvider
{
    public int $reads = 0;

    /**
     * @param list<RedirectRule> $rules
     */
    public function __construct(
        public array $rules = [],
    ) {
    }

    #[Override]
    public function redirects(): iterable
    {
        ++$this->reads;

        return $this->rules;
    }
}
