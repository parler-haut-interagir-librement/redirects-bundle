# Administration

The administration is a few Twig pages under the routes of `config/routes/admin.php`:

| Route | Page |
|---|---|
| `phil_redirects_admin_list` | The redirects, the latest first, with a search, the hits and the last hit; edit, enable, disable, delete |
| `phil_redirects_admin_create` | The form of a new redirect; `?from=<id>` fills it from a path of the 404 log |
| `phil_redirects_admin_edit` | The form of a redirect |
| `phil_redirects_admin_toggle`, `phil_redirects_admin_delete` | The buttons of the list |
| `phil_redirects_admin_not_found` | The 404 log, the most requested first, each path with *Create a redirect* |
| `phil_redirects_admin_not_found_delete` | Removes a path from the log |
| `phil_redirects_admin_export` | Every redirect as a CSV download |
| `phil_redirects_admin_import` | Uploads a CSV file, tried first without writing anything by default |

It needs Twig, the translator, `security.authorization_checker` and `security.csrf.token_manager`.

## Role and token

Every page checks `admin.role`. Writing or changing a regular expression needs `admin.regex_role` on top, and
regular expressions must be enabled. Every form carries a CSRF token of id `phil_redirects_admin`; a form without a
valid one is refused with a `400`. Every save goes through `Redirects`: a refusal — a loop, a chain, a source already
taken, a host not allowed — is shown on the form, in the user's language, with a `422`.

## Layout

The pages extend `admin.layout`, a template that defines the `title` and `content` blocks. The default is a bare HTML
page; an application points it to a template of its back office:

```twig
{# templates/admin/redirects.html.twig #}
{% extends 'admin/base.html.twig' %}
{% block title %}{% endblock %}
{% block body %}{% block content %}{% endblock %}{% endblock %}
```

## Translations

The labels, outcomes and refusals are in the `phil_redirects` translation domain, in English and French. A refusal's
reason is the key `error.<reason>` with its parameters. An application overrides any message in its own
`translations/phil_redirects.<locale>.*` file.

## EasyAdmin

`Infrastructure\EasyAdmin\RedirectsAdmin::menuItems()` gives the links of a dashboard's menu — the redirects, the 404
log and the import:

```php
public function configureMenuItems(): iterable
{
    yield MenuItem::section('Redirects');
    yield from RedirectsAdmin::menuItems();
}
```

The pages keep their own routes, role and forms.
