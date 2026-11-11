<!-- phil-dev source-sha256=4b568c36ba75b173c21e27d910d184638903162237fd62cccac16c86cee27e76 -->
# Configuration

Chaque option de `phil_redirects`, avec sa valeur par défaut :

```yaml
phil_redirects:
    # The DBAL connection of the two tables.
    connection: default

    # Service ids of the redirect providers, in order; the first one that gives a source wins.
    # Defaults to [phil_redirects.provider.stored], the redirects of the administration.
    providers: []

    # The hosts an absolute target URL may send to. None by default: no redirect leaves the site.
    allowed_hosts: []

    # Sources written as regular expressions: off by default.
    regex:
        enabled: false
        max_length: 255          # 1 to 1000

    # A redirect whose target is itself redirected.
    chains:
        allowed: false
        max_length: 3            # 1 to 10, the redirects of a chain at most

    # The table kept between requests.
    cache:
        pool: null               # a PSR-6 pool service id, cache.app for instance
        key: phil_redirects.table
        lifetime: null           # seconds; none keeps it until a change invalidates it

    # The hits of each redirect, written after the response.
    hits:
        enabled: true
        throttle: 60             # seconds between two writes of the last hit

    # The paths not found.
    not_found_log:
        enabled: true
        ignored_paths: ['/_wdt', '/_profiler', '/.well-known/']
        ignore_crawlers: true
        crawler_tokens: []       # more words of a user agent to ignore, in lower case
        robot_verdict_header: null
        retention: 90            # days, for phil:redirects:purge-404
        capacity: 10000          # entries at most
        max_path_length: 1024    # 1 to 2048

    # Old domains, by old host.
    old_domains:
        old-example.com:
            host: www.example.com
            status: 301          # 301, 302, 307 or 308
            keep_path: true
            keep_query: true
            scheme: null         # http or https; the request's when null
            base_path: ''        # put in front of the path: /en

    csv:
        max_lines: 10000

    admin:
        enabled: false
        role: ROLE_ADMIN
        regex_role: ROLE_SUPER_ADMIN
        layout: '@PhilRedirects/admin/layout.html.twig'
        import_max_bytes: 2000000

    # Service id of the PSR-20 clock.
    clock: clock
```

Un domaine ancien mal défini — un hôte qui n'en est pas un, un statut `410`, un schéma autre que `http` ou `https` —
fait échouer la construction du conteneur, avec le nom du domaine dans le message.

## Services

| Service | Public | Rôle |
|---|---|---|
| `Phil\RedirectsBundle\Application\Redirects` | oui | Créer, changer, retirer, importer et vérifier les redirections enregistrées. |
| `Phil\RedirectsBundle\Application\RedirectResolver` | oui | Résoudre un hôte et un chemin. |
| `Phil\RedirectsBundle\Application\RedirectTableCache` | oui | La table ; `invalidate()` après un changement d'un fournisseur. |
| `Phil\RedirectsBundle\Domain\Contract\RedirectRepository` | oui, alias | Les redirections enregistrées. |
| `Phil\RedirectsBundle\Domain\Contract\NotFoundLogRepository` | oui, alias | Le journal des 404. |
| `phil_redirects.provider.stored` | non | Les redirections enregistrées, sous forme de fournisseur. |
