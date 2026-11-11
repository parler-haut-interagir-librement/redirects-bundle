<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

use Override;
use Phil\RedirectsBundle\Application\Contract\RedirectProvider;
use Phil\RedirectsBundle\Domain\Contract\RedirectRepository;

/**
 * The redirects of the administration that are enabled, as a provider of the table.
 */
final readonly class StoredRedirects implements RedirectProvider
{
    public function __construct(
        private RedirectRepository $repository,
    ) {
    }

    #[Override]
    public function redirects(): iterable
    {
        foreach ($this->repository->all() as $redirect) {
            if ($redirect->isEnabled()) {
                yield $redirect->rule();
            }
        }
    }
}
