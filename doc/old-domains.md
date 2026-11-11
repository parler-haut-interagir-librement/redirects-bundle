# Old domains

A domain the application left is mapped as a whole, without a redirect per page:

```yaml
phil_redirects:
    old_domains:
        old-example.com: { host: www.example.com }
        old-example.fr: { host: www.example.com, base_path: /fr, scheme: https }
        promo.example.com: { host: www.example.com, keep_path: false, status: 302 }
```

`OldDomainListener` listens to `kernel.request` before the router's listener, so that no route of the site answers
for the old domain. It is registered only when old domains are configured.

| Option | Default | Effect |
|---|---|---|
| `host` | required | The new host. |
| `status` | `301` | `301`, `302`, `307` or `308`. |
| `keep_path` | `true` | The request's path is kept; otherwise the location is the new host's root (or base path). |
| `keep_query` | `true` | The request's query string is kept. |
| `scheme` | the request's | `http` or `https`. |
| `base_path` | none | A path put in front of the request's path: `/fr`. It starts with `/` and does not end with one. |

The old host must reach the application — a DNS record and a virtual host — and be one of the application's trusted
hosts when it restricts them.
