<?php
/** Просмотр журнала анкет. Доступ по логину и паролю. */
$USER = 'vkl';
$PASS = 'svet-2026';

if (!isset($_SERVER['PHP_AUTH_USER']) || $_SERVER['PHP_AUTH_USER'] !== $USER || $_SERVER['PHP_AUTH_PW'] !== $PASS) {
    header('WWW-Authenticate: Basic realm="Журнал анкет"');
    http_response_code(401);
    exit('Нужен доступ');
}

$dir = dirname(__DIR__) . '/leads';
$files = glob($dir . '/leads-*.jsonl') ?: [];
$backup = __DIR__ . '/.leads-backup.jsonl';
if (file_exists($backup)) { $files[] = $backup; }
rsort($files);

$month = $_GET['m'] ?? '';
$rows = [];
foreach ($files as $f) {
    if ($month && strpos(basename($f), $month) === false) { continue; }
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (is_array($r)) { $rows[] = $r; }
    }
}
usort($rows, fn($a, $b) => strcmp($b['time'] ?? '', $a['time'] ?? ''));

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=windows-1251');
    header('Content-Disposition: attachment; filename="vkl-svet-anketi.csv"');
    $out = fopen('php://output', 'w');
    $head = ['Время','Фамилия','Имя','Отчество','Дата рождения','Возраст','Пол','Телефон','Почта','Город','Церковь','Telegram','Сопровождающий','Телефон сопровождающего','Вопрос','Сумма'];
    fputcsv($out, array_map(fn($h) => iconv('UTF-8', 'Windows-1251//TRANSLIT', $h), $head), ';');
    foreach ($rows as $r) {
        $line = [$r['time'] ?? '', $r['lastName'] ?? '', $r['firstName'] ?? '', $r['patronymic'] ?? '',
                 $r['birthDate'] ?? '', $r['age'] ?? '', $r['gender'] ?? '', $r['phone'] ?? '',
                 $r['email'] ?? '', $r['city'] ?? '', $r['church'] ?? '', $r['telegram'] ?? '',
                 $r['guardianName'] ?? '', $r['guardianPhone'] ?? '', $r['comment'] ?? '', $r['amount'] ?? ''];
        fputcsv($out, array_map(fn($v) => iconv('UTF-8', 'Windows-1251//TRANSLIT', (string)$v), $line), ';');
    }
    exit;
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Анкеты — Включите свет 2026</title>
<style>
  body { margin:0; background:#16150F; color:#E8DFCE; font:15px/1.5 -apple-system,Segoe UI,Arial,sans-serif; }
  .wrap { max-width:1400px; margin:0 auto; padding:26px 18px 60px; }
  h1 { font-size:22px; margin:0 0 6px; }
  .sub { color:#A9A08D; font-size:14px; margin-bottom:20px; }
  .btn { display:inline-block; padding:9px 16px; background:#4CBD49; color:#16150F;
         font-weight:700; text-decoration:none; border-radius:2px; font-size:13px; }
  table { width:100%; border-collapse:collapse; font-size:13.5px; }
  th, td { padding:8px 10px; border-bottom:1px solid rgba(232,223,206,.14); text-align:left; vertical-align:top; }
  th { color:#4CBD49; font-size:12px; text-transform:uppercase; letter-spacing:.06em; position:sticky; top:0; background:#16150F; }
  tr:hover td { background:rgba(232,223,206,.04); }
  .num { color:#A9A08D; }
  .minor { color:#FFF7AE; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Анкеты конференции «Включите свет 2026»</h1>
  <p class="sub">
    Всего записей: <b><?= count($rows) ?></b>
    &nbsp;·&nbsp; <a class="btn" href="?csv=1">Скачать для Excel</a>
  </p>

  <table>
    <tr>
      <th>#</th><th>Время</th><th>Участник</th><th>Дата рождения</th><th>Телефон</th>
      <th>Почта</th><th>Город</th><th>Церковь</th><th>Telegram</th><th>Сопровождающий</th><th>Вопрос</th>
    </tr>
    <?php foreach ($rows as $i => $r): $age = $r['age'] ?? ''; ?>
    <tr>
      <td class="num"><?= count($rows) - $i ?></td>
      <td class="num"><?= htmlspecialchars($r['time'] ?? '') ?></td>
      <td>
        <?= htmlspecialchars(trim(($r['lastName'] ?? '') . ' ' . ($r['firstName'] ?? '') . ' ' . ($r['patronymic'] ?? ''))) ?>
        <?php if ($age !== '' && (int)$age < 18): ?><br><span class="minor">до 18 лет</span><?php endif; ?>
      </td>
      <td class="num"><?= htmlspecialchars($r['birthDate'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['phone'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['email'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['city'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['church'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['telegram'] ?? '') ?></td>
      <td><?= htmlspecialchars(trim(($r['guardianName'] ?? '') . ' ' . ($r['guardianPhone'] ?? ''))) ?></td>
      <td><?= htmlspecialchars($r['comment'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
</body>
</html>
