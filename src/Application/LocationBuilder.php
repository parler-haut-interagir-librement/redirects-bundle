<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

use Phil\RedirectsBundle\Domain\Enum\MatchKind;
use Phil\RedirectsBundle\Domain\Enum\QueryPolicy;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The location a redirect sends a request to: the target with `$1` to `$9` replaced by the values the source
 * captured, the rest of the path under a prefix appended, and the query string of the request kept when the redirect
 * says so.
 *
 * A captured value is put back percent-encoded, so that it cannot add a scheme, a host or a line to the location; the
 * location is then checked once more: a path stays a path of the site, and an absolute URL keeps the host of its
 * target. A location that fails, or a route that cannot be generated, gives none, and the request stays not found.
 */
final readonly class LocationBuilder
{
    private const string UNSAFE = '/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/%]/';

    private const string QUERY_UNSAFE = '/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/%?]/';

    public function __construct(
        private ?UrlGeneratorInterface $urls = null,
    ) {
    }

    public function build(RedirectMatch $match, string $query = ''): ?string
    {
        $rule = $match->rule;
        $target = $rule->target;
        $captures = array_map(static fn (string $capture): string => (string) preg_replace_callback(self::UNSAFE, static fn (array $character): string => rawurlencode($character[0]), $capture), $match->captures);

        if (null !== $target->url) {
            $location = $this->replace($target->url, $captures);
        } elseif (null !== $target->route) {
            $location = $this->generate($target->route, array_map(fn (string $value): string => rawurldecode($this->replace($value, $captures)), $target->parameters));
        } else {
            return null;
        }

        if (null === $location) {
            return null;
        }

        if (MatchKind::Prefix === $rule->source->kind) {
            $location = $this->appendToPath($location, $captures[0] ?? '');
        }

        if (QueryPolicy::Keep === $rule->query && '' !== $query) {
            $location = $this->appendQuery($location, $query);
        }

        return $this->isSafe($location, $target->host) ? $location : null;
    }

    /**
     * @param list<string> $captures
     */
    private function replace(string $text, array $captures): string
    {
        $values = [];

        foreach (range(1, 9) as $number) {
            $values['$'.$number] = $captures[$number - 1] ?? '';
        }

        return strtr($text, $values);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function generate(string $route, array $parameters): ?string
    {
        if (!$this->urls instanceof UrlGeneratorInterface) {
            return null;
        }

        try {
            return $this->urls->generate($route, $parameters);
        } catch (RoutingException) {
            return null;
        }
    }

    private function appendToPath(string $location, string $rest): string
    {
        if ('' === $rest) {
            return $location;
        }

        $end = strcspn($location, '?#');
        $path = substr($location, 0, $end);

        return (str_ends_with($path, '/') ? substr($path, 0, -1) : $path).$rest.substr($location, $end);
    }

    private function appendQuery(string $location, string $query): string
    {
        $fragment = strpos($location, '#');
        $tail = false === $fragment ? '' : substr($location, $fragment);
        $head = false === $fragment ? $location : substr($location, 0, $fragment);
        $query = (string) preg_replace_callback(self::QUERY_UNSAFE, static fn (array $character): string => rawurlencode($character[0]), $query);

        return $head.(str_contains($head, '?') ? '&' : '?').$query.$tail;
    }

    private function isSafe(string $location, ?string $host): bool
    {
        if (1 === preg_match('/[\x00-\x20\x7F\\\\]/', $location)) {
            return false;
        }

        if (str_starts_with($location, '/')) {
            return !str_starts_with($location, '//');
        }

        return null !== $host && strtolower(rtrim((string) parse_url($location, \PHP_URL_HOST), '.')) === $host;
    }
}
