<?php
/**
 * Dedicated signed server-to-server connector for the standalone VPN client.
 * Independent of the Telegram Mini App: never edits app/ or api/miniapp.php.
 * Disabled unless MIRZA_VPNAPP_BRIDGE_ENABLED=1.
 *
 * JSON only. Never expose Mirza's API key, panel credentials or user bearer
 * token to the mobile client. Iran API must verify device ownership first.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function vpnappReply($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function vpnappError($code, $text) { vpnappReply($code, ['ok'=>false, 'error'=>$text]); }
function vpnappSetup(PDO $db) {
    $db->exec("CREATE TABLE IF NOT EXISTS vpnapp_rpc_nonces (
        nonce CHAR(32) PRIMARY KEY, created_at BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS vpnapp_orders (
        external_id VARCHAR(64) PRIMARY KEY,
        owner_id BIGINT NOT NULL, kind VARCHAR(16) NOT NULL,
        status VARCHAR(24) NOT NULL, payment_id VARCHAR(32) NULL,
        invoice_id VARCHAR(32) NULL, result_json TEXT NULL,
        created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
        INDEX idx_owner (owner_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function vpnappSigned($raw, $headers, $secret, PDO $db) {
    $ts = (string)($headers['X-Mirza-Timestamp'] ?? '');
    $nonce = strtolower((string)($headers['X-Mirza-Nonce'] ?? ''));
    $sig = strtolower((string)($headers['X-Mirza-Signature'] ?? ''));
    if (!ctype_digit($ts) || strlen($ts)>12 || abs(time()-(int)$ts)>120 ||
        !preg_match('/^[0-9a-f]{32}$/D', $nonce) ||
        !preg_match('/^[0-9a-f]{64}$/D', $sig)) return false;
    $expected = hash_hmac('sha256', $ts."\n".$nonce."\n".$raw, $secret);
    if (!hash_equals($expected,$sig)) return false;
    $db->prepare("DELETE FROM vpnapp_rpc_nonces WHERE created_at<?")->execute([time()-300]);
    try {
        $db->prepare("INSERT INTO vpnapp_rpc_nonces(nonce,created_at) VALUES(?,?)")
            ->execute([$nonce,time()]);
    } catch (PDOException $e) { return false; }
    return true;
}
/** Restrict VPN app writes to designated Telegram IDs during checkout QA. */
function vpnappCheckoutAllowed($u) {
    if (getenv('MIRZA_VPNAPP_CHECKOUT_ENABLED')!=='1') return false;
    $list=trim((string)(getenv('MIRZA_VPNAPP_TEST_USER_IDS')?:''));
    if ($list==='') return true; // Explicitly empty means global release.
    $allowed=array_map('trim',explode(',',$list));
    return in_array((string)$u['id'],$allowed,true);
}
function vpnappUser(PDO $db, $id) {
    if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D',$id)) vpnappError(400,'Invalid account');
    $stmt=$db->prepare("SELECT id,username,agent,Balance,User_Status,pricediscount,namecustom,
                        number_username,affiliates FROM user WHERE id=? LIMIT 1");
    $stmt->execute([$id]); $u=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || $u['User_Status']==='block') vpnappError(403,'Account blocked or unknown');
    return $u;
}
function vpnappPanel(PDO $db, $code) {
    if (!is_string($code) || strlen($code)>64) vpnappError(400,'Invalid panel');
    $stmt=$db->prepare("SELECT * FROM marzban_panel WHERE code_panel=? AND status!='disable' LIMIT 1");
    $stmt->execute([$code]);$p=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) vpnappError(404,'Panel unavailable');
    return $p;
}
function vpnappProduct(PDO $db, $agent, $panel, $code) {
    if (!is_string($code) || strlen($code)>128) vpnappError(400,'Invalid plan');
    $stmt=$db->prepare("SELECT * FROM product WHERE code_product=? AND agent=?
        AND Location IN (?,?,'/all') LIMIT 1");
    $stmt->execute([$code,$agent,$panel['code_panel'],$panel['name_panel']]);
    $p=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p || in_array($panel['name_panel'], (array)json_decode($p['hide_panel']??'[]',true),true))
        vpnappError(404,'Plan unavailable');
    if (!is_numeric($p['price_product']) || $p['price_product']<0)
        vpnappError(400,'Plan price invalid');
    return $p;
}
function vpnappCatalog(PDO $db, $u) {
    $panels=$db->query("SELECT code_panel,name_panel,type,status FROM marzban_panel WHERE status!='disable'")->fetchAll(PDO::FETCH_ASSOC);
    $catalog=[];
    foreach ($panels as $panel) {
        $stmt=$db->prepare("SELECT code_product,name_product,price_product,Volume_constraint,Service_time,note,hide_panel
            FROM product WHERE agent=? AND Location IN (?,?,'/all') LIMIT 150");
        $stmt->execute([$u['agent'],$panel['code_panel'],$panel['name_panel']]);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $hidden=json_decode($p['hide_panel']??'[]',true);
            if (is_array($hidden)&&in_array($panel['name_panel'],$hidden,true)) continue;
            $base=(int)$p['price_product'];
            $discount=(int)$u['pricediscount'];
            $amount=max(0, (int)round($base*(100-$discount)/100));
            $catalog[]=['panel_code'=>$panel['code_panel'],'provider_type'=>$panel['type'],
                'panel_name'=>$panel['name_panel'],
                'plan_code'=>$p['code_product'],'title'=>$p['name_product'],
                'price_toman'=>$amount,'volume_gb'=>(float)$p['Volume_constraint'],
                'days'=>(int)$p['Service_time'],'description'=>$p['note']];
        }
    }
    return $catalog;
}
function vpnappInvoices(PDO $db, $u) {
    $stmt=$db->prepare("SELECT i.id_invoice,i.username,i.Service_location,i.name_product,
        i.Volume,i.Service_time,i.Status,i.time_sell,i.price_product,
        p.code_panel,p.name_panel AS panel_name,p.type AS provider_type
        FROM invoice i LEFT JOIN marzban_panel p ON p.name_panel=i.Service_location
        WHERE i.id_user=? AND i.Status!='Unpaid'
        ORDER BY i.time_sell DESC LIMIT 100");
    $stmt->execute([$u['id']]);
    $items=[];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $inv) {
        $items[]=['id'=>$inv['id_invoice'],'username'=>$inv['username'],
            'panel'=>$inv['Service_location'],
            'panel_code'=>$inv['code_panel'],'panel_name'=>$inv['panel_name']?:$inv['Service_location'],
            'provider_type'=>$inv['provider_type'],'plan_name'=>$inv['name_product'],
            'quota_gb'=>(float)$inv['Volume'],'days'=>(int)$inv['Service_time'],
            'status'=>$inv['Status'],'created_at'=>(int)$inv['time_sell']];
    }
    return $items;
}
function vpnappServicesIndex(PDO $db, $u) {
    // Fast customer-facing list from local invoices: do not query 30 remote
    // panels synchronously when merely opening the VPN app storefront.
    $stmt=$db->prepare("SELECT i.username,i.Service_location,i.name_product,
        i.Volume,i.Service_time,i.Status,i.time_sell,
        p.code_panel AS panel_code,p.name_panel AS panel_name,p.type AS provider_type
        FROM invoice i LEFT JOIN marzban_panel p ON p.name_panel=i.Service_location
        WHERE i.id_user=? AND i.Status!='Unpaid'
        ORDER BY i.time_sell DESC LIMIT 30");
    $stmt->execute([$u['id']]);
    $items=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $inv) {
        $items[]=['username'=>$inv['username'],'panel'=>$inv['Service_location'],
            'panel_code'=>$inv['panel_code'],
            'panel_name'=>$inv['panel_name']?:$inv['Service_location'],
            'provider_type'=>$inv['provider_type'],
            'plan_name'=>$inv['name_product'],'quota_gb'=>(float)$inv['Volume'],
            'days'=>(int)$inv['Service_time'],'status'=>$inv['Status'],
            'subscription_url'=>null];
    }
    return $items;
}
function vpnappServices(PDO $db, $u) {
    $stmt=$db->prepare("SELECT i.username,i.Service_location,i.name_product,i.Volume,i.Service_time,i.Status,
        p.code_panel AS panel_code,p.name_panel AS panel_name,p.type AS provider_type
        FROM invoice i LEFT JOIN marzban_panel p ON p.name_panel=i.Service_location
        WHERE i.id_user=? AND i.Status!='Unpaid' ORDER BY i.time_sell DESC LIMIT 30");
    $stmt->execute([$u['id']]);
    $manage=new ManagePanel();
    $services=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
        $record=['username'=>$invoice['username'],'panel'=>$invoice['Service_location'],
                 'panel_name'=>$invoice['panel_name']?:$invoice['Service_location'],
                 'plan_name'=>$invoice['name_product'],'panel_code'=>$invoice['panel_code'],
                 'provider_type'=>$invoice['provider_type'],'quota_gb'=>(float)$invoice['Volume'],
                 'days'=>(int)$invoice['Service_time'],'status'=>$invoice['Status'],
                 'used_bytes'=>null,'expire'=>null,'subscription_url'=>null,
                 'protocol'=>'unknown'];
        try {
            $live=$manage->DataUser($invoice['Service_location'],$invoice['username']);
            if (is_array($live) && ($live['status']??'')!=='Unsuccessful') {
                $record['used_bytes']=is_numeric($live['used_traffic']??null)?
                    (int)$live['used_traffic']:null;
                $record['expire']=is_numeric($live['expire']??null)?
                    (int)$live['expire']:null;
                $url=$live['subscription_url']??$live['key_subscription_url']??null;
                if (is_string($url) && strlen($url)<2048 && filter_var($url,FILTER_VALIDATE_URL) &&
                    parse_url($url,PHP_URL_SCHEME)==='https')
                    $record['subscription_url']=$url;
                $record['protocol']=$live['type']??'unknown';
                $record['status']=$live['status']??$invoice['Status'];
            }
        } catch (Throwable $e) {
            // Metadata still visible when a panel cannot be reached.
        }
        $services[]=$record;
    }
    return $services;
}
function vpnappRenewWallet(PDO $db, $u, $key, $username, $panelCode, $planCode, $existingOrder = false) {
    if (!is_string($username) || strlen($username)>128 || $username==='') vpnappError(400,'Invalid service');
    $stmt=$db->prepare("SELECT * FROM invoice WHERE id_user=? AND username=? AND Status!='Unpaid' LIMIT 1");
    $stmt->execute([$u['id'],$username]);$inv=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) vpnappError(404,'Service does not belong to user');
    $panel=vpnappPanel($db,$panelCode);
    if ($inv['Service_location']!==$panel['name_panel']) vpnappError(400,'Service panel mismatch');
    $plan=vpnappProduct($db,$u['agent'],$panel,$planCode);
    $price=(int)round((int)$plan['price_product']*(100-(int)$u['pricediscount'])/100);
    if ($price<=0 || $price>1000000) vpnappError(400,'Renewal amount unsupported');
    // A pending external operation is not retried by changing the client key.
    if (!$existingOrder) vpnappNewOrder($db,$u,'renewal',$key);
    $reserve=$db->prepare("UPDATE user SET Balance=Balance-? WHERE id=? AND Balance>=? AND User_Status!='block'");
    $reserve->execute([$price,$u['id'],$price]);
    if ($reserve->rowCount()!==1) {
        vpnappUpdate($db,$key,'failed',['result'=>['id'=>$key,'status'=>'failed','reason'=>'insufficient_wallet']]);
        vpnappError(409,'Insufficient wallet balance');
    }
    $manage=new ManagePanel();
    try {
        $out=$manage->extend($panel['Methodextend'], $plan['Volume_constraint'],
                            $plan['Service_time'],$username,$plan['code_product'],
                            $panel['code_panel']);
    } catch (Throwable $e) {
        // Leave charged funds pending review: the panel may have renewed just
        // before a network timeout. Never retry automatically.
        vpnappUpdate($db,$key,'unknown',['result'=>['id'=>$key,
            'status'=>'unknown','needs_review'=>true]]);
        return ['id'=>$key,'status'=>'unknown','needs_review'=>true];
    }
    if (!is_array($out) || empty($out['status'])) {
        $db->prepare("UPDATE user SET Balance=Balance+? WHERE id=?")
            ->execute([$price,$u['id']]);
        vpnappUpdate($db,$key,'failed',['result'=>['id'=>$key,'status'=>'failed',
            'reason'=>'panel_failed']]);
        return ['id'=>$key,'status'=>'failed','reason'=>'panel_failed'];
    }
    $db->prepare("UPDATE invoice SET Status='active',name_product=?,price_product=?
        WHERE id_user=? AND username=?")->execute([$plan['name_product'],$price,$u['id'],$username]);
    $result=['id'=>$key,'status'=>'fulfilled','service'=>$username,
             'panel_name'=>$panel['name_panel'],'plan_name'=>$plan['name_product'],
             'amount_toman'=>$price];
    vpnappUpdate($db,$key,'fulfilled',['result'=>$result]);
    vpnappNotify('✅ تمدید از اپ VPN'. "\n".
        'کاربر: '.htmlspecialchars($u['id'],ENT_QUOTES,'UTF-8'). "\n".
        'سرویس: '.htmlspecialchars($username,ENT_QUOTES,'UTF-8'). "\n".
        'مبلغ: '.number_format($price).' تومان',$u['id']);
    return $result;
}

