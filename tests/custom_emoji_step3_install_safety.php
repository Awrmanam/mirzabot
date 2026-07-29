<?php

$root = dirname(__DIR__);
$installer = file_get_contents($root . DIRECTORY_SEPARATOR . 'install.sh');
$attributes = file_get_contents($root . DIRECTORY_SEPARATOR . '.gitattributes');
$failures = [];

$check = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$extractFunction = static function ($source, $name) {
    $start = strpos($source, "function {$name}()");
    if ($start === false) {
        return '';
    }

    $next = strpos($source, "\nfunction ", $start + 1);
    return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
};

$install = $extractFunction($installer, 'install_bot');
$update = $extractFunction($installer, 'update_bot');

$check(
    strpos($installer, 'readonly MIRZA_SOURCE_BRANCH="premium-emoji-install-safety-step3-20260729"') !== false,
    'installer is pinned to the step-3 safety branch'
);
$check($install !== '', 'install_bot function exists');
$check($update !== '', 'update_bot function exists');
$check(
    preg_match('/^\*\.sh text eol=lf$/m', $attributes) === 1,
    'shell scripts are normalized to LF'
);

$installDownload = strpos($install, 'wget -O "$TEMP_DIR/bot.zip"');
$installCutover = strpos($install, 'sudo mv "$BOT_DIR" "$BACKUP_DIR"');
$check(
    $installDownload !== false && $installCutover !== false && $installDownload < $installCutover,
    'fresh install validates a downloaded package before replacing an existing directory'
);
$check(
    strpos($install, 'sudo mv "$BACKUP_DIR" "$BOT_DIR"') !== false,
    'fresh install restores the previous version when replacement fails'
);
$installBackupDelete = strpos($install, 'sudo rm -rf "$BACKUP_DIR"');
$installOwnership = strpos($install, 'sudo chown -R www-data:www-data "$BOT_DIR"');
$installPermissions = strpos($install, 'sudo chmod -R 755 "$BOT_DIR"');
$installSelfUpdate = strrpos($install, 'self_update_script');
$check(
    $installBackupDelete !== false
        && $installOwnership !== false
        && $installPermissions !== false
        && $installSelfUpdate !== false
        && $installBackupDelete > $installOwnership
        && $installBackupDelete > $installPermissions
        && $installBackupDelete > $installSelfUpdate,
    'fresh install deletes its backup only after final required operations'
);

$updateMigration = strpos($update, 'php "$EXTRACTED_DIR/scripts/migrate_custom_emoji.php"');
$updateCutover = strpos($update, 'sudo mv "$BOT_DIR" "$BACKUP_DIR"');
$check(
    $updateMigration !== false && $updateCutover !== false && $updateMigration < $updateCutover,
    'update migration succeeds before live files are changed'
);
$check(
    strpos($update, 'migration runs against the live database before cutover and must remain idempotent and backward-compatible') !== false,
    'pre-cutover live database migration compatibility requirement is documented'
);
$check(
    strpos($update, 'sudo mv "$BACKUP_DIR" "$BOT_DIR"') !== false,
    'update restores the previous version when cutover fails'
);
$check(
    strpos($update, 'Proceeding without backup') === false,
    'update does not continue without config.php'
);
$rollbackStart = strpos($update, 'rollback_update_cutover() {');
$rollbackEnd = $rollbackStart === false
    ? false
    : strpos($update, 'trap rollback_update_cutover EXIT', $rollbackStart);
$updateRollback = $rollbackStart !== false && $rollbackEnd !== false
    ? substr($update, $rollbackStart, $rollbackEnd - $rollbackStart)
    : '';
$normalUpdate = $rollbackStart !== false && $rollbackEnd !== false
    ? substr($update, 0, $rollbackStart) . substr($update, $rollbackEnd)
    : $update;
$rollbackGuard = strpos($updateRollback, 'if [ "$CUTOVER_ACTIVE" -eq 1 ]; then');
$rollbackDelete = strpos($updateRollback, 'sudo rm -rf "$BOT_DIR"');
$normalCutover = strpos($normalUpdate, 'sudo mv "$BOT_DIR" "$BACKUP_DIR"');
$normalActivation = strpos($normalUpdate, 'CUTOVER_ACTIVE=1');
$normalPreCutover = $normalActivation === false ? $normalUpdate : substr($normalUpdate, 0, $normalActivation);
$check(
    $rollbackGuard !== false && $rollbackDelete !== false && $rollbackGuard < $rollbackDelete,
    'update deletes BOT_DIR only inside the active rollback handler'
);
$check(
    $normalCutover !== false
        && $normalActivation !== false
        && $normalCutover < $normalActivation
        && strpos($normalPreCutover, 'sudo rm -rf "$BOT_DIR"') === false,
    'update backs up live BOT_DIR before activating rollback without unconditional pre-cutover deletion'
);
$updateBackupDelete = strpos($update, 'sudo rm -rf "$BACKUP_DIR"');
$updateInstallerCopy = strpos($update, 'sudo cp "$BOT_DIR/install.sh" /root/install.sh');
$updateOwnership = strpos($update, 'sudo chown -R www-data:www-data "$BOT_DIR"');
$updatePermissions = strpos($update, 'sudo chmod -R 755 "$BOT_DIR"');
$updateCliLink = strpos($update, 'sudo ln -sf /root/install.sh /usr/local/bin/mirza');
$check(
    $updateBackupDelete !== false
        && $updateInstallerCopy !== false
        && $updateOwnership !== false
        && $updatePermissions !== false
        && $updateCliLink !== false
        && $updateBackupDelete > $updateInstallerCopy
        && $updateBackupDelete > $updateOwnership
        && $updateBackupDelete > $updatePermissions
        && $updateBackupDelete > $updateCliLink,
    'update deletes its backup only after final required operations'
);
$updateInstallerBackup = strpos($update, 'sudo cp -a /root/install.sh "$INSTALLER_BACKUP"');
$updateInstallerRestore = strpos($update, 'sudo cp -a "$INSTALLER_BACKUP" /root/install.sh');
$updateInstallerRemove = strpos($update, 'sudo rm -f /root/install.sh');
$updateTempCleanup = strrpos($update, 'rm -rf "$TEMP_DIR"');
$check(
    $updateInstallerBackup !== false
        && $updateInstallerRestore !== false
        && $updateInstallerRemove !== false
        && $updateInstallerBackup < $updateInstallerCopy,
    'update backs up and can restore or remove /root/install.sh during rollback'
);
$check(
    $updateTempCleanup !== false
        && $updateTempCleanup > $updateOwnership
        && $updateTempCleanup > $updatePermissions
        && $updateTempCleanup > $updateCliLink,
    'update retains and cleans the installer backup only after final required operations'
);

if ($failures) {
    fwrite(STDERR, "Step 3 installer safety checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Step 3 installer safety checks passed.\n";
