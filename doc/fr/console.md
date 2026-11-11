<!-- phil-dev source-sha256=90501092d283a6ff0eee588eedda931a83b99cc333497e64b419438f8dd500a4 -->
# Import, export et commandes

## Le format CSV

Une redirection par ligne, après un en-tête qui nomme les colonnes, dans n'importe quel ordre :

```csv
source,target,status,kind,host,query,locale,enabled,hits,last_hit_at
/old/page,/new/page,301,exact,,drop,,1,12,2026-05-01T10:00:00+00:00
/docs,/manual,301,prefix,,keep,,1,0,
/blog/*/**,route:blog_post?slug=$1,308,wildcard,www.example.com,drop,fr,1,0,
/removed,,410,exact,,drop,,1,0,
```

| Colonne | Valeur par défaut si absente ou vide |
|---|---|
| `source` | obligatoire |
| `target` | aucune (pour une `410`) |
| `status` | `301` |
| `kind` | `exact` |
| `host` | chaque hôte |
| `query` | `drop` |
| `locale` | chaque locale |
| `enabled` | activée ; `0`, `false`, `no` ou `off` désactivent |
| `hits`, `last_hit_at` | écrites à l'export, ignorées à l'import |

Une marque d'ordre des octets est ignorée, une colonne inconnue aussi. Chaque ligne passe par les vérifications d'un
enregistrement : une source déjà présente est sautée, ou mise à jour avec `--update` ; les lignes précédentes comptent
pour les suivantes. Une ligne refusée est signalée avec son numéro de ligne et sa raison, et les autres sont importées.

## Commandes

| Commande | Effet |
|---|---|
| `phil:redirects:import <file> [--update] [--dry-run]` | Importe un fichier CSV ; échoue quand une ligne a été refusée. |
| `phil:redirects:export [file]` | Écrit chaque redirection en CSV, dans le fichier ou sur la sortie standard. |
| `phil:redirects:check` | Liste les redirections enregistrées que les vérifications refuseraient aujourd'hui ; échoue s'il y en a une. |
| `phil:redirects:purge-404 [--older-than=DAYS]` | Retire les chemins non demandés depuis `retention` jours, ou depuis les jours donnés. |

La console importe les expressions régulières quand elles sont activées ; l'import de l'administration ne le fait
jamais.
