<!-- phil-dev source-sha256=a11013a81a90a8c6578f76cf6b1338acca3ab60679525694ca690225142627d4 -->
# phil/redirects-bundle

**Des redirections pour Symfony, gérées depuis une administration et résolues seulement quand rien d'autre n'a
répondu.**

phil/redirects-bundle garde les redirections d'un site qui a déménagé : `301`, `302`, `307`, `308` et `410 Gone`, d'une
source par chemin exact, par préfixe ou par joker, vers un chemin, une URL absolue permise ou une route. Il ne les
résout que sur une `404` : une page qui existe ne paie ainsi jamais pour la table. Il associe aussi des domaines
anciens entiers à un nouvel hôte avant le routage. Les boucles et les chaînes sont refusées à l'enregistrement d'une
redirection, et aucune redirection ne quitte le site sauf si l'application a listé l'hôte. Les chemins introuvables
sont journalisés par hôte et par chemin, sans aucune donnée sur le visiteur, et deviennent une redirection en un clic
depuis l'administration. Les redirections entrent et sortent en CSV, depuis l'administration ou la console, et
d'autres paquets fournissent les leurs par un fournisseur.

C'est la table des redirections de tout site dont les adresses changent : une nouvelle structure, une section
fusionnée, un domaine abandonné.

## État

| Partie | État |
|---|---|
| Sources (exacte, préfixe, joker, expression régulière) et cibles (chemin, URL, route, disparue) | Implémenté et testé |
| La table, son cache PSR-6 et le résolveur sur `404` | Implémenté et testé, banc d'essai sur 10 000 redirections |
| Les domaines anciens avant le routage | Implémenté et testé |
| Boucles, chaînes et redirections ouvertes refusées à l'enregistrement | Implémenté et testé |
| Le journal des 404, ses filtres et sa purge | Implémenté et testé |
| L'administration en Twig, en anglais et en français, et le menu EasyAdmin | Implémenté et testé |
| Import et export CSV, `phil:redirects:import`, `export`, `check`, `purge-404` | Implémenté et testé |
| Fournisseurs pour les redirections d'autres paquets | Implémenté et testé |

## Documentation

| Document | Contenu |
|---|---|
| [Installation](doc/fr/installation.md) | Prérequis, enregistrement du bundle, les tables, les routes de l'administration. |
| [Configuration](doc/fr/configuration.md) | Chaque option de `phil_redirects`. |
| [Sources et cibles](doc/fr/sources-and-targets.md) | Les quatre genres de source, leur syntaxe bornée, les cibles et leurs paramètres substituables. |
| [Résolution](doc/fr/resolution.md) | Quand une redirection répond, dans quel ordre, sa priorité après les routes d'autres paquets, le cache. |
| [Domaines anciens](doc/fr/old-domains.md) | Un domaine associé à un nouvel hôte avant le routage. |
| [Sécurité](doc/fr/safety.md) | Boucles et chaînes, redirections ouvertes, expressions régulières, les bornes de chaque entrée. |
| [Le journal des 404](doc/fr/not-found-log.md) | Ce qui est gardé et ce qui ne l'est pas, les filtres, le verdict de robot, la purge. |
| [Administration](doc/fr/administration.md) | Les pages, le rôle, le jeton CSRF, la mise en page, EasyAdmin. |
| [Import, export et commandes](doc/fr/console.md) | Le format CSV et les quatre commandes. |
| [Points d'extension](doc/fr/extension-points.md) | Fournisseurs, filtres, dépôts et options que l'application choisit ou remplace. |
| [Tests](doc/fr/testing.md) | Les tests, le seuil de mutation, la cible de fuzzing et le banc d'essai. |
| [Référence](doc/fr/reference.md) | Chaque type du bundle, avec son rôle. |

## Démarrage rapide

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

## Prérequis

PHP 8.5 ou plus récent, Symfony 8.2 ou plus récent et Doctrine DBAL 4.5 ou plus récent. La console, Twig, la
sécurité, le traducteur, un pool PSR-6, EasyAdmin et `phil/bot-detection-bundle` sont facultatifs.

## Contribuer

Les contributions sont les bienvenues sur le monodépôt PHIL : lisez le [guide de contribution](CONTRIBUTING.md) et le
[code de conduite](CODE_OF_CONDUCT.md). Signalez une vulnérabilité en privé, comme l'explique la [politique de
sécurité](SECURITY.md), jamais dans un ticket public.

## Licence

MIT, © Philippe Gamache, doing business as Le Gnome Archiviste; a Parler Haut Interagir Librement project. Voir [LICENSE](LICENSE).
