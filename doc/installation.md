# Installation

## Requirements

| Requirement | Version | Why |
|---|---|---|
| PHP | 8.5 or later | Typed class constants, `readonly` classes, `#[Override]`, `array_any()`. |
| `ext-ctype`, `ext-mbstring` | any | Numbers read from a form or a row; searches kept to a number of characters. |
| `doctrine/dbal` | `^4.5` | The two tables, built with the schema editors of DBAL 4.5. |
| `doctrine/orm` | `^3.7` | The schema listener hooks into the ORM's `postGenerateSchema` event. |
| `psr/cache`, `psr/clock`, `psr/log` | `^3.0`, `^1.0`, `^3.0` | The pool of the table, the clock of every date, the log of a failed write. |
| `symfony/config`, `dependency-injection`, `http-foundation`, `http-kernel`, `routing` | `^8.2` | Configuration, wiring, the listeners, the route targets. |

Optional packages, each enabling one part:

| Package | Enables |
|---|---|
| `doctrine/doctrine-bundle` | The `doctrine.dbal.<name>_connection` service and the schema listener's tag. In practice always there. |
| `symfony/console` | The four commands. |
| `symfony/cache` (or any PSR-6 pool) | The table kept between requests. |
| `twig/twig`, `symfony/twig-bundle`, `symfony/translation`, `symfony/security-core`, `symfony/security-csrf` | The administration. |
| `easycorp/easyadmin-bundle` | The links of the administration in an EasyAdmin menu. |
| `phil/bot-detection-bundle` | The robot verdict that keeps a robot out of the 404 log. |

## Install with Composer

```sh
composer require phil/redirects-bundle
```

## Register the bundle

```php
// config/bundles.php
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],          // the administration only
    Phil\RedirectsBundle\PhilRedirectsBundle::class => ['all' => true],
];
```

With no configuration at all, the bundle resolves the stored redirects on every `404`, counts their hits and logs the
paths not found. The administration is off until enabled.

## The tables

`phil_redirect` and `phil_redirect_not_found` are added to the schema the ORM generates for the configured
connection, so the application's migrations create them:

```sh
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

`RedirectTables::configure()` gives the same tables to an application that writes its migrations by hand.

## The administration

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

The routes sit behind the application's firewall; the pages check the role themselves, and the forms carry a CSRF
token. See [Administration](administration.md).
