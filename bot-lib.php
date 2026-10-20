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

/**
 * Один ли это человек: те же фамилия и имя плюс совпадает телефон, дата рождения или почта.
 * Одного телефона мало: люди ошибаются в цифре при повторной анкете.
 */
function vkl_same_person($a, $b) {
    $last = vkl_norm($a[COL_LAST] ?? '');
    if ($last === '' || $last !== vkl_norm($b[COL_LAST] ?? '')
        || vkl_norm($a[COL_FIRST] ?? '') !== vkl_norm($b[COL_FIRST] ?? '')) { return false; }
    $checks = [
        [COL_PHONE, 'vkl_phone10'],
        [COL_BIRTH, 'trim'],
        [COL_EMAIL, function ($v) { return mb_strtolower(trim($v)); }],
    ];
    foreach ($checks as list($col, $fn)) {
        $x = $fn((string)($a[$col] ?? ''));
        if ($x !== '' && $x === $fn((string)($b[$col] ?? ''))) { return true; }
    }
    return false;
}

/** Все анкеты того же человека, включая $idx (по цепочке совпадений). Индексы по возрастанию. */
function vkl_person_group($rows, $idx) {
    $in = [$idx => true];
    $queue = [$idx];
    while ($queue) {
        $k = array_pop($queue);
        for ($i = 1; $i < count($rows); $i++) {
            if (isset($in[$i]) || vkl_is_service_row($rows[$i])) { continue; }
            if (vkl_same_person($rows[$k], $rows[$i])) { $in[$i] = true; $queue[] = $i; }
        }
    }
    $group = array_keys($in);
    sort($group);
    return $group;
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
 * Статус неоплаченной строки следует за категорией: организатор → «не требуется», остальные → «ожидает оплаты».
 * Возвращает ячейки для записи. Оплаченные, дубли и тесты не трогает.
 */
function vkl_role_status_cells($rowNum, $status, $role) {
    $status = trim((string)$status);
    if ($status !== '' && $status !== ST_WAIT && $status !== ST_FREE) { return []; }
    $want = $role === ROLE_ORG ? ST_FREE : ST_WAIT;
    return $status === $want ? [] : ['B' . $rowNum => $want];
}

/** Анкеты, сгруппированные по людям: [[индексы строк], ...]. Тестовые строки не входят. */
function vkl_people_groups($rows) {
    $parent = [];
    $find = function ($x) use (&$parent) {
        while ($parent[$x] !== $x) { $parent[$x] = $parent[$parent[$x]]; $x = $parent[$x]; }
        return $x;
    };
    $buckets = [];
    for ($i = 1; $i < count($rows); $i++) {
        if (vkl_is_service_row($rows[$i])) { continue; }
        $parent[$i] = $i;
        $last = vkl_norm($rows[$i][COL_LAST] ?? '');
        if ($last !== '') { $buckets[$last . '|' . vkl_norm($rows[$i][COL_FIRST] ?? '')][] = $i; }
    }
    foreach ($buckets as $ids) {
        foreach ($ids as $a) {
            foreach ($ids as $b) {
                if ($a < $b && vkl_same_person($rows[$a], $rows[$b])) { $parent[$find($a)] = $find($b); }
            }
        }
    }
    $groups = [];
    foreach (array_keys($parent) as $i) { $groups[$find($i)][] = $i; }
    return array_values($groups);
}

function vkl_is_paid($status) {
    return in_array(trim((string)$status), [ST_RECEIPT, ST_PAID, ST_NOFORM], true);
}

function vkl_amount($v) {
    $n = (int)preg_replace('/\D+/', '', (string)$v);
    return $n > 0 ? $n : VKL_RECOMMENDED;
}

/** Сколько ждём от человека: организатор — ничего, служитель — половину рекомендуемого, участник — сумму из анкеты. */
function vkl_expected($role, $declared) {
    if ($role === ROLE_ORG) { return 0; }
    if ($role === ROLE_SERV) { return (int)round(VKL_RECOMMENDED * VKL_SERV_SHARE); }
    return $declared;
}

function vkl_ucfirst($s) {
    return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}

/** Расстояние Левенштейна по буквам, а не байтам (для кириллицы). */
function vkl_lev($a, $b) {
    $a = mb_str_split($a); $b = mb_str_split($b);
    $prev = range(0, count($b));
    foreach ($a as $i => $ca) {
        $cur = [$i + 1];
        foreach ($b as $j => $cb) {
            $cur[] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($ca === $cb ? 0 : 1));
        }
        $prev = $cur;
    }
    return end($prev);
}

