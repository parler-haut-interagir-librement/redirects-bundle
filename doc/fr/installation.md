<!-- phil-dev source-sha256=359a30791f3096f11b48ecc4b2210d15ab667d3b7a38429560c9c7e52228d254 -->
# Installation

## Prérequis

| Prérequis | Version | Pourquoi |
|---|---|---|
| PHP | 8.5 ou plus récent | Constantes de classe typées, classes `readonly`, `#[Override]`, `array_any()`. |
| `ext-ctype`, `ext-mbstring` | toute version | Nombres lus dans un formulaire ou une ligne ; recherches limitées à un nombre de caractères. |
| `doctrine/dbal` | `^4.5` | Les deux tables, construites avec les éditeurs de schéma de DBAL 4.5. |
| `doctrine/orm` | `^3.7` | L'écouteur de schéma se branche sur l'événement `postGenerateSchema` de l'ORM. |
| `psr/cache`, `psr/clock`, `psr/log` | `^3.0`, `^1.0`, `^3.0` | Le pool de la table, l'horloge de chaque date, la journalisation d'une écriture ratée. |
| `symfony/config`, `dependency-injection`, `http-foundation`, `http-kernel`, `routing` | `^8.2` | Configuration, câblage, les écouteurs, les cibles de type route. |

Les paquets facultatifs activent chacun une partie :

| Paquet | Active |
|---|---|
| `doctrine/doctrine-bundle` | Le service `doctrine.dbal.<name>_connection` et l'étiquette de l'écouteur de schéma. En pratique, toujours présent. |
| `symfony/console` | Les quatre commandes. |
| `symfony/cache` (ou tout pool PSR-6) | La table gardée d'une requête à l'autre. |
| `twig/twig`, `symfony/twig-bundle`, `symfony/translation`, `symfony/security-core`, `symfony/security-csrf` | L'administration. |
| `easycorp/easyadmin-bundle` | Les liens de l'administration dans un menu EasyAdmin. |
| `phil/bot-detection-bundle` | Le verdict de robot qui tient un robot hors du journal des 404. |

## Installer avec Composer

```sh
composer require phil/redirects-bundle
```

## Enregistrer le bundle

```php
// config/bundles.php
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],          // the administration only
    Phil\RedirectsBundle\PhilRedirectsBundle::class => ['all' => true],
];
```

Sans aucune configuration, le bundle résout les redirections enregistrées sur chaque `404`, compte leurs accès et
journalise les chemins introuvables. L'administration reste désactivée jusqu'à ce que vous l'activiez.

## Les tables

`phil_redirect` et `phil_redirect_not_found` sont ajoutées au schéma que l'ORM génère pour la connexion configurée :
les migrations de l'application les créent donc.

```sh
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

`RedirectTables::configure()` donne les mêmes tables à une application qui écrit ses migrations à la main.

## L'administration

```yaml
# config/packages/phil_redirects.yaml
phil_redirects:
    admin: { enabled: true, role: ROLE_EDITOR }
```

```yaml
# config/routes/phil_redirects.yaml
phil_redirects_admin:
    resource: '@PhilRedirectsBundle/config/routes/admin.php'
    prefix: /admin/redirects
```

Les routes se trouvent derrière le pare-feu de l'application ; les pages vérifient elles-mêmes le rôle, et les
formulaires portent un jeton CSRF. Voir [Administration](administration.md).
