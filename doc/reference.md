# Reference

| Type | Role |
|---|---|
| `PhilRedirectsBundle` | Wires the services and configures `phil_redirects` |
| `Domain\Enum\MatchKind` | Exact, prefix, wildcard, regular expression |
| `Domain\Enum\RedirectStatus` | 301, 302, 307, 308, 410 |
| `Domain\Enum\QueryPolicy` | Drop or keep the request's query string |
| `Domain\ValueObject\SourcePattern` | Where a redirect applies, and the match |
| `Domain\ValueObject\Target` | Where it sends: a path, a URL, a route, none |
| `Domain\ValueObject\RedirectRule` | One redirect as the table resolves it |
| `Domain\ValueObject\NotFoundEntry` | A path of the 404 log |
| `Domain\Model\Redirect` | A stored redirect, with its hits and dates |
| `Domain\Contract\RedirectRepository` | The port of the stored redirects |
| `Domain\Contract\NotFoundLogRepository` | The port of the 404 log |
| `Domain\Exception\InvalidRedirect` | A value refused, with its reason |
| `Application\RedirectTable` | The redirects in force, indexed |
| `Application\RedirectTableCache` | The table kept for the request and in a pool |
| `Application\RedirectResolver` | A host and a path to a resolution |
| `Application\LocationBuilder` | A match to a safe location |
| `Application\RedirectMatch`, `Application\Resolution` | What the table found; what the request answers |
| `Application\ChainInspector` | Refuses loops and chains |
| `Application\TargetPolicy` | Refuses external targets to hosts not allowed |
| `Application\Redirects` | Creates, changes, removes, imports and checks |
| `Application\RedirectDraft` | A redirect as text, from a form or a line of CSV |
| `Application\ImportReport`, `Application\Finding` | What an import did; what a check found |
| `Application\Exception\RedirectRefused`, `RedirectNotFound` | A redirect refused with the others; a missing one |
| `Application\Contract\RedirectProvider` | The port of a source of redirects |
| `Application\StoredRedirects` | The stored redirects as a provider |
| `Application\Contract\NotFoundFilter` | The port of a filter of the 404 log |
| `Application\Filter\DeclaredCrawlerFilter` | Ignores the declared crawlers and scripts |
| `Application\NotFoundHit`, `Application\NotFoundRecorder` | A request not found; its log |
| `Application\OldDomain`, `Application\OldDomains` | The old domains and their new host |
| `Application\Csv\RedirectCsv` | The CSV form of the redirects |
| `Infrastructure\Doctrine\RedirectTables` | The two tables |
| `Infrastructure\Doctrine\RedirectSchemaListener` | Adds them to the schema of the ORM |
| `Infrastructure\Doctrine\DbalRedirectRepository`, `DbalNotFoundLog` | The two stores with DBAL |
| `Infrastructure\Doctrine\RowReader` | The values of a row, read strictly |
| `Infrastructure\Http\NotFoundListener` | Resolves a `404`, writes after the response |
| `Infrastructure\Http\OldDomainListener` | Maps an old domain before the routing |
| `Infrastructure\Http\AdminGuard` | The role, the token, the layout of the administration |
| `Infrastructure\Http\RedirectAdminController` | The redirects in the administration |
| `Infrastructure\Http\NotFoundAdminController` | The 404 log in the administration |
| `Infrastructure\Http\TransferAdminController` | The CSV download and upload |
| `Infrastructure\Console\ImportRedirectsCommand` | `phil:redirects:import` |
| `Infrastructure\Console\ExportRedirectsCommand` | `phil:redirects:export` |
| `Infrastructure\Console\CheckRedirectsCommand` | `phil:redirects:check` |
| `Infrastructure\Console\PurgeNotFoundCommand` | `phil:redirects:purge-404` |
| `Infrastructure\EasyAdmin\RedirectsAdmin` | The links of an EasyAdmin menu |
| `Infrastructure\BotDetection\RobotVerdictFilter` | Ignores a request with the robot verdict |