/** Латиница → кириллица для названий городов («Volgograd» → «волгоград»). */
function vkl_translit_ru($s) {
    return strtr($s, ['shch' => 'щ', 'sch' => 'щ', 'zh' => 'ж', 'kh' => 'х', 'ts' => 'ц', 'ch' => 'ч', 'sh' => 'ш',
        'yu' => 'ю', 'ya' => 'я', 'yo' => 'е', 'iy' => 'ий', 'yy' => 'ый', 'a' => 'а', 'b' => 'б', 'v' => 'в',
        'g' => 'г', 'd' => 'д', 'e' => 'е', 'z' => 'з', 'i' => 'и', 'y' => 'ы', 'k' => 'к', 'l' => 'л', 'm' => 'м',
        'n' => 'н', 'o' => 'о', 'p' => 'п', 'r' => 'р', 's' => 'с', 't' => 'т', 'u' => 'у', 'f' => 'ф', 'h' => 'х',
        'c' => 'к', 'w' => 'в', 'x' => 'кс', 'j' => 'й', 'q' => 'к']);
}

/** Город для фильтра: без «г.», без области после запятой, регистр и латиница не важны. */
function vkl_city_key($s) {
    $s = str_replace('ё', 'е', mb_strtolower(trim((string)$s)));
    $s = preg_replace('/^(г\.|город)\s*/u', '', $s);
    $s = trim(explode(',', $s)[0]);
    return preg_match('/[a-z]/', $s) ? vkl_translit_ru($s) : $s;
}

/** Церковь для фильтра: люди пишут одну и ту же церковь по-разному («БИХ», «Благодать Иисуса Христа Волгоград»). */
function vkl_church_label($s) {
    $t = str_replace('ё', 'е', mb_strtolower(trim((string)$s)));
    $rules = [
        '/елей/u' => 'Елей',
        '/благодать|(^|\W)бих(\W|$)/u' => 'Благодать Иисуса Христа',
        '/спасител/u' => 'Иисуса Христа Спасителя',
        '/нов\w*\s+жизн/u' => 'Новая жизнь',
        '/дом\s+молитв/u' => 'Дом молитвы',
        '/оцхве/u' => 'ОЦХВЕ',
        '/путь\s+истин/u' => 'Путь истины',
        '/истин/u' => 'Истина',
        '/божий\s+путь/u' => 'Божий путь',
        '/краеугольн/u' => 'Краеугольный камень',
        '/святой\s+источник/u' => 'Святой источник',
        '/не\s+посещ|не\s+хож/u' => 'Не посещает',
    ];
    foreach ($rules as $re => $label) {
        if (preg_match($re, $t)) { return $label; }
    }
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    return $t === '' ? '' : vkl_ucfirst($t);
}

/**
 * Участники без дублей, по одному на человека. Номер — по дате первой регистрации,
 * данные — из самой свежей анкеты. Возвращает список, свежие сверху.
 */
