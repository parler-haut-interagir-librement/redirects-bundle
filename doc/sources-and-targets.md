# Sources and targets

A redirect is a `RedirectRule`: a source, a target, a status, a query policy and, optionally, a locale.

## Sources

`SourcePattern` holds a kind, a path and a host — or none, for every host. A path is kept as a request carries it:
percent-encoded. A character outside printable ASCII is encoded as it is written (`/café` is `/caf%C3%A9`), a space
is `%20`, and the hexadecimal digits of an escape are capitals. A trailing slash is ignored on both sides: `/old` and
`/old/` are one source and match both requests.

| Kind | Writes | Matches | Gives the target |
|---|---|---|---|
| `exact` | `/old/page` | that path only | nothing |
| `prefix` | `/docs` | `/docs` and every path under it, segment by segment: not `/docsx` | the rest of the path, appended to the target |
| `wildcard` | `/blog/*/**` | `*` for exactly one non-empty segment, a last `**` for any number of them, the others literally | each wildcard as `$1`, `$2`… |
| `regex` | `/item-(\d+)` | the whole path, anchored | each group as `$1`, `$2`… |

A source path starts with one `/`, has no control character, no `?`, no `#`, no `\` and no empty segment, and is at
most 2,048 characters long. A host is a host name, without a port, compared in lower case.

### The bounded wildcard syntax

A wildcard source is a list of segments, at most 32, with at most nine wildcards. `*` stands for a whole segment, never
for part of one: `/a*` is refused, and `/a*` written as an exact source is the literal path. `**` is the last segment
only. Matching walks the segments once, without any regular expression: a path costs no more than the source has
segments, however long it is.

### Regular expressions

A regular expression is off unless `regex.enabled` is set, and then written only by a user with `admin.regex_role`, or
from the console. It is between 1 and `regex.max_length` characters, without a space, a control character or `#`, and
must compile. It is anchored at both ends. At run time it meets the limits of PCRE: a pattern that fails there matches
nothing, and the request stays not found.

## Targets

`Target` is one of:

| Target | Writes | Notes |
|---|---|---|
| A path of the site | `/new/page?x=1` | Starts with one `/`: `//host` is refused. |
| An absolute URL | `https://shop.example.com/` | Allowed only when its host is in `allowed_hosts`. |
| A route | `route:blog_post?slug=$1` | Generated with its parameters; a route that cannot be generated leaves the request not found. |
| None | (empty) | For a `410 Gone` only. |

`$1` to `$9` in a path, a query or a route parameter stand for the values the source captured; never in a scheme or a
host. A target refers to no more values than its source captures: an exact source or a prefix captures none. A
captured value is put back percent-encoded, so that it cannot add a scheme, a host or a line to the location.

## Status, query and locale

| Field | Values | Default |
|---|---|---|
| Status | `301`, `302`, `307`, `308`, or `410 Gone` without a target | `301` |
| Query policy | `drop` the request's query string, or `keep` it after the target's own parameters | `drop` |
| Locale | a locale such as `fr` or `fr_CA`: the redirect applies only to a request in that locale (`Request::getLocale()`) | none |
