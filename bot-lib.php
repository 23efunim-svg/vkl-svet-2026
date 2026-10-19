<?php
/**
 * Бот приёма чеков @vklsvet_pay_bot: общая логика.
 * Работает через вебхук (tg-hook.php): Telegram сам присылает каждое сообщение на сайт,
 * поэтому нет фонового процесса, который может тихо умереть.
 * Секреты и данные лежат рядом с папкой сайта, по HTTP их не скачать.
 */
if (!defined('VKL_BOOT')) { http_response_code(404); exit; }

date_default_timezone_set('Europe/Moscow');
require_once __DIR__ . '/sheets.php';

define('VKL_BASE', dirname(__DIR__));
define('VKL_LEADS', VKL_BASE . '/leads');
define('VKL_SECRETS', VKL_BASE . '/secrets');
define('VKL_RECEIPTS', VKL_BASE . '/receipts');
define('VKL_SITE', 'https://xn----ctbbkconi1dhf6c4e.xn--p1ai');
define('VKL_HOOK_URL', VKL_SITE . '/tg-hook.php');
define('VKL_SHEET_URL', 'https://docs.google.com/spreadsheets/d/' . VKL_SHEET_ID . '/edit');
define('VKL_HELP_CONTACT', 'Если что-то не получается — напиши @vkl_svet или позвони +7 950 325-44-45.');

function vkl_secret($name) {
    return trim((string)@file_get_contents(VKL_SECRETS . '/' . $name));
}

function vkl_log($file, $msg) {
    if (!is_dir(VKL_LEADS)) { @mkdir(VKL_LEADS, 0755, true); }
    @file_put_contents(VKL_LEADS . '/' . $file, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function vkl_json_load($path, $default = []) {
    $v = @json_decode((string)@file_get_contents($path), true);
    return is_array($v) ? $v : $default;
}

function vkl_json_save($path, $data) {
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** Basic-auth для служебных страниц. Логин и пароль — в secrets/panel-auth.txt («логин:пароль»). */
function vkl_require_auth($realm) {
    $pair = explode(':', vkl_secret('panel-auth.txt'), 2);
    if (count($pair) !== 2 || $pair[1] === '') { http_response_code(503); exit('Доступ не настроен'); }
    $u = $_SERVER['PHP_AUTH_USER'] ?? '';
    $p = $_SERVER['PHP_AUTH_PW'] ?? '';
    if (!hash_equals($pair[0], $u) || !hash_equals($pair[1], $p)) {
        header('WWW-Authenticate: Basic realm="' . $realm . '"');
        http_response_code(401);
        exit('Нужен доступ');
    }
}

/* ---------------------------------------------------------------- Telegram */

function tg($method, $params = []) {
    $token = vkl_secret('bot-token.txt');
    if ($token === '') { return null; }
    $ch = curl_init("https://api.telegram.org/bot$token/$method");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
    ]);
    $res = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['ok'])) {
        vkl_log('bot-errors.log', "tg $method: " . substr(json_encode($res, JSON_UNESCAPED_UNICODE), 0, 300));
    }
    return $res;
}

function tg_say($chat, $text) {
    return tg('sendMessage', ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML',
                              'disable_web_page_preview' => true]);
}

function vkl_admins() {
    return vkl_json_load(VKL_SECRETS . '/admin-chats.json');
}

/** Сообщение организаторам. Если чат организаторов не подключён — только в журнал. */
function vkl_alert($text) {
    vkl_log('bot-alerts.log', strip_tags($text));
    foreach (vkl_admins() as $chat) { tg_say($chat, $text); }
}

function vkl_set_webhook() {
    $secret = vkl_secret('hook-secret.txt');
    if ($secret === '') { return ['ok' => false, 'description' => 'нет secrets/hook-secret.txt']; }
    return tg('setWebhook', [
        'url' => VKL_HOOK_URL,
        'secret_token' => $secret,
        'allowed_updates' => ['message'],
        'max_connections' => 1,          // по одному сообщению за раз: без гонок в таблице
        'drop_pending_updates' => false,
    ]);
}

/**
 * Самопроверка бота. Вызывается при каждой регистрации на сайте и со страницы статуса,
 * но не чаще раза в 10 минут. Возвращает вебхук на место и предупреждает организаторов.
 */
