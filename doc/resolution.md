# Resolution

## Only when nothing else answered

`NotFoundListener` listens to `kernel.exception`. When the main request ends in a `404` — a route that does not exist,
or a controller that threw `NotFoundHttpException` — the table is asked for the request's host, path, query string and
locale. A request that is found never reaches the table, so the routing is never slowed.

| The table gives | The response |
|---|---|
| A redirect | Its status, `Location` the target built from the captures |
| A `410 Gone` | `GoneHttpException` replaces the `404`, so the application's error page renders the `410` |
| A target that cannot be built | The `404` stays |
| Nothing | The `404` stays, and the path is kept for the log |

The writes — the hit of the redirect, the path not found — wait for `kernel.terminate`, after the response has gone.

## Precedence with other packages

The routes and controllers of the application, and of other packages, answer first. A content package that redirects
the old slugs of its pages with its own `301` does so in its controller, before any `404`: the table only sees the
requests it left. When a content controller throws a `404` for a slug it does not know, the table is asked next.

A package that wants its redirects in the table rather than in its controller writes a provider: see
[Extension points](extension-points.md).

## Order in the table

1. Exact sources, then prefixes — the longest first —, then wildcards and regular expressions, in the order the
   providers gave them.
2. Within each kind, the sources of the request's host before those of every host.
3. A redirect limited to a locale is skipped for a request in another one.
4. Two providers giving the same source: the first one listed wins.

An exact source is a key; a prefix and a wildcard are filed under their first segment, so that a path only meets the
sources that start like it. On a table of 10,000 redirects, a resolution takes a few microseconds; see
[Testing](testing.md).

## The cache

`RedirectTableCache` builds the table from the providers and keeps it:

- for the rest of the request, in memory, dropped on `kernel.reset`, so that a worker never serves a request with the
  table of another;
- between requests, in the PSR-6 pool of `cache.pool`, under `cache.key`, for `cache.lifetime` seconds or until a
  change.

Every save, removal and import through `Redirects` invalidates both copies. A provider whose redirects change calls
`RedirectTableCache::invalidate()`. Without a pool, the table is built on the first request not found of each process.

## Hits

Each stored redirect counts the requests it answered. The count is exact; the last hit is written at most once per
`hits.throttle` seconds, in the same statement. `hits.enabled: false` writes nothing.
