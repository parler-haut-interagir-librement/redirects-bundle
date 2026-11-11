<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

use Phil\RedirectsBundle\Domain\Enum\RedirectStatus;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;

/**
 * Where an old domain goes: the new host, its scheme — the request's when none is given —, a base path put in front,
 * and whether the path and the query string of the request are kept.
 */
final readonly class OldDomain
{
    public string $host;

    public function __construct(
        string $host,
        public RedirectStatus $status = RedirectStatus::MovedPermanently,
        public bool $keepPath = true,
        public bool $keepQuery = true,
        public ?string $scheme = null,
        public string $basePath = '',
    ) {
        $this->host = SourcePattern::hostName($host);

        if ($status->isGone()) {
            throw InvalidRedirect::because('old_domain_status', 'An old domain redirects with 301, 302, 307 or 308.');
        }

        if (null !== $scheme && !\in_array($scheme, ['http', 'https'], true)) {
            throw InvalidRedirect::because('old_domain_scheme', 'An old domain redirects to http or https.');
        }

        if ('' !== $basePath && ('/' === $basePath || SourcePattern::exact($basePath)->path !== $basePath)) {
            throw InvalidRedirect::because('old_domain_path', 'The base path of an old domain starts with "/" and does not end with one.');
        }
    }

    /**
     * The location of a request to the old domain: `$path` is its path from the root, `$query` its query string.
     */
    public function locationFor(string $scheme, string $path, string $query = ''): string
    {
        $path = $this->keepPath ? $path : '/';
        $location = ($this->scheme ?? $scheme).'://'.$this->host.$this->basePath.('/' === $path && '' !== $this->basePath ? '' : $path);

        return $this->keepQuery && '' !== $query ? $location.'?'.$query : $location;
    }
}
