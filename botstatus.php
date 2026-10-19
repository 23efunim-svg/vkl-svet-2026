<?php
/** Состояние бота для организаторов: на связи ли он, когда было последнее сообщение, ошибки. */
define('VKL_BOOT', 1);
require __DIR__ . '/bot-lib.php';
vkl_require_auth('Бот');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['rehook'])) {
    vkl_set_webhook();
    header('Location: botstatus.php');
    exit;
}

$w = vkl_watchdog(true);
$info = $w['info'] ?? [];
$st = vkl_json_load(VKL_LEADS . '/bot-status.json');
$tail = function ($file, $n) {
    $lines = @file(VKL_LEADS . '/' . $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_reverse(array_slice($lines, -$n));
};
$receipts = is_dir(VKL_RECEIPTS) ? count(array_diff(scandir(VKL_RECEIPTS), ['.', '..'])) : 0;
$hookOk = ($info['url'] ?? '') === VKL_HOOK_URL;
$errAt = $info['last_error_date'] ?? 0;
$fresh = $errAt && time() - $errAt < 7200;
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Бот — Включите свет 2026</title>
<style>
  body { margin:0; background:#16150F; color:#E8DFCE; font:15px/1.55 -apple-system,Segoe UI,Arial,sans-serif; }
  .wrap { max-width:900px; margin:0 auto; padding:26px 18px 60px; }
  h1 { font-size:22px; margin:0 0 18px; }
  h2 { font-size:16px; margin:28px 0 8px; color:#A9A08D; font-weight:600; }
  .big { font-size:20px; font-weight:700; padding:14px 16px; border:1px solid rgba(232,223,206,.16); background:#1F1D16; }
  .ok { color:#4CBD49; } .bad { color:#ff8a7a; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  td { padding:6px 8px; border-bottom:1px solid rgba(232,223,206,.12); vertical-align:top; }
  td:first-child { color:#A9A08D; width:240px; }
  pre { white-space:pre-wrap; word-break:break-word; font-size:12.5px; background:#1F1D16; padding:10px 12px; margin:0; }
  button { padding:9px 16px; background:#4CBD49; color:#16150F; border:0; font-weight:700; cursor:pointer; }
  a { color:#4CBD49; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Бот @vklsvet_pay_bot</h1>
  <div class="big <?= $hookOk && !$fresh ? 'ok' : 'bad' ?>">
    <?= $hookOk && !$fresh ? '✅ На связи' : '❗ Нужна проверка' ?>
  </div>

  <table style="margin-top:14px">
    <tr><td>Вебхук</td><td><?= $hookOk ? 'установлен' : 'не установлен: ' . htmlspecialchars($info['url'] ?? '') ?></td></tr>
    <tr><td>Сообщений ждут в очереди</td><td><?= (int)($info['pending_update_count'] ?? 0) ?></td></tr>
    <tr><td>Последняя ошибка доставки</td><td><?= $errAt ? date('Y-m-d H:i', $errAt) . ' — ' . htmlspecialchars($info['last_error_message'] ?? '') : 'не было' ?></td></tr>
    <tr><td>Последнее сообщение боту</td><td><?= htmlspecialchars($st['last_update'] ?? 'ещё не было') ?></td></tr>
    <tr><td>Обработано сообщений</td><td><?= (int)($st['count'] ?? 0) ?></td></tr>
    <tr><td>Файлов чеков на сервере</td><td><?= $receipts ?> · <a href="receipts.php">посмотреть</a></td></tr>
    <tr><td>Чат организаторов</td><td><?= count(vkl_admins()) ? 'подключён (' . count(vkl_admins()) . ')' : 'не подключён — отправьте боту /org и код' ?></td></tr>
    <tr><td>Таблица</td><td><a href="<?= VKL_SHEET_URL ?>" target="_blank">открыть</a></td></tr>
  </table>

  <form method="post" style="margin-top:16px"><button name="rehook" value="1">Переустановить вебхук</button></form>

  <h2>Последние чеки</h2>
  <pre><?= htmlspecialchars(implode("\n", $tail('bot.log', 15)) ?: 'пока нет') ?></pre>
  <h2>Ошибки бота</h2>
  <pre><?= htmlspecialchars(implode("\n", $tail('bot-errors.log', 10)) ?: 'нет') ?></pre>
  <h2>Ошибки таблицы</h2>
  <pre><?= htmlspecialchars(implode("\n", $tail('sheets-errors.log', 10)) ?: 'нет') ?></pre>
</div>
</body>
</html>