function vpnappCardRenew(PDO $db, $u, $key, $username, $panelCode, $planCode) {
    if (!is_string($username) || strlen($username)>128 || $username==='')
        vpnappError(400,'Invalid renewal service');
    $stmt=$db->prepare("SELECT * FROM invoice WHERE id_user=? AND username=?
        AND Status!='Unpaid' LIMIT 1");
    $stmt->execute([$u['id'],$username]);$invoice=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) vpnappError(404,'Service is not owned');
    $panel=vpnappPanel($db,$panelCode);
    if ($panel['name_panel']!==$invoice['Service_location'])
        vpnappError(400,'Service is on another panel');
    $plan=vpnappProduct($db,$u['agent'],$panel,$planCode);
    $price=(int)round((int)$plan['price_product']*(100-(int)$u['pricediscount'])/100);
    $cap=(int)(getenv('MIRZA_VPNAPP_CARD_CAP_TOMAN')?:150000);
    if ($price<1000 || $price>$cap) vpnappError(400,'Card renewal amount unsupported');
    $card=$db->query("SELECT cardnumber,namecard FROM card_number ORDER BY RAND() LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC);
    if (!$card || !preg_match('/^[0-9]{16}$/D',(string)$card['cardnumber']))
        vpnappError(503,'Card not configured');
    vpnappNewOrder($db,$u,'renewal_card',$key);
    $paymentId=bin2hex(random_bytes(5));
    // The original DirectPayment fallback tops up the Mirza wallet. A separate
    // idempotent cron worker then spends that credit and renews the owned
    // service, avoiding the legacy getextenduser branch that clears balance.
    $db->prepare("INSERT INTO Payment_report
        (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice)
        VALUES(?,?,?,?, 'Unpaid','cart to cart',?)")->execute([
        $u['id'],$paymentId,date('Y/m/d H:i:s'),$price,'vpnapp_renew|'.$username]);
    $result=['id'=>$key,'method'=>'card','amount_toman'=>$price,
        'card_number'=>$card['cardnumber'],'card_holder'=>$card['namecard'],
        'payment_id'=>$paymentId,'status'=>'awaiting_receipt'];
    vpnappUpdate($db,$key,'awaiting_receipt',['payment_id'=>$paymentId,
        'invoice_id'=>$invoice['id_invoice'],
        'result'=>['public'=>$result,'username'=>$username,
                  'panel_code'=>$panelCode,'plan_code'=>$planCode]]);
    return $result;
}

