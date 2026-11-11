# phil/redirects-bundle

**Redirects for Symfony, managed from an administration and resolved only when nothing else answered.**

phil/redirects-bundle keeps the redirects of a site that moved: `301`, `302`, `307`, `308` and `410 Gone`, from an
exact path, a prefix or a wildcard source, to a path, an allowed absolute URL or a route. It resolves them on a `404`
only, so a page that exists never pays for the table, and maps whole old domains to a new host before the routing.
Loops and chains are refused when a redirect is saved, and no redirect leaves the site unless the application listed
the host. The paths that were not found are logged by host and path, without any visitor data, and turned into a
redirect from the administration in one click. Redirects come and go as CSV, from the administration or the console,
and other packages contribute their own through a provider.

It is the redirect table of any site whose addresses change: a new structure, a merged section, a domain left behind.

## Status

| Part | State |
|---|---|
| Sources (exact, prefix, wildcard, regular expression) and targets (path, URL, route, gone) | Implemented and tested |
| The table, its PSR-6 cache and the resolver on `404` | Implemented and tested, benchmarked on 10,000 redirects |
| Old domains before the routing | Implemented and tested |
| Loops, chains and open redirects refused when saving | Implemented and tested |
| The 404 log, its filters and its purge | Implemented and tested |
| The administration in Twig, in English and French, and the EasyAdmin menu | Implemented and tested |
| CSV import and export, `phil:redirects:import`, `export`, `check`, `purge-404` | Implemented and tested |
| Providers for the redirects of other packages | Implemented and tested |

## Documentation

| Document | What it covers |
|---|---|
| [Installation](doc/installation.md) | Requirements, registering the bundle, the tables, the routes of the administration. |
| [Configuration](doc/configuration.md) | Every option of `phil_redirects`. |
| [Sources and targets](doc/sources-and-targets.md) | The four kinds of source, their bounded syntax, the targets and their placeholders. |
| [Resolution](doc/resolution.md) | When a redirect answers, in which order, its precedence after the routes of other packages, the cache. |
| [Old domains](doc/old-domains.md) | A domain mapped to a new host before the routing. |
| [Safety](doc/safety.md) | Loops and chains, open redirects, regular expressions, the bounds of every input. |
| [The 404 log](doc/not-found-log.md) | What is kept and what is not, the filters, the robot verdict, the purge. |
| [Administration](doc/administration.md) | The pages, the role, the CSRF token, the layout, EasyAdmin. |
| [Import, export and commands](doc/console.md) | The CSV format and the four commands. |
| [Extension points](doc/extension-points.md) | Providers, filters, repositories and options an application chooses or replaces. |
| [Testing](doc/testing.md) | The tests, the mutation bar, the fuzz target and the benchmark. |
| [Reference](doc/reference.md) | Every type of the bundle, with its role. |

## Quick start

```sh
composer require phil/redirects-bundle
```

```yaml
# config/packages/phil_redirects.yaml
phil_redirects:
    allowed_hosts: [shop.example.com]
    cache: { pool: cache.app }
    old_domains:
        old-example.com: { host: www.example.com }
    admin: { enabled: true, role: ROLE_EDITOR }
```

```yaml
# config/routes/phil_redirects.yaml
phil_redirects_admin:
    resource: '@PhilRedirectsBundle/config/routes/admin.php'
    prefix: /admin/redirects
```

```php
use Phil\RedirectsBundle\Application\Redirects;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;

$redirects->create(new RedirectRule(SourcePattern::wildcard('/blog/*/**'), Target::url('/articles/$1')));
```

## Requirements

PHP 8.5 or later, Symfony 8.2 or later, Doctrine DBAL 4.5 or later and `phil/url` 0.1.2, which requires the intl,
mbstring and URI extensions. The console, Twig, the security, the translator, a PSR-6 pool, EasyAdmin and
`phil/bot-detection-bundle` are optional.

## Contributing

Contributions are welcome on the PHIL monorepo: read the [contribution guide](CONTRIBUTING.md) and the
[code of conduct](CODE_OF_CONDUCT.md). Report a vulnerability privately, as the [security policy](SECURITY.md)
explains, never in a public issue.

## License

MIT, © Philippe Gamache, doing business as Le Gnome Archiviste; a Parler Haut Interagir Librement project. See [LICENSE](LICENSE).
