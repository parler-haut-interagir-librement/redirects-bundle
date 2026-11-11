<!-- phil-dev source-sha256=5a227a8b18a96265e545f1fccb20bf4d3fac9bcd3ceee0b532e91b788f725d6a -->
# Tests

| Domaine | Ce qui est couvert |
|---|---|
| Sources et cibles | Chaque genre, la normalisation d'un chemin, chaque borne et chaque refus, les paramètres substituables |
| La table et le résolveur | L'ordre des genres et des hôtes, le préfixe le plus long, les locales, le cache en mémoire et dans un pool |
| Destinations | Les captures encodées, le reste d'un préfixe, la politique de chaîne de requête, les routes, une destination qui quitterait le site |
| Boucles et chaînes | Boucles, chaînes permises et bornées, les redirections qui mènent à une nouvelle source, hôtes et locales tenus à part |
| `Redirects` | Chaque enregistrement à travers ses vérifications, l'import ligne par ligne, l'essai à blanc, la vérification |
| Le journal des 404 | Ce qui est gardé et ce qui ne l'est pas, les filtres, la capacité, la purge |
| Les magasins | SQLite en mémoire, et les instructions telles qu'un pilote peut y répondre |
| Les écouteurs | Une redirection, une `410`, les écritures après la réponse, la réinitialisation, une écriture ratée, un domaine ancien |
| L'administration | Chaque page et chaque formulaire sur Twig avec les gabarits et les traductions du bundle, le rôle, le jeton |
| Les commandes, le bundle | Chaque commande ; le câblage et chaque borne de la configuration ; un noyau sur SQLite |

Infection tient le bundle à 100 % de mutants tués ; les conversions qu'on ne peut pas observer sont nommées dans
`phil-dev.php`.

```sh
vendor/bin/phpunit packages/redirects-bundle/tests
```

## Fuzzing

`tests/Fuzz/pattern.php` lit une source de chaque genre et un chemin à partir d'entrées que personne n'a écrites : une
source est acceptée ou refusée avec `InvalidRedirect`, rien d'autre, et une destination construite à partir de ce
qu'elle a capturé ne quitte jamais le site.

```sh
composer fuzz -- pattern
```

## Banc d'essai

`tests/Benchmark/NotFoundResolverBench.php` résout des requêtes introuvables sur une table de 10 000 redirections :
7 000 exactes, 2 000 préfixes, 1 000 jokers, la moitié pour un seul hôte. Sur un portable récent :

| Sujet | Durée |
|---|---|
| Une source exacte | environ 3 µs |
| Un préfixe, un joker | environ 7 µs |
| Un chemin auquel rien ne correspond | environ 8 µs |
| Construction de la table | environ 7 ms |

```sh
composer bench -- packages/redirects-bundle/tests/Benchmark
```
