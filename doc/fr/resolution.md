<!-- phil-dev source-sha256=41e844ba587f038f6f515a50e4ded8676c05aa4af609c4cea0b29e803ccc5c06 -->
# Résolution

## Seulement quand rien d'autre n'a répondu

`NotFoundListener` écoute `kernel.exception`. Quand la requête principale se termine par une `404` — une route qui
n'existe pas, ou un contrôleur qui a levé `NotFoundHttpException` —, la table est interrogée avec l'hôte, le chemin, la
chaîne de requête et la locale de la requête. Une requête trouvée n'atteint jamais la table : le routage n'est donc
jamais ralenti.

| La table donne | La réponse |
|---|---|
| Une redirection | Son statut, avec `Location` égal à la cible construite à partir des captures |
| Une `410 Gone` | `GoneHttpException` remplace la `404`, et la page d'erreur de l'application produit le rendu de la `410` |
| Une cible impossible à construire | La `404` reste |
| Rien | La `404` reste, et le chemin est gardé pour le journal |

Les écritures — l'accès à la redirection, le chemin introuvable — attendent `kernel.terminate`, une fois la réponse
partie.

## Priorité par rapport aux autres paquets

Les routes et les contrôleurs de l'application, et ceux des autres paquets, répondent d'abord. Un paquet de contenu qui
redirige les anciens *slugs* de ses pages avec sa propre `301` le fait dans son contrôleur, avant toute `404` : la
table ne voit que les requêtes qu'il a laissées. Quand un contrôleur de contenu lève une `404` pour un *slug* qu'il ne
connaît pas, la table est interrogée ensuite.

Un paquet qui veut ses redirections dans la table plutôt que dans son contrôleur écrit un fournisseur : voir
[Points d'extension](extension-points.md).

## Ordre dans la table

1. Les sources exactes, puis les préfixes — les plus longs en premier —, puis les jokers et les expressions
   régulières, dans l'ordre où les fournisseurs les ont donnés.
2. Dans chaque genre, les sources de l'hôte de la requête avant celles de tous les hôtes.
3. Une redirection limitée à une locale est sautée pour une requête dans une autre locale.
4. Deux fournisseurs qui donnent la même source : le premier listé l'emporte.

Une source exacte est une clé ; un préfixe et un joker sont rangés sous leur premier segment : un chemin ne rencontre
ainsi que les sources qui commencent comme lui. Sur une table de 10 000 redirections, une résolution prend quelques
microsecondes ; voir [Tests](testing.md).

## Le cache

`RedirectTableCache` construit la table à partir des fournisseurs et la garde :

- pour le reste de la requête, en mémoire, abandonnée sur `kernel.reset` : un *worker* ne sert ainsi jamais une
  requête avec la table d'une autre ;
- d'une requête à l'autre, dans le pool PSR-6 de `cache.pool`, sous `cache.key`, pendant `cache.lifetime` secondes ou
  jusqu'à un changement.

Chaque enregistrement, retrait et import par `Redirects` invalide les deux copies. Un fournisseur dont les
redirections changent appelle `RedirectTableCache::invalidate()`. Sans pool, la table est construite à la première
requête introuvable de chaque processus.

## Accès

Chaque redirection enregistrée compte les requêtes auxquelles elle a répondu. Le décompte est exact ; le dernier accès
est écrit au plus une fois toutes les `hits.throttle` secondes, dans la même instruction. `hits.enabled: false`
n'écrit rien.
