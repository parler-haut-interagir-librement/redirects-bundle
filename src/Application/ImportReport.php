<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

/**
 * What an import did, line by line: the redirects created and updated, the lines skipped because their source was
 * already there, and the lines refused with their reason.
 */
final class ImportReport
{
    public int $created = 0;

    public int $updated = 0;

    /** @var list<int> */
    public array $skipped = [];

    /** @var array<int, string> */
    public array $rejected = [];

    public function __construct(
        public readonly bool $dryRun = false,
    ) {
    }

    public function isClean(): bool
    {
        return [] === $this->rejected;
    }
}
