#!/usr/bin/env bash
set -Eeuo pipefail

BOT_DIR="/var/www/html/mirzaprobotconfig"
STAGING="${BOT_DIR}.new.$$"
BACKUP="${BOT_DIR}.backup.$(date +%Y%m%d-%H%M%S)"
MYSQL_AUTH=()

cleanup() {
  rm -rf "$STAGING" 2>/dev/null || true
}
trap cleanup EXIT
trap 'echo "[ERROR] Installation stopped at line $LINENO" >&2' ERR

require_root() {
  if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
    echo "Run with: sudo bash install-safe.sh"
    exit 1
  fi
}

valid_name() {
  [[ "$1" =~ ^[A-Za-z0-9_]+$ ]]
}

read_secret() {
  local prompt="$1" value
  read -rsp "$prompt" value
  echo >&2
  printf '%s' "$value"
}

mysql_exec() {
  mysql "${MYSQL_AUTH[@]}" "$@"
}

require_root

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
if [[ ! -f "$SCRIPT_DIR/index.php" || ! -f "$SCRIPT_DIR/table.php" ]]; then
  echo "Run this file from the Mirza repository root."
  exit 1
fi

read -rp "Domain: " DOMAIN
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || { echo "Invalid domain"; exit 1; }

BOT_TOKEN="$(read_secret 'Test bot token: ')"
BOT_INFO="$(curl -fsS --max-time 20 "https://api.telegram.org/bot${BOT_TOKEN}/getMe")" || {
  echo "Invalid bot token"
  exit 1
}
BOT_USERNAME="$(php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["result"]["username"] ?? "";' <<<"$BOT_INFO")"
[[ -n "$BOT_USERNAME" ]] || { echo "Bot username not found"; exit 1; }

read -rp "Admin numeric Telegram ID: " ADMIN_ID
[[ "$ADMIN_ID" =~ ^-?[0-9]+$ ]] || { echo "Invalid admin ID"; exit 1; }

read -rp "Database name [mirzabot_test]: " DB_NAME
DB_NAME="${DB_NAME:-mirzabot_test}"
valid_name "$DB_NAME" || { echo "Invalid database name"; exit 1; }

read -rp "Database user [mirzabot_test]: " DB_USER
DB_USER="${DB_USER:-mirzabot_test}"
valid_name "$DB_USER" || { echo "Invalid database user"; exit 1; }

DB_PASS="$(read_secret 'Database password [Enter = generate]: ')"
DB_PASS="${DB_PASS:-$(openssl rand -hex 20)}"

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y \
  apache2 mysql-server rsync curl jq openssl certbot python3-certbot-apache \
  php php-cli php-common php-mysql php-curl php-mbstring php-zip php-gd php-xml \
  libapache2-mod-php

systemctl enable --now mysql apache2

if mysql -uroot -NBe 'SELECT 1' >/dev/null 2>&1; then
  MYSQL_AUTH=(-uroot)
else
  MYSQL_ROOT_PASSWORD="$(read_secret 'MySQL root password: ')"
  MYSQL_AUTH=(-uroot "-p${MYSQL_ROOT_PASSWORD}")
  mysql_exec -NBe 'SELECT 1' >/dev/null || {
    echo "MySQL root login failed"
    exit 1
  }
fi

DB_PASS_SQL="${DB_PASS//\\/\\\\}"
DB_PASS_SQL="${DB_PASS_SQL//\'/\'\'}"

mysql_exec <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS_SQL}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS_SQL}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

MYSQL_PWD="$DB_PASS" mysql -u"$DB_USER" -D"$DB_NAME" -NBe 'SELECT 1' >/dev/null || {
  echo "Application database login failed"
  exit 1
}

rm -rf "$STAGING"
mkdir -p "$STAGING"
rsync -a --delete \
  --exclude='.git/' \
  --exclude='config.php' \
  --exclude='install-safe.sh' \
  "$SCRIPT_DIR/" "$STAGING/"

b64() { printf '%s' "$1" | base64 -w0; }
DB_HOST_B64="$(b64 localhost)"
DB_NAME_B64="$(b64 "$DB_NAME")"
DB_USER_B64="$(b64 "$DB_USER")"
DB_PASS_B64="$(b64 "$DB_PASS")"
TOKEN_B64="$(b64 "$BOT_TOKEN")"
ADMIN_B64="$(b64 "$ADMIN_ID")"
DOMAIN_B64="$(b64 "$DOMAIN")"
USERNAME_B64="$(b64 "$BOT_USERNAME")"

cat > "$STAGING/config.php.tmp" <<PHP
<?php
\$request_exec_timeout = null;
\$dbhost = base64_decode('${DB_HOST_B64}');
\$dbname = base64_decode('${DB_NAME_B64}');
\$usernamedb = base64_decode('${DB_USER_B64}');
\$passworddb = base64_decode('${DB_PASS_B64}');
\$connect = mysqli_connect(\$dbhost, \$usernamedb, \$passworddb, \$dbname);
if (!\$connect) { throw new RuntimeException('MySQL connection failed'); }
mysqli_set_charset(\$connect, 'utf8mb4');
\$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
\$dsn = "mysql:host=\$dbhost;dbname=\$dbname;charset=utf8mb4";
\$pdo = new PDO(\$dsn, \$usernamedb, \$passworddb, \$options);
\$APIKEY = base64_decode('${TOKEN_B64}');
\$adminnumber = base64_decode('${ADMIN_B64}');
\$domainhosts = base64_decode('${DOMAIN_B64}');
\$usernamebot = base64_decode('${USERNAME_B64}');
\$customEmojiFlag = getenv('CUSTOM_EMOJI_ENABLED');
define('CUSTOM_EMOJI_ENABLED', \$customEmojiFlag !== false ? filter_var(\$customEmojiFlag, FILTER_VALIDATE_BOOLEAN) : false);
\$customEmojiUsageFlag = getenv('CUSTOM_EMOJI_USAGE_TRACKING');
define('CUSTOM_EMOJI_USAGE_TRACKING', \$customEmojiUsageFlag !== false ? filter_var(\$customEmojiUsageFlag, FILTER_VALIDATE_BOOLEAN) : false);
\$appDebugFlag = getenv('APP_DEBUG');
define('APP_DEBUG', \$appDebugFlag !== false ? filter_var(\$appDebugFlag, FILTER_VALIDATE_BOOLEAN) : false);
\$configuredMemoryLimit = trim((string) getenv('MIRZA_MEMORY_LIMIT'));
define('MIRZA_MEMORY_LIMIT', preg_match('/^[1-9][0-9]*[KMG]$/i', \$configuredMemoryLimit) ? strtoupper(\$configuredMemoryLimit) : '256M');
?>
PHP

