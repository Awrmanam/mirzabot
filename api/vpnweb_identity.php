<?php
/**
 * Non-Telegram commercial identity for the EXISTING Mirza user/Invoice/Wallet.
 * Server-to-server only. Imported by api/vpnapp.php after HMAC verification.
 * Never send a web_ ID to Telegram: it is NOT a Telegram ID.
 *
 * Explicit SQL migration is mandatory; do not automatically change live schema.
 */
function vpnwebIdentityId($appUserId) {
    if (!is_string($appUserId) || !preg_match('/^[a-f0-9]{32}$/D', $appUserId))
        vpnappError(400, 'Invalid authenticated app user ID');
    return 'web_'.$appUserId;
}

function vpnwebIdentitySchemaReady(PDO $db) {
    $table = $db->query("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema=DATABASE() AND table_name='vpnweb_identities'")->fetchColumn();
    if ((int)$table !== 1) return false;
    $type = $db->query("SELECT DATA_TYPE FROM information_schema.columns
        WHERE table_schema=DATABASE() AND table_name='vpnapp_orders' AND column_name='owner_id'
        LIMIT 1")->fetchColumn();
    return in_array(strtolower((string)$type), ['varchar','char','text'], true);
}

function vpnwebCreateIdentity(PDO $db, $appUserId) {
    if (getenv('MIRZA_VPNWEB_IDENTITY_ENABLED') !== '1')
        vpnappError(503, 'Independent registration not enabled');
    $commercialId = vpnwebIdentityId($appUserId);
    if (!vpnwebIdentitySchemaReady($db))
        vpnappError(503, 'Web identity schema migration required');

    $db->beginTransaction();
    try {
        $existing = $db->prepare('SELECT commercial_id FROM vpnweb_identities WHERE app_user_id=? FOR UPDATE');
        $existing->execute([$appUserId]);
        $bound = $existing->fetchColumn();
        if ($bound !== false) {
            if (!hash_equals($commercialId, (string)$bound))
                throw new RuntimeException('Invalid commercial mapping');
            $db->commit();
            return ['commercial_id'=>$commercialId, 'created'=>false];
        }
        // Reuse the EXACT Mirza user table; do not create a second wallet.
        // No fabricated Telegram numeric ID is ever assigned.
        $username = 'web_'.substr($appUserId, 0, 12);
        $invite = bin2hex(random_bytes(8));
        $stmt = $db->prepare("INSERT INTO user
          (id,step,limit_usertest,User_Status,number,Balance,pagenumber,
           username,agent,message_count,last_message_time,affiliates,
           affiliatescount,cardpayment,number_username,namecustom,register,
           verify,codeInvitation,pricediscount,maxbuyagent,joinchannel,
           score,status_cron)
           VALUES (?, 'none', 1, 'Active', 'none', 0, 1,
              ?, 'f', '0','0','0','0','0','100','none',?,
              '1',?,'0','0','0',0,'1')");
        $stmt->execute([$commercialId,$username,(string)time(),$invite]);
        $mapping = $db->prepare("INSERT INTO vpnweb_identities
            (app_user_id,commercial_id,created_at) VALUES(?,?,?)");
        $mapping->execute([$appUserId,$commercialId,time()]);
        $db->commit();
        return ['commercial_id'=>$commercialId, 'created'=>true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        // Never expose SQL schema, balances or sensitive DB errors to a client.
        error_log('[vpnweb] identity transaction failed: '.get_class($e));
        vpnappError(409, 'Identity could not be created; reconcile before retry');
    }
}

function vpnwebResolveIdentity(PDO $db, $commercialId) {
    if (!is_string($commercialId) ||
        !preg_match('/^web_[a-f0-9]{32}$/D', $commercialId)) {
        vpnappError(400, 'Invalid web commercial identity');
    }
    if (!vpnwebIdentitySchemaReady($db))
        vpnappError(503, 'Web identity schema unavailable');
    $query = $db->prepare("SELECT app_user_id FROM vpnweb_identities WHERE commercial_id=?");
    $query->execute([$commercialId]);
    $appUserId = $query->fetchColumn();
    if ($appUserId === false || !hash_equals($commercialId, vpnwebIdentityId($appUserId)))
        vpnappError(403, 'Unknown commercial identity');
    return $commercialId;
}
