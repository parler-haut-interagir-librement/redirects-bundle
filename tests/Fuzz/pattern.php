<?php

declare(strict_types=1);

use Phil\RedirectsBundle\Application\LocationBuilder;
use Phil\RedirectsBundle\Application\RedirectMatch;
use Phil\RedirectsBundle\Domain\Enum\MatchKind;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;

/*
 * The pattern matcher on what nobody wrote: the input's first line is a source of each kind, its second line a path of
 * a request. A source is accepted or refused with `InvalidRedirect`, nothing else; a path matches or not; a captured
 * value put back in a target never gives a location that leaves the site.
 */
$autoload = getenv('PHIL_DEV_AUTOLOAD');

if (!is_string($autoload)) {
    throw new RuntimeException('PHIL_DEV_AUTOLOAD names the autoloader: run this target through phil-dev fuzz.');
}

require $autoload;

/** @var PhpFuzzer\Config $config */
$config->setTarget(static function (string $input): void {
    [$source, $path] = explode("\n", $input, 2) + ['', ''];
    $path = SourcePattern::requestPath($path);

    foreach (MatchKind::cases() as $kind) {
        try {
            $pattern = SourcePattern::of($kind, $source, null, 64);
        } catch (InvalidRedirect) {
            continue;
        }

        $captures = $pattern->matches($path);

        if (null === $captures) {
            continue;
        }

        if (count($captures) > SourcePattern::MAX_CAPTURES) {
            throw new LogicException('More captures than a target can use.');
        }

        $target = '/to/'.implode('/', array_map(static fn (int $n): string => '$'.$n, range(1, max(1, min(SourcePattern::MAX_CAPTURES, $pattern->captures())))));

        try {
            $rule = new RedirectRule($pattern, Target::url($pattern->captures() > 0 ? $target : '/to'));
        } catch (InvalidRedirect) {
            continue;
        }

        $location = new LocationBuilder()->build(new RedirectMatch($rule, $captures));

        if (null !== $location && (!str_starts_with($location, '/to') || 1 === preg_match('/[\x00-\x20\x7F\\\\]/', $location))) {
            throw new LogicException('A location left the site: '.$location);
        }
    }
});
$config->setMaxLen(512);
