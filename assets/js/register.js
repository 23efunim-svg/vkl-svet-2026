/* ==========================================================================
   ВКЛЮЧИТЕ СВЕТ 2026 — форма регистрации и оплата
   Подпись платежа считается ТОЛЬКО на бэкенде. Пароли Робокассы сюда не попадают.
   ========================================================================== */
(function () {
  'use strict';

  var $ = window.VKL.$, $$ = window.VKL.$$, reduced = window.VKL.reduced;

  /* --- Настройки ---------------------------------------------------------- */
  var CONFIG = {
    // адрес функции Yandex Cloud, которая говорит с ЮKassa
    API: '',                       // например https://functions.yandexcloud.net/d4xxxxxxxxxx
    AMOUNT_MIN: 1000,
    CONF_DATE: '2026-11-26',
    DRAFT_KEY: 'vkl2026_reg_draft'
  };
  // пока бэкенд не развёрнут, форма работает в демонстрационном режиме
  CONFIG.DEMO = !CONFIG.API;

  var form = $('#regForm');
  if (!form) return;

  var STEP_NAMES = { 1: 'Анкета участника', 2: 'Проверка данных', 3: 'Оплата и чек', 4: 'Готово' };
  var startedAt = Date.now();

  /* --- Переключение шагов -------------------------------------------------- */
  var goStep = function (n) {
    $$('.form-step').forEach(function (s) {
      s.classList.toggle('is-active', Number(s.dataset.step) === n);
    });
    $$('.step-dot').forEach(function (d) {
      var i = Number(d.dataset.step);
      d.classList.toggle('is-done', i < n);
      d.classList.toggle('is-active', i === n);
    });
    $('#stepNow').textContent = String(n);
    $('#stepName').textContent = STEP_NAMES[n];
    window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
  };

  /* --- Возраст на дату конференции ----------------------------------------- */
  var ageOnConf = function (iso) {
    if (!iso) return null;
    var d = new Date(iso);
    if (isNaN(d.getTime())) return null;
    var conf = new Date(CONFIG.CONF_DATE);
    var a = conf.getFullYear() - d.getFullYear();
    var m = conf.getMonth() - d.getMonth();
    if (m < 0 || (m === 0 && conf.getDate() < d.getDate())) a--;
    return a;
  };

  var yearsWord = function (n) {
    var m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return 'год';
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return 'года';
    return 'лет';
  };

  var birth = $('#birthDate');
  var ageBlock = $('#ageBlock');

  var renderAgeBlock = function () {
    var a = ageOnConf(birth.value);
    if (a === null || a < 0) { ageBlock.hidden = true; ageBlock.innerHTML = ''; return; }

    // возрастного ограничения нет; младше 18 — обязательно сопровождающий
    if (a < 18) {
      ageBlock.hidden = false;
      ageBlock.innerHTML =
        '<div class="age-note">' +
        '<b>Тебе на конференции будет ' + a + ' ' + yearsWord(a) + '.</b> Понадобится доверенность от родителей ' +
        'на совершеннолетнего сопровождающего, её нужно иметь при себе все дни. ' +
        'Бланк пришлём после регистрации.' +
        '</div>' +
        '<div class="row2">' +
          '<div class="field"><label for="guardianName">ФИО сопровождающего <span class="req">*</span></label>' +
          '<input type="text" id="guardianName" name="guardianName" required>' +
          '<div class="field__err">Укажи, кто будет сопровождающим</div></div>' +
          '<div class="field"><label for="guardianPhone">Телефон сопровождающего <span class="req">*</span></label>' +
          '<input type="tel" id="guardianPhone" name="guardianPhone" placeholder="+7 900 000-00-00" required>' +
          '<div class="field__err">Укажи телефон полностью</div></div>' +
        '</div>' +
        '<label class="check"><input type="checkbox" id="docsOk" name="docsOk">' +
        '<span>Я знаю про документы и привезу оформленный оригинал</span></label>';
      attachPhoneMask($('#guardianPhone'));
      return;
    }

    ageBlock.hidden = true;
    ageBlock.innerHTML = '';
  };
  birth.addEventListener('change', renderAgeBlock);

  /* --- Маска телефона ------------------------------------------------------- */
  function attachPhoneMask(input) {
    if (!input || input.dataset.masked) return;
    input.dataset.masked = '1';
    input.addEventListener('input', function () {
      var d = input.value.replace(/\D/g, '');
      if (!d) { input.value = ''; return; }
      if (d[0] === '8') d = '7' + d.slice(1);
      if (d[0] !== '7') d = '7' + d;
      d = d.slice(0, 11);
      var out = '+7';
      if (d.length > 1) out += ' ' + d.slice(1, 4);
      if (d.length >= 5) out += ' ' + d.slice(4, 7);
      if (d.length >= 8) out += '-' + d.slice(7, 9);
      if (d.length >= 10) out += '-' + d.slice(9, 11);
      input.value = out;
    });
  }
  attachPhoneMask($('#phone'));

  /* --- Церковь: чекбокс «не посещаю» --------------------------------------- */
  var noChurch = $('#noChurch');
  var church = $('#church');
  noChurch.addEventListener('change', function () {
    church.disabled = noChurch.checked;
    if (noChurch.checked) {
      church.value = '';
      church.closest('.field').classList.remove('field--err');
    }
  });

  /* --- Валидация ------------------------------------------------------------ */
  var isPhone = function (v) { return v.replace(/\D/g, '').length === 11; };
  var isEmail = function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v.trim()); };

  var setErr = function (el, on) {
    var f = el.closest('.field');
    if (f) f.classList.toggle('field--err', on);
  };

  var validateStep1 = function () {
    var firstBad = null;
    var bad = function (el) { setErr(el, true); if (!firstBad) firstBad = el; };

    ['lastName', 'firstName', 'city'].forEach(function (id) {
      var el = $('#' + id);
      var v = el.value.trim();
      if (v.length < 2) { bad(el); } else { setErr(el, false); }
    });

    if (!birth.value) { bad(birth); } else { setErr(birth, false); }

    var age = ageOnConf(birth.value);
    if (age !== null && age < 0) { bad(birth); }     // дата рождения позже конференции

    var gender = form.querySelector('input[name="gender"]:checked');
    var genderField = form.querySelector('input[name="gender"]');
    if (!gender) { bad(genderField); } else { setErr(genderField, false); }

    var phone = $('#phone');
    if (!isPhone(phone.value)) { bad(phone); } else { setErr(phone, false); }

    var email = $('#email');
    if (!isEmail(email.value)) { bad(email); } else { setErr(email, false); }

    if (!noChurch.checked && church.value.trim().length < 2) { bad(church); }
    else { setErr(church, false); }

    // несовершеннолетним нужны данные сопровождающего и отчество
    if (age !== null && age >= 0 && age < 18) {
      var gn = $('#guardianName'), gp = $('#guardianPhone'), pt = $('#patronymic');
      if (gn && gn.value.trim().length < 3) { bad(gn); } else if (gn) { setErr(gn, false); }
      if (gp && !isPhone(gp.value)) { bad(gp); } else if (gp) { setErr(gp, false); }
      if (pt.value.trim().length < 2) { bad(pt); } else { setErr(pt, false); }
    }

    if (firstBad) {
      firstBad.focus();
      firstBad.scrollIntoView({ block: 'center', behavior: reduced ? 'auto' : 'smooth' });
      return false;
    }
    return true;
  };

  /* --- Сбор данных ---------------------------------------------------------- */
  var collect = function () {
    var o = {};
    new FormData(form).forEach(function (v, k) {
      o[k] = typeof v === 'string' ? v.trim() : v;
    });
    if (noChurch.checked) o.church = 'Не посещает церковь';
    o.amount = currentAmount();
    o.age = ageOnConf(o.birthDate);
    o.filledInMs = Date.now() - startedAt;
    return o;
  };

  /* --- Черновик в браузере -------------------------------------------------- */
  var saveDraft = function () {
    try { localStorage.setItem(CONFIG.DRAFT_KEY, JSON.stringify(collect())); } catch (e) {}
  };
  var loadDraft = function () {
    var raw;
    try { raw = localStorage.getItem(CONFIG.DRAFT_KEY); } catch (e) { return; }
    if (!raw) return;
    var d;
    try { d = JSON.parse(raw); } catch (e) { return; }
    Object.keys(d).forEach(function (k) {
      var el = form.elements[k];
      if (!el) return;
      if (el instanceof RadioNodeList || (el.length && el[0] && el[0].type === 'radio')) {
        Array.prototype.forEach.call(el, function (r) { if (r.value === d[k]) r.checked = true; });
      } else if (el.type === 'checkbox') {
        el.checked = Boolean(d[k]);
      } else if (el.type !== 'hidden' && typeof d[k] === 'string') {
        el.value = d[k];
      }
    });
    if (d.birthDate) renderAgeBlock();
    if (noChurch.checked) church.disabled = true;
  };
  form.addEventListener('input', saveDraft);
  form.addEventListener('change', saveDraft);
  loadDraft();

  /* --- Сводка на шаге 2 ------------------------------------------------------ */
  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  var fillSummary = function () {
    var d = collect();
    var fio = [d.lastName, d.firstName, d.patronymic].filter(Boolean).join(' ');
    var rows = [
      ['Участник', fio],
      ['Дата рождения', d.birthDate ? d.birthDate.split('-').reverse().join('.') : ''],
      ['Пол', d.gender],
      ['Город', d.city],
      ['Церковь', d.church],
      ['Телефон', d.phone],
      ['Почта', d.email]
    ];
    if (d.telegram) rows.push(['Telegram', d.telegram]);
    if (d.guardianName) rows.push(['Сопровождающий', d.guardianName + ', ' + d.guardianPhone]);

    var html = rows.filter(function (r) { return r[1]; }).map(function (r) {
      return '<div class="summary__row"><dt>' + r[0] + '</dt><dd>' + escapeHtml(r[1]) + '</dd></div>';
    }).join('');

    html += '<div class="summary__row summary__row--total"><dt>Добровольное пожертвование</dt>' +
            '<dd>' + currentAmount() + ' <span class="rub">₽</span></dd></div>';

    $('#summary').innerHTML = html;
  };

  /* --- Навигация ------------------------------------------------------------- */
  $$('[data-go]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var to = Number(btn.dataset.go);
      if (to === 2) {
        if (!validateStep1()) return;
        fillSummary();
      }
      goStep(to);
    });
  });

  /* --- Переход к оплате ------------------------------------------------------ */
  $('#toPay').addEventListener('click', function () {
    var okPd = $('#agreePd').checked;
    var okOffer = $('#agreeOffer').checked;
    $('#agreeErr').style.display = (okPd && okOffer) ? 'none' : 'block';
    if (!okPd || !okOffer) return;

    var data = collect();

    // защита от ботов: скрытое поле и слишком быстрое заполнение
    if (data.website || data.filledInMs < 3000) {
      alert('Проверь, пожалуйста, форму ещё раз.');
      return;
    }

    var btn = $('#toPay');
    btn.disabled = true;
    btn.textContent = 'Готовим...';

    try { sessionStorage.setItem('vkl_reg_last', JSON.stringify(data)); } catch (e) {}

    var showPay = function () {
      btn.disabled = false;
      btn.textContent = 'Перейти к оплате';
      goStep(3);
      try { localStorage.removeItem(CONFIG.DRAFT_KEY); } catch (e) {}
    };

    // анкета уходит в журнал на сервере до оплаты, чтобы ничего не терялось;
    // в ответ приходит код анкеты для ссылки на бота
    fetch('save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (res && res.rid) {
        try { sessionStorage.setItem('vkl_reg_rid', res.rid); } catch (e) {}
        setBotLink(res.rid);
      }
      showPay();
    }).catch(showPay);
  });

  /* --- Копирование назначения платежа ------------------------------------- */
  var copyBtn = $('#copyPurpose');
  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var text = $('#purposeText').textContent.trim();
      var done = function () {
        copyBtn.textContent = 'скопировано';
        setTimeout(function () { copyBtn.textContent = 'скопировать'; }, 2000);
      };
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(done, done);
      } else {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta); done();
      }
    });
  }

  /* --- Ссылка на бота: код анкеты, по нему бот сразу находит человека ------ */
  // Telegram принимает в start только латиницу, цифры, _ и -, поэтому имя туда не кладём
  function setBotLink(rid) {
    var link = $('#botLink');
    if (link && /^r[a-z0-9]{6,16}$/.test(rid || '')) {
      link.href = link.href.split('?')[0] + '?start=' + rid;
    }
  }
  try { setBotLink(sessionStorage.getItem('vkl_reg_rid')); } catch (e) {}

  /* --- Размер пожертвования ---------------------------------------------- */
  function currentAmount() {
    var el = $('#amount');
    var v = el ? parseInt(String(el.value).replace(/\D/g, ''), 10) : NaN;
    if (!v || v < CONFIG.AMOUNT_MIN) return CONFIG.AMOUNT_MIN;
    return Math.min(v, 1000000);
  }

  var amountInput = $('#amount');
  var amountRange = $('#amountRange');

  // закрашиваем пройденную часть дорожки ползунка
  function paintRange() {
    if (!amountRange) return;
    var min = Number(amountRange.min), max = Number(amountRange.max);
    var pct = ((Number(amountRange.value) - min) / (max - min)) * 100;
    amountRange.style.setProperty('--fill', pct.toFixed(2) + '%');
  }

  if (amountInput) {
    amountInput.addEventListener('input', function () {
      amountInput.value = amountInput.value.replace(/\D/g, '').slice(0, 7);
      if (amountRange) {
        var v = Math.min(Math.max(Number(amountInput.value) || 0, 1000), 1000000);
        amountRange.value = v;
        paintRange();
      }
      updateTotal();
    });
    amountInput.addEventListener('blur', function () {
      amountInput.value = currentAmount();
      if (amountRange) { amountRange.value = currentAmount(); paintRange(); }
      updateTotal();
    });
  }

  if (amountRange) {
    amountRange.addEventListener('input', function () {
      amountInput.value = amountRange.value;
      paintRange();
      updateTotal();
    });
    paintRange();
  }

  // строка «итого» в сводке должна следовать за полем суммы
  function updateTotal() {
    var row = document.querySelector('.summary__row--total dd');
    if (row) {
      row.innerHTML = currentAmount() + ' <span class="rub">₽</span>';
    }
  }

})();
