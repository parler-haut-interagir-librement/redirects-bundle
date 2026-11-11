<!-- phil-dev source-sha256=43a261be04ad6ee3defd2c3d9d4780e860174510c8f342b3955c1ceff62078b8 -->
# Sécurité

## Boucles et chaînes

`ChainInspector` vérifie chaque redirection activée à son enregistrement, par rapport aux redirections en vigueur,
celles qui sont enregistrées et celles des fournisseurs :

- la redirection est suivie à partir d'un chemin que sa source accepte — son propre chemin pour une source exacte ou un
  préfixe, chaque joker valant `x` —, comme un navigateur la suivrait ;
- une redirection qui revient à une redirection déjà rencontrée est une **boucle**, toujours refusée : `/a → /a`,
  `/old → /old/new` pour un préfixe, `/a → /b → /a` ;
- une redirection qui aboutit à une autre redirection est une **chaîne** : refusée sauf avec `chains.allowed`, et alors
  refusée au-delà de `chains.max_length` redirections ;
- les redirections qui mènent déjà à la nouvelle source, et celles qui mènent à elles, sont suivies de la même façon.

Une expression régulière n'a pas de chemin de départ et n'est pas suivie ; une URL absolue termine le parcours. Une
redirection désactivée n'est pas vérifiée avant d'être activée. `phil:redirects:check` liste les redirections
enregistrées qu'un changement ultérieur d'un fournisseur a transformées en boucle ou en chaîne.

## Redirections ouvertes

Un chemin du site et une route sont toujours permis. Une URL absolue n'est permise que si son hôte figure dans
`allowed_hosts` ; la liste est vide par défaut. Une valeur capturée ne peut jamais changer l'hôte : les paramètres
substituables sont refusés dans un schéma ou un hôte, une valeur capturée est remise encodée en pourcentage, et la
destination est vérifiée une fois de plus avant son envoi. Un chemin reste un chemin du site (jamais `//host` ni une
barre oblique inverse), une URL absolue garde l'hôte de sa cible. Une destination qui échoue à la vérification n'est
pas envoyée.

Chaque hôte est lu par phil/url, comme un navigateur le lit : l'hôte d'une cible, celui de la destination, ceux de
`allowed_hosts`, celui d'un référent. Un hôte est comparé en minuscules, un hôte international sous sa forme ASCII
(`Bücher.example.` dans `allowed_hosts` permet `https://xn--bcher-kva.example/`), et une destination absolue est
envoyée sous sa forme canonique, qu'un en-tête peut porter. Une cible dont l'hôte est une adresse IP, écrite avec des
points ou en un seul nombre (`https://2130706433/` est `127.0.0.1` pour un navigateur), ou un seul nom, est refusée ;
tout comme le port 0. Une entrée de `allowed_hosts` qui n'est pas un nom d'hôte seul (avec un chemin, un port ou un
nom d'utilisateur) ne permet rien.

## Entrées bornées

| Entrée | Borne |
|---|---|
| Un chemin source, une cible, une cellule CSV | 2 048 caractères ; 2 064 pour une cellule |
| Une source à joker | 32 segments, neuf jokers ; comparée sans expression régulière |
| Une expression régulière | désactivée par défaut ; `regex.max_length` caractères ; écrite par `admin.regex_role` seulement ; jamais importée depuis l'administration |
| Un chemin de requête | au-delà de 2 048 caractères, il ne correspond à rien |
| Le journal des 404 | `capacity` entrées, des chemins de `max_path_length` caractères |
| Un fichier CSV | `csv.max_lines` lignes ; `admin.import_max_bytes` octets depuis l'administration |
| Une recherche de l'administration | 200 caractères |

## L'administration

Chaque page vérifie `admin.role` ; un utilisateur qui ne l'a pas se heurte au pare-feu. Chaque formulaire porte un
jeton CSRF, et un changement est suivi d'une `303`. Le résultat qu'une page annonce après un changement fait partie
d'une liste fixe, et n'est jamais ce qu'un lien forgé place dans la chaîne de requête. Les réponses portent
`Cache-Control: no-store`.

## Les formules d'un tableur

Une cellule CSV qu'un tableur lirait comme une formule — qui commence par `=`, `+`, `-` ou `@` — est écrite après une
`'`, que l'import retire de nouveau.