function vkl_watchdog($force = false) {
    $file = VKL_LEADS . '/watchdog.json';
    $w = vkl_json_load($file);
    if (!$force && time() - ($w['checked_at'] ?? 0) < 600) { return $w; }
    $w['checked_at'] = time();

    $info = tg('getWebhookInfo');
    if (empty($info['ok'])) {
        $w['state'] = 'Telegram API недоступен';
        vkl_json_save($file, $w);
        return $w;
    }
    $r = $info['result'];
    $w['info'] = $r;
    $w['state'] = 'ok';

    if (($r['url'] ?? '') !== VKL_HOOK_URL) {
        $set = vkl_set_webhook();
        $w['state'] = !empty($set['ok']) ? 'вебхук был снят и восстановлен' : 'вебхук не ставится';
        vkl_alert('⚠️ <b>Бот:</b> ' . $w['state'] . '. ' . htmlspecialchars($set['description'] ?? ''));
    } elseif (!empty($r['last_error_date']) && $r['last_error_date'] > ($w['alerted_error'] ?? 0)
              && time() - $r['last_error_date'] < 7200) {
        $w['alerted_error'] = $r['last_error_date'];
        $w['state'] = 'ошибка доставки';
        vkl_alert('⚠️ <b>Бот:</b> Telegram не может доставить сообщения на сайт: '
            . htmlspecialchars($r['last_error_message'] ?? '') . '. В очереди: ' . ($r['pending_update_count'] ?? 0));
    }
    vkl_json_save($file, $w);
    return $w;
}

/* ------------------------------------------------------------ Разбор имени */

