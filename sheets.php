<?php
/**
 * Запись анкеты в Google-таблицу напрямую с сервера.
 * Нужен, чтобы таблица заполнялась сама, без промежуточных скриптов на чьём-то компьютере.
 */

define('VKL_SHEET_ID', '1guyrmCKxsNvMBnL_NxHemu0UDiuO1Su821D2vNy6nQk');
define('VKL_SHEET_NAME', 'Участники');
define('VKL_SA_FILE', dirname(__DIR__) . '/secrets/google-sa.json');

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

/** Добавляем строку участника в конец листа. */
function vkl_sheet_append(array $row) {
    $token = vkl_google_token();
    if (!$token) { return false; }

    $range = rawurlencode(VKL_SHEET_NAME) . '!A:R';
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . VKL_SHEET_ID
         . '/values/' . $range . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['values' => [$row]], JSON_UNESCAPED_UNICODE),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        @file_put_contents(dirname(__DIR__) . '/leads/sheets-errors.log',
            date('Y-m-d H:i:s') . " HTTP $code $body\n", FILE_APPEND);
        return false;
    }
    return true;
}
