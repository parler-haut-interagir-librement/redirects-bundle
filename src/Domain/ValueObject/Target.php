<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Domain\ValueObject;

use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Phil\Url\Exception\InvalidUrl;
use Phil\Url\Url;

/**
 * Where a redirect sends the request: a URL — a path of the site, or an absolute `http` or `https` URL —, a route of
 * the application with its parameters, or nowhere, for a `410 Gone`.
 *
 * `$1` to `$9` in a URL's path and query, or in a route parameter, stand for the values the source captured; never in
 * the scheme or the host of a URL, so that no request can choose the site it is sent to. The host of an absolute URL
 * is read by phil/url, as a browser reads it: in lower case, an international host in its ASCII form; one that is an
 * IP address, written dotted or as a single number, or a single name, is refused. Written as text, a route is
 * `route:<name>` followed by its parameters as a query string: `route:blog_post?slug=$1`.
 */
final readonly class Target
{
    public const int MAX_LENGTH = 2048;

    public const string ROUTE_PREFIX = 'route:';

    private const string ROUTE = '/^[A-Za-z0-9_.-]{1,200}$/D';

    private const string PARAMETER = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D';

    private const string ABSOLUTE = '#^(https?)://([^/?\#@:]+)(?::([1-9]\d{0,4}))?([/?\#].*)?$#i';

    /**
     * @param array<string, string> $parameters
     */
    private function __construct(
        public ?string $url,
        public ?string $route = null,
        public array $parameters = [],
        public ?string $host = null,
    ) {
    }

    public static function url(string $url): self
    {
        if ('' === $url || \strlen($url) > self::MAX_LENGTH || 1 === preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            throw InvalidRedirect::because('target_url', 'A target URL has between 1 and %max% characters, without spaces, control characters or "\\".', ['%max%' => (string) self::MAX_LENGTH]);
        }

        if (str_starts_with($url, '/')) {
            if (str_starts_with($url, '//')) {
                throw InvalidRedirect::because('target_relative', 'A target path starts with one "/"; a URL of another site gives its scheme.');
            }

            return new self($url);
        }

        if (1 !== preg_match(self::ABSOLUTE, $url, $parts)) {
            throw InvalidRedirect::because('target_url', 'A target URL has between 1 and %max% characters, without spaces, control characters or "\\".', ['%max%' => (string) self::MAX_LENGTH]);
        }

        try {
            $host = Url::fromString($url)->host;
        } catch (InvalidUrl) {
            throw InvalidRedirect::because('host', 'The host "%host%" is not a host name.', ['%host%' => $parts[2]]);
        }

        return new self($url, host: $host);
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    public static function route(string $name, array $parameters = []): self
    {
        if (1 !== preg_match(self::ROUTE, $name)) {
            throw InvalidRedirect::because('target_route', 'The route "%route%" is not a route name.', ['%route%' => $name]);
        }

        $checked = [];

        foreach ($parameters as $key => $value) {
            if (!\is_string($key) || 1 !== preg_match(self::PARAMETER, $key) || !\is_string($value) || \strlen($value) > 512) {
                throw InvalidRedirect::because('target_parameter', 'A route parameter has a name and a text value of 512 characters at most.');
            }

            $checked[$key] = $value;
        }

        ksort($checked);

        return new self(null, $name, $checked);
    }

    public static function none(): self
    {
        return new self(null);
    }

    /**
     * Reads a target as text: nothing for none, `route:` for a route, a URL otherwise.
     */
    public static function fromString(string $target): self
    {
        $target = trim($target);

        if ('' === $target) {
            return self::none();
        }

        if (!str_starts_with($target, self::ROUTE_PREFIX)) {
            return self::url($target);
        }

        $route = substr($target, \strlen(self::ROUTE_PREFIX));
        $query = strpos($route, '?');
        $parameters = [];

        if (false !== $query) {
            parse_str(substr($route, $query + 1), $parameters);
            $route = substr($route, 0, $query);
        }

        return self::route($route, $parameters);
    }

    public function isNone(): bool
    {
        return null === $this->url && null === $this->route;
    }

    /**
     * An absolute URL, whose host is then checked against the hosts the application allows.
     */
    public function isExternal(): bool
    {
        return null !== $this->host;
    }

    /**
     * The highest `$n` the target refers to.
     */
    public function highestPlaceholder(): int
    {
        $text = $this->url ?? implode("\n", $this->parameters);

        preg_match_all('/\$([1-9])/', $text, $placeholders);

        return max([0, ...array_map(intval(...), $placeholders[1])]);
    }

    public function toString(): string
    {
        if (null !== $this->route) {
            return self::ROUTE_PREFIX.$this->route.([] === $this->parameters ? '' : '?'.http_build_query($this->parameters, '', '&', \PHP_QUERY_RFC3986));
        }

        return $this->url ?? '';
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }
}
