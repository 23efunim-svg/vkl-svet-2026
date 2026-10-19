<?php
/**
 * Google-таблица участников: чтение, запись, оформление.
 * Ключ сервисного аккаунта лежит вне папки сайта, по HTTP его не достать.
 */

define('VKL_SHEET_ID', '1guyrmCKxsNvMBnL_NxHemu0UDiuO1Su821D2vNy6nQk');
define('VKL_SHEET_NAME', 'Участники');
define('VKL_SHEET_GID', 0);
define('VKL_SHEET_LAST_COL', 'V');
define('VKL_SA_FILE', dirname(__DIR__) . '/secrets/google-sa.json');

// столбцы листа (с нуля)
define('COL_TIME', 0);   define('COL_STATUS', 1); define('COL_LAST', 2);  define('COL_FIRST', 3);
define('COL_PATR', 4);   define('COL_BIRTH', 5);  define('COL_AGE', 6);   define('COL_GENDER', 7);
define('COL_PHONE', 8);  define('COL_EMAIL', 9);  define('COL_CITY', 10); define('COL_CHURCH', 11);
define('COL_TG', 12);    define('COL_GNAME', 13); define('COL_GPHONE', 14); define('COL_COMMENT', 15);
define('COL_AMOUNT', 16); define('COL_KEY', 17);  define('COL_RECEIPT', 18); define('COL_RID', 19);
define('COL_TGID', 20);  define('COL_RTIME', 21);

define('VKL_HEADERS', json_encode([
    'Дата регистрации', 'Статус', 'Фамилия', 'Имя', 'Отчество', 'Дата рождения', 'Лет', 'Пол',
    'Телефон', 'Почта', 'Город', 'Церковь', 'Telegram', 'Сопровождающий', 'Телефон сопровождающего',
    'Вопрос', 'Сумма', 'Ключ', 'Чек', 'Код анкеты', 'Telegram ID', 'Чек пришёл',
], JSON_UNESCAPED_UNICODE));

// статусы
define('ST_WAIT', 'ожидает оплаты');
define('ST_RECEIPT', 'чек получен');
define('ST_PAID', 'оплачено');
define('ST_NOFORM', 'чек без анкеты');
define('ST_DUP', 'дубль');
define('ST_TEST', 'тест');

function vkl_b64url($s) {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** Токен доступа Google по сервисному аккаунту. Кэшируем на час. */
function vkl_google_token() {
    $cache = dirname(__DIR__) . '/secrets/token.json';
    if (file_exists($cache)) {
        $c = json_decode(file_get_contents($cache), true);
        if (is_array($c) && ($c['exp'] ?? 0) > time() + 120) { return $c['token']; }
    }
    if (!file_exists(VKL_SA_FILE)) { return null; }
    $sa = json_decode(file_get_contents(VKL_SA_FILE), true);
    if (!is_array($sa)) { return null; }

    $now = time();
    $header = vkl_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = vkl_b64url(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/spreadsheets',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'exp'   => $now + 3600,
        'iat'   => $now,
    ]));
    $sig = '';
    openssl_sign($header . '.' . $claim, $sig, $sa['private_key'], 'sha256WithRSAEncryption');
    $jwt = $header . '.' . $claim . '.' . vkl_b64url($sig);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (empty($res['access_token'])) { return null; }
    @file_put_contents($cache, json_encode(['token' => $res['access_token'], 'exp' => $now + 3500]));
    return $res['access_token'];
}

function vkl_sheet_log($msg) {
    @file_put_contents(dirname(__DIR__) . '/leads/sheets-errors.log',
        date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}

/** Запрос к Sheets API. Возвращает разобранный ответ или null при ошибке. */
function vkl_gapi($method, $path, $body = null) {
    $token = vkl_google_token();
    if (!$token) { vkl_sheet_log('нет токена Google'); return null; }

    $ch = curl_init('https://sheets.googleapis.com/v4/spreadsheets/' . VKL_SHEET_ID . $path);
    $headers = ['Authorization: Bearer ' . $token];
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CUSTOMREQUEST => $method];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        vkl_sheet_log("$method $path HTTP $code " . substr((string)$raw, 0, 500));
        return null;
    }
    $res = json_decode($raw, true);
    return is_array($res) ? $res : [];
}

function vkl_range($a1) {
    return rawurlencode(VKL_SHEET_NAME . '!' . $a1);
}

/** Все строки листа, включая заголовок. null — таблица недоступна. */
function vkl_sheet_rows() {
    $res = vkl_gapi('GET', '/values/' . vkl_range('A:' . VKL_SHEET_LAST_COL));
    if ($res === null) { return null; }
    return $res['values'] ?? [];
}

/** Добавляем строку участника в конец листа. */
function vkl_sheet_append(array $row) {
    $res = vkl_gapi('POST', '/values/' . vkl_range('A:' . VKL_SHEET_LAST_COL)
        . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS', ['values' => [$row]]);
    return $res !== null;
}

/** Записываем несколько ячеек одним запросом: ['B5' => 'чек получен', ...]. */
function vkl_sheet_set_cells(array $cells) {
    if (!$cells) { return true; }
    $data = [];
    foreach ($cells as $a1 => $value) {
        $data[] = ['range' => VKL_SHEET_NAME . '!' . $a1, 'values' => [[$value]]];
    }
    $res = vkl_gapi('POST', '/values:batchUpdate', ['valueInputOption' => 'RAW', 'data' => $data]);
    return $res !== null;
}

/** Ячейка-ссылка без формул: текст + адрес. $row и $col — с нуля. */
function vkl_link_request($row, $col, $text, $url) {
    return ['updateCells' => [
        'range' => ['sheetId' => VKL_SHEET_GID, 'startRowIndex' => $row, 'endRowIndex' => $row + 1,
                    'startColumnIndex' => $col, 'endColumnIndex' => $col + 1],
        'rows' => [['values' => [[
            'userEnteredValue' => ['stringValue' => $text],
            'textFormatRuns' => [['startIndex' => 0, 'format' => ['link' => ['uri' => $url]]]],
        ]]]],
        'fields' => 'userEnteredValue,textFormatRuns',
    ]];
}

function vkl_sheet_batch(array $requests) {
    if (!$requests) { return true; }
    return vkl_gapi('POST', ':batchUpdate', ['requests' => $requests]) !== null;
}

/** Номер столбца (с нуля) → буква. */
function vkl_col($i) {
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) { $s = chr(65 + ($i - 1) % 26) . $s; }
    return $s;
}
