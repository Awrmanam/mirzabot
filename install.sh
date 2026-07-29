#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/installer/common.sh
. "$SCRIPT_DIR/scripts/installer/common.sh"

require_value() {
    local variable="$1"
    [[ -n "${!variable:-}" ]] || die "${variable} is required"
}

require_root
detect_os

require_value MIRZA_RELEASE_SHA256
if [[ -z "${MIRZA_RELEASE_ARCHIVE:-}" ]]; then
    require_value MIRZA_RELEASE_REF
fi
require_value MIRZA_DOMAIN
require_value MIRZA_CERTBOT_EMAIL
require_value MIRZA_BOT_TOKEN
require_value MIRZA_ADMIN_ID
require_value MIRZA_BOT_USERNAME

validate_domain "$MIRZA_DOMAIN" || die "MIRZA_DOMAIN is invalid"
[[ "$MIRZA_CERTBOT_EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] \
    || die "MIRZA_CERTBOT_EMAIL is invalid"
[[ "$MIRZA_ADMIN_ID" =~ ^[0-9]{4,20}$ ]] || die "MIRZA_ADMIN_ID is invalid"
[[ "$MIRZA_BOT_USERNAME" =~ ^[A-Za-z0-9_]{5,32}$ ]] || die "MIRZA_BOT_USERNAME is invalid"
[[ "$MIRZA_BOT_TOKEN" =~ ^[0-9]{6,15}:[A-Za-z0-9_-]{20,}$ ]] || die "MIRZA_BOT_TOKEN format is invalid"

export MIRZA_DB_NAME="${MIRZA_DB_NAME:-mirzaprobot}"
export MIRZA_DB_USER="${MIRZA_DB_USER:-mirzabot}"
export MIRZA_DB_PASSWORD="${MIRZA_DB_PASSWORD:-$(generate_secret 24)}"
export MIRZA_WEBHOOK_SECRET="${MIRZA_WEBHOOK_SECRET:-$(generate_secret 24)}"

install -d -m 0750 "$(dirname "$MIRZA_LOG_FILE")"
touch "$MIRZA_LOG_FILE"
chmod 0640 "$MIRZA_LOG_FILE"

log INFO "starting MirzaBot install/reinstall state machine"
install_packages
ensure_directories

if [[ ! -s "$MIRZA_SHARED_DIR/config.php" && -s "$MIRZA_LEGACY_PATH/config.php" ]]; then
    install -m 0640 -o root -g www-data "$MIRZA_LEGACY_PATH/config.php" "$MIRZA_SHARED_DIR/config.php"
    log INFO "legacy config copied to shared storage before release migration"
fi

migrate_legacy_persistent_data

if [[ ! -s "$MIRZA_SHARED_DIR/config.php" || "${MIRZA_REPAIR_CONFIG:-no}" == "yes" ]]; then
    ensure_database "$MIRZA_DB_NAME" "$MIRZA_DB_USER" "$MIRZA_DB_PASSWORD"
fi
write_config_atomic
prepare_release

if [[ -L "$MIRZA_CURRENT_LINK" || -d "$MIRZA_LEGACY_PATH" ]]; then
    backup_database
else
    export MIRZA_FRESH_DATABASE=yes
fi

run_migrations
pre_activate_health_check
activate_release
configure_apache_http
obtain_certificate
configure_apache_https
health_check
register_webhook

certbot renew --dry-run
log INFO "MirzaBot installation completed successfully"
