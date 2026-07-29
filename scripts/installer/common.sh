#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

readonly MIRZA_ROOT="${MIRZA_ROOT:-/var/www/mirzabot}"
readonly MIRZA_RELEASES_DIR="${MIRZA_ROOT}/releases"
readonly MIRZA_SHARED_DIR="${MIRZA_ROOT}/shared"
readonly MIRZA_CURRENT_LINK="${MIRZA_ROOT}/current"
readonly MIRZA_LEGACY_PATH="${MIRZA_LEGACY_PATH:-/var/www/html/mirzaprobotconfig}"
readonly MIRZA_APACHE_SITE="${MIRZA_APACHE_SITE:-/etc/apache2/sites-available/mirzabot.conf}"
readonly MIRZA_APACHE_SITE_NAME="mirzabot.conf"
readonly MIRZA_LOG_FILE="${MIRZA_LOG_FILE:-/var/log/mirzabot-installer.log}"
readonly MIRZA_REPOSITORY="Awrmanam/mirzabot"

WORK_DIR=""
PREVIOUS_RELEASE=""
CUTOVER_COMPLETE=0
DATABASE_BACKUP=""

timestamp() {
    date -u '+%Y-%m-%dT%H:%M:%SZ'
}

log() {
    local level="$1"
    shift
    local message="$*"
    printf '%s [%s] %s\n' "$(timestamp)" "$level" "$message" | tee -a "$MIRZA_LOG_FILE"
}

die() {
    log ERROR "$*"
    exit 1
}

cleanup() {
    local exit_code=$?
    if [[ -n "$WORK_DIR" && -d "$WORK_DIR" ]]; then
        rm -rf -- "$WORK_DIR"
    fi
    return "$exit_code"
}

on_error() {
    local exit_code=$?
    local line_number="${BASH_LINENO[0]:-unknown}"
    log ERROR "command failed at line ${line_number} with exit ${exit_code}"
    if [[ "$CUTOVER_COMPLETE" -eq 1 ]]; then
        rollback_release || log ERROR "automatic file/symlink rollback failed"
    fi
    exit "$exit_code"
}

trap cleanup EXIT
trap on_error ERR

require_root() {
    [[ "${EUID}" -eq 0 ]] || die "run this command as root"
}

ensure_work_dir() {
    [[ -n "$WORK_DIR" ]] && return
    WORK_DIR="$(mktemp -d /tmp/mirzabot-installer.XXXXXX)"
    chmod 700 "$WORK_DIR"
}

detect_os() {
    [[ -r /etc/os-release ]] || die "/etc/os-release is unavailable"
    # shellcheck disable=SC1091
    . /etc/os-release
    [[ "${ID:-}" == "ubuntu" ]] || die "only Ubuntu 22.04 and 24.04 are supported"
    case "${VERSION_ID:-}" in
        22.04) MIRZA_EXPECTED_PHP="8.1" ;;
        24.04) MIRZA_EXPECTED_PHP="8.3" ;;
        *) die "unsupported Ubuntu release: ${VERSION_ID:-unknown}" ;;
    esac
    export MIRZA_EXPECTED_PHP
    log INFO "detected Ubuntu ${VERSION_ID}; repository-default PHP ${MIRZA_EXPECTED_PHP}"
}

