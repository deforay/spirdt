# Backup and upgrade

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

!!! note "A silent empty backup is fixed, and here is what to check if it returns"
    On 2026-08-07 `composer db:backup` printed `✓ Backup created` and wrote a
    13-byte archive containing nothing. It had two causes, both now closed.

    **The privilege.** MySQL 8's `mysqldump` reads `INFORMATION_SCHEMA.FILES`
    to record tablespaces, which needs the global `PROCESS` privilege. An
    application user granted `ALL ON spirdt.*` does not have it. Grant it
    wherever the app user was created:

    ```sql
    GRANT PROCESS ON *.* TO 'spirdt'@'127.0.0.1';
    FLUSH PRIVILEGES;
    ```

    **The option file.** A `~/.my.cnf` carrying `[client]` credentials beat the
    password db-tools supplies. MySQL ranks option files above environment
    variables, and `MYSQL_PWD` is where a password belongs because it stays out
    of `ps`. The connection went out with the configured user and somebody
    else's password. Fixed in db-tools 3.3.0, which passes `--no-defaults`
    whenever a password is configured.

    Neither failure was visible, because a shell pipeline reports only its last
    stage and `zstd` compresses an empty stream perfectly happily. db-tools
    3.2.3 fixed that half: a failing dump now stops the command and prints the
    reason. Both halves had to land before the upgrade guard below meant
    anything.

    Verifying the size costs nothing and catches the next failure of this
    shape:

    ```bash
    zstd -dc var/backups/db/<archive>.sql.zst | wc -c
    ```

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

That guard is only as good as what "fails" means, and db-tools twice wrote nothing while exiting `0` — see the note under [Backups](#backups). Both causes are fixed, and so is the reporting that hid them, so the guard now fires on a failed dump rather than on an errored command. Checking the archive size before relying on it still costs nothing.

**Migrations run unconditionally**, unlike in `bin/refresh`. A previously failed run can leave pending work that the current pull's diff says nothing about, so gating on the diff would skip it.

Rehearse first:

```bash
composer app:upgrade -- --dry-run
```

## Housekeeping

One sweep, one crontab line, scheduled by `bin/setup.sh` at 02:17:

```bash
composer housekeeping             # every target
composer housekeeping -- --dry-run
composer housekeeping -- --only=api-logs
```

Targets are `tokens`, `api-logs`, `backup`, `exports`, `cache` and `own-log`. Retention is one array at the top of `bin/housekeeping`, so the question "how long do we keep exports?" is answered by reading four lines rather than five functions.

A target that fails does not stop the others — a backup that failed is not a reason to leave expired tokens in the database for another day — and the script exits non-zero if any did, so cron's mail says something went wrong.

The backup target does not trust `db-tools`' exit code. It checks the archive it just wrote is bigger than a kilobyte, because a compressed dump of nothing is a few hundred bytes and exits `0` — see the note under [Backups](#backups).

The rule that matters most is which tables it must never touch:

!!! danger "Never pruned"
    `assessments`, `answers`, `findings`, `assessment_scores`, `submissions_raw`, `audit_log`.

    These are the audit trail. Integrity comes before disk space. Only `api_logs` and the auth token tables are prune candidates, and those are the only two the sweep touches.

    `api_logs` is a diagnostic and holds a copy of the request body, which on this API means assessment answers; `audit_log` is evidence. They look alike and they are not the same thing.

## Logs

Application logs rotate daily in `var/log/`. Every line carries a per-request UID from Monolog's `UidProcessor`, so all lines emitted while handling one request share an identifier and can be correlated with the `api_logs` row for that request.

`error_log()` is banned everywhere except `src/Helper/Log.php` — it bypasses the processor, so the line lands with no request UID. The pre-commit hook enforces this.
