<?php
/**
 * Бот приёма чеков, работающий на самом хостинге.
 * Запускается фоном:  nohup php botd.php > ../leads/bot.log 2>&1 &
 * Сторож перезапускает его, если процесс упал: см. botwatch.sh
 */
set_time_limit(0);
date_default_timezone_set('Europe/Moscow');

require_once __DIR__ . '/sheets.php';

$TOKEN   = trim(@file_get_contents(dirname(__DIR__) . '/secrets/bot-token.txt'));
$ADMIN   = trim(@file_get_contents(dirname(__DIR__) . '/secrets/admin-chat.txt'));
$STATE   = dirname(__DIR__) . '/leads/bot-state.json';
$OFFSET  = dirname(__DIR__) . '/leads/bot-offset.txt';

if ($TOKEN === '') { exit("нет токена\n"); }

function tg($method, $params = []) {
    global $TOKEN;
    $ch = curl_init("https://api.telegram.org/bot$TOKEN/$method");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 65,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res;
}

function state_load() { global $STATE; $s = @json_decode(@file_get_contents($STATE), true); return is_array($s) ? $s : []; }
function state_save($s) { global $STATE; @file_put_contents($STATE, json_encode($s, JSON_UNESCAPED_UNICODE), LOCK_EX); }

$HELLO = "Привет! Это бот конференции <b>«Включите свет»</b>.\n\n"
       . "Чтобы подтвердить регистрацию, пришли:\n"
       . "1. свои <b>фамилию и имя</b>;\n"
       . "2. <b>фото или скриншот чека</b> об оплате.\n\n"
       . "Можно одним сообщением: приложи чек и подпиши его фамилией и именем.";

$THANKS = "Спасибо, мы получили данные! 💚\n\n"
        . "Ждём тебя <b>26 ноября</b>, Волгоград, ул. Руднева, 2д.\n\n"
        . "Все новости — в канале @vkl_svet";

/** Ищем анкету участника в таблице и ставим «чек получен». */
function mark_receipt($name, $username) {
    $token = vkl_google_token();
    if (!$token) { return false; }

    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . VKL_SHEET_ID
         . '/values/' . rawurlencode(VKL_SHEET_NAME) . '!A:R';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    ]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $rows = $data['values'] ?? [];
    $parts = preg_split('/\s+/u', mb_strtolower(trim($name)));
    $last  = $parts[0] ?? '';
    $first = $parts[1] ?? '';

    for ($i = count($rows) - 1; $i >= 1; $i--) {
        $rLast  = mb_strtolower(trim($rows[$i][2] ?? ''));
        $rFirst = mb_strtolower(trim($rows[$i][3] ?? ''));
        if ($rLast === $last && ($first === '' || $rFirst === $first)) {
            $cell = 'B' . ($i + 1);
            $u = 'https://sheets.googleapis.com/v4/spreadsheets/' . VKL_SHEET_ID
               . '/values/' . rawurlencode(VKL_SHEET_NAME . '!' . $cell) . '?valueInputOption=RAW';
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['values' => [['чек получен']]], JSON_UNESCAPED_UNICODE),
            ]);
            curl_exec($ch);
            curl_close($ch);
            return true;
        }
    }

    // анкеты нет: заводим строку, чтобы человек не потерялся
    vkl_sheet_append([date('Y-m-d H:i:s'), 'чек без анкеты', $last, $first, '', '', '', '',
                      '', '', '', '', $username ? '@' . $username : '', '', '', '', 1000, 'tg-' . time()]);
    return true;
}

function is_name($s) {
    return (bool)preg_match('/^[\p{Cyrillic}A-Za-z][\p{Cyrillic}A-Za-z\s\-]{4,80}$/u', trim($s));
}

echo "бот запущен " . date('H:i:s') . "\n";
$offset = (int)@file_get_contents($OFFSET);

while (true) {
    $upd = tg('getUpdates', ['offset' => $offset, 'timeout' => 50, 'limit' => 20]);
    if (empty($upd['ok'])) { sleep(3); continue; }

    foreach ($upd['result'] as $u) {
        $offset = $u['update_id'] + 1;
        @file_put_contents($OFFSET, (string)$offset);

        $m = $u['message'] ?? null;
        if (!$m) { continue; }

        $chat = $m['chat']['id'];
        $uid  = (string)($m['from']['id'] ?? '');
        $uname = $m['from']['username'] ?? '';
        $state = state_load();
        $entry = $state[$uid] ?? [];
        $entry['username'] = $uname;

        $text = trim($m['text'] ?? '');
        $caption = trim($m['caption'] ?? '');

        if (strpos($text, '/start') === 0) {
            $payload = trim(substr($text, 6));
            if ($payload !== '') { $entry['name'] = str_replace('_', ' ', $payload); }
            $state[$uid] = $entry; state_save($state);
            tg('sendMessage', ['chat_id' => $chat, 'text' => $HELLO, 'parse_mode' => 'HTML']);
            continue;
        }

        $hasPhoto = !empty($m['photo']) || !empty($m['document']);
        if ($hasPhoto) {
            $entry['photo'] = !empty($m['photo'])
                ? end($m['photo'])['file_id']
                : $m['document']['file_id'];
            $entry['kind'] = !empty($m['photo']) ? 'photo' : 'document';
            if ($caption !== '' && is_name($caption)) { $entry['name'] = $caption; }
        } elseif ($text !== '' && is_name($text)) {
            $entry['name'] = $text;
        } elseif ($text !== '') {
            tg('sendMessage', ['chat_id' => $chat,
                'text' => 'Напиши, пожалуйста, фамилию и имя, а также приложи чек об оплате.']);
            $state[$uid] = $entry; state_save($state);
            continue;
        }

        $state[$uid] = $entry; state_save($state);

        if (!empty($entry['name']) && !empty($entry['photo'])) {
            mark_receipt($entry['name'], $entry['username']);
            tg('sendMessage', ['chat_id' => $chat, 'text' => $THANKS, 'parse_mode' => 'HTML']);

            if ($ADMIN !== '') {
                $cap = "<b>Чек</b>\nИмя: " . htmlspecialchars($entry['name'])
                     . "\nTelegram: @" . ($entry['username'] ?: 'нет');
                $method = $entry['kind'] === 'document' ? 'sendDocument' : 'sendPhoto';
                $key = $entry['kind'] === 'document' ? 'document' : 'photo';
                tg($method, ['chat_id' => $ADMIN, $key => $entry['photo'],
                             'caption' => $cap, 'parse_mode' => 'HTML']);
            }

            unset($state[$uid]); state_save($state);
            echo date('H:i:s') . " чек принят: " . $entry['name'] . "\n";
        } elseif (!empty($entry['photo'])) {
            tg('sendMessage', ['chat_id' => $chat,
                'text' => 'Чек получил. Теперь напиши свои <b>фамилию и имя</b>.', 'parse_mode' => 'HTML']);
        } elseif (!empty($entry['name'])) {
            tg('sendMessage', ['chat_id' => $chat,
                'text' => 'Записал: <b>' . htmlspecialchars($entry['name']) . '</b>. Теперь пришли фото чека.',
                'parse_mode' => 'HTML']);
        }
    }
}