function vkl_participants($rows) {
    $list = [];
    foreach (vkl_people_groups($rows) as $g) {
        $alive = array_values(array_filter($g, function ($k) use ($rows) {
            return trim($rows[$k][COL_STATUS] ?? '') !== ST_DUP;
        }));
        if (!$alive) { continue; }
        $main = max($alive);
        $status = trim($rows[$main][COL_STATUS] ?? '') ?: ST_WAIT;
        foreach ($alive as $k) {
            $s = trim($rows[$k][COL_STATUS] ?? '');
            if (vkl_status_rank($s) > vkl_status_rank($status)) { $status = $s; }
        }
        $first = min(array_map(function ($k) use ($rows) { return $rows[$k][COL_TIME] ?? ''; }, $g));
        $r = $rows[$main];

        // категорию и полученную сумму ставит человек вручную — берём из свежей анкеты, иначе из любой
        $role = ''; $got = 0;
        foreach (array_merge([$main], $alive) as $k) {
            if ($role === '') { $role = trim($rows[$k][COL_ROLE] ?? ''); }
            if ($got === 0) { $got = (int)preg_replace('/\D+/', '', (string)($rows[$k][COL_GOT] ?? '')); }
        }
        if ($role === '' && $status === ST_FREE) { $role = ROLE_ORG; }   // в таблице выбрали статус «не требуется»
        $role = in_array($role, [ROLE_SERV, ROLE_ORG], true) ? $role : ROLE_PART;
        $declared = vkl_amount($r[COL_AMOUNT] ?? '');
        $expected = vkl_expected($role, $declared);
        $paid = vkl_is_paid($status) || $got > 0;
        $state = $paid ? 'paid' : ($expected > 0 ? 'wait' : 'free');
        $amount = $paid ? ($got > 0 ? $got : ($role === ROLE_SERV ? $expected : $declared)) : $expected;

        $list[] = ['first' => $first, 'row' => $main + 1, 'status' => $status, 'r' => $r, 'forms' => count($g),
                   'paid' => $paid, 'state' => $state, 'role' => $role, 'declared' => $declared, 'got' => $got,
                   'amount' => $amount];
    }
    usort($list, function ($a, $b) { return strcmp($a['first'], $b['first']) ?: $a['row'] - $b['row']; });

    // опечатки в названии города («Волгорад») склеиваем с самым частым похожим вариантом
    $count = [];
    foreach ($list as $p) { $k = vkl_city_key($p['r'][COL_CITY] ?? ''); if ($k !== '') { $count[$k] = ($count[$k] ?? 0) + 1; } }
    arsort($count);
    $canon = [];
    foreach (array_keys($count) as $k) {
        $canon[$k] = $k;
        foreach (array_keys($count) as $big) {
            if ($big === $k) { break; }
            if (mb_strlen($k) >= 5 && vkl_lev($k, $big) <= 1) { $canon[$k] = $big; break; }
        }
    }
    foreach ($list as $i => $p) {
        $k = vkl_city_key($p['r'][COL_CITY] ?? '');
        $list[$i]['num'] = $i + 1;
        $list[$i]['city'] = $k === '' ? '' : vkl_ucfirst($canon[$k] ?? $k);
        $list[$i]['church'] = vkl_church_label($p['r'][COL_CHURCH] ?? '');
    }
    return array_reverse($list);
}

/** Все анкеты, у которых и фамилия, и имя есть среди слов сообщения. */
function vkl_find_all_rows($rows, $tokens) {
    $set = array_flip($tokens);
    $hits = [];
    for ($i = 1; $i < count($rows); $i++) {
        if (vkl_is_service_row($rows[$i])) { continue; }
        $last = vkl_norm($rows[$i][COL_LAST] ?? '');
        $first = vkl_norm($rows[$i][COL_FIRST] ?? '');
        if ($last !== '' && $first !== '' && isset($set[$last]) && isset($set[$first])) { $hits[] = $i; }
    }
    return $hits;
}

/**
 * Отмечаем чек у человека. Повторные анкеты того же человека помечаются «дубль»,
 * актуальной считается самая свежая. Возвращает описание для организаторов.
 */
