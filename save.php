<?php
/**
 * Журнал анкет конференции «Включите свет 2026».
 * Пишет каждую отправленную форму на диск ДО оплаты, чтобы ничего не терялось.
 * Файл журнала лежит вне папки сайта, по HTTP его не скачать.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"error":"method"}'; exit; }

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { $data = $_POST; }
if (!is_array($data) || !count($data)) { http_response_code(400); echo '{"error":"empty"}'; exit; }

/** Чиним кодировку: битая строка роняет json_encode и заявка теряется. */
function vkl_utf8_fix($v) {
    if (is_array($v)) { return array_map('vkl_utf8_fix', $v); }
    if (!is_string($v)) { return $v; }
    if (function_exists('mb_check_encoding') && !mb_check_encoding($v, 'UTF-8')) {
        $v = function_exists('mb_convert_encoding')
            ? mb_convert_encoding($v, 'UTF-8', 'Windows-1251')
            : utf8_encode($v);
    }
    return $v;
}
$data = vkl_utf8_fix($data);

$row = [
    'time'      => date('Y-m-d H:i:s'),
    'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
    'lastName'  => trim((string)($data['lastName'] ?? '')),
    'firstName' => trim((string)($data['firstName'] ?? '')),
    'patronymic'=> trim((string)($data['patronymic'] ?? '')),
    'birthDate' => trim((string)($data['birthDate'] ?? '')),
    'age'       => $data['age'] ?? '',
    'gender'    => trim((string)($data['gender'] ?? '')),
    'phone'     => trim((string)($data['phone'] ?? '')),
    'email'     => trim((string)($data['email'] ?? '')),
    'city'      => trim((string)($data['city'] ?? '')),
    'church'    => trim((string)($data['church'] ?? '')),
    'telegram'  => trim((string)($data['telegram'] ?? '')),
    'guardianName'  => trim((string)($data['guardianName'] ?? '')),
    'guardianPhone' => trim((string)($data['guardianPhone'] ?? '')),
    'comment'   => trim((string)($data['comment'] ?? '')),
    'amount'    => $data['amount'] ?? 1000,
];

if ($row['lastName'] === '' && $row['firstName'] === '') {
    http_response_code(400); echo '{"error":"no name"}'; exit;
}

$dir = dirname(__DIR__) . '/leads';          // рядом с public_html, не внутри
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

$file = $dir . '/leads-' . date('Y-m') . '.jsonl';
$line = json_encode($row, JSON_UNESCAPED_UNICODE);
if ($line === false) { $line = json_encode(['time' => $row['time'], 'error' => 'json_encode']); }

$ok = @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);

// запасная копия внутри сайта, если основная папка недоступна на запись
if ($ok === false) {
    $alt = __DIR__ . '/.leads-backup.jsonl';
    @file_put_contents($alt, $line . "\n", FILE_APPEND | LOCK_EX);
}

// сразу отправляем анкету в Google-таблицу, чтобы она не зависела от внешних скриптов
$sheetOk = false;
try {
    require_once __DIR__ . '/sheets.php';
    $sheetOk = vkl_sheet_append([
        $row['time'], 'ожидает оплаты',
        $row['lastName'], $row['firstName'], $row['patronymic'],
        $row['birthDate'], $row['age'], $row['gender'],
        $row['phone'], $row['email'], $row['city'], $row['church'], $row['telegram'],
        $row['guardianName'], $row['guardianPhone'], $row['comment'], $row['amount'],
        $row['time'] . '|' . $row['phone'],
    ]);
} catch (Throwable $e) {
    @file_put_contents($dir . '/sheets-errors.log',
        date('Y-m-d H:i:s') . ' ' . $e->getMessage() . "
", FILE_APPEND);
}

echo json_encode(['ok' => true, 'sheet' => $sheetOk], JSON_UNESCAPED_UNICODE);
