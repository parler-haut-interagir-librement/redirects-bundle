<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Benchmark;

use Phil\RedirectsBundle\Application\LocationBuilder;
use Phil\RedirectsBundle\Application\RedirectResolver;
use Phil\RedirectsBundle\Application\RedirectTable;
use Phil\RedirectsBundle\Application\RedirectTableCache;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use Phil\RedirectsBundle\Tests\Unit\Fixture\ListProvider;

/**
 * The resolver of a request not found, on a table of 10,000 redirects of a site that moved: 7,000 exact paths, 2,000
 * prefixes over 200 sections, 1,000 wildcard sources, half of them for one host. Each request not found pays one
 * resolution; the table is built once per process without a pool, which `benchBuildTable` measures.
 */
final class NotFoundResolverBench
{
    private readonly RedirectResolver $resolver;

    /** @var list<RedirectRule> */
    private array $rules = [];

    public function __construct()
    {
        for ($index = 0; $index < 7000; ++$index) {
            $this->rules[] = new RedirectRule(SourcePattern::exact(\sprintf('/old/article-%d', $index), 0 === $index % 2 ? 'www.example.com' : null), Target::url(\sprintf('/articles/%d', $index)));
        }

        for ($index = 0; $index < 2000; ++$index) {
            $this->rules[] = new RedirectRule(SourcePattern::prefix(\sprintf('/section-%d/archive-%d', $index % 200, $index)), Target::url(\sprintf('/archives/%d', $index)));
        }

        for ($index = 0; $index < 1000; ++$index) {
            $this->rules[] = new RedirectRule(SourcePattern::wildcard(\sprintf('/catalogue-%d%s/*/**', $index % 100, $index >= 100 ? '/v'.$index : '')), Target::url('/products/$1'));
        }

        $tables = new RedirectTableCache([new ListProvider($this->rules)]);
        $this->resolver = new RedirectResolver($tables, new LocationBuilder());
        $this->resolver->resolve('www.example.com', '/warm-up');
    }

    public function benchExactHit(): void
    {
        $this->resolver->resolve('www.example.com', '/old/article-6998');
    }

    public function benchPrefixHit(): void
    {
        $this->resolver->resolve('www.example.com', '/section-199/archive-1999/a/b', 'utm_source=x');
    }

    public function benchWildcardHit(): void
    {
        $this->resolver->resolve('www.example.com', '/catalogue-42/shoe/red/large');
    }

    public function benchMiss(): void
    {
        $this->resolver->resolve('www.example.com', '/section-12/nothing/here');
    }

    /**
     * @Revs(5)
     */
    public function benchBuildTable(): void
    {
        RedirectTable::of($this->rules);
    }
}
