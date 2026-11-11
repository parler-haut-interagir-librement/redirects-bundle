<!-- phil-dev source-sha256=bbcd4da39707cbc9a4f236727d6b8b0d756c889e3966a1c3f64ae08ac59e58c2 -->
# Référence

| Type | Rôle |
|---|---|
| `PhilRedirectsBundle` | Câble les services et configure `phil_redirects` |
| `Domain\Enum\MatchKind` | Exacte, préfixe, joker, expression régulière |
| `Domain\Enum\RedirectStatus` | 301, 302, 307, 308, 410 |
| `Domain\Enum\QueryPolicy` | Abandonner ou garder la chaîne de requête |
| `Domain\ValueObject\SourcePattern` | Où une redirection s'applique, et la correspondance |
| `Domain\ValueObject\Target` | Où elle envoie : un chemin, une URL, une route, nulle part |
| `Domain\ValueObject\RedirectRule` | Une redirection telle que la résout la table |
| `Domain\ValueObject\NotFoundEntry` | Un chemin du journal des 404 |
| `Domain\Model\Redirect` | Une redirection enregistrée, avec ses accès et ses dates |
| `Domain\Contract\RedirectRepository` | Le port des redirections enregistrées |
| `Domain\Contract\NotFoundLogRepository` | Le port du journal des 404 |
| `Domain\Exception\InvalidRedirect` | Une valeur refusée, avec sa raison |
| `Application\RedirectTable` | Les redirections en vigueur, indexées |
| `Application\RedirectTableCache` | La table gardée pour la requête et dans un pool |
| `Application\RedirectResolver` | D'un hôte et d'un chemin à une résolution |
| `Application\LocationBuilder` | D'une correspondance à une destination sûre |
| `Application\RedirectMatch`, `Application\Resolution` | Ce que la table a trouvé ; ce à quoi répond la requête |
| `Application\ChainInspector` | Refuse les boucles et les chaînes |
| `Application\TargetPolicy` | Refuse les cibles externes vers des hôtes non permis |
| `Application\Redirects` | Crée, change, retire, importe et vérifie |
| `Application\RedirectDraft` | Une redirection sous forme de texte, depuis un formulaire ou une ligne CSV |
| `Application\ImportReport`, `Application\Finding` | Ce qu'a fait un import ; ce qu'a trouvé une vérification |
| `Application\Exception\RedirectRefused`, `RedirectNotFound` | Une redirection refusée avec les autres ; une redirection absente |
| `Application\Contract\RedirectProvider` | Le port d'une source de redirections |
| `Application\StoredRedirects` | Les redirections enregistrées sous forme de fournisseur |
| `Application\Contract\NotFoundFilter` | Le port d'un filtre du journal des 404 |
| `Application\Filter\DeclaredCrawlerFilter` | Ignore les robots d'indexation et les scripts déclarés |
| `Application\NotFoundHit`, `Application\NotFoundRecorder` | Une requête introuvable ; sa journalisation |
| `Application\OldDomain`, `Application\OldDomains` | Les domaines anciens et leur nouvel hôte |
| `Application\Csv\RedirectCsv` | La forme CSV des redirections |
| `Infrastructure\Doctrine\RedirectTables` | Les deux tables |
| `Infrastructure\Doctrine\RedirectSchemaListener` | Les ajoute au schéma de l'ORM |
| `Infrastructure\Doctrine\DbalRedirectRepository`, `DbalNotFoundLog` | Les deux magasins avec DBAL |
| `Infrastructure\Doctrine\RowReader` | Les valeurs d'une ligne, lues strictement |
| `Infrastructure\Http\NotFoundListener` | Résout une `404`, écrit après la réponse |
| `Infrastructure\Http\OldDomainListener` | Associe un domaine ancien avant le routage |
| `Infrastructure\Http\AdminGuard` | Le rôle, le jeton, la mise en page de l'administration |
| `Infrastructure\Http\RedirectAdminController` | Les redirections dans l'administration |
| `Infrastructure\Http\NotFoundAdminController` | Le journal des 404 dans l'administration |
| `Infrastructure\Http\TransferAdminController` | Le téléchargement et le téléversement CSV |
| `Infrastructure\Console\ImportRedirectsCommand` | `phil:redirects:import` |
| `Infrastructure\Console\ExportRedirectsCommand` | `phil:redirects:export` |
| `Infrastructure\Console\CheckRedirectsCommand` | `phil:redirects:check` |
| `Infrastructure\Console\PurgeNotFoundCommand` | `phil:redirects:purge-404` |
| `Infrastructure\EasyAdmin\RedirectsAdmin` | Les liens d'un menu EasyAdmin |
| `Infrastructure\BotDetection\RobotVerdictFilter` | Ignore une requête qui porte le verdict de robot |