php -l "$STAGING/config.php.tmp" >/dev/null
grep -Eq '\{[A-Za-z0-9_]+\}' "$STAGING/config.php.tmp" && {
  echo "Config contains placeholders"
  exit 1
}
mv "$STAGING/config.php.tmp" "$STAGING/config.php"
chown -R www-data:www-data "$STAGING"
find "$STAGING" -type d -exec chmod 755 {} +
find "$STAGING" -type f -exec chmod 644 {} +

sudo -u www-data php -r 'require $argv[1]; echo ($connect && $connect->ping()) ? "DB_OK\n" : exit(1);' "$STAGING/config.php"

(
  cd "$STAGING"
  sudo -u www-data env \
    CUSTOM_EMOJI_ENABLED=false \
    CUSTOM_EMOJI_USAGE_TRACKING=false \
    APP_DEBUG=false \
    MIRZA_MEMORY_LIMIT=256M \
    php table.php

  if [[ -f scripts/migrate_custom_emoji.php ]]; then
    sudo -u www-data env \
      CUSTOM_EMOJI_ENABLED=false \
      CUSTOM_EMOJI_USAGE_TRACKING=false \
      APP_DEBUG=false \
      MIRZA_MEMORY_LIMIT=256M \
      php scripts/migrate_custom_emoji.php
  fi
)

if [[ -d "$BOT_DIR" ]]; then
  mv "$BOT_DIR" "$BACKUP"
fi
mv "$STAGING" "$BOT_DIR"
trap - EXIT

chown -R www-data:www-data "$BOT_DIR"

a2enmod ssl rewrite headers >/dev/null
cat > "/etc/apache2/sites-available/${DOMAIN}.conf" <<APACHE
<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${BOT_DIR}
    <Directory ${BOT_DIR}>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/${DOMAIN}-error.log
    CustomLog \${APACHE_LOG_DIR}/${DOMAIN}-access.log combined
</VirtualHost>
APACHE

a2ensite "${DOMAIN}.conf" >/dev/null
apache2ctl configtest
systemctl restart apache2

CERT="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
KEY="/etc/letsencrypt/live/${DOMAIN}/privkey.pem"
if [[ ! -s "$CERT" || ! -s "$KEY" ]] || ! openssl x509 -checkend 86400 -noout -in "$CERT" >/dev/null 2>&1; then
  certbot certonly --webroot \
    -w "$BOT_DIR" \
    -d "$DOMAIN" \
    --non-interactive \
    --agree-tos \
    --register-unsafely-without-email
fi

cat > "/etc/apache2/sites-available/${DOMAIN}.conf" <<APACHE
<VirtualHost *:80>
    ServerName ${DOMAIN}
    Redirect permanent / https://${DOMAIN}/
</VirtualHost>

<VirtualHost *:443>
    ServerName ${DOMAIN}
    DocumentRoot ${BOT_DIR}
    SSLEngine on
    SSLCertificateFile ${CERT}
    SSLCertificateKeyFile ${KEY}
    <Directory ${BOT_DIR}>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/${DOMAIN}-error.log
    CustomLog \${APACHE_LOG_DIR}/${DOMAIN}-access.log combined
</VirtualHost>
APACHE

apache2ctl configtest
systemctl restart apache2

HTTP_CODE="$(curl -sk --max-time 20 -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data '{"update_id":999999940}' "https://${DOMAIN}/index.php")"
[[ "$HTTP_CODE" =~ ^2[0-9][0-9]$ ]] || {
  echo "HTTPS test failed: HTTP $HTTP_CODE"
  exit 1
}

curl -fsS \
  --data-urlencode "url=https://${DOMAIN}/index.php" \
  --data "drop_pending_updates=true" \
  "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" | jq -e '.ok == true' >/dev/null

WEBHOOK_URL="$(curl -fsS "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo" | jq -r '.result.url')"
[[ "$WEBHOOK_URL" == "https://${DOMAIN}/index.php" ]] || {
  echo "Webhook verification failed"
  exit 1
}

cat > /root/mirza-test-install.txt <<EOF2
DOMAIN=${DOMAIN}
DATABASE_NAME=${DB_NAME}
DATABASE_USER=${DB_USER}
DATABASE_PASSWORD=${DB_PASS}
BOT_USERNAME=${BOT_USERNAME}
EOF2
chmod 600 /root/mirza-test-install.txt

echo "INSTALLATION_OK"
echo "HTTPS_HTTP=${HTTP_CODE}"
echo "WEBHOOK=${WEBHOOK_URL}"
echo "BACKUP=${BACKUP}"
