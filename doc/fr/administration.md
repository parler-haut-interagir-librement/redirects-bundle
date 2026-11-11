<!-- phil-dev source-sha256=304b97959bba35dd24c6cf88bb8e8005f5316c03770d78af052b9de50bfd34e5 -->
# Administration

L'administration est faite de quelques pages Twig sous les routes de `config/routes/admin.php` :

| Route | Page |
|---|---|
| `phil_redirects_admin_list` | Les redirections, les plus récentes en premier, avec une recherche, les accès et le dernier accès ; modifier, activer, désactiver, supprimer |
| `phil_redirects_admin_create` | Le formulaire d'une nouvelle redirection ; `?from=<id>` le remplit à partir d'un chemin du journal des 404 |
| `phil_redirects_admin_edit` | Le formulaire d'une redirection |
| `phil_redirects_admin_toggle`, `phil_redirects_admin_delete` | Les boutons de la liste |
| `phil_redirects_admin_not_found` | Le journal des 404, les plus demandés en premier, chaque chemin avec *Créer une redirection* |
| `phil_redirects_admin_not_found_delete` | Retire un chemin du journal |
| `phil_redirects_admin_export` | Chaque redirection, en téléchargement CSV |
| `phil_redirects_admin_import` | Téléverse un fichier CSV, d'abord essayé sans rien écrire par défaut |

Elle exige Twig, le traducteur, `security.authorization_checker` et `security.csrf.token_manager`.

## Rôle et jeton

Chaque page vérifie `admin.role`. Écrire ou changer une expression régulière exige en plus `admin.regex_role`, et les
expressions régulières doivent être activées. Chaque formulaire porte un jeton CSRF d'identifiant
`phil_redirects_admin` ; un formulaire sans jeton valide est refusé avec une `400`. Chaque enregistrement passe par
`Redirects` : un refus — une boucle, une chaîne, une source déjà prise, un hôte non permis — est affiché sur le
formulaire, dans la langue de l'utilisateur, avec une `422`.

## Mise en page

Les pages étendent `admin.layout`, un gabarit qui définit les blocs `title` et `content`. Par défaut, c'est une page
HTML dépouillée ; l'application le fait pointer vers un gabarit de son interface d'administration :

```twig
{# templates/admin/redirects.html.twig #}
{% extends 'admin/base.html.twig' %}
{% block title %}{% endblock %}
{% block body %}{% block content %}{% endblock %}{% endblock %}
```

## Traductions

Les libellés, résultats et refus sont dans le domaine de traduction `phil_redirects`, en anglais et en français. La
raison d'un refus est la clé `error.<reason>` avec ses paramètres. L'application remplace n'importe quel message dans
son propre fichier `translations/phil_redirects.<locale>.*`.

## EasyAdmin

`Infrastructure\EasyAdmin\RedirectsAdmin::menuItems()` donne les liens du menu d'un tableau de bord : les
redirections, le journal des 404 et l'import :

```php
public function configureMenuItems(): iterable
{
    yield MenuItem::section('Redirects');
    yield from RedirectsAdmin::menuItems();
}
```

Les pages gardent leurs propres routes, leur rôle et leurs formulaires.
