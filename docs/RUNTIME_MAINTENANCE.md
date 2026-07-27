# Runtime database and retention

The web application never creates tables, runs migrations, or inserts demo
catalog/IES data during a normal HTTP request. Deployment must provision the
database before traffic is switched to a new release:

```bash
php tools/migrate.php
```

Use `--no-seed` when the environment receives its catalog and IES library from
authoritative integrations. The command applies migrations, optionally ensures
the explicitly labelled demo catalog and demo IES profiles, and finishes with
a read-only schema readiness check.

If the database is missing, inaccessible, has unapplied migrations, or has a
migration checksum mismatch, database-backed APIs return HTTP 503 with a
generic public message. The detailed condition is written only to server logs.
`/api/health.php` reports HTTP 200 only when that database readiness check
passes and the protected `storage` directory is writable; otherwise it returns
HTTP 503 without exposing filesystem paths.

## Retention cleanup

`tools/cleanup.php` is CLI-only and defaults to a dry run:

```bash
php tools/cleanup.php
php tools/cleanup.php --json
```

Review the counts, take the normal server backup, and apply the same plan
explicitly:

```bash
php tools/cleanup.php --apply
```

Optional retention windows are available through `--cart-days`,
`--simulation-days`, `--guest-completed-days`, `--orphan-report-days`, and
`--orphan-upload-days`.

The cleanup scope is deliberately conservative:

- active carts are only marked expired after their own `expires_at`;
- only old expired/abandoned carts with no procurement request are deleted;
- only old draft/failed/archived simulations with no cart or request reference
  are deleted;
- completed simulations are deleted only when they are old, have neither a
  customer nor user owner, and have no cart or procurement-request reference;
- only old orphan reports/uploads and files belonging to attachment records
  already marked `deleted` are removed;
- submitted carts, procurement requests/items, owned or referenced completed
  simulations, and active attachment files are never deletion candidates.

Stale rate-limit files are also removed opportunistically by the bounded
limiter housekeeping path. General API limiter state defaults to the protected
`storage/api-rate-limits` directory, while lighting simulation limiter state
defaults to `storage/rate-limits`. Tests and multi-release deployments can
isolate those locations with `ARTDON_API_RATE_LIMIT_PATH` and
`ARTDON_RATE_LIMIT_PATH`; both directories must be writable by the PHP-FPM
user and must remain outside public downloads.

Attachment storage fails closed before files are moved when either the durable
2 GB application quota or the 1 GB filesystem reserve would be crossed.
Deployments may adjust those byte limits with `ARTDON_UPLOAD_QUOTA_BYTES` and
`ARTDON_UPLOAD_FREE_RESERVE_BYTES`, but only alongside a monitored
disk-capacity policy.

Run cleanup from cron only with an explicit `--apply`, redirect its output to
the operations log, and alert on a non-zero exit status.
