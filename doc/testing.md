# Testing

| Area | What is covered |
|---|---|
| Sources and targets | Every kind, the normalization of a path, every bound and refusal, the placeholders |
| The table and the resolver | The order of the kinds and hosts, the longest prefix, the locales, the cache in memory and in a pool |
| Locations | The captures encoded, a prefix's rest, the query policy, the routes, a location that would leave the site |
| Loops and chains | Loops, chains allowed and bounded, the redirects leading to a new source, hosts and locales kept apart |
| `Redirects` | Every save through its checks, the import line by line, the dry run, the check |
| The 404 log | What is kept and what is not, the filters, the capacity, the purge |
| The stores | SQLite in memory, and the statements as a driver may answer them |
| The listeners | A redirect, a `410`, the writes after the response, the reset, a failed write, an old domain |
| The administration | Each page and form on Twig with the bundle's templates and translations, the role, the token |
| The commands, the bundle | Each command; the wiring and every bound of the configuration; a kernel on SQLite |

Infection holds the bundle at 100 % of mutants killed; the casts that cannot be observed are named in `phil-dev.php`.

```sh
vendor/bin/phpunit packages/redirects-bundle/tests
```

## Fuzzing

`tests/Fuzz/pattern.php` reads a source of each kind and a path from inputs nobody wrote: a source is accepted or
refused with `InvalidRedirect`, nothing else, and a location built from what it captured never leaves the site.

```sh
composer fuzz -- pattern
```

## Benchmark

`tests/Benchmark/NotFoundResolverBench.php` resolves requests not found on a table of 10,000 redirects — 7,000 exact,
2,000 prefixes, 1,000 wildcards, half for one host. On a recent laptop:

| Subject | Time |
|---|---|
| An exact source | about 3 µs |
| A prefix, a wildcard | about 7 µs |
| A path nothing matches | about 8 µs |
| Building the table | about 7 ms |

```sh
composer bench -- packages/redirects-bundle/tests/Benchmark
```