function vpnappReceiptInfo(PDO $db, $u, $order) {
    $stmt=$db->prepare("SELECT o.external_id,o.kind,o.status,o.invoice_id,o.payment_id,o.result_json,
        p.payment_Status AS payment_status,i.Status AS service_status,
        i.username AS service_username,i.Service_location AS panel_name,
        i.name_product AS plan_name,i.price_product AS invoice_amount
        FROM vpnapp_orders o LEFT JOIN Payment_report p ON p.id_order=o.payment_id
        LEFT JOIN invoice i ON i.id_invoice=o.invoice_id AND i.id_user=o.owner_id
        WHERE o.owner_id=? AND o.external_id=? LIMIT 1");
    $stmt->execute([$u['id'],$order]);$result=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$result) vpnappError(404,'Order not found');
    $stored=json_decode($result['result_json']??'null',true);
    if (is_array($stored)) {
        $public=isset($stored['public']) && is_array($stored['public'])
            ? $stored['public'] : $stored;
        foreach (['card_number','card_holder','amount_toman'] as $field) {
            if (isset($public[$field])) $result[$field]=$public[$field];
        }
    }
    unset($result['result_json']);
    // Only the original invoice's *active* status confirms provisioning.
    // An unknown panel outcome with an Unpaid invoice needs reconciliation;
    // do NOT provision, refund or charge a second time here.
    $isActive=$result['service_status']==='active';
    // A renewal's invoice might already be active *before* extension,
    // so its active status cannot prove that the renewal succeeded.
    $walletBought=$result['kind']==='wallet';
    if ($isActive && $walletBought &&
        in_array($result['status'],['unknown','processing','fulfilled'],true)) {
        $result['status']='fulfilled';
        $db->prepare("UPDATE vpnapp_orders SET status='fulfilled',updated_at=?
            WHERE external_id=? AND owner_id=? AND status IN ('unknown','processing')")
            ->execute([time(),$order,$u['id']]);
    } elseif ($result['kind']==='card' &&
        $result['payment_status']==='paid' && $isActive) {
        $result['status']='fulfilled';
    } elseif ($result['payment_status']==='reject' ||
              $result['payment_status']==='expire') {
        $result['status']='rejected';
    } elseif ($result['kind']==='card' && $result['payment_status']==='waiting') {
        $result['status']='waiting';
    }
    // Supply a friendly, verified outcome for the authenticated owner, not
    // internal invoice or panel credentials.
    if ($result['status']==='fulfilled' && $isActive) {
        $result['service']=$result['service_username'];
        if (!isset($result['amount_toman'])) {
            $result['amount_toman']=(int)$result['invoice_amount'];
        }
        // Restore a valid direct import URL after a mobile timeout/restart.
        // Unverified links and non-V2Ray protocols are never auto-imported.
        if (is_array($stored) && isset($stored['subscription_url']) &&
            is_string($stored['subscription_url']) &&
            preg_match('/^https:\/\//i',$stored['subscription_url'])) {
            $result['subscription_url']=$stored['subscription_url'];
        }
    }
    $result['id']=$result['external_id'];
    unset($result['external_id'],$result['invoice_amount'],$result['service_username']);
    return $result;
}
function vpnappNotify($text, $buttonId = null, $photo = null, $paymentId = null) {
    global $pdo, $APIKEY;
    $setting=$pdo->query("SELECT Channel_Report FROM setting LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $topic=$pdo->prepare("SELECT idreport FROM topicid WHERE report='paymentreport' LIMIT 1");
    $topic->execute();$thread=$topic->fetchColumn();
    if (!($setting['Channel_Report']??'')) return;
    $fields=['chat_id'=>$setting['Channel_Report'],'caption'=>$text,'text'=>$text,
             'parse_mode'=>'HTML'];
    if ($thread) $fields['message_thread_id']=$thread;
    if ($buttonId) {
        $buttons=[];
        if ($paymentId && preg_match('/^[0-9a-f]{10}$/D',(string)$paymentId)) {
            $buttons[]=[
                ['text'=>'✅ تأیید رسید','callback_data'=>'Confirm_pay_'.$paymentId],
                ['text'=>'❌ رد رسید','callback_data'=>'reject_pay_'.$paymentId]
            ];
        }
        $buttons[]=[['text'=>'مدیریت مشتری','callback_data'=>'manageuser_'.$buttonId],
                    ['text'=>'مسدودسازی','callback_data'=>'blockuserfake_'.$buttonId]];
        $fields['reply_markup']=json_encode(['inline_keyboard'=>$buttons]);
    }
    if ($photo) {
        unset($fields['text']);$fields['photo']=new CURLFile($photo);
        $method='sendPhoto';
    } else {
        unset($fields['caption']);$method='sendMessage';
    }
    $ch=curl_init('https://api.telegram.org/bot'.$APIKEY.'/'.$method);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8]);
    curl_exec($ch);curl_close($ch);
}
function vpnappExisting(PDO $db, $u, $key) {
    $stmt=$db->prepare("SELECT * FROM vpnapp_orders WHERE external_id=? AND owner_id=?");
    $stmt->execute([$key,$u['id']]);return $stmt->fetch(PDO::FETCH_ASSOC);
}
function vpnappNewOrder(PDO $db, $u, $kind, $key) {
    if (!is_string($key) || !preg_match('/^[0-9a-f-]{36}$/D',$key))
        vpnappError(400,'Invalid order key');
    // Serialize checkouts from the same Mirza user, even with different
    // client-generated UUIDs, so two requests cannot race the pending limit.
    $lock=$db->prepare("SELECT GET_LOCK(?,10)");
    $lock->execute(['vpnapp_account_'.$u['id']]);
    if ((int)$lock->fetchColumn()!==1) vpnappError(409,'Checkout currently busy');
    $stmt=$db->prepare("SELECT COUNT(*) FROM vpnapp_orders WHERE owner_id=?
        AND (status='unknown' OR
             (status IN ('processing','awaiting_receipt','waiting') AND created_at>?))");
    $stmt->execute([$u['id'],time()-86400]);
    if ((int)$stmt->fetchColumn()>0) vpnappError(409,'A previous order is still pending');
    $stmt=$db->prepare("INSERT INTO vpnapp_orders(external_id,owner_id,kind,status,created_at,updated_at) VALUES(?,?,?,?,?,?)");
    $stmt->execute([$key,$u['id'],$kind,'processing',time(),time()]);
}
function vpnappUpdate(PDO $db, $key, $status, $fields=[]) {
    $stmt=$db->prepare("UPDATE vpnapp_orders SET status=?,payment_id=COALESCE(?,payment_id),invoice_id=COALESCE(?,invoice_id),result_json=COALESCE(?,result_json),updated_at=? WHERE external_id=?");
    $stmt->execute([$status,$fields['payment_id']??null,$fields['invoice_id']??null,
        isset($fields['result'])?json_encode($fields['result'],JSON_UNESCAPED_UNICODE):null,time(),$key]);
}
function vpnappCardOrder(PDO $db, $u, $plan, $panel, $key) {
    $amount=(int)round((int)$plan['price_product']*(100-(int)$u['pricediscount'])/100);
    $cap=(int)(getenv('MIRZA_VPNAPP_CARD_CAP_TOMAN')?:150000);
    if ($amount<1000 || $amount>$cap) vpnappError(400,'Amount exceeds app card limit');
    // Keep the existing mirza card flow/cron: Payment_report -> DirectPayment.
    $card=$db->query("SELECT cardnumber,namecard FROM card_number ORDER BY RAND() LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$card || !preg_match('/^[0-9]{16}$/D',(string)$card['cardnumber']))
        vpnappError(503,'Card not configured');
    $invoiceId=bin2hex(random_bytes(4));
    $paymentId=bin2hex(random_bytes(5));
    // Avoid panel-specific username collisions with random, panel-safe suffix.
    $name='app_'.substr(hash('sha256',$u['id'].$invoiceId),0,15);
    $max=(int)$db->query("SELECT COUNT(*) FROM invoice WHERE username=".$db->quote($name))->fetchColumn();
    if ($max) vpnappError(409,'Duplicate service username');
    $ins=$db->prepare("INSERT INTO invoice(id_user,id_invoice,username,time_sell,Service_location,name_product,
        price_product,Volume,Service_time,Status,note,refral,notifctions)
        VALUES(?,?,?,?,?,?,?,?,?,'Unpaid','',?,?)");
    $ins->execute([$u['id'],$invoiceId,$name,time(),$panel['name_panel'],$plan['name_product'],
        $amount,$plan['Volume_constraint'],$plan['Service_time'],$u['affiliates'],
        '{"volume":false,"time":false}']);
    $pay=$db->prepare("INSERT INTO Payment_report(id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice)
        VALUES(?,?,?,?, 'Unpaid','cart to cart',?)");
    $pay->execute([$u['id'],$paymentId,date('Y/m/d H:i:s'),$amount,'getconfigafterpay|'.$name]);
    $result=['id'=>$key,'method'=>'card','amount_toman'=>$amount,
        'card_number'=>$card['cardnumber'],'card_holder'=>$card['namecard'],
        'payment_id'=>$paymentId,'status'=>'awaiting_receipt'];
    vpnappUpdate($db,$key,'awaiting_receipt',['payment_id'=>$paymentId,
        'invoice_id'=>$invoiceId,'result'=>$result]);
    return $result;
}
function vpnappSubmitReceipt(PDO $db, $u, $key, $encoded) {
    if (!is_string($encoded) || strlen($encoded)>2800000 ||
        !preg_match('/^[A-Za-z0-9+\/=]+$/D',$encoded))
        vpnappError(400,'Invalid image data');
    $data=base64_decode($encoded,true);
    if ($data===false || strlen($data)<200 || strlen($data)>2000000)
        vpnappError(400,'Invalid image size');
    $kind=(new finfo(FILEINFO_MIME_TYPE))->buffer($data);
    $image=@getimagesizefromstring($data);
    if (!in_array($kind,['image/png','image/jpeg'],true) || !$image ||
        $image[0]>5000 || $image[1]>5000)
        vpnappError(400,'Unsupported image');
    $stmt=$db->prepare("SELECT payment_id,status FROM vpnapp_orders WHERE owner_id=? AND external_id=? FOR UPDATE");
    $db->beginTransaction();
    $stmt->execute([$u['id'],$key]);$order=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {$db->rollBack();vpnappError(404,'Order not found');}
    if ($order['status']==='waiting') {$db->commit();return ['status'=>'waiting'];}
    if ($order['status']!=='awaiting_receipt') {
        $db->rollBack();vpnappError(409,'Receipt already submitted or order closed');
    }
    $update=$db->prepare("UPDATE Payment_report SET payment_Status='waiting',at_updated=?,dec_not_confirmed='VPN app receipt'
        WHERE id_order=? AND id_user=? AND payment_Status='Unpaid'");
    $update->execute([date('Y/m/d H:i:s'),$order['payment_id'],$u['id']]);
    if ($update->rowCount()!=1) {$db->rollBack();vpnappError(409,'Payment is not pending');}
    $db->prepare("UPDATE vpnapp_orders SET status='waiting',updated_at=? WHERE external_id=?")
        ->execute([time(),$key]);
    $db->commit();
    // Notifications must never determine payment status. Send best-effort
    // receipt preview to the existing Mirza report group, not a third bot.
    $tmp=tempnam(sys_get_temp_dir(),'vpnreceipt_');
    if ($tmp!==false) {
        try {
            file_put_contents($tmp,$data,LOCK_EX);
            vpnappNotify('🧾 رسید خرید از اپ VPN'. "\n".
                'کاربر: '.htmlspecialchars($u['id'],ENT_QUOTES,'UTF-8'). "\n".
                'پیگیری: '.htmlspecialchars($order['payment_id'],ENT_QUOTES,'UTF-8'). "\n".
                'وضعیت: منتظر تأیید خودکار میرزا', $u['id'],$tmp,$order['payment_id']);
        } finally { @unlink($tmp); }
    }
    return ['status'=>'waiting'];
}
function vpnappWalletPurchase(PDO $db, $u, $panel, $plan, $key) {
    // Mirza's own panel manager and invoice records are authoritative.
    // A fresh app user must NOT depend on ever logging into Telegram Mini App
    // to obtain a legacy miniapp bearer token.
    $amount=(int)round((int)$plan['price_product']*(100-(int)$u['pricediscount'])/100);
    if ($amount<=0 || $amount>1000000) vpnappError(400,'Unsupported wallet plan price');
    if (!empty($plan['one_buy_status'])) {
        $stmt=$db->prepare("SELECT COUNT(*) FROM invoice WHERE id_user=? AND Status!='Unpaid'");
        $stmt->execute([$u['id']]);
        if ((int)$stmt->fetchColumn()>0) vpnappError(409,'One-time plan unavailable');
    }
    $id=bin2hex(random_bytes(4));
    $suffix=bin2hex(random_bytes(7));
    $username=generateUsername($u['id'],$panel['MethodUsername'],$u['username'],
        $suffix, 'vpn_'.$suffix,$panel['namecustom'],$u['namecustom']);
    if (!is_string($username) || strlen($username)<3 ||
        !preg_match('/^[A-Za-z0-9_.-]{3,128}$/D',$username))
        $username='vpn_'.$suffix;
    $stmt=$db->prepare("SELECT COUNT(*) FROM invoice WHERE username=?");
    $stmt->execute([$username]);
    if ((int)$stmt->fetchColumn()>0) vpnappError(409,'Generated username already exists');
    $invoice=$db->prepare("INSERT INTO invoice(id_user,id_invoice,username,time_sell,Service_location,
        name_product,price_product,Volume,Service_time,Status,note,refral,notifctions)
        VALUES(?,?,?,?,?,?,?,?,?,'Unpaid','',?,?)");
    $invoice->execute([$u['id'],$id,$username,time(),$panel['name_panel'],
        $plan['name_product'],$amount,$plan['Volume_constraint'],
        $plan['Service_time'],$u['affiliates'],'{"volume":false,"time":false}']);
    $reserve=$db->prepare("UPDATE user SET Balance=Balance-? WHERE id=? AND Balance>=?
                           AND User_Status!='block'");
    $reserve->execute([$amount,$u['id'],$amount]);
    if ($reserve->rowCount()!==1) {
        vpnappUpdate($db,$key,'failed',['invoice_id'=>$id,
            'result'=>['id'=>$key,'status'=>'failed','reason'=>'insufficient_wallet']]);
        return ['id'=>$key,'status'=>'failed','reason'=>'insufficient_wallet'];
    }
    $manage=new ManagePanel();
    try {
        $days=(int)$plan['Service_time'];
        $expires=$days>0?strtotime('+'.$days.' days'):0;
        $limit=(float)$plan['Volume_constraint']*1073741824;
        $output=$manage->createUser($panel['name_panel'],$plan['code_product'],
            $username,['expire'=>$expires,'data_limit'=>$limit,
                       'from_id'=>$u['id'],'username'=>$u['username'],'type'=>'buy']);
    } catch (Throwable $e) {
        // Panel may have created the user before a timeout. Never blind-retry.
        vpnappUpdate($db,$key,'unknown',['invoice_id'=>$id,
            'result'=>['id'=>$key,'status'=>'unknown','needs_review'=>true]]);
        return ['id'=>$key,'status'=>'unknown','needs_review'=>true];
    }
    if (!is_array($output) || empty($output['username'])) {
        $db->prepare("UPDATE user SET Balance=Balance+? WHERE id=?")
            ->execute([$amount,$u['id']]);
        vpnappUpdate($db,$key,'failed',['invoice_id'=>$id,
            'result'=>['id'=>$key,'status'=>'failed','reason'=>'panel_failed']]);
        return ['id'=>$key,'status'=>'failed','reason'=>'panel_failed'];
    }
    $db->prepare("UPDATE invoice SET Status='active' WHERE id_user=? AND id_invoice=?")
        ->execute([$u['id'],$id]);
    // Mirror Mirza's counters for sequential username schemes.
    if (in_array($panel['MethodUsername'],[
        'نام کاربری + عدد به ترتیب','آیدی عددی+عدد ترتیبی',
        'متن دلخواه نماینده + عدد ترتیبی','متن دلخواه + عدد ترتیبی'],true)) {
        $db->prepare("UPDATE user SET number_username=number_username+1 WHERE id=?")
            ->execute([$u['id']]);
    }
    if ($panel['MethodUsername']==='متن دلخواه + عدد ترتیبی') {
        $db->exec("UPDATE setting SET numbercount=numbercount+1");
    }
    $result=['id'=>$key,'status'=>'fulfilled','invoice_id'=>$id,
             'service'=>$username,'panel_name'=>$panel['name_panel'],
             'plan_name'=>$plan['name_product'],'amount_toman'=>$amount];
    // Subscription URLs can be returned only over authenticated app API.
    // Other protocols may not have a directly importable V2Ray HTTPS URL.
    if (isset($output['subscription_url']) &&
        is_string($output['subscription_url']) &&
        preg_match('/^https:\/\//i',$output['subscription_url'])) {
        $result['subscription_url']=$output['subscription_url'];
    }
    vpnappUpdate($db,$key,'fulfilled',['invoice_id'=>$id,'result'=>$result]);
    vpnappNotify('✅ خرید با کیف پول از اپ VPN'. "\n".
        'کاربر: '.htmlspecialchars($u['id'],ENT_QUOTES,'UTF-8'). "\n".
        'پلن: '.htmlspecialchars($plan['name_product'],ENT_QUOTES,'UTF-8'). "\n".
        'سرویس: '.htmlspecialchars($username,ENT_QUOTES,'UTF-8'). "\n".
        'مبلغ: '.number_format($amount).' تومان', $u['id']);
    return $result;
}

if (defined('MIRZA_VPNAPP_LIBRARY_ONLY') && MIRZA_VPNAPP_LIBRARY_ONLY) return;

if (getenv('MIRZA_VPNAPP_BRIDGE_ENABLED')!=='1')
    vpnappError(503,'VPN app integration is disabled');
$secret=(string)getenv('MIRZA_APP_BRIDGE_SECRET');
$raw=file_get_contents('php://input');
if (strlen($secret)<32 || !is_string($raw) || strlen($raw)>2900000)
    vpnappError(403,'Bridge unavailable');
try {
    vpnappSetup($pdo);
    $headers=getallheaders();
    $normalized=[];
    foreach($headers as $key=>$value) $normalized[strtolower($key)]=$value;
    $signed=['X-Mirza-Timestamp'=>$normalized['x-mirza-timestamp']??'',
             'X-Mirza-Nonce'=>$normalized['x-mirza-nonce']??'',
             'X-Mirza-Signature'=>$normalized['x-mirza-signature']??''];
    if (!vpnappSigned($raw,$signed,$secret,$pdo)) vpnappError(403,'Invalid signature');
    $input=json_decode($raw,true);
    if (!is_array($input)) vpnappError(400,'Invalid request');
    $u=vpnappUser($pdo,(string)($input['telegram_id']??''));
    $action=$input['action']??'';
    if ($action==='account') vpnappReply(200,['ok'=>true,'telegram_id'=>(string)$u['id'],
        'balance_toman'=>(int)$u['Balance'],'username'=>$u['username'],
        'agent'=>$u['agent'],'blocked'=>false]);
    if ($action==='catalog') vpnappReply(200,['ok'=>true,'items'=>vpnappCatalog($pdo,$u)]);
    if ($action==='invoices') vpnappReply(200,['ok'=>true,'items'=>vpnappInvoices($pdo,$u)]);
    if ($action==='capabilities') vpnappReply(200, ['ok'=>true,
        'checkout_enabled'=>vpnappCheckoutAllowed($u)]);
    if ($action==='services_index') vpnappReply(200,['ok'=>true,'items'=>vpnappServicesIndex($pdo,$u)]);
    if ($action==='services') vpnappReply(200,['ok'=>true,'items'=>vpnappServices($pdo,$u)]);
    if ($action==='order_status') vpnappReply(200,['ok'=>true,
        'order'=>vpnappReceiptInfo($pdo,$u,(string)($input['order_id']??''))]);
    if ($action==='receipt') {
        if (!vpnappCheckoutAllowed($u))
            vpnappError(403,'Checkout disabled');
        vpnappReply(200,['ok'=>true,'receipt'=>vpnappSubmitReceipt($pdo,$u,
            (string)($input['order_id']??''),$input['image_base64']??'')]);
    }
    if ($action==='renew_card') {
        if (!vpnappCheckoutAllowed($u))
            vpnappError(403,'Checkout disabled');
        $key=(string)($input['order_id']??'');
        $previous=vpnappExisting($pdo,$u,$key);
        if ($previous) {
            $r=json_decode($previous['result_json']??'null',true);
            if (is_array($r) && isset($r['public'])) $r=$r['public'];
            vpnappReply(200,['ok'=>true,'order'=>$r?:[
                'id'=>$key,'status'=>$previous['status']]]);
        }
        vpnappReply(200,['ok'=>true,'order'=>vpnappCardRenew($pdo,$u,$key,
            $input['username']??null,$input['panel_code']??null,$input['plan_code']??null)]);
    }
    if ($action==='renew_wallet') {
        if (!vpnappCheckoutAllowed($u))
            vpnappError(403,'Checkout disabled');
        $key=(string)($input['order_id']??'');
        $previous=vpnappExisting($pdo,$u,$key);
        if ($previous) {
            vpnappReply(200,['ok'=>true,'order'=>json_decode($previous['result_json']??'null',true)
                ?:['id'=>$key,'status'=>$previous['status']]]);
        }
        $result=vpnappRenewWallet($pdo,$u,$key,
            $input['username']??null,$input['panel_code']??null,$input['plan_code']??null);
        vpnappReply(200,['ok'=>true,'order'=>$result]);
    }
    if ($action==='checkout') {
        if (!vpnappCheckoutAllowed($u))
            vpnappError(403,'Checkout disabled');
        $key=(string)($input['order_id']??'');
        $previous=vpnappExisting($pdo,$u,$key);
        if ($previous) {
            $result=json_decode($previous['result_json']??'null',true);
            if (is_array($result) && isset($result['public'])) $result=$result['public'];
            vpnappReply(200,['ok'=>true,'order'=>$result?:[
                'id'=>$key,'status'=>$previous['status']]]);
        }
        $panel=vpnappPanel($pdo,$input['panel_code']??null);
        $plan=vpnappProduct($pdo,$u['agent'],$panel,$input['plan_code']??null);
        $method=$input['method']??'card';
        if (!in_array($method,['card','wallet'],true))
            vpnappError(400,'Unknown payment method');
        // Rejections and idempotency are performed within a dedicated bridge
        // order table. Other Mini App purchases are NOT modified.
        vpnappNewOrder($pdo,$u,$method,$key);
        if ($method==='card') {
            $result=vpnappCardOrder($pdo,$u,$plan,$panel,$key);
        } else {
            $result=vpnappWalletPurchase($pdo,$u,$panel,$plan,$key);
        }
        vpnappReply(200,['ok'=>true,'order'=>$result]);
    }
    vpnappError(400,'Unknown action');
} catch (Throwable $e) {
    error_log('[vpnapp] '.get_class($e).' while processing integration request');
    vpnappError(500,'Integration error');
}
