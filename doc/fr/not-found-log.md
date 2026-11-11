<!-- phil-dev source-sha256=c2ffb1e1324c2965e7552c1347bc00141c80da571e4e0c6344b761429fde904c -->
# Le journal des 404

Une requête introuvable à laquelle aucune redirection n'a répondu est journalisée dans `phil_redirect_not_found`, une
ligne par hôte et par chemin :

| Gardé | Non gardé |
|---|---|
| L'hôte et le chemin, encodés en pourcentage | L'adresse du visiteur |
| Combien de fois, première et dernière apparition | L'agent utilisateur |
| L'hôte de la dernière page qui y menait | Le référent complet, son chemin et sa requête |

La ligne est mise à jour en une seule instruction ; la première requête la crée. Au-delà de `capacity` entrées, un
nouveau chemin n'est pas journalisé, tandis que les chemins déjà présents continuent de compter.

## Ce qui n'est jamais journalisé

- Une requête autre que `GET` ou `HEAD`.
- Un chemin plus long que `max_path_length`, ou sous un préfixe de `ignored_paths` (par défaut, le profileur et
  `/.well-known/`).
- Une requête qu'un filtre ignore.

## Filtres

Chaque `NotFoundFilter` est consulté ; il suffit qu'un seul ignore la requête. Ceux du bundle :

- `DeclaredCrawlerFilter`, avec `ignore_crawlers` : un agent utilisateur qui se déclare robot d'indexation, script,
  outil de surveillance ou aperçu — `bot`, `crawl`, `spider`, `curl/`, `python`, `headless`… —, ou l'absence d'agent
  utilisateur. `crawler_tokens` ajoute des mots. Il ne lit que ce que déclare le client.
- `RobotVerdictFilter`, avec `phil/bot-detection-bundle` installé et `robot_verdict_header` défini : une requête dont
  l'en-tête porte le verdict de robot, lu strictement. La navigation d'un navigateur ne l'envoie pas d'elle-même :
  l'application l'envoie depuis ses propres scripts quand elle veut aussi filtrer ces requêtes. Le verdict ne décide
  que de cette requête et n'est pas gardé.

Un filtre de l'application implémente `Application\Contract\NotFoundFilter` ; l'autoconfiguration lui donne son
étiquette.

## D'un chemin à une redirection

L'administration liste les chemins, les plus demandés en premier, chacun avec un bouton qui ouvre le formulaire d'une
nouvelle redirection rempli à partir de lui. Une fois la redirection enregistrée, le chemin quitte le journal.

## Purge

```sh
bin/console phil:redirects:purge-404              # not requested for `retention` days
bin/console phil:redirects:purge-404 --older-than=30
```

À faire exécuter par le planificateur chaque nuit.
