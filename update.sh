#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/installer/common.sh
. "$SCRIPT_DIR/scripts/installer/common.sh"

require_root
detect_os
ensure_directories

[[ -s "$MIRZA_SHARED_DIR/config.php" ]] || die "shared config.php is missing; use install.sh repair mode"
[[ -L "$MIRZA_CURRENT_LINK" || -d "$MIRZA_LEGACY_PATH" ]] || die "no existing MirzaBot installation was detected"
[[ -n "${MIRZA_RELEASE_SHA256:-}" ]] || die "MIRZA_RELEASE_SHA256 is required"
if [[ -z "${MIRZA_RELEASE_ARCHIVE:-}" ]]; then
    [[ -n "${MIRZA_RELEASE_REF:-}" ]] || die "MIRZA_RELEASE_REF is required"
fi

export MIRZA_DOMAIN
MIRZA_DOMAIN="$(config_value domainhosts)"
export MIRZA_BOT_TOKEN
MIRZA_BOT_TOKEN="$(config_value APIKEY)"
export MIRZA_WEBHOOK_SECRET
MIRZA_WEBHOOK_SECRET="$(config_value telegram_webhook_secret)"
[[ -n "$MIRZA_WEBHOOK_SECRET" ]] || die "existing config has no webhook secret; rerun install.sh with MIRZA_REPAIR_CONFIG=yes"

log INFO "starting staged MirzaBot update"
backup_database
prepare_release
run_migrations
activate_release
apache2ctl configtest
systemctl reload apache2
health_check
register_webhook
log INFO "MirzaBot update completed; previous release and validated database dump were retained"
