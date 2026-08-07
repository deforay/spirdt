# Backup & Upgrade

## Backups

Backups use [`amitdugar/db-tools`](https://github.com/amitdugar/db-tools), the same tool as the other house projects, configured in `db-tools.php` at the repo root. That file maps `.env` variables onto db-tools profiles and loads `.env` itself, because db-tools gets invoked down several paths — shell, cron, spawned subprocess — and cannot rely on the environment already being loaded.

```bash
composer db:backup
```

Archives land in `var/backups/db/` as zstd-compressed `.sql.zst`, alongside a `.meta.json`. Retention is 14, set in `db-tools.php`.

db-tools does considerably more than dump:

```bash
vendor/bin/db-tools list       # restore, verify, pitr, export, import, size, clean
```

!!! danger "Check the size. A backup can be empty and still report success"
    On 2026-08-07 `composer db:backup` printed `✓ Backup created` and wrote a
    13-byte archive containing nothing at all. The dump had failed.

    The cause is a privilege, not a bug in the dump: MySQL 8's `mysqldump`
    reads `INFORMATION_SCHEMA.FILES` to record tablespaces, and that needs the
    **global `PROCESS`** privilege. An application user granted `ALL ON
    spirdt.*` does not have it. `mysqldump` then errors — and exits `0`, so
    nothing downstream can tell.

    Grant it wherever the app user was created:

    ```sql
    GRANT PROCESS ON *.* TO 'spirdt'@'127.0.0.1';
    FLUSH PRIVILEGES;
    ```

    Read the consequence in full, because it is the part that matters: the
    upgrade path below promises to abort if the backup fails. It cannot. A
    backup that fails *this* way succeeds, so the guard never fires and the
    migration proceeds with no restore point. **Verify the archive is a
    plausible size before trusting an upgrade to it**, until db-tools refuses
    to write an empty dump:

    ```bash
    zstd -dc var/backups/db/<archive>.sql.zst | wc -c
    ```

    This is the second failure of the same shape — see the encryption note
    below. The pattern is what to watch for, not the individual cause.

!!! warning "Backups are currently unencrypted"
    db-tools encrypts by default, but supplying an encryption password currently produces **no archive at all** while still exiting 0. `--no-encrypt` is therefore passed explicitly — a backup step that silently writes nothing is far worse than an unencrypted one.

    This should be revisited alongside a key-management story. Assessment data is health-system data, and encrypted backups are the right end state.

### The MySQL client

The php image ships **MySQL's own client**, copied from the `mysql:8.4` image, rather than the distribution package.

This is deliberate and was arrived at the hard way. On both Alpine and Debian, `mysql-client` is *MariaDB's* client, and it cannot talk to a default MySQL 8.4 server on two independent counts:

1. MySQL 8.4 enables TLS with a self-signed certificate by default. MariaDB's client verifies it and refuses to connect; MySQL's client does not.
2. MySQL 8's default authentication is `caching_sha2_password`. MariaDB's client has no such plugin and fails with *"could not be loaded"* before it can authenticate at all.

Working around either inside the backup tool would be papering over the wrong binary. Backups have to be trustworthy, so the correct client is shipped. This is also why the image is Debian-based rather than Alpine — those binaries are glibc-linked and will not run against musl.

`zstd`, `gzip` and `xz` are installed for the same reason: without them db-tools silently falls back to an uncompressed dump.

## Upgrading

```bash
composer app:upgrade
```

Five steps:

1. **Record current state** — code version, schema version, commit
2. **Back up the database** — and abort if it fails
3. **Sync code and dependencies** — delegated to `bin/refresh`, so the git logic lives in exactly one place
4. **Migrate** — unconditionally
5. **Verify** — via `bin/preflight`, then report versions before and after

Two design points worth understanding:

**The backup is not optional.** If it fails, the upgrade stops before anything else changes. An upgrade with no restore point is how a bad migration becomes an incident rather than an inconvenience. `--skip-backup` exists for when one was just taken by other means.

That guard is only as good as what "fails" means, and twice now db-tools has written nothing while exiting `0` — see the warnings under [Backups](#backups). The step stops an upgrade that *errors*, not one that produces an empty archive. Until the tool distinguishes the two, check the size of the archive before relying on it.

**Migrations run unconditionally**, unlike in `bin/refresh`. A previously failed run can leave pending work that the current pull's diff says nothing about, so gating on the diff would skip it.

Rehearse first:

```bash
composer app:upgrade -- --dry-run
```

## Housekeeping

Not yet implemented. When it lands it will follow the pattern from the other house projects: **one** idempotent `bin/housekeeping` sweep, retention policy in a single array, with `--dry-run` and `--only=<target>`.

The rule that matters most is which tables it must never touch:

!!! danger "Never pruned"
    `assessments`, `answers`, `findings`, `assessment_scores`, `submissions_raw`, `audit_log`.

    These are the audit trail. Integrity comes before disk space. Only `api_logs` and the auth token tables are prune candidates.

## Logs

Application logs rotate daily in `var/log/`. Every line carries a per-request UID from Monolog's `UidProcessor`, so all lines emitted while handling one request share an identifier and can be correlated with the `api_logs` row for that request.

`error_log()` is banned everywhere except `src/Helper/Log.php` — it bypasses the processor, so the line lands with no request UID. The pre-commit hook enforces this.
