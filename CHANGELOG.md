# Changelog

All notable changes to `phil/redirects-bundle` will be documented in this file.

Updates should follow the [Keep a CHANGELOG](https://keepachangelog.com/) principles.

## 0.1.1-alpha - 2026-11-11

### Security

- The host of a target is read by `phil/url`, as a browser reads it: a target whose host is an IP address, written
  dotted (`https://127.0.0.1/`) or as a single number (`https://2130706433/`, the loopback address to a browser), or
  a single name (`https://intranet/`), or with a port out of range, is refused as a `host`; port 0 as a `target_url`.
- The location an absolute target gives is checked by `phil/url`, no longer by `parse_url()`, and sent in its
  canonical form: an international host in its ASCII form, the escaped dot segments resolved.

### Added

- The option `allow_localhost`, `false` by default: `true`, in development and tests, lets a target, an entry of
  `allowed_hosts` and a referrer be on `localhost` or under `.localhost`; an IP address stays refused. `Target::url()`,
  `Target::fromString()` and `RedirectDraft::rule()` take it as `allowLocalhost`, `LocationBuilder`, `TargetPolicy`,
  `NotFoundRecorder`, `Redirects` (with `allowLocalhost()`) and `DbalRedirectRepository` as their last argument.

### Changed

- An absolute target may have an international host, `https://bücher.example/`; `allowed_hosts` reads its entries
  by `phil/url`, so that `Bücher.example.` allows it, and an entry that is no host name alone — with a path, a port
  or a user name — allows nothing.
- The 404 log reads the host of a referrer by `phil/url`, whatever its scheme: `https://evil.example\@partner.example/`
  is logged under evil.example, where `parse_url()` read partner.example; a referrer with a user name or an IP
  address is logged without a host, an international one under its ASCII form.
- The package requires `phil/url` 0.1.3.

## 0.1.0-alpha - 2026-11-11

### Added

- `SourcePattern`: an exact path, a prefix matched segment by segment, a wildcard source (`*` for one segment, a last
  `**` for the rest, nine captures at most, 32 segments at most) and a regular expression of bounded length, for one
  host or every host, kept percent-encoded; `MatchKind`.
- `Target`: a path of the site, an absolute `http` or `https` URL, a route with its parameters (`route:name?key=value`)
  or none, `$1` to `$9` never in a scheme or a host; `RedirectStatus` (301, 302, 307, 308, 410) and `QueryPolicy`
  (drop, keep).
- `RedirectRule` and `InvalidRedirect`, whose reason is a translation key; `Redirect`, the stored redirect with its
  hits, last hit and dates; `NotFoundEntry`.
- The `RedirectRepository` and `NotFoundLogRepository` ports and their DBAL adapters on `phil_redirect` and
  `phil_redirect_not_found`, added to the schema the ORM generates by `RedirectSchemaListener`; `RowReader`.
- `RedirectTable`, indexed by host, kind and first segment; `RedirectTableCache`, kept for the request and in a PSR-6
  pool; `RedirectResolver`, `LocationBuilder` and `Resolution`.
- The `RedirectProvider` port, the providers listed in order, and `StoredRedirects`.
- `Redirects`: create, update, enable, delete, import and check, through `TargetPolicy` (external targets to allowed
  hosts only) and `ChainInspector` (loops always refused, chains refused or bounded); `RedirectRefused`,
  `RedirectNotFound`, `RedirectDraft`, `ImportReport` and `Finding`.
- `NotFoundListener` on `kernel.exception`, with the writes after the response and its state dropped on `kernel.reset`;
  `OldDomainListener`, `OldDomain` and `OldDomains`.
- The 404 log: `NotFoundRecorder`, `NotFoundHit`, the `NotFoundFilter` port, `DeclaredCrawlerFilter` and, with
  `phil/bot-detection-bundle`, `RobotVerdictFilter`.
- `RedirectCsv`, and the commands `phil:redirects:import`, `phil:redirects:export`, `phil:redirects:check` and
  `phil:redirects:purge-404`.
- The administration: `AdminGuard`, `RedirectAdminController`, `NotFoundAdminController`, `TransferAdminController`,
  their routes and Twig templates, with the messages of the `phil_redirects` domain in English and French;
  `RedirectsAdmin`, the links of an EasyAdmin menu.
- The configuration `phil_redirects`: `connection`, `providers`, `allowed_hosts`, `regex`, `chains`, `cache`, `hits`,
  `not_found_log`, `old_domains`, `csv`, `admin` and `clock`.
- The fuzz target of the pattern matcher and the benchmark of the resolver on 10,000 redirects.
- The documentation in `doc/`, one file per topic, indexed by the README.
- `CONTRIBUTING.md`, `SECURITY.md` and `CODE_OF_CONDUCT.md`.
