<?php
$source = (string) file_get_contents(dirname(__DIR__) . '/alireza_single.php');

function alirezaCheck($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] " . $message . PHP_EOL);
        exit(1);
    }
}

alirezaCheck(
    strpos($source, 'function alirezaApiPostWithReauth') !== false,
    'Alireza redirect reauthentication helper is missing.'
);
alirezaCheck(
    strpos($source, "login(\$panel['code_panel'], false);") !== false,
    'Forced Alireza relogin is missing.'
);
foreach ([301, 302, 303, 307, 308, 401, 403] as $status) {
    alirezaCheck(
        strpos($source, (string) $status) !== false,
        "Retry status {$status} is missing."
    );
}
alirezaCheck(
    substr_count($source, 'return alirezaApiPostWithReauth(') === 4,
    'Expected create/update/reset/delete to share the reauth helper.'
);
alirezaCheck(
    strpos($source, "return alirezaApiPostWithReauth(\$marzban_list_get, \$url, \$configpanel, \$headers);") !== false,
    'Create/update requests are not using the reauth helper.'
);
alirezaCheck(
    strpos($source, "return alirezaApiPostWithReauth(\$marzban_list_get, \$url, [], \$headers);") !== false,
    'Reset/delete requests are not using the reauth helper.'
);

echo "[OK] Alireza API redirect/auth retry protection is present." . PHP_EOL;
