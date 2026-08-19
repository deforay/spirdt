#!/usr/bin/env bash
#
# upgrade.sh — bring an installed server up to the current release.
#
#   sudo bash /var/www/spirdt/bin/upgrade.sh
#
# HOW THIS DIFFERS FROM bin/upgrade — the same split as setup:
#
#   bin/upgrade (PHP) is the application upgrade: back up, pull, composer,
#   migrate, verify. It is the part that is identical everywhere and it is
#   called from here rather than repeated.
#
#   bin/upgrade.sh (this) is the machine around it: the packages, the PHP
#   version, the permissions Apache needs, the services to reload, the cron
#   entry and the certificate. None of that can be done by the application,
#   and all of it drifts on a server that has been running for a year.
#
# THE BACKUP IS NOT OPTIONAL and this script does not take one — bin/upgrade
# does, before anything changes, and stops if it fails. An upgrade with no
# restore point is how a bad migration becomes an incident. --skip-backup is
# passed through for the case where one was just taken by other means.
#
# Usage:
#   sudo bash bin/upgrade.sh                    everything
#   sudo bash bin/upgrade.sh --path=/var/www/spirdt
#   sudo bash bin/upgrade.sh --skip-apt         do not touch system packages
#   sudo bash bin/upgrade.sh --skip-backup      passed to bin/upgrade
#   sudo bash bin/upgrade.sh --dry-run          say what would happen
#   sudo bash bin/upgrade.sh --help
#
# Exit codes: 0 success, anything else failure, with the failing line named.

set -Eeuo pipefail

PHP_VERSION="8.4"

INSTALL_PATH=""
SKIP_APT=0
SKIP_BACKUP=0
DRY_RUN=0
ASSUME_YES=0

for argument in "$@"; do
    case "$argument" in
        --path=*)      INSTALL_PATH="${argument#*=}" ;;
        --skip-apt)    SKIP_APT=1 ;;
        --skip-backup) SKIP_BACKUP=1 ;;
        --dry-run)     DRY_RUN=1 ;;
        --yes | -y)    ASSUME_YES=1 ;;
        --help | -h)
            sed -n '2,/^set -Eeuo/p' "$0" | sed -e 's/^# \{0,1\}//' -e '$d'
            exit 0
            ;;
        *)
            printf 'Unknown option: %s (try --help)\n' "$argument" >&2
            exit 2
            ;;
    esac
done

export ASSUME_YES

SCRIPT_DIRECTORY="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIRECTORY}/shared-functions.sh"

LOG_FILE="/tmp/spirdt-upgrade-$(date +'%Y%m%d-%H%M%S').log"
export LOG_FILE
trap 'on_error "$LINENO" "$BASH_COMMAND" "$?"' ERR

say step "SPI-RDT upgrade"
say info "Log: ${LOG_FILE}"

require_root

# ── Which installation ───────────────────────────────────────────────────
#
# Defaults to the checkout this script is part of, which is the common case:
# an administrator runs the copy that is already on the server.

[ -n "$INSTALL_PATH" ] || INSTALL_PATH="$(dirname "$SCRIPT_DIRECTORY")"
INSTALL_PATH="$(absolute_path "$INSTALL_PATH")"

[ -f "${INSTALL_PATH}/bin/upgrade" ] || die "${INSTALL_PATH} is not a spirdt checkout."
[ -f "${INSTALL_PATH}/.env" ] || die "${INSTALL_PATH}/.env is missing — is this installation set up?"

cd "$INSTALL_PATH"

BEFORE_VERSION="$(cat VERSION 2>/dev/null || echo 'unknown')"
BEFORE_COMMIT="$(as_owner "$INSTALL_PATH" git rev-parse --short HEAD 2>/dev/null || echo 'unknown')"

say info "Installation: ${INSTALL_PATH}"
say info "Currently:    ${BEFORE_VERSION} (${BEFORE_COMMIT})"

if [ "$DRY_RUN" -eq 1 ]; then
    say step "Dry run — nothing will change"
    say info "Would: apt upgrade, ensure PHP ${PHP_VERSION}, run bin/upgrade, reset permissions,"
    say info "       reload Apache, re-check the cron entry and the certificate."
    as_owner "$INSTALL_PATH" php bin/upgrade --dry-run
    exit 0
fi

# ── The machine ──────────────────────────────────────────────────────────
#
# Before the application, not after: a migration that runs against an old PHP
# and then finds the extension it needed was added in this step has already
# half-migrated.

if [ "$SKIP_APT" -eq 0 ]; then
    say step "System packages"
    silence_needrestart
    apt_refresh
    DEBIAN_FRONTEND=noninteractive apt-get upgrade -y -qq >/dev/null
    say success "System packages up to date"
fi

say step "Runtime"
ensure_php "$PHP_VERSION"
ensure_composer
ensure_apache "$PHP_VERSION"
ensure_mysql

# ── The application ──────────────────────────────────────────────────────
#
# One call. bin/upgrade takes the restore point, delegates the pull to
# bin/refresh so the git logic lives in one place, migrates unconditionally —
# never gated on the diff, because a previous run may have failed partway and
# left work pending that this pull does not mention — and verifies.

say step "Application"

UPGRADE_ARGUMENTS=()
[ "$SKIP_BACKUP" -eq 1 ] && UPGRADE_ARGUMENTS+=(--skip-backup)

as_owner "$INSTALL_PATH" php bin/upgrade "${UPGRADE_ARGUMENTS[@]}"

# ── The machine again ────────────────────────────────────────────────────
#
# A pull brings files in as the owner, and anything new under var/ is then
# unwritable by Apache until this runs. Cheap, and skipping it produces an
# error at the first upload rather than here.

say step "Permissions and services"
set_ownership "$INSTALL_PATH"

apache2ctl configtest >/dev/null 2>&1 || die "Apache configuration is invalid — not reloading."
systemctl reload apache2
say success "Apache reloaded"

# The cron entry names an absolute path, so it survives a move only if this
# runs. Re-asserting it costs nothing and catches the installation that was
# copied to a new host.
install_cron "$INSTALL_PATH"

if command -v certbot >/dev/null 2>&1; then
    if certbot renew --dry-run >/dev/null 2>&1; then
        say success "Certificate renewal is working"
    else
        say warn "certbot renew --dry-run failed — the certificate may not renew."
    fi
fi

# ── Report ───────────────────────────────────────────────────────────────

AFTER_VERSION="$(cat VERSION 2>/dev/null || echo 'unknown')"
AFTER_COMMIT="$(as_owner "$INSTALL_PATH" git rev-parse --short HEAD 2>/dev/null || echo 'unknown')"

say step "Done"
if [ "$BEFORE_COMMIT" = "$AFTER_COMMIT" ]; then
    say success "Already current: ${AFTER_VERSION} (${AFTER_COMMIT})"
else
    say success "Upgraded ${BEFORE_VERSION} (${BEFORE_COMMIT}) → ${AFTER_VERSION} (${AFTER_COMMIT})"
fi
say info "Backups: ${INSTALL_PATH}/var/backups/db/"
say info "Log:     ${LOG_FILE}"
