<?php
/**
 * Участники конференции для организаторов. Источник — Google-таблица (там все анкеты и дубли),
 * здесь каждый человек один раз: номер по дате первой регистрации, свежие сверху.
 * Категорию и «Как оплатил» можно менять прямо здесь — они пишутся в таблицу.
 * Логин и пароль — в secrets/panel-auth.txt вне папки сайта.
 */
define('VKL_BOOT', 1);
require __DIR__ . '/bot-lib.php';
vkl_require_auth('Участники');

define('VKL_SHEET_CACHE', VKL_LEADS . '/sheet-cache.json');
define('VKL_PAYWAYS', json_encode(['', PAY_CARD, PAY_CASH1000, PAY_CASH500, PAY_FREE], JSON_UNESCAPED_UNICODE));

/** Таблица с коротким кэшем: правки в Google-таблице видны здесь через несколько секунд. */
function vkl_rows_cached($ttl = 10) {
    $c = vkl_json_load(VKL_SHEET_CACHE);
    if (isset($c['at'], $c['rows']) && time() - $c['at'] < $ttl && !isset($_GET['fresh'])) { return [$c['rows'], $c['at']]; }
    $rows = vkl_sheet_rows();
    if ($rows === null) { return [$c['rows'] ?? null, $c['at'] ?? 0]; }
    vkl_json_save(VKL_SHEET_CACHE, ['at' => time(), 'rows' => $rows]);
    return [$rows, time()];
}

function rub($n) { return number_format($n, 0, '', ' ') . ' ₽'; }
function vkl_pay_text($p) {
    if ($p['state'] === 'paid') { return 'оплачено · ' . rub($p['amount']); }
    if ($p['state'] === 'free') { return 'не требуется'; }
    return 'не оплачено · ' . rub($p['amount']);
}

