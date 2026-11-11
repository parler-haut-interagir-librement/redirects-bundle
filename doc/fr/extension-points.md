<!-- phil-dev source-sha256=8e05cabcb78883d7f9f6c9015adf7c47afe184b9badb9e335307fdba03533f9a -->
# Points d'extension

| Point | Comment |
|---|---|
| Les redirections d'un autre paquet | Implémentez `Application\Contract\RedirectProvider`, enregistrez-le, et listez-le dans `providers` avec `phil_redirects.provider.stored` |
| Un changement dans les redirections d'un fournisseur | Appelez `RedirectTableCache::invalidate()` |
| Ce que le journal des 404 ignore | Implémentez `Application\Contract\NotFoundFilter` ; l'autoconfiguration lui donne l'étiquette `phil_redirects.not_found_filter` |
| L'endroit où les redirections sont gardées | Implémentez `Domain\Contract\RedirectRepository` et créez-en l'alias |
| L'endroit où le journal des 404 est gardé | Implémentez `Domain\Contract\NotFoundLogRepository` et créez-en l'alias |
| Créer des redirections depuis le code | `Application\Redirects` : `create()`, `update()`, `enable()`, `delete()`, `import()`, `check()` |
| Résoudre un chemin depuis le code | `Application\RedirectResolver::resolve()` |
| Les mots de l'administration | Les messages du domaine `phil_redirects` |
| Les pages de l'administration | `admin.layout`, et les gabarits de `@PhilRedirects/admin/` remplacés dans `templates/bundles/PhilRedirectsBundle/` |
| L'horloge | L'option `clock` : le bundle ne lit jamais l'heure lui-même |

## Un fournisseur

Un fournisseur donne des `RedirectRule`, lues à la construction de la table :

```php
use Phil\RedirectsBundle\Application\Contract\RedirectProvider;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;

final readonly class FormerSlugs implements RedirectProvider
{
    public function __construct(private SlugHistory $history)
    {
    }

    public function redirects(): iterable
    {
        foreach ($this->history->formerSlugs() as $former => $current) {
            yield new RedirectRule(SourcePattern::exact('/pages/'.$former), Target::route('page', ['slug' => $current]));
        }
    }
}
```

```yaml
phil_redirects:
    providers: [phil_redirects.provider.stored, App\Redirects\FormerSlugs]
```

Les invariants :

- les règles sont lues à la construction de la table, puis gardées avec elle : un fournisseur appelle
  `RedirectTableCache::invalidate()` quand ses redirections changent ;
- le premier fournisseur listé l'emporte pour une source donnée deux fois : en listant d'abord les redirections
  enregistrées, l'administration peut remplacer toute redirection fournie ;
- une règle fournie n'a pas d'identifiant : ses accès ne sont donc pas comptés ;
- les vérifications d'un enregistrement tiennent compte des règles fournies : une redirection enregistrée qui ferait
  une boucle ou une chaîne avec elles est refusée.

## Un filtre

```php
use Phil\RedirectsBundle\Application\Contract\NotFoundFilter;
use Phil\RedirectsBundle\Application\NotFoundHit;

final readonly class NoAssets implements NotFoundFilter
{
    public function ignores(NotFoundHit $hit): bool
    {
        return 1 === preg_match('/\.(?:map|php)$/', $hit->path);
    }
}
```

Un filtre voit l'hôte, le chemin, le référent, l'agent utilisateur et le verdict de robot ; le journal ne garde que
l'hôte, le chemin et l'hôte du référent.