validate_domain() {
    local domain="${1,,}"
    [[ ${#domain} -le 253 ]] || return 1
    [[ "$domain" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || return 1
    [[ "$domain" != *..* ]]
}

validate_release_input() {
    local release_ref="${MIRZA_RELEASE_REF:-}"
    local release_sha="${MIRZA_RELEASE_SHA256:-}"
    if [[ -z "${MIRZA_RELEASE_ARCHIVE:-}" ]]; then
        [[ "$release_ref" =~ ^[A-Za-z0-9._/-]{1,100}$ ]] || die "MIRZA_RELEASE_REF must be an immutable commit/tag"
    fi
    [[ "$release_sha" =~ ^[a-fA-F0-9]{64}$ ]] || die "MIRZA_RELEASE_SHA256 is required"
}

install_packages() {
    export DEBIAN_FRONTEND=noninteractive
    local database_packages=()
    if ! command -v mysql >/dev/null 2>&1; then
        database_packages=(default-mysql-server)
    fi
    apt-get update
    apt-get install -y --no-install-recommends \
        apache2 certbot curl ca-certificates unzip openssl dnsutils \
        php libapache2-mod-php php-cli php-mysql php-curl php-mbstring \
        php-xml php-zip php-intl "${database_packages[@]}"

    a2dismod mpm_event >/dev/null 2>&1 || true
    a2enmod mpm_prefork rewrite ssl headers >/dev/null
    systemctl enable --now apache2
    if systemctl list-unit-files mysql.service >/dev/null 2>&1; then
        systemctl enable --now mysql
    elif systemctl list-unit-files mariadb.service >/dev/null 2>&1; then
        systemctl enable --now mariadb
    else
        die "neither MySQL nor MariaDB systemd service is available"
    fi

    verify_php_runtime
}

verify_php_runtime() {
    local php_version
    php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    [[ "$php_version" == "$MIRZA_EXPECTED_PHP" ]] \
        || die "expected PHP ${MIRZA_EXPECTED_PHP}, found ${php_version}; mixed/PPA PHP is not supported"

    local required_module
    for required_module in mysqli pdo_mysql curl mbstring xml zip intl; do
        php -m | grep -Fxqi "$required_module" || die "missing PHP module: ${required_module}"
    done
    apache2ctl -M 2>/dev/null | grep -Eq 'php[0-9]*_module' || die "Apache mod_php integration is unavailable"
}

generate_secret() {
    openssl rand -hex "${1:-24}"
}

ensure_directories() {
    install -d -m 0750 -o root -g www-data "$MIRZA_ROOT" "$MIRZA_RELEASES_DIR" "$MIRZA_SHARED_DIR"
    install -d -m 0750 -o www-data -g www-data "$MIRZA_SHARED_DIR/acme"
    install -d -m 0700 -o root -g root "$MIRZA_SHARED_DIR/backups"
    install -d -m 0770 -o www-data -g www-data "$MIRZA_SHARED_DIR/runtime" "$MIRZA_SHARED_DIR/storage"
}

link_shared_runtime() {
    local release="$1"
    local runtime="$MIRZA_SHARED_DIR/runtime"
    local mutable_file
    for mutable_file in error_log log.txt users.json cookie.txt ss custom.jpg; do
        touch "$runtime/$mutable_file"
        chown www-data:www-data "$runtime/$mutable_file"
        chmod 0660 "$runtime/$mutable_file"
        rm -f -- "$release/$mutable_file"
        ln -s "$runtime/$mutable_file" "$release/$mutable_file"
    done

    local seeded_file
    for seeded_file in images.jpg text.json; do
        if [[ ! -s "$runtime/$seeded_file" && -f "$release/$seeded_file" ]]; then
            cp -- "$release/$seeded_file" "$runtime/$seeded_file"
        fi
        touch "$runtime/$seeded_file"
        chown www-data:www-data "$runtime/$seeded_file"
        chmod 0660 "$runtime/$seeded_file"
        rm -f -- "$release/$seeded_file"
        ln -s "$runtime/$seeded_file" "$release/$seeded_file"
    done
    touch "$runtime/api-hash.txt"
    chown www-data:www-data "$runtime/api-hash.txt"
    chmod 0660 "$runtime/api-hash.txt"
    rm -f -- "$release/api/hash.txt"
    ln -s "$runtime/api-hash.txt" "$release/api/hash.txt"

    local cron_state
    for cron_state in users.json info gift username.json; do
        touch "$runtime/cron-${cron_state}"
        chown www-data:www-data "$runtime/cron-${cron_state}"
        chmod 0660 "$runtime/cron-${cron_state}"
        rm -f -- "$release/cronbot/$cron_state"
        ln -s "$runtime/cron-${cron_state}" "$release/cronbot/$cron_state"
    done

    rm -rf -- "$release/storage"
    ln -s "$MIRZA_SHARED_DIR/storage" "$release/storage"

    if [[ ! -d "$MIRZA_SHARED_DIR/vpnbot" ]]; then
        cp -a "$release/vpnbot" "$MIRZA_SHARED_DIR/vpnbot"
    else
        install -d -m 0770 -o www-data -g www-data \
            "$MIRZA_SHARED_DIR/vpnbot/Default" "$MIRZA_SHARED_DIR/vpnbot/update"
        cp -a "$release/vpnbot/Default"/. "$MIRZA_SHARED_DIR/vpnbot/Default"/
        cp -a "$release/vpnbot/update"/. "$MIRZA_SHARED_DIR/vpnbot/update"/
    fi
    chown -R www-data:www-data "$MIRZA_SHARED_DIR/vpnbot"
    find "$MIRZA_SHARED_DIR/vpnbot" -type d -exec chmod 0770 {} +
    find "$MIRZA_SHARED_DIR/vpnbot" -type f -exec chmod 0660 {} +
    rm -rf -- "$release/vpnbot"
    ln -s "$MIRZA_SHARED_DIR/vpnbot" "$release/vpnbot"
}

migrate_legacy_persistent_data() {
    [[ -d "$MIRZA_LEGACY_PATH" && ! -L "$MIRZA_LEGACY_PATH" ]] || return
    local runtime="$MIRZA_SHARED_DIR/runtime"

    if [[ -d "$MIRZA_LEGACY_PATH/vpnbot" && ! -d "$MIRZA_SHARED_DIR/vpnbot" ]]; then
        cp -a "$MIRZA_LEGACY_PATH/vpnbot" "$MIRZA_SHARED_DIR/vpnbot"
    fi
    if [[ -d "$MIRZA_LEGACY_PATH/storage" ]]; then
        cp -a "$MIRZA_LEGACY_PATH/storage"/. "$MIRZA_SHARED_DIR/storage"/
    fi

    local source relative target
    for relative in error_log log.txt users.json cookie.txt ss custom.jpg images.jpg text.json api/hash.txt \
        cronbot/users.json cronbot/info cronbot/gift cronbot/username.json; do
        source="$MIRZA_LEGACY_PATH/$relative"
        [[ -s "$source" ]] || continue
        case "$relative" in
            api/hash.txt) target="$runtime/api-hash.txt" ;;
            cronbot/*) target="$runtime/cron-${relative#cronbot/}" ;;
            *) target="$runtime/${relative##*/}" ;;
        esac
        if [[ ! -s "$target" ]]; then
            cp -- "$source" "$target"
        fi
    done
    chown -R www-data:www-data "$MIRZA_SHARED_DIR/runtime" "$MIRZA_SHARED_DIR/storage"
    log INFO "legacy persistent files copied to shared storage"
}

ensure_database() {
    local database_name="$1"
    local database_user="$2"
    local database_password="$3"
    [[ "$database_name" =~ ^[A-Za-z0-9_]{1,64}$ ]] || die "invalid database name"
    [[ "$database_user" =~ ^[A-Za-z0-9_]{1,32}$ ]] || die "invalid database user"

    mysql --protocol=socket --user=root <<SQL
CREATE DATABASE IF NOT EXISTS \`${database_name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${database_user}'@'localhost' IDENTIFIED BY '${database_password}';
ALTER USER '${database_user}'@'localhost' IDENTIFIED BY '${database_password}';
GRANT ALL PRIVILEGES ON \`${database_name}\`.* TO '${database_user}'@'localhost';
FLUSH PRIVILEGES;
SQL

    MYSQL_PWD="$database_password" mysql \
        --protocol=socket --host=localhost --user="$database_user" \
        --database="$database_name" --execute='SELECT 1' >/dev/null
    log INFO "application database and localhost-only user are ready"
}

write_config_atomic() {
    local config_path="$MIRZA_SHARED_DIR/config.php"
    if [[ -s "$config_path" && "${MIRZA_REPAIR_CONFIG:-no}" != "yes" ]]; then
        php -l "$config_path" >/dev/null || die "existing shared config.php is invalid"
        local current_webhook_secret
        # shellcheck disable=SC2016
        current_webhook_secret="$(php -r '
            ob_start();
            require $argv[1];
            ob_end_clean();
            echo isset($telegram_webhook_secret) ? $telegram_webhook_secret : "";
        ' "$config_path")"
        if [[ -n "$current_webhook_secret" ]]; then
            MIRZA_WEBHOOK_SECRET="$current_webhook_secret"
            export MIRZA_WEBHOOK_SECRET
        else
            local upgraded_config
            upgraded_config="$(mktemp "$MIRZA_SHARED_DIR/.config.php.XXXXXX")"
            cp -- "$config_path" "$upgraded_config"
            # shellcheck disable=SC2016
            printf "\n"'$telegram_webhook_secret'" = '%s';\n" "$MIRZA_WEBHOOK_SECRET" >>"$upgraded_config"
            chmod 0640 "$upgraded_config"
            chown root:www-data "$upgraded_config"
            php -l "$upgraded_config" >/dev/null
            mv -f -- "$upgraded_config" "$config_path"
            log INFO "webhook secret added atomically to the preserved config"
        fi
        php -r "require '$config_path'; \$pdo->query('SELECT 1'); \$connect->query('SELECT 1');"
        log INFO "preserving existing shared config.php"
        return
    fi

    local temp_config
    temp_config="$(mktemp "$MIRZA_SHARED_DIR/.config.php.XXXXXX")"
    cat >"$temp_config" <<PHP
<?php
\$request_exec_timeout = null;
\$dbhost = 'localhost';
\$dbname = '${MIRZA_DB_NAME}';
\$usernamedb = '${MIRZA_DB_USER}';
\$passworddb = '${MIRZA_DB_PASSWORD}';
\$connect = mysqli_connect(\$dbhost, \$usernamedb, \$passworddb, \$dbname);
if (!\$connect || \$connect->connect_error) { throw new RuntimeException('Database connection failed'); }
mysqli_set_charset(\$connect, 'utf8mb4');
\$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
\$dsn = "mysql:host=\$dbhost;dbname=\$dbname;charset=utf8mb4";
\$pdo = new PDO(\$dsn, \$usernamedb, \$passworddb, \$options);
\$APIKEY = '${MIRZA_BOT_TOKEN}';
\$adminnumber = '${MIRZA_ADMIN_ID}';
\$domainhosts = '${MIRZA_DOMAIN}';
\$usernamebot = '${MIRZA_BOT_USERNAME}';
\$telegram_webhook_secret = '${MIRZA_WEBHOOK_SECRET}';
PHP
    chmod 0640 "$temp_config"
    chown root:www-data "$temp_config"
    php -l "$temp_config" >/dev/null
    php -r "require '$temp_config'; \$pdo->query('SELECT 1'); \$connect->query('SELECT 1');"
    mv -f -- "$temp_config" "$config_path"
    log INFO "validated shared config.php written atomically"
}

prepare_release() {
    validate_release_input
    ensure_work_dir

    local archive="$WORK_DIR/release.zip"
    if [[ -n "${MIRZA_RELEASE_ARCHIVE:-}" ]]; then
        [[ -f "$MIRZA_RELEASE_ARCHIVE" ]] || die "local release archive not found"
        cp -- "$MIRZA_RELEASE_ARCHIVE" "$archive"
    else
        curl --fail --location --proto '=https' --tlsv1.2 \
            --output "$archive" \
            "https://github.com/${MIRZA_REPOSITORY}/archive/${MIRZA_RELEASE_REF}.zip"
    fi
    printf '%s  %s\n' "${MIRZA_RELEASE_SHA256,,}" "$archive" | sha256sum --check --status \
        || die "release archive checksum mismatch"

    local extracted="$WORK_DIR/extracted"
    mkdir -p "$extracted"
    unzip -q "$archive" -d "$extracted"
    local source_dir
    source_dir="$(find "$extracted" -mindepth 1 -maxdepth 1 -type d -print -quit)"
    [[ -n "$source_dir" ]] || die "release archive has no top-level directory"

    local required
    for required in index.php admin.php botapi.php table.php emoji_system.php \
        scripts/migrate_custom_emoji.php scripts/migrate_panel_identity.php health.php; do
        [[ -f "$source_dir/$required" ]] || die "release is missing ${required}"
    done

    local release_ref_label="${MIRZA_RELEASE_REF:-local}"
    local release_id="${MIRZA_RELEASE_ID:-$(date -u +%Y%m%d%H%M%S)-${release_ref_label:0:12}}"
    [[ "$release_id" =~ ^[A-Za-z0-9._-]{1,80}$ ]] || die "invalid release id"
    MIRZA_PREPARED_RELEASE="$MIRZA_RELEASES_DIR/$release_id"
    [[ ! -e "$MIRZA_PREPARED_RELEASE" ]] || die "release directory already exists"
    mkdir "$MIRZA_PREPARED_RELEASE"
    cp -a "$source_dir"/. "$MIRZA_PREPARED_RELEASE"/
    ln -s ../../shared/config.php "$MIRZA_PREPARED_RELEASE/config.php"
    link_shared_runtime "$MIRZA_PREPARED_RELEASE"
    chown -R root:www-data "$MIRZA_PREPARED_RELEASE"
    find "$MIRZA_PREPARED_RELEASE" -type d -exec chmod 0750 {} +
    find "$MIRZA_PREPARED_RELEASE" -type f -exec chmod 0640 {} +

    while IFS= read -r -d '' php_file; do
        php -l "$php_file" >/dev/null
    done < <(find "$MIRZA_PREPARED_RELEASE" -type f -name '*.php' -print0)
    bash -n "$MIRZA_PREPARED_RELEASE/install.sh"
    export MIRZA_PREPARED_RELEASE
    log INFO "release prepared and linted before cutover"
}

config_value() {
    local variable="$1"
    case "$variable" in
        dbhost|dbname|usernamedb|passworddb|APIKEY|adminnumber|domainhosts|usernamebot|telegram_webhook_secret) ;;
        *) die "unsupported config key" ;;
    esac
    # shellcheck disable=SC2016
    php -r '
        ob_start();
        require $argv[1];
        ob_end_clean();
        $key = $argv[2];
        echo isset($$key) ? $$key : "";
    ' "$MIRZA_SHARED_DIR/config.php" "$variable"
}

backup_database() {
    local host database user password
    host="$(config_value dbhost)"
    database="$(config_value dbname)"
    user="$(config_value usernamedb)"
    password="$(config_value passworddb)"
    DATABASE_BACKUP="$MIRZA_SHARED_DIR/backups/pre-update-$(date -u +%Y%m%d%H%M%S).sql"
    umask 077
    MYSQL_PWD="$password" mysqldump --single-transaction --quick --routines --triggers \
        --host="$host" --user="$user" "$database" >"$DATABASE_BACKUP"
    [[ -s "$DATABASE_BACKUP" ]] || die "database dump is empty"
    head -n 20 "$DATABASE_BACKUP" | grep -Eqi '(MySQL|MariaDB) dump' || die "database dump validation failed"
    log INFO "validated pre-update database backup created"
}

run_migrations() {
    [[ -n "${MIRZA_PREPARED_RELEASE:-}" ]] || die "release is not prepared"
    (
        cd "$MIRZA_PREPARED_RELEASE"
        php table.php
    )
    php "$MIRZA_PREPARED_RELEASE/scripts/migrate_custom_emoji.php"
    local migration_args=(--backup-dir="$MIRZA_SHARED_DIR/backups")
    if [[ "${MIRZA_FRESH_DATABASE:-no}" == "yes" ]]; then
        migration_args+=(--fresh-database)
    fi
    php "$MIRZA_PREPARED_RELEASE/scripts/migrate_panel_identity.php" "${migration_args[@]}"
    log INFO "forward-only migrations completed"
}

pre_activate_health_check() {
    [[ -d "${MIRZA_PREPARED_RELEASE:-}" ]] || die "prepared release is unavailable"
    php "$MIRZA_PREPARED_RELEASE/health.php" --cli \
        || die "prepared release CLI health check failed before cutover"
    log INFO "prepared release passed its pre-cutover CLI health check"
}

activate_release() {
    [[ -d "${MIRZA_PREPARED_RELEASE:-}" ]] || die "prepared release is unavailable"
    if [[ -L "$MIRZA_CURRENT_LINK" ]]; then
        PREVIOUS_RELEASE="$(readlink -f "$MIRZA_CURRENT_LINK")"
    elif [[ -d "$MIRZA_LEGACY_PATH" && ! -L "$MIRZA_LEGACY_PATH" ]]; then
        local legacy_backup
        legacy_backup="$MIRZA_SHARED_DIR/legacy-$(date -u +%Y%m%d%H%M%S)"
        mv -- "$MIRZA_LEGACY_PATH" "$legacy_backup"
        PREVIOUS_RELEASE="$legacy_backup"
        log INFO "legacy directory preserved under shared before symlink migration"
    fi

    local next_link="$MIRZA_ROOT/.current.next"
    ln -s "$MIRZA_PREPARED_RELEASE" "$next_link"
    mv -Tf -- "$next_link" "$MIRZA_CURRENT_LINK"
    if [[ -e "$MIRZA_LEGACY_PATH" || -L "$MIRZA_LEGACY_PATH" ]]; then
        rm -f -- "$MIRZA_LEGACY_PATH"
    fi
    ln -s "$MIRZA_CURRENT_LINK" "$MIRZA_LEGACY_PATH"
    CUTOVER_COMPLETE=1
    log INFO "release symlink switched atomically on one filesystem"
}

rollback_release() {
    [[ -n "$PREVIOUS_RELEASE" && -d "$PREVIOUS_RELEASE" ]] || {
        log ERROR "no previous release is available for automatic file rollback"
        return 1
    }
    local rollback_link="$MIRZA_ROOT/.current.rollback"
    ln -s "$PREVIOUS_RELEASE" "$rollback_link"
    mv -Tf -- "$rollback_link" "$MIRZA_CURRENT_LINK"
    CUTOVER_COMPLETE=0
    apache2ctl configtest
    systemctl reload apache2
    log WARN "file release rolled back; database dump was not restored automatically"
}

configure_apache_http() {
    validate_domain "$MIRZA_DOMAIN" || die "invalid domain: ${MIRZA_DOMAIN}"
    cat >"$MIRZA_APACHE_SITE" <<APACHE
<VirtualHost *:80>
    ServerName ${MIRZA_DOMAIN}
    DocumentRoot ${MIRZA_CURRENT_LINK}
    Alias /.well-known/acme-challenge/ ${MIRZA_SHARED_DIR}/acme/
    <Directory ${MIRZA_SHARED_DIR}/acme>
        Require all granted
    </Directory>
    <Directory ${MIRZA_CURRENT_LINK}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/mirzabot-error.log
    CustomLog \${APACHE_LOG_DIR}/mirzabot-access.log combined
</VirtualHost>
APACHE
    a2ensite "$MIRZA_APACHE_SITE_NAME" >/dev/null
    apache2ctl configtest
    systemctl reload apache2
}

verify_dns() {
    local ipv4 ipv6 public4 public6
    mapfile -t ipv4 < <(dig +short A "$MIRZA_DOMAIN" | sort -u)
    mapfile -t ipv6 < <(dig +short AAAA "$MIRZA_DOMAIN" | sort -u)
    [[ ${#ipv4[@]} -gt 0 || ${#ipv6[@]} -gt 0 ]] || die "domain has no A or AAAA record"

    public4="${MIRZA_EXPECTED_IPV4:-$(curl -4fsS --max-time 10 https://api.ipify.org || true)}"
    public6="${MIRZA_EXPECTED_IPV6:-$(curl -6fsS --max-time 10 https://api64.ipify.org || true)}"
    if [[ -n "$public4" && ${#ipv4[@]} -gt 0 ]] && ! printf '%s\n' "${ipv4[@]}" | grep -Fxq "$public4"; then
        [[ "${MIRZA_ALLOW_DNS_MISMATCH:-no}" == "yes" ]] || die "domain A record does not point to this server"
    fi
    if [[ ${#ipv6[@]} -gt 0 ]]; then
        [[ -n "$public6" ]] || die "AAAA exists but this server has no verified public IPv6"
        if ! printf '%s\n' "${ipv6[@]}" | grep -Fxq "$public6"; then
            [[ "${MIRZA_ALLOW_DNS_MISMATCH:-no}" == "yes" ]] || die "domain AAAA record points to another server"
        fi
    fi
}

obtain_certificate() {
    verify_dns
    local challenge
    challenge="mirza-$(generate_secret 8)"
    printf '%s' "$challenge" >"$MIRZA_SHARED_DIR/acme/$challenge"
    local served
    served="$(curl -fsS --max-time 15 "http://${MIRZA_DOMAIN}/.well-known/acme-challenge/${challenge}")"
    rm -f -- "$MIRZA_SHARED_DIR/acme/$challenge"
    [[ "$served" == "$challenge" ]] || die "Apache webroot challenge self-test failed"

    local certificate="/etc/letsencrypt/live/${MIRZA_DOMAIN}/fullchain.pem"
    if [[ -s "$certificate" ]] && openssl x509 -checkend 2592000 -noout -in "$certificate"; then
        log INFO "existing certificate is valid for at least 30 days"
        return
    fi
    certbot certonly --non-interactive --agree-tos --webroot \
        --webroot-path "$MIRZA_SHARED_DIR/acme" \
        --email "$MIRZA_CERTBOT_EMAIL" \
        --domain "$MIRZA_DOMAIN"
    [[ -s "$certificate" && -s "/etc/letsencrypt/live/${MIRZA_DOMAIN}/privkey.pem" ]] \
        || die "Certbot returned without valid certificate files"
}

configure_apache_https() {
    cat >>"$MIRZA_APACHE_SITE" <<APACHE

<VirtualHost *:443>
    ServerName ${MIRZA_DOMAIN}
    DocumentRoot ${MIRZA_CURRENT_LINK}
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/${MIRZA_DOMAIN}/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/${MIRZA_DOMAIN}/privkey.pem
    Header always set Strict-Transport-Security "max-age=31536000"
    <Directory ${MIRZA_CURRENT_LINK}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/mirzabot-ssl-error.log
    CustomLog \${APACHE_LOG_DIR}/mirzabot-ssl-access.log combined
</VirtualHost>
APACHE
    apache2ctl configtest
    systemctl reload apache2
    curl -fsS --max-time 20 "https://${MIRZA_DOMAIN}/health.php" >/dev/null
}

health_check() {
    php "$MIRZA_CURRENT_LINK/health.php" --cli
    curl -fsS --max-time 20 "https://${MIRZA_DOMAIN}/health.php" \
        | grep -q '"ok":true' || die "HTTPS health check failed"
}

register_webhook() {
    local response webhook_url
    webhook_url="https://${MIRZA_DOMAIN}/index.php"
    response="$(curl -fsS --request POST \
        "https://api.telegram.org/bot${MIRZA_BOT_TOKEN}/setWebhook" \
        --data-urlencode "url=${webhook_url}" \
        --data-urlencode "secret_token=${MIRZA_WEBHOOK_SECRET}")"
    # shellcheck disable=SC2016
    php -r '
        $data = json_decode(stream_get_contents(STDIN), true);
        exit(is_array($data) && ($data["ok"] ?? false) === true ? 0 : 1);
    ' <<<"$response" || die "Telegram setWebhook returned ok=false"

    response="$(curl -fsS "https://api.telegram.org/bot${MIRZA_BOT_TOKEN}/getWebhookInfo")"
    # shellcheck disable=SC2016
    php -r '
        $data = json_decode(stream_get_contents(STDIN), true);
        exit(
            is_array($data)
            && ($data["ok"] ?? false) === true
            && ($data["result"]["url"] ?? "") === $argv[1]
            ? 0 : 1
        );
    ' "$webhook_url" <<<"$response" || die "Telegram webhook URL verification failed"
    log INFO "Telegram webhook registered and verified"
}

restore_database_dump() {
    [[ "${MIRZA_ALLOW_DB_RESTORE:-no}" == "yes" ]] || die "database restore requires MIRZA_ALLOW_DB_RESTORE=yes"
    [[ -s "$DATABASE_BACKUP" ]] || die "validated database backup is unavailable"
    head -n 20 "$DATABASE_BACKUP" | grep -Eqi '(MySQL|MariaDB) dump' || die "database backup header is invalid"
    local host database user password
    host="$(config_value dbhost)"
    database="$(config_value dbname)"
    user="$(config_value usernamedb)"
    password="$(config_value passworddb)"
    MYSQL_PWD="$password" mysql --host="$host" --user="$user" "$database" <"$DATABASE_BACKUP"
}
