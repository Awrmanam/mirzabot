<?php
ini_set('error_log', 'error.log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/card_auto_match.php';
$ManagePanel = new ManagePanel();
require __DIR__ . '/../vendor/autoload.php';
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

$PaySetting = mysqli_fetch_assoc(mysqli_query($connect, "SELECT (ValuePay) FROM PaySetting WHERE NamePay = 'statuscardautoconfirm'"))['ValuePay'];
if($PaySetting == "onautoconfirm"){
$name_post = array_keys($_POST);
$name_post = array_map('htmlspecialchars', $name_post);
$name_post = preg_split("/_+/", $name_post[0], -1);
$secret_key = select("admin", "*", "password", base64_decode($name_post[0]), "count");
if($secret_key == 0)return;
$name_bank = $name_post[1];
$valuepost = $_POST["{$name_post[0]}_$name_bank"];
$setting = mysqli_fetch_assoc(mysqli_query($connect, "SELECT * FROM setting"));
$admin_ids = array_column(mysqli_fetch_all(mysqli_query($connect, "SELECT (id_admin) FROM admin"), MYSQLI_ASSOC), 'id_admin');
 $datatextbotget = select("textbot", "*",null ,null ,"fetchAll");
    $datatxtbot = array();
foreach ($datatextbotget as $row) {
    $datatxtbot[] = array(
        'id_text' => $row['id_text'],
        'text' => $row['text']
    );
}
$datatextbot = array(
    'textafterpay' => '',
    'textaftertext' => '',
    'textmanual' => '',
    'textselectlocation' => '',
    'textafterpayibsng' => ''
);
foreach ($datatxtbot as $item) {
    if (isset($datatextbot[$item['id_text']])) {
        $datatextbot[$item['id_text']] = $item['text'];
    }
}
if($name_bank == 'blu'){
$pattern = "/(\d[\d,]+) ریال به حساب شما نشست\./u";
preg_match($pattern, $valuepost, $matches);
if (isset($matches[1])) {
    $amountString = str_replace(',', '', $matches[1]);
    $amount = intval($amountString);
    $amountInteger = intval($amount) * 0.1;
}}
elseif($name_bank == "meli"){
$pattern = '/انتقال:(.*?)[+\-]/u';
preg_match($pattern, $valuepost, $matches);
if (isset($matches[1])) {
    $amount = str_replace([',', '-'], '', $matches[1]);
    $amountInteger = intval($amount) * 0.1;
}}
elseif($name_bank == "grdsh"){
preg_match('/مبلغ: ([0-9,]+)/u',$valuepost, $matches);
if (isset($matches[1])) {
    $amountInteger = str_replace(',', '', $matches[1]) * 0.1;
}}
elseif($name_bank == "sadhrat"){
preg_match('/انتقال: ([\d,]+)/', $valuepost, $matches);
if (isset($matches[1])) {
    $amountInteger = str_replace(',', '', $matches[1]) * 0.1;
}}
elseif($name_bank == "melet"){
preg_match('/واریز(\d{1,3}(?:,\d{3})*)/u', $valuepost, $matches);
if (isset($matches[1])) {
    $amountInteger = str_replace(',', '', $matches[1])* 0.1;
}}
elseif($name_bank  == "terjart"){
if(preg_match('/واریز\s*:\s*([\d,]+)/u', $valuepost, $matches)) {
    $amountInteger = str_replace(',', '', $matches[1]) * 0.1;
}}
elseif($name_bank  == "keshavarsi"){
if(preg_match('/واريز(\d+(?:,\d+)*)/', $valuepost, $matches)) {
    $amountInteger = str_replace(',', '', $matches[1])*0.1;
}}
elseif($name_bank  == "resalet"){
if(preg_match('/\+([\d,]+)/', $valuepost, $matches)) {
    $amountInteger = str_replace(',', '', $matches[1])*0.1;
}}
elseif($name_bank  == "sheahr"){
if(preg_match('/مبلغ:(\d+(?:,\d+)*)ريال//', $valuepost, $matches)) {
    $amountInteger = str_replace(',', '', $matches[1])*0.1;
}}
elseif($name_bank  == "maskan"){
if(preg_match('/انتقال اينترنت:\D*([\d,]+)/u', $valuepost, $matches)) {
    $amountInteger = str_replace(',', '', $matches[1])*0.1;
}}elseif($name_bank  == "parsian"){
if(preg_match('/مبلغ:(\d{1,3}(?:,\d{3})*)\+/', $valuepost, $matches)) {
    file_put_contents('ss',json_encode($matches));
    $amountInteger = str_replace(',', '', $matches[1])*0.1;
}}elseif($name_bank  == "sphe"){
if(preg_match('/مبلغ:\s*([\d,]+)\s*ريال/', $valuepost, $matches)) {
    $amountInteger = str_replace(',', '', $matches[1])*0.1;
}}elseif($name_bank  == "paselc"){
if(preg_match('/\+([0-9,]+)/', $valuepost, $matches)) {
    $amountInteger = str_replace(',', '', $matches[1])*0.1;
}}elseif($name_bank  == "gharz"){
if(preg_match('/(\d{1,3}(?:,\d{3})*\+)/', $valuepost, $matches)) {
    $amountInteger = str_replace(',', '', $matches[1])*0.1;
}}


// A valid bank deposit for a round plan price (e.g. 500000 tomans)
// MUST NOT be ignored. The old "ends with 000" guard silently dropped it.
if (isset($amountInteger) && is_numeric($amountInteger) &&
    (int)$amountInteger > 0 && (float)$amountInteger == (int)$amountInteger) {
    $amount=(int)$amountInteger;
    // Serialize bank SMS webhooks by amount; older duplicate notifications
    // cannot cause another side effect after payment_Status becomes paid.
    $lockName='mirza_bank_auto_'.hash('sha256',(string)$amount);
    $guard=$pdo->prepare("SELECT GET_LOCK(?,5)");
    $guard->execute([$lockName]);
    if ((int)$guard->fetchColumn() !== 1) {
        error_log('[Mirza bank auto] Payment amount is already being checked');
        return;
    }
    try {
        $Payment_report=mirzaFindUniqueBankCardPayment($pdo,$amount);
        if (!$Payment_report) return;
        $order_id=(string)$Payment_report['id_order'];
        $Balance_id=select('user','*','id',$Payment_report['id_user'],'select');
        if (!$Balance_id) return;
        $textbotlang=languagechange('../text.json');

        // Claim exactly once BEFORE creating the VPN service or charging a
        // wallet. A second webhook or admin callback sees "paid" and stops.
        // Bank transfer evidence has already been checked by the inbound
        // trusted SMS notification handler above.
        $claim=$pdo->prepare("UPDATE Payment_report
            SET payment_Status='paid',
                dec_not_confirmed='تأیید با اعلان تراکنش بانکی'
            WHERE id_order=? AND payment_Status IN ('waiting','Unpaid')
              AND Payment_Method='cart to cart'");
        $claim->execute([$order_id]);
        if ($claim->rowCount() !== 1) return;

        try {
            DirectPayment($order_id,"../images.jpg");
        } catch (Throwable $error) {
            // Never blindly re-run provisioning or balance credit after an
            // external-panel exception: operations may be partially complete.
            error_log('[Mirza bank auto] Claimed payment needs MANUAL provisioning review: '
                .$error->getMessage());
            return;
        }
    $balanceformatsell = number_format(mysqli_fetch_assoc(mysqli_query($connect, "SELECT (Balance) FROM user WHERE id = '{$Payment_report['id_user']}' LIMIT 1"))['Balance'], 0);
    $paymentreports = select("topicid","idreport","report","paymentreport","select")['idreport'];
    $text_report = "یک رسید توسط ربات  تایید شد

اطلاعات :
💰 مبلغ پرداخت : {$Payment_report['price']}
👤  آیدی عددی کاربر : {$Balance_id['id']} 
👤 نام کاربری کاربر : @{$Balance_id['username']} 
موجودی کاربر : $balanceformatsell تومان
کد پیگیری پرداخت : $order_id";
    if (strlen($setting['Channel_Report']) > 0) {
        telegram('sendmessage',[
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $paymentreports,
        'text' => $text_report,
        'parse_mode' => "HTML"
        ]);
    }
    } finally {
        $release=$pdo->prepare("SELECT RELEASE_LOCK(?)");
        $release->execute([$lockName]);
    }
}
}