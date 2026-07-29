#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/installer/common.sh
. "$SCRIPT_DIR/scripts/installer/common.sh"

require_root
detect_os
verify_php_runtime
ensure_directories

[[ -L "$MIRZA_CURRENT_LINK" || -d "$MIRZA_LEGACY_PATH" ]] || die "no existing MirzaBot installation was detected"
[[ -n "${MIRZA_RELEASE_SHA256:-}" ]] || die "MIRZA_RELEASE_SHA256 is required"
if [[ -z "${MIRZA_RELEASE_ARCHIVE:-}" ]]; then
    [[ -n "${MIRZA_RELEASE_REF:-}" ]] || die "MIRZA_RELEASE_REF is required"
fi

if [[ ! -s "$MIRZA_SHARED_DIR/config.php" && -s "$MIRZA_LEGACY_PATH/config.php" ]]; then
    install -m 0640 -o root -g www-data "$MIRZA_LEGACY_PATH/config.php" "$MIRZA_SHARED_DIR/config.php"
    log INFO "legacy Step 2 config copied to shared storage"
fi
[[ -s "$MIRZA_SHARED_DIR/config.php" ]] || die "no existing config.php was found"

migrate_legacy_persistent_data

export MIRZA_WEBHOOK_SECRET="${MIRZA_WEBHOOK_SECRET:-$(generate_secret 24)}"
write_config_atomic

export MIRZA_DOMAIN
MIRZA_DOMAIN="$(config_value domainhosts)"
export MIRZA_BOT_TOKEN
MIRZA_BOT_TOKEN="$(config_value APIKEY)"
export MIRZA_WEBHOOK_SECRET
MIRZA_WEBHOOK_SECRET="$(config_value telegram_webhook_secret)"
[[ -n "$MIRZA_WEBHOOK_SECRET" ]] || die "failed to add a webhook secret to the shared config"

log INFO "starting staged MirzaBot update"
backup_database
prepare_release
run_migrations
pre_activate_health_check
activate_release
apache2ctl configtest
systemctl reload apache2
health_check
register_webhook
log INFO "MirzaBot update completed; previous release and validated database dump were retained"