function vkl_mark_paid($rows, $idx, $file, $tgId, $username) {
    $group = vkl_person_group($rows, $idx);
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
    $fileUniq = '';     // одинаковый у одного и того же файла, даже если его прислали повторно
    if (!empty($m['photo'])) {
        $fileId = end($m['photo'])['file_id'];
        $fileUniq = end($m['photo'])['file_unique_id'] ?? '';
        $kind = 'photo';
    } elseif (!empty($m['document'])) {
        $fileId = $m['document']['file_id'];
        $fileUniq = $m['document']['file_unique_id'] ?? '';
        $kind = 'document';
    }

    // ответ на своё старое сообщение с чеком («.», «вот», имя — что угодно): берём чек оттуда
    $quoted = $m['reply_to_message'] ?? null;
    if ($fileId === '' && $quoted && empty($quoted['from']['is_bot'])) {
        if (!empty($quoted['photo'])) {
            $fileId = end($quoted['photo'])['file_id'];
            $fileUniq = end($quoted['photo'])['file_unique_id'] ?? '';
            $kind = 'photo';
        } elseif (!empty($quoted['document'])) {
            $fileId = $quoted['document']['file_id'];
            $fileUniq = $quoted['document']['file_unique_id'] ?? '';
            $kind = 'document';
        }
        if ($fileId !== '') { $caption = $text; }
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
            $e['files'][] = ['id' => $fileId, 'uniq' => $fileUniq, 'kind' => $kind, 'file' => vkl_save_receipt($fileId, 'tg' . $uid)];
            $state[$uid] = $e; vkl_json_save($statePath, $state);
            return;
        }
    }

    $source = $fileId !== '' ? $caption : $text;
    $tokens = vkl_name_tokens($source);
    if ($tokens) { $e['name'] = implode(' ', $tokens); }

    if ($fileId !== '') {
        $file = vkl_save_receipt($fileId, 'tg' . $uid . ($uname ? '_' . $uname : ''));
        $e['files'][] = ['id' => $fileId, 'uniq' => $fileUniq, 'kind' => $kind, 'file' => $file];
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

    // тот же файл уже присылали: за этого же человека — просто подтверждаем, за другого — предупреждаем организаторов
    $knownPath = VKL_LEADS . '/receipt-ids.json';
    $known = vkl_json_load($knownPath);
    $before = [];
    foreach ($e['files'] as $f) {
        if (!empty($f['uniq']) && isset($known[$f['uniq']])) { $before[] = $known[$f['uniq']]; }
    }
    $labelKey = vkl_norm($label);
    $samePerson = array_filter($before, function ($b) use ($labelKey) { return vkl_norm($b['label']) === $labelKey; });
    if ($before && count($samePerson) === count($e['files'])) {
        foreach ($e['files'] as $f) { if ($f['file'] !== '') { @unlink(VKL_RECEIPTS . '/' . $f['file']); } }
        tg_say($chat, 'Этот чек у нас уже есть ✅ Всё в порядке, повторно отправлять не нужно.');
        vkl_log('bot.log', 'повтор того же чека: ' . $label . ' (уже был ' . $before[0]['at'] . ')');
        $state[$uid] = ['username' => $uname, 'at' => time(), 'group' => $group, 'done_name' => $label];
        vkl_json_save($statePath, $state);
        return;
    }
    $otherWho = [];
    foreach ($before as $b) {
        if (vkl_norm($b['label']) !== $labelKey) { $otherWho[] = $b['label'] . ' (' . $b['at'] . ')'; }
    }

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

        // один чек за нескольких («Мамаджонов Данияр, Мамаджонов Далер»): отмечаем каждого названного
        if (count($nameTokens) >= 3) {
            $covered = vkl_person_group($rows, $idx);
            foreach (vkl_find_all_rows($rows, $nameTokens) as $j) {
                if (in_array($j, $covered, true)) { continue; }
                $covered = array_merge($covered, vkl_person_group($rows, $j));
                $more = vkl_mark_paid($rows, $j, $mainFile, $uid, $uname);
                $who .= '; ' . trim(($rows[$j][COL_LAST] ?? '') . ' ' . ($rows[$j][COL_FIRST] ?? ''));
                $note .= '; также строка ' . $more['row'] . ($more['ok'] ? ' → «' . $more['status'] . '»' : ' ❗ не записалась');
            }
        }
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

    if ($otherWho) { $note .= "\n⚠️ этот же чек раньше присылали за: " . implode(', ', $otherWho); }
    $cap = '🧾 <b>Чек</b>: ' . htmlspecialchars($who) . "\nTelegram: " . ($uname ? '@' . htmlspecialchars($uname) : 'id ' . $uid)
         . "\n" . htmlspecialchars($note);
    foreach ($e['files'] as $k => $f) {
        vkl_forward_admins($f['kind'], $f['id'], $k === 0 ? $cap : '🧾 ещё файл: ' . htmlspecialchars($who));
    }
    vkl_log('bot.log', 'чек: ' . $who . ' | ' . str_replace("\n", ' ', $note) . ' | ' . implode(', ', $saved));

    foreach ($e['files'] as $f) {
        if (!empty($f['uniq'])) { $known[$f['uniq']] = ['label' => $label, 'at' => date('d.m H:i'), 'uid' => $uid]; }
    }
    vkl_json_save($knownPath, $known);

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
    $people = vkl_participants($rows);
    $paid = array_filter($people, function ($p) { return $p['state'] === 'paid'; });
    $sum = function ($ps) { return number_format(array_sum(array_column($ps, 'amount')), 0, '', ' '); };
    $wait = array_filter($people, function ($p) { return $p['state'] === 'wait'; });
    $free = array_filter($people, function ($p) { return $p['state'] === 'free'; });
    $lines = ['📊 <b>Участники</b>: ' . count($people) . ' (без дублей и тестов)',
              '✅ оплачено: ' . count($paid) . ' — ' . $sum($paid) . ' ₽',
              '⏳ ожидают: ' . count($wait) . ' — ' . $sum($wait) . ' ₽',
              '🙌 организаторы без оплаты: ' . count($free),
              '<a href="' . VKL_SITE . '/leads.php">Список участников</a> · <a href="' . VKL_SHEET_URL . '">Таблица</a>'];
    tg_say($chat, implode("\n", $lines));
}
