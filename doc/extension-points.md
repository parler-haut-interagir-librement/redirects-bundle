# Extension points

| Point | How |
|---|---|
| Redirects of another package | Implement `Application\Contract\RedirectProvider`, register it, and list it in `providers` with `phil_redirects.provider.stored` |
| A change in a provider's redirects | Call `RedirectTableCache::invalidate()` |
| What the 404 log ignores | Implement `Application\Contract\NotFoundFilter`; autoconfiguration tags it `phil_redirects.not_found_filter` |
| Where the redirects are kept | Implement `Domain\Contract\RedirectRepository` and alias it |
| Where the 404 log is kept | Implement `Domain\Contract\NotFoundLogRepository` and alias it |
| Creating redirects from code | `Application\Redirects`: `create()`, `update()`, `enable()`, `delete()`, `import()`, `check()` |
| Resolving a path from code | `Application\RedirectResolver::resolve()` |
| The words of the administration | The messages of the `phil_redirects` domain |
| The pages of the administration | `admin.layout`, and the templates under `@PhilRedirects/admin/` overridden in `templates/bundles/PhilRedirectsBundle/` |
| The clock | The `clock` option: the bundle never reads the time itself |

## A provider

A provider gives `RedirectRule`s, read when the table is built:

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

The invariants:

- the rules are read when the table is built, then kept with it: a provider calls `RedirectTableCache::invalidate()`
  when its redirects change;
- the first provider listed wins a source given twice, so the stored redirects listed first let the administration
  override any provided redirect;
- a provided rule has no identifier, so its hits are not counted;
- the checks of a save take the provided rules into account: a stored redirect that would make a loop or a chain with
  them is refused.

## A filter

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

A filter sees the host, the path, the referrer, the user agent and the robot verdict; the log keeps only the host, the
path and the host of the referrer.
