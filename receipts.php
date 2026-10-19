<?php
/** Просмотр присланных чеков. Логин и пароль — в secrets/panel-auth.txt вне папки сайта. */
define('VKL_BOOT', 1);
require __DIR__ . '/bot-lib.php';
vkl_require_auth('Чеки');

$dir = dirname(__DIR__) . '/receipts';

// отдача самого файла
if (isset($_GET['f'])) {
    $name = basename($_GET['f']);
    $path = $dir . '/' . $name;
    if (!file_exists($path)) { http_response_code(404); exit('нет файла'); }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
              'webp' => 'image/webp', 'pdf' => 'application/pdf', 'heic' => 'image/heic'];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

$files = is_dir($dir) ? array_values(array_diff(scandir($dir, SCANDIR_SORT_DESCENDING), ['.', '..'])) : [];
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Чеки — Включите свет 2026</title>
<style>
  body { margin:0; background:#16150F; color:#E8DFCE; font:15px/1.5 -apple-system,Segoe UI,Arial,sans-serif; }
  .wrap { max-width:1300px; margin:0 auto; padding:26px 18px 60px; }
  h1 { font-size:22px; margin:0 0 6px; }
  .sub { color:#A9A08D; font-size:14px; margin-bottom:22px; }
  .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:16px; }
  .card { border:1px solid rgba(232,223,206,.16); background:#1F1D16; }
  .card img { width:100%; height:280px; object-fit:cover; display:block; background:#0f0e0a; }
  .card .meta { padding:10px 12px; font-size:13px; }
  .card .who { color:#4CBD49; font-weight:700; word-break:break-word; }
  .card .when { color:#A9A08D; font-size:12px; margin-top:3px; }
  .card a.open { display:block; padding:8px 12px; border-top:1px solid rgba(232,223,206,.12);
                 color:#E8DFCE; text-decoration:none; font-size:12.5px; text-transform:uppercase; letter-spacing:.06em; }
  .card a.open:hover { color:#4CBD49; }
  .empty { color:#A9A08D; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Чеки об оплате</h1>
  <p class="sub">Всего файлов: <b><?= count($files) ?></b>. Имя файла — дата и участник.</p>

  <?php if (!$files): ?>
    <p class="empty">Пока ни одного чека. Файлы появятся здесь автоматически, как только их пришлют боту.</p>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($files as $f):
        $parts = explode('_', pathinfo($f, PATHINFO_FILENAME));
        $when = ($parts[0] ?? '') . ' ' . str_replace('-', ':', $parts[1] ?? '');
        $whoParts = array_slice($parts, 2);
        if (count($whoParts) > 1 && preg_match('/^[0-9a-f]{4}$/', end($whoParts))) { array_pop($whoParts); }
        $who = implode(' ', $whoParts);
        $isPdf = strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'pdf';
    ?>
    <div class="card">
      <?php if (!$isPdf): ?>
        <img src="?f=<?= urlencode($f) ?>" alt="чек" loading="lazy">
      <?php endif; ?>
      <div class="meta">
        <div class="who"><?= htmlspecialchars($who ?: 'без имени') ?></div>
        <div class="when"><?= htmlspecialchars($when) ?></div>
      </div>
      <a class="open" href="?f=<?= urlencode($f) ?>" target="_blank">Открыть файл</a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
