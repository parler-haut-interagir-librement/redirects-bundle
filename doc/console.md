# Import, export and commands

## The CSV format

One redirect per line, after a header that names the columns, in any order:

```csv
source,target,status,kind,host,query,locale,enabled,hits,last_hit_at
/old/page,/new/page,301,exact,,drop,,1,12,2026-05-01T10:00:00+00:00
/docs,/manual,301,prefix,,keep,,1,0,
/blog/*/**,route:blog_post?slug=$1,308,wildcard,www.example.com,drop,fr,1,0,
/removed,,410,exact,,drop,,1,0,
```

| Column | Default when missing or blank |
|---|---|
| `source` | required |
| `target` | none (for a `410`) |
| `status` | `301` |
| `kind` | `exact` |
| `host` | every host |
| `query` | `drop` |
| `locale` | every locale |
| `enabled` | enabled; `0`, `false`, `no` or `off` disable |
| `hits`, `last_hit_at` | written on export, ignored on import |

A byte-order mark is ignored, an unknown column too. Every line goes through the checks of a save — a source already
there is skipped, or updated with `--update`; the earlier lines count for the later ones. A refused line is reported
with its line number and its reason, and the others are imported.

## Commands

| Command | Does |
|---|---|
| `phil:redirects:import <file> [--update] [--dry-run]` | Imports a CSV file; fails when a line was refused. |
| `phil:redirects:export [file]` | Writes every redirect as CSV, to the file or to the standard output. |
| `phil:redirects:check` | Lists the stored redirects the checks would refuse today; fails when there is one. |
| `phil:redirects:purge-404 [--older-than=DAYS]` | Removes the paths not requested for `retention` days, or the days given. |

The console imports regular expressions when they are enabled; the administration's import never does.
