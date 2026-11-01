<?php
/** Staging-only regression of the reported invoice/user ID collation mismatch.
 * Original MySQL 8 schema: invoice.id_user utf8mb4_unicode_ci,
 * vpnapp_orders.owner_id utf8mb4_0900_ai_ci.
 * Absolutely no DDL/DML, production connections or customer data printed.
 */
declare(strict_types=1);
if (getenv('APP_ENV') !== 'staging' || getenv('MIRZA_STAGING_PANEL_MOCK') !== '1') {
    throw new RuntimeException('DISPOSABLE STAGING ONLY');
}
$source=file_get_contents(__DIR__.'/../api/vpnapp.php');
$guard='i.id_user COLLATE utf8mb4_unicode_ci = o.owner_id COLLATE utf8mb4_unicode_ci';
if (!is_string($source) || substr_count($source,$guard)!==2) {
    throw new RuntimeException('Both receipt/order and admin joins must be explicit');
}
require __DIR__.'/../config.php';
if (!isset($pdo) || !$pdo instanceof PDO) throw new RuntimeException('Missing staging PDO');
// Compile/execute the same collation expression against the genuine stage
// MySQL column definitions without returning user records.
$pdo->query("SELECT COUNT(*) AS matches
    FROM vpnapp_orders o LEFT JOIN invoice i
    ON i.id_user COLLATE utf8mb4_unicode_ci =
       o.owner_id COLLATE utf8mb4_unicode_ci
    WHERE 1=0")->fetchColumn();
echo "PASS: two explicit invoice owner collations; actual isolated MySQL join accepted\n";
