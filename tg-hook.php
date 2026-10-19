<?php
/**
 * Вебхук Telegram-бота @vklsvet_pay_bot.
 * Telegram присылает сюда каждое сообщение. Чужие запросы отсекаются секретным заголовком.
 * Сначала сообщение пишется в журнал на диск, потом обрабатывается: даже при сбое оно не теряется.
 */
define('VKL_BOOT', 1);
require __DIR__ . '/bot-lib.php';

$secret = vkl_secret('hook-secret.txt');
$given = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($secret === '' || !hash_equals($secret, $given)) { http_response_code(403); exit; }

$raw = file_get_contents('php://input');
$u = json_decode($raw, true);
if (!is_array($u) || !isset($u['update_id'])) { http_response_code(200); exit('ok'); }

if (!is_dir(VKL_LEADS)) { @mkdir(VKL_LEADS, 0755, true); }
// Telegram присылает JSON с переносами строк, в журнал пишем одной строкой
@file_put_contents(VKL_LEADS . '/tg-inbox-' . date('Y-m') . '.jsonl',
    json_encode($u, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

// Telegram может прислать то же сообщение повторно, если ответ задержался
$statusPath = VKL_LEADS . '/bot-status.json';
$st = vkl_json_load($statusPath);
$seen = $st['seen'] ?? [];
if (in_array($u['update_id'], $seen, true)) { http_response_code(200); exit('ok'); }

set_time_limit(90);
try {
    vkl_bot_handle($u);
    $st['last_ok'] = date('Y-m-d H:i:s');
} catch (Throwable $e) {
    $st['last_error'] = date('Y-m-d H:i:s') . ' ' . $e->getMessage();
    vkl_log('bot-errors.log', 'update ' . $u['update_id'] . ': ' . $e->getMessage() . ' @' . $e->getLine());
    vkl_alert('❗ <b>Бот:</b> ошибка при обработке сообщения, оно сохранено в журнале. '
        . htmlspecialchars($e->getMessage()));
}

$seen[] = $u['update_id'];
$st['seen'] = array_slice($seen, -300);
$st['last_update'] = date('Y-m-d H:i:s');
$st['count'] = ($st['count'] ?? 0) + 1;
vkl_json_save($statusPath, $st);

http_response_code(200);
echo 'ok';
