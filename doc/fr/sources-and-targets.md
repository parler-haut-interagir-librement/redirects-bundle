<!-- phil-dev source-sha256=9fdd86e00809a597d1043aacef2071d2294148687026374cb73f6b899dab3062 -->
# Sources et cibles

Une redirection est un `RedirectRule` : une source, une cible, un statut, une politique de chaîne de requête et,
facultativement, une locale.

## Sources

`SourcePattern` contient un genre, un chemin et un hôte, ou aucun hôte pour tous les hôtes. Un chemin est gardé tel
que le porte une requête : encodé en pourcentage. Un caractère hors de l'ASCII imprimable est encodé tel qu'il est
écrit (`/café` devient `/caf%C3%A9`), une espace devient `%20`, et les chiffres hexadécimaux d'un échappement sont en
majuscules. Une barre oblique finale est ignorée des deux côtés : `/old` et `/old/` sont une même source et
correspondent aux deux requêtes.

| Genre | S'écrit | Correspond à | Donne à la cible |
|---|---|---|---|
| `exact` | `/old/page` | ce chemin seulement | rien |
| `prefix` | `/docs` | `/docs` et chaque chemin en dessous, segment par segment : pas `/docsx` | le reste du chemin, ajouté à la cible |
| `wildcard` | `/blog/*/**` | `*` pour exactement un segment non vide, un `**` final pour un nombre quelconque de segments, les autres littéralement | chaque joker sous la forme `$1`, `$2`… |
| `regex` | `/item-(\d+)` | le chemin entier, ancré | chaque groupe sous la forme `$1`, `$2`… |

Un chemin source commence par un seul `/`, n'a ni caractère de contrôle, ni `?`, ni `#`, ni `\`, ni segment vide, et
compte au plus 2 048 caractères. Un hôte est un nom d'hôte, sans port, comparé en minuscules.

### La syntaxe bornée des jokers

Une source à joker est une liste de segments, au plus 32, avec au plus neuf jokers. `*` vaut un segment entier, jamais
une partie de segment : `/a*` est refusé, et `/a*` écrit comme source exacte est le chemin littéral. `**` ne peut être
que le dernier segment. La comparaison parcourt les segments une seule fois, sans aucune expression régulière : un
chemin ne coûte pas plus que le nombre de segments de la source, quelle que soit sa longueur.

### Expressions régulières

Une expression régulière est désactivée sauf si `regex.enabled` est défini, et elle n'est alors écrite que par un
utilisateur qui a `admin.regex_role`, ou depuis la console. Elle compte de 1 à `regex.max_length` caractères, sans
espace, caractère de contrôle ni `#`, et doit compiler. Elle est ancrée aux deux bouts. À l'exécution, elle est soumise
aux limites de PCRE : un motif qui y échoue ne correspond à rien, et la requête reste introuvable.

## Cibles

`Target` est l'un de ces genres :

| Cible | S'écrit | Remarques |
|---|---|---|
| Un chemin du site | `/new/page?x=1` | Commence par un seul `/` : `//host` est refusé. |
| Une URL absolue | `https://shop.example.com/` | Permise seulement si son hôte figure dans `allowed_hosts`. |
| Une route | `route:blog_post?slug=$1` | Générée avec ses paramètres ; une route impossible à générer laisse la requête introuvable. |
| Aucune | (vide) | Pour une `410 Gone` seulement. |

De `$1` à `$9`, dans un chemin, une requête ou un paramètre de route, valent les valeurs que la source a capturées ;
jamais dans un schéma ou un hôte. Une cible ne fait pas référence à plus de valeurs que n'en capture sa source : une
source exacte ou un préfixe n'en capture aucune. Une valeur capturée est remise encodée en pourcentage : elle ne peut
ainsi ajouter ni schéma, ni hôte, ni ligne à la destination.

## Statut, chaîne de requête et locale

| Champ | Valeurs | Par défaut |
|---|---|---|
| Statut | `301`, `302`, `307`, `308`, ou `410 Gone` sans cible | `301` |
| Politique de chaîne de requête | `drop` abandonne la chaîne de requête, `keep` la garde après les propres paramètres de la cible | `drop` |
| Locale | une locale comme `fr` ou `fr_CA` : la redirection ne s'applique qu'à une requête dans cette locale (`Request::getLocale()`) | aucune |
