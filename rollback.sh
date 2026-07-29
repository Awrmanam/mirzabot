#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/installer/common.sh
. "$SCRIPT_DIR/scripts/installer/common.sh"

require_root
[[ -n "${MIRZA_ROLLBACK_RELEASE:-}" ]] || die "MIRZA_ROLLBACK_RELEASE is required"
[[ "$MIRZA_ROLLBACK_RELEASE" == "$MIRZA_RELEASES_DIR/"* ]] \
    || die "rollback target must be a release below ${MIRZA_RELEASES_DIR}"
[[ -d "$MIRZA_ROLLBACK_RELEASE" && -f "$MIRZA_ROLLBACK_RELEASE/index.php" ]] \
    || die "rollback release is invalid"

PREVIOUS_RELEASE="$MIRZA_ROLLBACK_RELEASE"
rollback_release

if [[ "${MIRZA_RESTORE_DATABASE:-no}" == "yes" ]]; then
    [[ -n "${MIRZA_DATABASE_BACKUP:-}" ]] || die "MIRZA_DATABASE_BACKUP is required"
    DATABASE_BACKUP="$MIRZA_DATABASE_BACKUP"
    restore_database_dump
fi

log INFO "rollback completed"
