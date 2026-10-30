<?php
/**
 * Bank-notification candidate lookup shared by legacy Telegram + VPN app.
 * No confirmation, wallet change or panel provisioning happens here.
 *
 * A bank SMS identifies an amount, not a Telegram customer or order.
 * Confirm automatically ONLY when exactly one eligible card order matches.
 * Same-amount ambiguity must be sent for manual reconciliation.
 */
function mirzaBankCandidateFromRows(array $rows): ?array {
    if (count($rows) !== 1) return null;
    $candidate=$rows[0];
    if (!is_array($candidate)) return null;
    // App renewals currently use an optional separate wallet-spend worker,
    // not the legacy DirectPayment renewal branch. Do not auto-mark them
    // paid until that worker is installed, audited and reconciled.
    if (str_starts_with((string)($candidate['id_invoice']??''),'vpnapp_renew|')) {
        error_log('[Mirza bank auto] App card renewal requires manual reconciliation; automatic fulfilment is not configured');
        return null;
    }
    return $candidate;
}
function mirzaFindUniqueBankCardPayment(PDO $db, int $amount): ?array {
    if ($amount < 1) return null;
    $statement = $db->prepare("SELECT * FROM Payment_report
        WHERE price=? AND Payment_Method='cart to cart'
          AND payment_Status IN ('waiting','Unpaid')
        ORDER BY CASE WHEN payment_Status='waiting' THEN 0 ELSE 1 END,
                 at_updated DESC LIMIT 2");
    $statement->execute([$amount]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 1) {
        // Do not print customer identifiers or bank notification details.
        error_log('[Mirza bank auto] Multiple open card orders with the same amount; manual reconciliation required');
        return null;
    }
    return mirzaBankCandidateFromRows($rows);
}
