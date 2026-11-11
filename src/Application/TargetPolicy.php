<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

use Phil\RedirectsBundle\Domain\ValueObject\Target;
use Phil\Url\Exception\InvalidUrl;
use Phil\Url\Url;

/**
 * The open-redirect guard: a path of the site and a route are always allowed; an absolute URL only when its host is
 * one the application lists — its own hosts, those of a site it moved to. The list is empty by default, so that no
 * redirect leaves the site unless the application said where it may go. A listed host is read by phil/url, as the
 * target's: `Bücher.example.` allows `https://xn--bcher-kva.example/`; an entry that is no host name allows nothing.
 */
final readonly class TargetPolicy
{
    /** @var list<?string> the allowed hosts; none for an entry that is no host name, which no target's host is */
    private array $hosts;

    /**
     * @param list<string> $allowedHosts
     * @param bool         $allowLocalhost whether `localhost` and the names under `.localhost` may be listed, in
     *                                     development and tests
     */
    public function __construct(array $allowedHosts = [], private bool $allowLocalhost = false)
    {
        $this->hosts = array_map($this->hostOf(...), $allowedHosts);
    }

    /**
     * A host as phil/url reads it — in lower case, an international one in its ASCII form —, or none for what is not a
     * host name alone: a host with a path or a port, a user name, an IP address.
     */
    private function hostOf(string $host): ?string
    {
        try {
            $url = Url::fromString('https://'.$host, allowLocalhost: $this->allowLocalhost);
        } catch (InvalidUrl) {
            return null;
        }

        return 'https://'.$url->host.'/' === $url->toString() ? $url->host : null;
    }

    public function allows(Target $target): bool
    {
        return !$target->isExternal() || \in_array($target->host, $this->hosts, true);
    }
}