function vkl_norm($s) {
    $s = mb_strtolower(trim((string)$s));
    $s = str_replace('ё', 'е', $s);
    $s = preg_replace('/[^\p{L}\-]+/u', ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function vkl_phone10($s) {
    $d = preg_replace('/\D+/', '', (string)$s);
    return strlen($d) >= 10 ? substr($d, -10) : $d;
}

function vkl_uname($s) {
    return ltrim(mb_strtolower(trim((string)$s)), '@');
}

/** Слова из сообщения, которые точно не фамилия и не имя. */
function vkl_stopwords() {
    static $w = null;
    if ($w === null) {
        $w = array_flip(explode(' ', 'здравствуйте здравствуй привет приветствую добрый доброе доброго день дня '
            . 'вечер вечера утро утра я мы это вот чек чека чеки скрин скриншот оплата оплатил оплатила оплатили '
            . 'оплачено перевод перевела перевел отправляю отправила отправил спасибо пожалуйста за и на от для '
            . 'регистрация регистрацию участие пожертвование конференция конференцию мой моя мое мои меня зовут '
            . 'фамилия имя ок хорошо да нет руб рублей рубля сумма сбп сбер сбербанк'));
    }
    return $w;
}

/** Похоже ли сообщение на «фамилия имя»: 2–4 слова после выкидывания служебных слов и цифр. */
function vkl_name_tokens($text) {
    $text = trim((string)$text);
    if ($text === '' || mb_strlen($text) > 100) { return []; }
    $stop = vkl_stopwords();
    $tokens = [];
    foreach (explode(' ', vkl_norm($text)) as $t) {
        $t = trim($t, '-');
        if ($t !== '' && mb_strlen($t) >= 2 && !isset($stop[$t])) { $tokens[] = $t; }
    }
    return (count($tokens) >= 2 && count($tokens) <= 4) ? $tokens : [];
}

/** Человеческий вид имени: «Иванова Мария». */
function vkl_pretty($tokens) {
    return implode(' ', array_map(function ($t) {
        return mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }, $tokens));
}

/* ------------------------------------------------------------- Таблица */

/** Ключ человека: телефон + фамилия + имя. По нему склеиваются повторные анкеты. */
function vkl_person_key($r) {
    $phone = vkl_phone10($r[COL_PHONE] ?? '');
    $name = vkl_norm($r[COL_LAST] ?? '') . '|' . vkl_norm($r[COL_FIRST] ?? '');
    return ($phone !== '' ? $phone : vkl_norm($r[COL_BIRTH] ?? '')) . '|' . $name;
}

function vkl_status_rank($s) {
    $s = trim((string)$s);
    if ($s === ST_PAID) { return 3; }
    if ($s === ST_RECEIPT) { return 2; }
    if ($s === ST_NOFORM) { return 1; }
    return 0;
}

function vkl_is_service_row($r) {
    $s = trim((string)($r[COL_STATUS] ?? ''));
    return $s === ST_TEST;
}

/**
 * Ищем анкету. Порядок: код из ссылки на сайте → фамилия+имя (в любом порядке) → ник Telegram.
 * Возвращает индекс строки (с нуля, 0 — заголовок) или -1.
 */
function vkl_find_row($rows, $rid, $tokens, $username) {
    $n = count($rows);
    if ($rid !== '') {
        for ($i = $n - 1; $i >= 1; $i--) {
            if (trim($rows[$i][COL_RID] ?? '') === $rid) { return $i; }
        }
    }
    $uname = vkl_uname($username);
    if ($tokens) {
        $set = array_flip($tokens);
        $hits = [];
        for ($i = $n - 1; $i >= 1; $i--) {
            $r = $rows[$i];
            if (vkl_is_service_row($r)) { continue; }
            $last = vkl_norm($r[COL_LAST] ?? '');
            $first = vkl_norm($r[COL_FIRST] ?? '');
            if ($last !== '' && $first !== '' && isset($set[$last]) && isset($set[$first])) { $hits[] = $i; }
        }
        if ($hits) {
            // однофамильцы: предпочитаем того, у кого совпадает ник
            foreach ($hits as $i) {
                if ($uname !== '' && vkl_uname($rows[$i][COL_TG] ?? '') === $uname) { return $i; }
            }
            return $hits[0];
        }
    }
    if ($uname !== '') {
        for ($i = $n - 1; $i >= 1; $i--) {
            if (!vkl_is_service_row($rows[$i]) && vkl_uname($rows[$i][COL_TG] ?? '') === $uname) { return $i; }
        }
    }
    return -1;
}

/**
 * Отмечаем чек у человека. Повторные анкеты того же человека помечаются «дубль»,
 * актуальной считается самая свежая. Возвращает описание для организаторов.
 */
function vkl_mark_paid($rows, $idx, $file, $tgId, $username) {
    $key = vkl_person_key($rows[$idx]);
    $group = [];
    foreach ($rows as $i => $r) {
        if ($i >= 1 && !vkl_is_service_row($r) && vkl_person_key($r) === $key) { $group[] = $i; }
    }
    if (!$group) { $group = [$idx]; }
    $main = max($group);
    $best = ST_RECEIPT;
    foreach ($group as $i) {
        if (vkl_status_rank($rows[$i][COL_STATUS] ?? '') > vkl_status_rank($best)) { $best = trim($rows[$i][COL_STATUS]); }
    }

    $n = $main + 1;
    $cells = ['B' . $n => $best, 'U' . $n => (string)$tgId, 'V' . $n => date('Y-m-d H:i:s')];
    if ($username !== '' && trim($rows[$main][COL_TG] ?? '') === '') { $cells['M' . $n] = '@' . $username; }
    foreach ($group as $i) {
        if ($i !== $main) { $cells['B' . ($i + 1)] = ST_DUP; }
    }
    $ok = vkl_sheet_set_cells($cells);
    if ($ok && $file !== '') {
        vkl_sheet_batch([vkl_link_request($main, COL_RECEIPT, 'чек', VKL_SITE . '/receipts.php?f=' . rawurlencode($file))]);
    }
    return ['ok' => $ok, 'row' => $n, 'status' => $best, 'dups' => count($group) - 1, 'r' => $rows[$main]];
}

/* ------------------------------------------------------------- Файлы чеков */

/** Скачиваем чек к себе сразу: у Telegram файл живёт не вечно, а сверять надо нам. */
function vkl_save_receipt($fileId, $label) {
    if (!is_dir(VKL_RECEIPTS)) { @mkdir(VKL_RECEIPTS, 0755, true); }
    $info = tg('getFile', ['file_id' => $fileId]);
    $path = $info['result']['file_path'] ?? '';
    if ($path === '') { return ''; }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'jpg';
    $safe = trim(preg_replace('/[^\p{Cyrillic}\w\-]+/u', '_', $label), '_');
    $name = date('Y-m-d_H-i-s') . '_' . mb_substr($safe, 0, 40) . '_' . substr(md5($fileId), 0, 4) . '.' . $ext;

    $token = vkl_secret('bot-token.txt');
    $ch = curl_init("https://api.telegram.org/file/bot$token/$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    $bin = curl_exec($ch);
    curl_close($ch);
    if ($bin === false || strlen($bin) < 100) { return ''; }

    @file_put_contents(VKL_RECEIPTS . '/' . $name, $bin);
    return $name;
}

/** Переименовываем файл чека, когда узнали, чей он. */
function vkl_rename_receipt($file, $label) {
    if ($file === '' || !file_exists(VKL_RECEIPTS . '/' . $file)) { return $file; }
    $parts = explode('_', pathinfo($file, PATHINFO_FILENAME));
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $safe = trim(preg_replace('/[^\p{Cyrillic}\w\-]+/u', '_', $label), '_');
    $new = $parts[0] . '_' . $parts[1] . '_' . mb_substr($safe, 0, 40) . '_' . end($parts) . '.' . $ext;
    return @rename(VKL_RECEIPTS . '/' . $file, VKL_RECEIPTS . '/' . $new) ? $new : $file;
}

/* ------------------------------------------------------------- Диалог */

function vkl_state_path() { return VKL_LEADS . '/bot-state.json'; }

function vkl_texts() {
    return [
        'hello' => "Привет! Это бот конференции <b>«Включите свет»</b>.\n\n"
                 . "Чтобы подтвердить регистрацию, пришли сюда <b>фото или скриншот чека</b> об оплате "
                 . "и подпиши его <b>фамилией и именем</b>, как в анкете.\n\n"
                 . "Можно и по очереди: сначала фамилию и имя, потом чек.",
        'ask_name' => "Чек получил 👍\nТеперь напиши свои <b>фамилию и имя</b> — как в анкете на сайте.",
        'ask_photo' => "Записал: <b>%s</b>.\nТеперь пришли фото или скриншот чека об оплате.",
        'unknown' => "Не совсем понял 🙂\nПришли, пожалуйста, <b>фамилию и имя</b> и <b>фото или скриншот чека</b>.\n\n"
                   . VKL_HELP_CONTACT,
        'thanks' => "Спасибо, %s! Чек получили 💚\n\n"
                  . "Ждём тебя <b>26 ноября</b>, Волгоград, ул. Руднева, 2д.\n"
                  . "Не забудь бумажную Библию и блокнот.\n\n"
                  . "Все новости — в канале @vkl_svet",
    ];
}

/** Главный обработчик одного обновления от Telegram. */
function vkl_bot_handle($u) {
    $m = $u['message'] ?? null;
    if (!$m) { return; }
    $chat = $m['chat']['id'];
    $text = trim((string)($m['text'] ?? ''));

    // служебные команды организаторов работают и в группе
    if (preg_match('#^/(orgoff|org|stat)(?:@\w+)?(?:\s+(.*))?$#u', $text, $cmd)) {
        vkl_admin_command($chat, $cmd[1], trim($cmd[2] ?? ''));
        return;
    }
    if (($m['chat']['type'] ?? '') !== 'private') { return; }

    $T = vkl_texts();
    $uid = (string)($m['from']['id'] ?? '');
    $uname = (string)($m['from']['username'] ?? '');
    $statePath = vkl_state_path();
    $state = vkl_json_load($statePath);
    $e = $state[$uid] ?? [];
    $e['username'] = $uname;
    $e['at'] = time();
    $caption = trim((string)($m['caption'] ?? ''));

    // /start с кодом анкеты из ссылки на сайте
    if (strpos($text, '/start') === 0) {
        $payload = trim(substr($text, 6));
        $e = ['username' => $uname, 'at' => time()];
        $greet = $T['hello'];
        if (preg_match('/^r[a-z0-9]{6,16}$/', $payload)) {
            $e['rid'] = $payload;
            $rows = vkl_sheet_rows();
            $i = $rows ? vkl_find_row($rows, $payload, [], '') : -1;
            if ($i > 0) {
                $e['name'] = vkl_norm(($rows[$i][COL_LAST] ?? '') . ' ' . ($rows[$i][COL_FIRST] ?? ''));
                $greet = 'Привет, ' . htmlspecialchars($rows[$i][COL_FIRST] ?? '') . "! Анкету нашёл ✅\n\n"
                       . 'Теперь пришли сюда <b>фото или скриншот чека</b> об оплате — и всё готово.';
            }
        } elseif ($payload !== '' && ($t = vkl_name_tokens(str_replace('_', ' ', $payload)))) {
            $e['name'] = implode(' ', $t);      // старые ссылки, где в коде было имя
        }
        $state[$uid] = $e; vkl_json_save($statePath, $state);
        tg_say($chat, $greet);
        return;
    }

    $fileId = '';
    if (!empty($m['photo'])) {
        $fileId = end($m['photo'])['file_id'];
        $kind = 'photo';
    } elseif (!empty($m['document'])) {
        $fileId = $m['document']['file_id'];
        $kind = 'document';
    }

    // остальные фото из того же альбома прикладываем к тому же человеку молча
    $group = (string)($m['media_group_id'] ?? '');
    if ($fileId !== '' && $group !== '' && ($e['group'] ?? '') === $group) {
        if (!empty($e['done_name'])) {
            vkl_save_receipt($fileId, $e['done_name']);
            vkl_forward_admins($kind, $fileId, '🧾 ещё один файл от: ' . htmlspecialchars($e['done_name']));
            $state[$uid] = $e; vkl_json_save($statePath, $state);
            return;
        }
        if (!empty($e['files'])) {
            $e['files'][] = ['id' => $fileId, 'kind' => $kind, 'file' => vkl_save_receipt($fileId, 'tg' . $uid)];
            $state[$uid] = $e; vkl_json_save($statePath, $state);
            return;
        }
    }

    $source = $fileId !== '' ? $caption : $text;
    $tokens = vkl_name_tokens($source);
    if ($tokens) { $e['name'] = implode(' ', $tokens); }

    if ($fileId !== '') {
        $file = vkl_save_receipt($fileId, 'tg' . $uid . ($uname ? '_' . $uname : ''));
        $e['files'][] = ['id' => $fileId, 'kind' => $kind, 'file' => $file];
        $e['group'] = $group;
    } elseif ($text === '' || !$tokens) {
        $state[$uid] = $e; vkl_json_save($statePath, $state);
        tg_say($chat, $T['unknown']);
        return;
    }

    if (empty($e['files'])) {
        $state[$uid] = $e; vkl_json_save($statePath, $state);
        tg_say($chat, sprintf($T['ask_photo'], htmlspecialchars(vkl_pretty(explode(' ', $e['name'])))));
        return;
    }

    // есть чек: ищем анкету (по коду, имени или нику)
    $rows = vkl_sheet_rows();
    $nameTokens = !empty($e['name']) ? explode(' ', $e['name']) : [];
    $idx = $rows ? vkl_find_row($rows, $e['rid'] ?? '', $nameTokens, $uname) : -1;

    if ($idx < 0 && !$nameTokens) {
        // не знаем, чей чек: спрашиваем имя, файл уже сохранён
        $state[$uid] = $e; vkl_json_save($statePath, $state);
        tg_say($chat, $T['ask_name']);
        return;
    }

    $label = $idx > 0 ? trim(($rows[$idx][COL_LAST] ?? '') . ' ' . ($rows[$idx][COL_FIRST] ?? '')) : vkl_pretty($nameTokens);
    $saved = [];
    foreach ($e['files'] as $f) { $saved[] = vkl_rename_receipt($f['file'], $label); }
    $mainFile = $saved[0] ?? '';

    if ($rows === null) {
        $note = '❗ таблица Google недоступна, строку надо отметить вручную';
        $who = $label;
    } elseif ($idx > 0) {
        $res = vkl_mark_paid($rows, $idx, $mainFile, $uid, $uname);
        $r = $res['r'];
        $who = trim(($r[COL_LAST] ?? '') . ' ' . ($r[COL_FIRST] ?? '')) . (trim($r[COL_CITY] ?? '') ? ', ' . trim($r[COL_CITY]) : '');
        $note = $res['ok']
            ? 'анкета найдена, строка ' . $res['row'] . ' → «' . $res['status'] . '»' . ($res['dups'] ? ', повторных анкет: ' . $res['dups'] : '')
            : '❗ анкета найдена (строка ' . $res['row'] . '), но запись в таблицу не прошла';
    } else {
        $p = $nameTokens;
        $ok = vkl_sheet_append([date('Y-m-d H:i:s'), ST_NOFORM, vkl_pretty([$p[0]]), vkl_pretty([$p[1] ?? '']), vkl_pretty([$p[2] ?? '']),
            '', '', '', '', '', '', '', $uname ? '@' . $uname : '', '', '', '', '', 'tg-' . $uid . '-' . time(),
            $mainFile, '', $uid, date('Y-m-d H:i:s')]);
        $who = vkl_pretty($nameTokens);
        $note = $ok ? '⚠️ анкета не найдена, добавлена строка «' . ST_NOFORM . '»' : '❗ анкета не найдена и строка не добавилась';
    }

    $firstName = $idx > 0 ? trim($rows[$idx][COL_FIRST] ?? '') : vkl_pretty([$nameTokens[1] ?? ($nameTokens[0] ?? '')]);
    if ($firstName === '') { $firstName = 'друг'; }
    tg_say($chat, sprintf($T['thanks'], htmlspecialchars($firstName)));

    $cap = '🧾 <b>Чек</b>: ' . htmlspecialchars($who) . "\nTelegram: " . ($uname ? '@' . htmlspecialchars($uname) : 'id ' . $uid)
         . "\n" . htmlspecialchars($note);
    foreach ($e['files'] as $k => $f) {
        vkl_forward_admins($f['kind'], $f['id'], $k === 0 ? $cap : '🧾 ещё файл: ' . htmlspecialchars($who));
    }
    vkl_log('bot.log', 'чек: ' . $who . ' | ' . $note . ' | ' . implode(', ', $saved));

    $state[$uid] = ['username' => $uname, 'at' => time(), 'group' => $group, 'done_name' => $label];
    vkl_json_save($statePath, $state);
}

function vkl_forward_admins($kind, $fileId, $caption) {
    foreach (vkl_admins() as $chat) {
        $method = $kind === 'document' ? 'sendDocument' : 'sendPhoto';
        tg($method, ['chat_id' => $chat, $kind === 'document' ? 'document' : 'photo' => $fileId,
                     'caption' => $caption, 'parse_mode' => 'HTML']);
    }
}

/** /org КОД — подключить чат организаторов, /orgoff — отключить, /stat — сводка. */
function vkl_admin_command($chat, $cmd, $arg) {
    $admins = vkl_admins();
    $path = VKL_SECRETS . '/admin-chats.json';
    if ($cmd === 'org') {
        $code = vkl_secret('org-code.txt');
        if ($code === '' || !hash_equals($code, $arg)) { tg_say($chat, 'Неверный код.'); return; }
        if (!in_array($chat, $admins)) { $admins[] = $chat; vkl_json_save($path, $admins); }
        tg_say($chat, "✅ Этот чат подключён: сюда будут приходить все чеки и предупреждения бота.\n"
                    . 'Сводка — /stat, отключить — /orgoff.');
        return;
    }
    if (!in_array($chat, $admins)) { return; }
    if ($cmd === 'orgoff') {
        vkl_json_save($path, array_values(array_filter($admins, function ($c) use ($chat) { return $c != $chat; })));
        tg_say($chat, 'Чат отключён от уведомлений бота.');
        return;
    }
    $rows = vkl_sheet_rows();
    if ($rows === null) { tg_say($chat, 'Таблица сейчас недоступна.'); return; }
    $c = [];
    for ($i = 1; $i < count($rows); $i++) {
        $s = trim($rows[$i][COL_STATUS] ?? '') ?: ST_WAIT;
        $c[$s] = ($c[$s] ?? 0) + 1;
    }
    $people = array_sum($c) - ($c[ST_DUP] ?? 0) - ($c[ST_TEST] ?? 0);
    $lines = ['📊 <b>Участники</b>: ' . $people . ' (без дублей и тестов)'];
    foreach ([ST_PAID, ST_RECEIPT, ST_NOFORM, ST_WAIT] as $s) { $lines[] = $s . ': ' . ($c[$s] ?? 0); }
    $lines[] = '<a href="' . VKL_SHEET_URL . '">Открыть таблицу</a>';
    tg_say($chat, implode("\n", $lines));
}
