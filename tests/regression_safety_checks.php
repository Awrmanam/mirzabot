<?php
$root = dirname(__DIR__);
function bad($m){fwrite(STDERR,"[FAIL] $m\n");exit(1);}
function has($s,$n,$m){if(strpos($s,$n)===false)bad($m." missing: ".$n);}
$files=[];
foreach(['install.sh','index.php','function.php','keyboard.php','admin.php','emoji_system.php','botapi.php','product_panel_identity.php'] as $f){
    $files[$f]=file_get_contents($root.'/'.$f);
    if($files[$f]===false) bad("cannot read $f");
}
$i=$files['install.sh'];
$start=strpos($i,'function update_bot()');
$end=strpos($i,'# Delete Function for Mirza Pro',$start);
if($start===false||$end===false)bad('cannot isolate update_bot');
$u=substr($i,$start,$end-$start);
has($u,'--no-tablespaces','privilege-safe DB backup');
has($u,'files.tar.gz','compressed file backup');
has($u,'Overlaying validated release','overlay updater');
has($u,'PRODUCT_COUNT_BEFORE','product count guard');
has($u,'PANEL_COUNT_BEFORE','panel count guard');
has($u,'restore_update','rollback support');
has($u,'function panelKeyboardButton','panel Premium Emoji guard');
has($u,'function productPanelLocationValues','stable product-panel guard');
has($u,'function reserveUserTrialSlot','per-panel trial guard');
if(strpos($u,'rm -rf "$BOT_DIR"')!==false && strpos($u,'restore_update')===false) bad('unsafe live directory deletion');

has($files['product_panel_identity.php'],'function panelKeyboardButton','panel button helper');
has($files['product_panel_identity.php'],'function productPanelLocationValues','stable panel identity helper');
has($files['keyboard.php'],'panelKeyboardButton(','Premium Emoji panel button integration');
has($files['index.php'],'productPanelLocationValues(','stable product location integration');
has($files['emoji_system.php'],'function styledCustomEmojiEnabled','Premium Emoji runtime');
has($files['botapi.php'],'styledPrepareTelegramRequest','Premium Emoji transport');
has($files['function.php'],'function reserveUserTrialSlot','atomic trial reservation');
has($files['function.php'],'function getUserEligibleTrialPanels','trial eligibility');
has($files['admin.php'],'♻️ ریست تست‌های کاربر','per-user trial reset');
has($files['admin.php'],'confirm_reset_all_trial_usage','all-user reset confirmation');
if(strpos($files['index.php'],"\$limit_usertest = \$userlimit['limit_usertest'] - 1;")!==false) bad('legacy global trial decrement present');
echo "[OK] production product/panel/Premium Emoji/trial invariants present.\n";
