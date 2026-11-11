# The 404 log

A request not found that no redirect answered is logged in `phil_redirect_not_found`, one row per host and path:

| Kept | Not kept |
|---|---|
| The host and the path, percent-encoded | The address of the visitor |
| How many times, first and last seen | The user agent |
| The host of the last page that linked to it, read by phil/url, whatever its scheme — none for an IP address or a user name | The full referrer, its path and query |

The row is updated in a single statement; the first request creates it. Beyond `capacity` entries, a new path is not
logged, while the paths already there keep counting.

## What is never logged

- A request other than `GET` or `HEAD`.
- A path longer than `max_path_length`, or one under a prefix of `ignored_paths` (by default the profiler and
  `/.well-known/`).
- A request a filter ignores.

## Filters

Every `NotFoundFilter` is asked; one that ignores the request is enough. The bundle's own:

- `DeclaredCrawlerFilter`, with `ignore_crawlers`: a user agent that declares itself a crawler, a script, a monitor or
  a preview — `bot`, `crawl`, `spider`, `curl/`, `python`, `headless`… — or no user agent at all. `crawler_tokens` adds
  words. It reads only what the client declares.
- `RobotVerdictFilter`, with `phil/bot-detection-bundle` installed and `robot_verdict_header` set: a request whose
  header carries the robot verdict, read strictly. A browser's navigation does not send it by itself: the application
  sends it from its own scripts when it wants those requests filtered too. The verdict decides this request only and is
  not kept.

An application's filter implements `Application\Contract\NotFoundFilter`; it is tagged by autoconfiguration.

## From a path to a redirect

The administration lists the paths, the most requested first, each with a button that opens the form of a new redirect
filled in from it. Once the redirect is saved, the path leaves the log.

## Purge

```sh
bin/console phil:redirects:purge-404              # not requested for `retention` days
bin/console phil:redirects:purge-404 --older-than=30
```

For the scheduler to run every night.
