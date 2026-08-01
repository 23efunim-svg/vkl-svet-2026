<?php
/**
 * Сторож бота. Запускается планировщиком раз в минуту.
 * Если процесс бота упал, поднимает его заново.
 */
$dir = '/home/c/ce13721/vkl-svet';
$running = trim(shell_exec('pgrep -f "php botd.php" 2>/dev/null'));

if ($running === '') {
    shell_exec('cd ' . $dir . '/public_html && setsid nohup php botd.php >> ' . $dir . '/leads/bot.log 2>&1 < /dev/null &');
    @file_put_contents($dir . '/leads/bot.log', date('Y-m-d H:i:s') . " сторож поднял бота\n", FILE_APPEND);
    echo "бот перезапущен\n";
} else {
    echo "бот работает\n";
}