/**
 * Правка со страницы: категория или «Как оплатил». Принимаем только fetch со своим заголовком
 * (чужой сайт так не отправит). Статус в таблице подстраивается, ответ — пересчитанная строка.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['action'] ?? '') === 'set') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true);
    $row = (int)($in['row'] ?? 0);
    $field = (string)($in['field'] ?? '');
    $value = (string)($in['value'] ?? '');
    $rows = vkl_sheet_rows();
    $okInput = ($_SERVER['HTTP_X_VKL'] ?? '') === '1' && $rows !== null && $row >= 2 && $row <= count($rows)
        && !vkl_is_service_row($rows[$row - 1])
        && (($field === 'role' && in_array($value, [ROLE_PART, ROLE_SERV, ROLE_ORG], true))
            || ($field === 'payway' && in_array($value, json_decode(VKL_PAYWAYS, true), true)));
    if (!$okInput) { http_response_code(400); echo '{"ok":false}'; exit; }

    $r = $rows[$row - 1];
    $status = trim($r[COL_STATUS] ?? '');
    $role = trim($r[COL_ROLE] ?? '') ?: ROLE_PART;
    $hasReceipt = trim($r[COL_RECEIPT] ?? '') !== '' || trim($r[COL_RTIME] ?? '') !== '';
    if ($field === 'role') {
        $cells = ['W' . $row => $value];
        $r[COL_ROLE] = $value;
        $cells += vkl_status_cells($row, $r);
    } else {
        $cells = ['Y' . $row => $value];
        if (vkl_is_paid_way($value) && in_array($status, ['', ST_WAIT, ST_FREE, ST_RECEIPT], true)) {
            $cells['B' . $row] = ST_PAID;                       // проверяющий подтвердил оплату
        } elseif ($value === '' && in_array($status, [ST_PAID, ST_FREE], true)) {
            $cells['B' . $row] = $hasReceipt ? ST_RECEIPT : ($role === ROLE_ORG ? ST_FREE : ST_WAIT);   // отметку сняли
        } else {
            $r[COL_PAYWAY] = $value;
            $cells += vkl_status_cells($row, $r);
        }
    }
    $ok = vkl_sheet_set_cells($cells);
    @unlink(VKL_SHEET_CACHE);
    if (!$ok) { echo '{"ok":false}'; exit; }

    foreach ($cells as $a1 => $v) {
        $col = ['B' => COL_STATUS, 'W' => COL_ROLE, 'Y' => COL_PAYWAY][$a1[0]];
        $rows[$row - 1][$col] = $v;
    }
    $who = trim(($r[COL_LAST] ?? '') . ' ' . ($r[COL_FIRST] ?? ''));
    vkl_log('bot.log', 'вручную со страницы: строка ' . $row . ' (' . $who . ') ' . ($field === 'role' ? 'категория' : 'как оплатил')
        . ' → «' . ($value === '' ? '—' : $value) . '»' . (isset($cells['B' . $row]) ? ', статус → «' . $cells['B' . $row] . '»' : ''));
    $out = ['ok' => true];
    foreach (vkl_participants($rows) as $p) {
        if ($p['row'] === $row) {
            $out += ['state' => $p['state'], 'amount' => $p['amount'], 'cash' => $p['cash'], 'role' => $p['role'],
                     'payway' => $p['payway'], 'text' => vkl_pay_text($p)];
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

list($rows, $at) = vkl_rows_cached();
if ($rows === null) { http_response_code(503); exit('Таблица участников сейчас недоступна, попробуйте через минуту.'); }
$people = vkl_participants($rows);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function vkl_date($s) { $t = strtotime((string)$s); return $t ? date('d.m.Y H:i', $t) : (string)$s; }
function vkl_tg_nick($s) {
    $t = ltrim(preg_replace('#^https?://t\.me/#i', '', trim((string)$s)), '@');
    return preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $t) ? $t : '';
}
function vkl_fio($r) { return trim(($r[COL_LAST] ?? '') . ' ' . ($r[COL_FIRST] ?? '') . ' ' . ($r[COL_PATR] ?? '')); }

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_map(function ($p) {
        $r = $p['r'];
        return ['num' => $p['num'], 'registered' => $p['first'], 'name' => vkl_fio($r), 'phone' => $r[COL_PHONE] ?? '',
                'telegram' => $r[COL_TG] ?? '', 'city' => $p['city'], 'age' => $r[COL_AGE] ?? '', 'role' => $p['role'],
                'state' => $p['state'], 'payway' => $p['payway'], 'amount' => $p['amount'], 'church' => $r[COL_CHURCH] ?? ''];
    }, $people), JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=windows-1251');
    header('Content-Disposition: attachment; filename="vkl-svet-uchastniki.csv"');
    $out = fopen('php://output', 'w');
    $enc = function ($v) { return iconv('UTF-8', 'Windows-1251//TRANSLIT', (string)$v); };
    fputcsv($out, array_map($enc, ['№', 'Дата регистрации', 'ФИО', 'Телефон', 'Telegram', 'Город', 'Возраст', 'Категория',
        'Оплата', 'Сумма', 'Как оплатил', 'Церковь', 'Сопровождающий', 'Телефон сопровождающего', 'Вопрос', 'Дата рождения',
        'Пол', 'Почта']), ';');
    foreach ($people as $p) {
        $r = $p['r'];
        $pay = ['paid' => 'оплачено', 'wait' => 'не оплачено', 'free' => 'не требуется'][$p['state']];
        fputcsv($out, array_map($enc, [$p['num'], vkl_date($p['first']), vkl_fio($r), $r[COL_PHONE] ?? '', $r[COL_TG] ?? '',
            $p['city'], $r[COL_AGE] ?? '', $p['role'], $pay, $p['state'] === 'free' ? '' : $p['amount'], $p['payway'],
            $r[COL_CHURCH] ?? '', $r[COL_GNAME] ?? '', $r[COL_GPHONE] ?? '', $r[COL_COMMENT] ?? '', $r[COL_BIRTH] ?? '',
            $r[COL_GENDER] ?? '', $r[COL_EMAIL] ?? '']), ';');
    }
    exit;
}

$cities = $churches = [];
foreach ($people as $p) {
    if ($p['city'] !== '') { $cities[$p['city']] = ($cities[$p['city']] ?? 0) + 1; }
    if ($p['church'] !== '') { $churches[$p['church']] = ($churches[$p['church']] ?? 0) + 1; }
}
arsort($cities); arsort($churches);
$payways = json_decode(VKL_PAYWAYS, true);
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Участники — Включите свет 2026</title>
<style>
  :root { --bg:#16150F; --card:#1F1D16; --line:rgba(232,223,206,.14); --text:#E8DFCE; --dim:#A9A08D;
          --green:#4CBD49; --green-bg:rgba(76,189,73,.14); --red:#E8907A; --red-bg:rgba(232,144,122,.13);
          --yellow:#FFF7AE; --yellow-bg:rgba(255,247,174,.12); --blue:#9CC3EA; --blue-bg:rgba(156,195,234,.13); }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font:15px/1.45 -apple-system,Segoe UI,Arial,sans-serif; }
  .wrap { max-width:1640px; margin:0 auto; padding:24px 16px 60px; }
  .top { display:flex; flex-wrap:wrap; align-items:baseline; justify-content:space-between; gap:10px 20px; margin-bottom:18px; }
  h1 { font-size:22px; margin:0; }
  .meta { color:var(--dim); font-size:13px; }
  .meta a { color:var(--dim); }
  .meta a.btn { color:var(--bg); }
  .btn { display:inline-block; padding:8px 14px; background:var(--green); color:var(--bg); font-weight:700;
         text-decoration:none; border:0; border-radius:2px; font-size:13px; cursor:pointer; }
  .btn--ghost { background:transparent; color:var(--text); border:1px solid var(--line); }

  .dash { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:18px; }
  .kpi { background:var(--card); border:1px solid var(--line); padding:14px 16px; }
  .kpi__l { color:var(--dim); font-size:12px; text-transform:uppercase; letter-spacing:.06em; }
  .kpi__v { font-size:28px; font-weight:700; margin-top:4px; font-variant-numeric:tabular-nums; }
  .kpi__s { color:var(--dim); font-size:13px; margin-top:2px; }
  .kpi--paid .kpi__v { color:var(--green); }
  .kpi--wait .kpi__v { color:var(--red); }

  .filters { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:12px; }
  .filters input, .filters select, select.edit { background:var(--card); color:var(--text); border:1px solid var(--line);
         padding:8px 10px; font:inherit; font-size:14px; border-radius:2px; }
  .filters input { min-width:220px; flex:1 1 220px; max-width:320px; }
  .shown { color:var(--dim); font-size:13px; margin-left:auto; }

  .tbl { overflow-x:auto; border:1px solid var(--line); }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  th, td { padding:8px 10px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
  th { color:var(--green); font-size:11.5px; text-transform:uppercase; letter-spacing:.05em; position:sticky; top:0;
       background:var(--bg); white-space:nowrap; z-index:1; }
  tr:hover td { background:rgba(232,223,206,.035); }
  td.num { color:var(--dim); font-variant-numeric:tabular-nums; white-space:nowrap; }
  td.nw { white-space:nowrap; }
  td.fio { min-width:200px; }
  td a { color:var(--text); text-decoration:none; border-bottom:1px dotted var(--dim); }
  select.edit { padding:4px 6px; font-size:13px; }
  select.edit.is-saving { opacity:.5; }
  .saved { color:var(--green); font-size:12px; margin-left:4px; }
  .sub { color:var(--dim); font-size:12px; }
  .minor { display:inline-block; margin-left:6px; padding:0 6px; font-size:11px; color:var(--yellow); background:var(--yellow-bg); }
  .pay { display:inline-block; padding:2px 8px; font-size:12.5px; font-weight:600; white-space:nowrap; }
  .pay--paid { color:var(--green); background:var(--green-bg); }
  .pay--wait { color:var(--red); background:var(--red-bg); }
  .pay--free { color:var(--blue); background:var(--blue-bg); }
  .note { color:var(--dim); font-size:12px; margin-top:10px; }
  .empty { padding:30px; text-align:center; color:var(--dim); }

  @media (max-width:800px) {
    .dash { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .kpi__v { font-size:22px; }
    .shown { margin-left:0; width:100%; }
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1>Участники «Включите свет 2026»</h1>
    <div class="meta">
      Данные таблицы на <?= date('H:i', $at) ?> · <a href="?fresh=1">обновить</a>
      &nbsp; <a class="btn" href="?csv=1">Скачать для Excel</a>
    </div>
  </div>

  <div class="dash">
    <div class="kpi"><div class="kpi__l">Регистраций</div><div class="kpi__v" id="kReg">—</div><div class="kpi__s" id="kRegS"></div></div>
    <div class="kpi"><div class="kpi__l">Городов</div><div class="kpi__v" id="kCity">—</div><div class="kpi__s" id="kCityS"></div></div>
    <div class="kpi kpi--paid"><div class="kpi__l">Оплачено</div><div class="kpi__v" id="kPaid">—</div><div class="kpi__s" id="kPaidS"></div></div>
    <div class="kpi kpi--wait"><div class="kpi__l">Не оплачено</div><div class="kpi__v" id="kWait">—</div><div class="kpi__s" id="kWaitS"></div></div>
  </div>

  <div class="filters">
    <input type="search" id="fq" placeholder="Поиск: имя, телефон, ник">
    <select id="fcity"><option value="">Все города</option>
      <?php foreach ($cities as $c => $n): ?><option value="<?= h($c) ?>"><?= h($c) ?> (<?= $n ?>)</option><?php endforeach; ?>
    </select>
    <select id="fage">
      <option value="">Любой возраст</option>
      <option value="0-17">до 18 лет</option>
      <option value="18-200">18 и старше</option>
      <option value="0-13">до 14</option>
      <option value="14-15">14–15</option>
      <option value="16-17">16–17</option>
      <option value="18-24">18–24</option>
      <option value="25-34">25–34</option>
      <option value="35-200">35 и старше</option>
    </select>
    <select id="fchurch"><option value="">Все церкви</option>
      <?php foreach ($churches as $c => $n): ?><option value="<?= h($c) ?>"><?= h($c) ?> (<?= $n ?>)</option><?php endforeach; ?>
    </select>
    <select id="frole">
      <option value="">Все категории</option>
      <option value="<?= ROLE_PART ?>">Участники</option>
      <option value="<?= ROLE_SERV ?>">Служители</option>
      <option value="<?= ROLE_ORG ?>">Организаторы</option>
    </select>
    <select id="fstate">
      <option value="">Оплата: все</option>
      <option value="paid">Оплачено</option>
      <option value="wait">Не оплачено</option>
      <option value="free">Не требуется</option>
    </select>
    <select id="fway">
      <option value="">Как оплатил: все</option>
      <option value="<?= PAY_CARD ?>">Безнал</option>
      <option value="cash">Наличка</option>
      <option value="<?= PAY_FREE ?>">Бесплатно</option>
      <option value="none">Не отмечено</option>
    </select>
    <button class="btn btn--ghost" id="freset" type="button">Сбросить</button>
    <span class="shown" id="shown"></span>
  </div>

  <div class="tbl">
  <table>
    <thead><tr>
      <th>№</th><th>Дата регистрации</th><th>ФИО</th><th>Телефон</th><th>Telegram</th><th>Город</th>
      <th>Возраст</th><th>Категория</th><th>Оплата</th><th>Как оплатил</th><th>Церковь</th><th>Сопровождающий</th><th>Вопрос</th>
    </tr></thead>
    <tbody id="tb">
    <?php foreach ($people as $p): $r = $p['r']; $age = trim($r[COL_AGE] ?? ''); $nick = vkl_tg_nick($r[COL_TG] ?? '');
          $phone = trim($r[COL_PHONE] ?? ''); $gname = trim($r[COL_GNAME] ?? ''); $gphone = trim($r[COL_GPHONE] ?? '');
          $search = mb_strtolower(vkl_fio($r) . ' ' . $phone . ' ' . preg_replace('/\D/', '', $phone) . ' ' . ($r[COL_TG] ?? '')); ?>
      <tr data-row="<?= $p['row'] ?>" data-city="<?= h($p['city']) ?>" data-church="<?= h($p['church']) ?>" data-age="<?= h($age) ?>"
          data-role="<?= h($p['role']) ?>" data-state="<?= $p['state'] ?>" data-amount="<?= $p['amount'] ?>"
          data-payway="<?= h($p['payway']) ?>" data-cash="<?= $p['cash'] ? 1 : 0 ?>" data-q="<?= h($search) ?>">
        <td class="num"><?= $p['num'] ?></td>
        <td class="num"><?= h(vkl_date($p['first'])) ?></td>
        <td class="fio"><?= h(vkl_fio($r)) ?><?php if ($p['forms'] > 1): ?><div class="sub">анкет: <?= $p['forms'] ?></div><?php endif; ?></td>
        <td class="nw"><?php if ($phone !== ''): ?><a href="tel:<?= h(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= h($phone) ?></a><?php endif; ?></td>
        <td class="nw"><?php if ($nick !== ''): ?><a href="https://t.me/<?= h($nick) ?>" target="_blank" rel="noopener">@<?= h($nick) ?></a><?php else: ?><?= h($r[COL_TG] ?? '') ?><?php endif; ?></td>
        <td><?= h($p['city']) ?></td>
        <td class="nw"><?= h($age) ?><?php if ($age !== '' && (int)$age < 18): ?><span class="minor">до 18</span><?php endif; ?></td>
        <td class="nw"><select class="edit" data-field="role" aria-label="Категория">
          <?php foreach ([ROLE_PART, ROLE_SERV, ROLE_ORG] as $ro): ?><option<?= $ro === $p['role'] ? ' selected' : '' ?>><?= $ro ?></option><?php endforeach; ?>
        </select></td>
        <td><span class="pay pay--<?= $p['state'] ?>"><?= h(vkl_pay_text($p)) ?></span></td>
        <td class="nw"><select class="edit" data-field="payway" aria-label="Как оплатил">
          <?php foreach ($payways as $pw): ?><option value="<?= h($pw) ?>"<?= $pw === $p['payway'] ? ' selected' : '' ?>><?= $pw === '' ? '—' : h($pw) ?></option><?php endforeach; ?>
        </select></td>
        <td><?= h($r[COL_CHURCH] ?? '') ?></td>
        <td><?= h($gname) ?><?php if ($gphone !== ''): ?><div class="sub"><a href="tel:<?= h(preg_replace('/[^\d+]/', '', $gphone)) ?>"><?= h($gphone) ?></a></div><?php endif; ?></td>
        <td><?= h($r[COL_COMMENT] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="empty" id="empty" hidden>Никого не нашлось — измените фильтры.</div>
  </div>
  <p class="note">Каждый человек показан один раз: повторные анкеты склеены, номер — по дате первой регистрации.
    Полные данные со всеми анкетами — в Google-таблице. «Как оплатил»: безнал или наличка — оплата подтверждена,
    «бесплатно» — платить не нужно, «—» — снять отметку. Ожидаемая сумма: участник — <?= rub(vkl_expected(ROLE_PART)) ?>, служитель — <?= rub(vkl_expected(ROLE_SERV)) ?>,
    организатор — не требуется. Если пожертвование отличается, впишите сумму в столбец «Получено, ₽» в Google-таблице.</p>
</div>

<script>
(function () {
  var CARD = <?= json_encode(PAY_CARD, JSON_UNESCAPED_UNICODE) ?>, ORG = <?= json_encode(ROLE_ORG, JSON_UNESCAPED_UNICODE) ?>,
      SERV = <?= json_encode(ROLE_SERV, JSON_UNESCAPED_UNICODE) ?>, FREEWAY = <?= json_encode(PAY_FREE, JSON_UNESCAPED_UNICODE) ?>;
  var rows = Array.prototype.slice.call(document.querySelectorAll('#tb tr'));
  var $ = function (id) { return document.getElementById(id); };
  var f = { q: $('fq'), city: $('fcity'), age: $('fage'), church: $('fchurch'), role: $('frole'), state: $('fstate'), way: $('fway') };
  var money = function (n) { return n.toLocaleString('ru-RU').replace(/\s/g, ' ') + ' ₽'; };
  var people = function (n) {
    var m10 = n % 10, m100 = n % 100;
    return n + ((m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) ? ' человека' : ' человек');
  };
  var wayOk = function (d) {
    var w = f.way.value;
    if (!w) return true;
    if (w === 'cash') return d.cash === '1';
    if (w === 'none') return d.payway === '';
    return d.payway === w;
  };

  function apply() {
    var q = f.q.value.trim().toLowerCase(), qd = q.replace(/\D/g, '');
    var age = f.age.value ? f.age.value.split('-').map(Number) : null;
    var shown = 0, cities = {}, paidN = 0, paidSum = 0, cashSum = 0, waitN = 0, waitSum = 0, minors = 0, orgs = 0, servs = 0;
    rows.forEach(function (tr) {
      var d = tr.dataset, a = parseInt(d.age, 10);
      var ok = (!f.city.value || d.city === f.city.value)
        && (!f.church.value || d.church === f.church.value)
        && (!f.role.value || d.role === f.role.value)
        && (!f.state.value || d.state === f.state.value)
        && wayOk(d)
        && (!age || (!isNaN(a) && a >= age[0] && a <= age[1]))
        && (!q || d.q.indexOf(q) !== -1 || (qd.length >= 4 && d.q.indexOf(qd) !== -1));
      tr.hidden = !ok;
      if (!ok) return;
      shown++;
      if (d.city) cities[d.city] = 1;
      if (!isNaN(a) && a < 18) minors++;
      if (d.role === ORG) orgs++;
      if (d.role === SERV) servs++;
      if (d.state === 'paid') { paidN++; paidSum += +d.amount; if (d.cash === '1') cashSum += +d.amount; }
      if (d.state === 'wait') { waitN++; waitSum += +d.amount; }
    });
    var cityList = Object.keys(cities);
    $('kReg').textContent = shown;
    $('kRegS').textContent = 'до 18 лет: ' + minors + ' · служителей: ' + servs + ' · организаторов: ' + orgs;
    $('kCity').textContent = cityList.length;
    $('kCityS').textContent = cityList.length === 1 ? cityList[0] : '';
    $('kPaid').textContent = money(paidSum);
    $('kPaidS').textContent = people(paidN) + ' · наличными ' + money(cashSum);
    $('kWait').textContent = money(waitSum);
    $('kWaitS').textContent = people(waitN) + (orgs ? ' · организаторы не считаются' : '');
    $('shown').textContent = shown === rows.length ? 'Всего: ' + rows.length : 'Показано: ' + shown + ' из ' + rows.length;
    $('empty').hidden = shown > 0;
    try {
      var s = new URLSearchParams();
      Object.keys(f).forEach(function (k) { if (f[k].value) s.set(k, f[k].value); });
      history.replaceState(null, '', s.toString() ? '#' + s.toString() : location.pathname + location.search);
    } catch (e) {}
  }

  /* правка категории или «Как оплатил»: сразу пишем в Google-таблицу, строку пересчитывает сервер */
  rows.forEach(function (tr) {
    Array.prototype.forEach.call(tr.querySelectorAll('select.edit'), function (sel) {
      var prev = sel.value;
      sel.addEventListener('change', function () {
        var next = sel.value;
        sel.classList.add('is-saving'); sel.disabled = true;
        fetch('leads.php?action=set', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-VKL': '1' },
          body: JSON.stringify({ row: +tr.dataset.row, field: sel.dataset.field, value: next })
        }).then(function (r) { return r.json(); }).then(function (res) {
          if (!res.ok || !res.state) throw new Error('save');
          var d = tr.dataset;
          d.role = res.role; d.state = res.state; d.amount = res.amount; d.payway = res.payway; d.cash = res.cash ? '1' : '0';
          var span = tr.querySelector('.pay');
          span.className = 'pay pay--' + res.state; span.textContent = res.text;
          prev = next; apply();
          var ok = document.createElement('span'); ok.className = 'saved'; ok.textContent = '✓';
          sel.parentNode.appendChild(ok); setTimeout(function () { ok.remove(); }, 1500);
        }).catch(function () {
          sel.value = prev;
          alert('Не удалось сохранить. Проверьте интернет и попробуйте ещё раз.');
        }).then(function () { sel.classList.remove('is-saving'); sel.disabled = false; });
      });
    });
  });

  try {
    var init = new URLSearchParams(location.hash.slice(1));
    Object.keys(f).forEach(function (k) { if (init.get(k)) f[k].value = init.get(k); });
  } catch (e) {}
  Object.keys(f).forEach(function (k) { f[k].addEventListener(k === 'q' ? 'input' : 'change', apply); });
  $('freset').addEventListener('click', function () { Object.keys(f).forEach(function (k) { f[k].value = ''; }); apply(); });
  apply();
})();
</script>
</body>
</html>
