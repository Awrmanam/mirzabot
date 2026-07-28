<?php

$root = dirname(__DIR__);

function staticAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, '[FAILED] ' . $message . PHP_EOL);
        exit(1);
    }
}

$emojiSystem = file_get_contents($root . '/emoji_system.php');
$index = file_get_contents($root . '/index.php');
$keyboard = file_get_contents($root . '/keyboard.php');
$functions = file_get_contents($root . '/function.php');
$botApi = file_get_contents($root . '/botapi.php');

staticAssert(
    strpos($emojiSystem, "require_once __DIR__ . '/emoji_install.php'") === false,
    'emoji_install.php is still included by runtime.'
);
staticAssert(
    substr_count($index, 'languagechange(') + substr_count($keyboard, 'languagechange(') === 1,
    'languagechange is not called exactly once in the webhook path.'
);
staticAssert(strpos($index, "memory_limit', '-1") === false, 'Unlimited memory is still configured.');
staticAssert(strpos($index, 'MIRZA_MEMORY_LIMIT') !== false, 'Configurable memory limit is not applied.');

foreach (['flock -n', 'timeout %ds', '--connect-timeout 5', '--max-time', '>/dev/null 2>&1'] as $requiredCronPart) {
    staticAssert(strpos($functions, $requiredCronPart) !== false, 'Cron protection is missing: ' . $requiredCronPart);
}
staticAssert(
    strpos($functions, 'mirzaCronCommand(') !== false,
    'Cron endpoints are not generated through the protected command builder.'
);
staticAssert(
    substr_count($botApi, "return telegram(\$method, \$fallbackDatas, \$token);") === 1,
    'Telegram fallback recursion is not structurally limited to one call site.'
);
staticAssert(
    strpos($botApi, 'styledTelegramErrorAllowsEmojiFallback') !== false,
    'Telegram fallback is not restricted to Custom Emoji errors.'
);
staticAssert(
    strpos($functions, '$field !== \'message_count\'')
        && strpos($functions, '$field !== \'last_message_time\''),
    'High-frequency update logging exclusion is missing.'
);

fwrite(STDOUT, '[OK] runtime installer=0, languagechange=1, memory=bounded, cron=protected, fallback=single-site' . PHP_EOL);
