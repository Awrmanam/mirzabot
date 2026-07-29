<?php

$root = dirname(__DIR__);
$common = file_get_contents($root . '/scripts/installer/common.sh');
$install = file_get_contents($root . '/install.sh');
$update = file_get_contents($root . '/update.sh');
$rollback = file_get_contents($root . '/rollback.sh');
$uninstall = file_get_contents($root . '/uninstall.sh');
$index = file_get_contents($root . '/index.php');
$webhookSecurity = file_get_contents($root . '/webhook_security.php');
$table = file_get_contents($root . '/table.php');
$failures = [];

$check = function ($condition, string $message) use (&$failures): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . "\n";
    if (!$condition) {
        $failures[] = $message;
    }
};

$position = function (string $source, string $needle): int {
    $value = strpos($source, $needle);
    return $value === false ? PHP_INT_MAX : $value;
};

$check(strpos($common, 'set -Eeuo pipefail') !== false, 'installer common code enables strict error handling');
$check(strpos($common, 'trap on_error ERR') !== false, 'installer logs line-aware ERR failures');
$check(strpos($common, 'MIRZA_RELEASE_SHA256 is required') !== false, 'release checksum is mandatory');
$check(strpos($common, 'sha256sum --check --status') !== false, 'staged release checksum is verified');
$check(stripos($common . $install, 'self-update') === false, 'installer does not replace itself mid-run');
$check(strpos($common, 'skip-grant-tables') === false, 'database setup never enables skip-grant-tables');
$check(strpos($common, "ALTER USER 'root") === false, 'database setup never alters MySQL root authentication');
$check(strpos($common, "\${database_user}'@'localhost'") !== false, 'application database user is localhost-only');
$check(strpos($common, 'ondrej') === false && strpos($common, 'add-apt-repository') === false, 'default install uses no external PHP PPA');
$check(strpos($common, 'php-fpm') === false && strpos($common, 'libapache2-mod-php') !== false, 'exactly the mod_php architecture is selected');
$check(strpos($common, '22.04) MIRZA_EXPECTED_PHP="8.1"') !== false, 'Ubuntu 22.04 expects repository PHP 8.1');
$check(strpos($common, '24.04) MIRZA_EXPECTED_PHP="8.3"') !== false, 'Ubuntu 24.04 expects repository PHP 8.3');
$check(strpos($common, 'mv -Tf -- "$next_link" "$MIRZA_CURRENT_LINK"') !== false, 'release cutover is an atomic same-filesystem symlink replacement');
$check(strpos($common, 'ln -s ../../shared/config.php') !== false, 'persistent config remains in shared storage');
$check(strpos($common, 'chmod 0640 "$temp_config"') !== false, 'new config permissions exclude world access');
$check(
    $position($install, 'prepare_release')
        < $position($install, 'run_migrations')
        && $position($install, 'run_migrations')
        < $position($install, 'activate_release'),
    'release validation and migrations happen before cutover'
);
$check(strpos($common, 'systemctl stop apache2') === false, 'SSL flow never stops Apache');
$check(substr_count($common, 'certbot certonly') === 1 && strpos($common, '--webroot') !== false, 'SSL uses one Certbot webroot issuance flow');
$check(
    $position($install, 'configure_apache_http')
        < $position($install, 'obtain_certificate')
        && $position($install, 'obtain_certificate')
        < $position($install, 'configure_apache_https'),
    'HTTP/webroot is configured before certificate and HTTPS'
);
$check(
    $position($install, 'health_check') < $position($install, 'register_webhook'),
    'webhook registration happens only after health checks'
);
$check(strpos($common, 'secret_token=${MIRZA_WEBHOOK_SECRET}') !== false, 'Telegram webhook is registered with a secret token');
$check(strpos($index, 'mirzaEnforceTelegramWebhookSecret') !== false, 'webhook endpoint enforces the secret header');
$check(strpos($webhookSecurity, 'hash_equals') !== false, 'webhook secret comparison is timing-safe');
$check(strpos($webhookSecurity, 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN') !== false, 'webhook reads the Telegram secret header');
$check(strpos($table, "telegram('setwebhook'") === false, 'schema migration has no webhook side effect');
$check(
    $position($update, 'backup_database')
        < $position($update, 'prepare_release')
        && $position($update, 'prepare_release')
        < $position($update, 'run_migrations'),
    'update validates a database backup before staging/migration'
);
$check(strpos($rollback, 'MIRZA_ALLOW_DB_RESTORE') !== false || strpos($common, 'MIRZA_ALLOW_DB_RESTORE') !== false, 'database restore needs explicit opt-in');
$check(strpos($common, 'database dump was not restored automatically') !== false, 'file rollback does not falsely claim automatic DDL rollback');
$check(stripos($uninstall, 'apt-get purge') === false, 'uninstall never purges shared packages');
$check(strpos($uninstall, '/var/www/html"') === false, 'uninstall never removes the shared web root');
$check(strpos($uninstall, 'remove-mirzabot-files') !== false, 'uninstall requires scoped file-removal confirmation');
$check(strpos($uninstall, 'delete-mirzabot-database') !== false, 'database deletion uses a separate confirmation');
$check(strpos($uninstall, 'grep -Rqs') !== false, 'certificate deletion checks for other Apache consumers');

if ($failures) {
    echo "\n" . count($failures) . " installer safety check(s) failed.\n";
    exit(1);
}

echo "\nAll installer safety checks passed.\n";
