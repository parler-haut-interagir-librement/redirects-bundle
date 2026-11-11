<!-- phil-dev source-sha256=0fe258382a3944a2c78212e63fca7c116972b06dde66ba9ac2082a9f1c25b99a -->
# Domaines anciens

Un domaine que l'application a quitté est associé en entier, sans une redirection par page :

```yaml
phil_redirects:
    old_domains:
        old-example.com: { host: www.example.com }
        old-example.fr: { host: www.example.com, base_path: /fr, scheme: https }
        promo.example.com: { host: www.example.com, keep_path: false, status: 302 }
```

`OldDomainListener` écoute `kernel.request` avant l'écouteur du routeur : aucune route du site ne répond ainsi pour le
domaine ancien. Il n'est enregistré que si des domaines anciens sont configurés.

| Option | Par défaut | Effet |
|---|---|---|
| `host` | obligatoire | Le nouvel hôte. |
| `status` | `301` | `301`, `302`, `307` ou `308`. |
| `keep_path` | `true` | Le chemin de la requête est gardé ; sinon, la destination est la racine du nouvel hôte (ou son chemin de base). |
| `keep_query` | `true` | La chaîne de requête est gardée. |
| `scheme` | celui de la requête | `http` ou `https`. |
| `base_path` | aucun | Un chemin placé devant le chemin de la requête : `/fr`. Il commence par `/` et ne se termine pas par `/`. |

L'ancien hôte doit atteindre l'application — un enregistrement DNS et un hôte virtuel — et faire partie des hôtes de
confiance de l'application quand elle les restreint.
