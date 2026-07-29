#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/installer/common.sh
. "$SCRIPT_DIR/scripts/installer/common.sh"

require_root
[[ "${MIRZA_CONFIRM_UNINSTALL:-}" == "remove-mirzabot-files" ]] \
    || die "set MIRZA_CONFIRM_UNINSTALL=remove-mirzabot-files"

ensure_directories
if [[ -s "$MIRZA_SHARED_DIR/config.php" ]]; then
    backup_database
fi

if [[ -L "$MIRZA_LEGACY_PATH" ]]; then
    rm -f -- "$MIRZA_LEGACY_PATH"
fi
if [[ -f "$MIRZA_APACHE_SITE" ]]; then
    a2dissite "$MIRZA_APACHE_SITE_NAME" >/dev/null || true
    rm -f -- "$MIRZA_APACHE_SITE"
    apache2ctl configtest
    systemctl reload apache2
fi

find /etc/cron.d /etc/systemd/system -maxdepth 1 -type f -name 'mirzabot*' -delete 2>/dev/null || true
systemctl daemon-reload

if [[ "${MIRZA_CONFIRM_DATABASE_DELETE:-}" == "delete-mirzabot-database" ]]; then
    database="$(config_value dbname)"
    database_user="$(config_value usernamedb)"
    [[ "$database" =~ ^[A-Za-z0-9_]{1,64}$ ]] || die "invalid database name in config"
    [[ "$database_user" =~ ^[A-Za-z0-9_]{1,32}$ ]] || die "invalid database user in config"
    mysql --protocol=socket --user=root <<SQL
DROP DATABASE IF EXISTS \`${database}\`;
DROP USER IF EXISTS '${database_user}'@'localhost';
SQL
    log WARN "MirzaBot application database and localhost user were removed after separate confirmation"
else
    log INFO "database retained; set the separate database confirmation only if deletion is intended"
fi

if [[ "${MIRZA_CONFIRM_CERTIFICATE_DELETE:-}" == "delete-unused-mirzabot-certificate" ]]; then
    domain="$(config_value domainhosts)"
    if grep -Rqs -- "/etc/letsencrypt/live/${domain}/" /etc/apache2/sites-enabled; then
        die "certificate is still referenced by an enabled Apache site"
    fi
    certbot delete --non-interactive --cert-name "$domain"
fi

if [[ -d "$MIRZA_ROOT" ]]; then
    archive_root="/var/backups/mirzabot-uninstalled-$(date -u +%Y%m%d%H%M%S)"
    mv -- "$MIRZA_ROOT" "$archive_root"
    log INFO "MirzaBot files moved to ${archive_root}; Apache, PHP and MySQL packages were retained"
fi
