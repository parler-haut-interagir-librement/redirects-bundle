# Safety

## Loops and chains

`ChainInspector` checks every enabled redirect when it is saved, against the redirects in force — the stored ones and
those of the providers:

- the redirect is followed from a path its source matches — its own path for an exact source or a prefix, each
  wildcard standing for `x` —, as a browser would follow it;
- a redirect that comes back to one already met is a **loop**, always refused: `/a → /a`, `/old → /old/new` for a
  prefix, `/a → /b → /a`;
- one that lands on another redirect is a **chain**: refused unless `chains.allowed`, and then refused beyond
  `chains.max_length` redirects;
- the redirects that already lead to the new source, and those that lead to them, are followed the same way.

A regular expression has no path to start from and is not followed; an absolute URL ends the walk. A disabled redirect
is not checked until it is enabled. `phil:redirects:check` lists the stored redirects that a provider's later change
turned into a loop or a chain.

## Open redirects

A path of the site and a route are always allowed. An absolute URL is allowed only when its host is in
`allowed_hosts`; the list is empty by default. A captured value can never change the host: placeholders are refused in
a scheme or a host, a captured value is put back percent-encoded, and the location is checked once more before it is
sent — a path stays a path of the site (never `//host` nor a backslash), an absolute URL keeps the host of its target.
A location that fails the check is not sent.

Every host is read by phil/url, as a browser reads it: the host of a target, the host of the location, the hosts of
`allowed_hosts`, the host of a referrer. A host is compared in lower case, an international one in its ASCII form —
`Bücher.example.` in `allowed_hosts` allows `https://xn--bcher-kva.example/` —, and an absolute location is sent in
its canonical form, which a header can carry. A target whose host is an IP address, written dotted or as a single
number (`https://2130706433/` is `127.0.0.1` to a browser), or a single name, is refused; so is port 0. An entry of
`allowed_hosts` that is not a host name alone — with a path, a port or a user name — allows nothing.

## Bounded inputs

| Input | Bound |
|---|---|
| A source path, a target, a CSV cell | 2,048 characters; 2,064 for a cell |
| A wildcard source | 32 segments, nine wildcards; matched without a regular expression |
| A regular expression | off by default; `regex.max_length` characters; written by `admin.regex_role` only; never imported from the administration |
| A request path | longer than 2,048 characters, it matches nothing |
| The 404 log | `capacity` entries, paths of `max_path_length` characters |
| A CSV file | `csv.max_lines` lines; `admin.import_max_bytes` bytes from the administration |
| A search of the administration | 200 characters |

## The administration

Every page checks `admin.role`; a user without it meets the firewall. Every form carries a CSRF token, and a change is
followed by a `303`. The outcome a page reports after a change is one of a fixed list, never what a crafted link puts
in the query string. The responses are `Cache-Control: no-store`.

## A spreadsheet's formulas

A CSV cell that a spreadsheet would read as a formula — starting with `=`, `+`, `-` or `@` — is written after a `'`,
which the import removes again.
