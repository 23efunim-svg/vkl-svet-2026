/* ==========================================================================
   ВКЛЮЧИТЕ СВЕТ 2026 — общая логика: анимации, меню, параллакс
   ========================================================================== */
(function () {
  'use strict';

  var $  = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  window.VKL = { $: $, $$: $$, reduced: reduced };

  /* --- Хедер и мобильное меню -------------------------------------------- */
  var header = $('#header');
  var burger = $('#burger');
  var nav = $('#nav');

  if (header && !header.classList.contains('is-stuck')) {
    var onScroll = function () { header.classList.toggle('is-stuck', window.scrollY > 40); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  if (burger && nav) {
    burger.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      burger.classList.toggle('is-open', open);
      burger.setAttribute('aria-expanded', String(open));
      document.body.style.overflow = open ? 'hidden' : '';
    });
    $$('#nav a').forEach(function (a) {
      a.addEventListener('click', function () {
        nav.classList.remove('is-open');
        burger.classList.remove('is-open');
        burger.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
      });
    });
  }

  /* --- Появление блоков при скролле --------------------------------------- */
  var revealables = $$('.rv');
  if ('IntersectionObserver' in window && !reduced) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); }
      });
    }, { rootMargin: '0px 0px -12% 0px', threshold: 0.08 });
    revealables.forEach(function (el) { io.observe(el); });
  } else {
    revealables.forEach(function (el) { el.classList.add('is-in'); });
  }

  var heroTitle = $('.hero__title');
  if (heroTitle) { requestAnimationFrame(function () { heroTitle.classList.add('is-in'); }); }

  /* --- Галерея: плавное движение при прокрутке + листание руками ----------- */
  $$('.gal-row').forEach(function (row) {
    var down = false, startX = 0, startScroll = 0, moved = 0;

    row.addEventListener('pointerdown', function (e) {
      if (e.pointerType === 'touch') return;   // пальцем работает родная прокрутка
      down = true; moved = 0;
      startX = e.clientX;
      startScroll = row.scrollLeft;
      row.classList.add('is-dragging');
      row.setPointerCapture(e.pointerId);
    });

    row.addEventListener('pointermove', function (e) {
      if (!down) return;
      var dx = e.clientX - startX;
      moved = Math.abs(dx);
      row.scrollLeft = startScroll - dx;
    });

    var stop = function (e) {
      if (!down) return;
      down = false;
      row.classList.remove('is-dragging');
      try { row.releasePointerCapture(e.pointerId); } catch (err) {}
    };
    row.addEventListener('pointerup', stop);
    row.addEventListener('pointercancel', stop);
    row.addEventListener('pointerleave', stop);

    row.addEventListener('click', function (e) {
      if (moved > 6) { e.preventDefault(); e.stopPropagation(); }
    }, true);
  });

  // ленты разъезжаются навстречу друг другу по мере прокрутки страницы
  var galRows = $$('.gal-row');
  var gallery = $('.gallery');
  if (galRows.length && gallery && !reduced) {
    var gTicking = false;
    var moveGallery = function () {
      var rect = gallery.getBoundingClientRect();
      var vh = window.innerHeight;
      if (rect.bottom > -200 && rect.top < vh + 200) {
        var progress = (vh / 2 - (rect.top + rect.height / 2)) / vh;
        galRows.forEach(function (row) {
          var track = row.querySelector('.gal-track');
          if (!track) return;
          var speed = parseFloat(row.dataset.speed || '0');
          track.style.transform = 'translate3d(' + (progress * speed * window.innerWidth) + 'px,0,0)';
        });
      }
      gTicking = false;
    };
    window.addEventListener('scroll', function () {
      if (!gTicking) { gTicking = true; requestAnimationFrame(moveGallery); }
    }, { passive: true });
    moveGallery();
  }

  /* --- Стих на фото: фон уезжает при прокрутке ----------------------------- */
  var verseSections = $$('.verse--photo');
  if (verseSections.length && !reduced) {
    var vTicking = false;
    var moveVerse = function () {
      var vh = window.innerHeight;
      verseSections.forEach(function (sec) {
        var bg = sec.querySelector('.verse__bg');
        if (!bg) return;
        var r = sec.getBoundingClientRect();
        if (r.bottom < -100 || r.top > vh + 100) return;
        var p = (vh / 2 - (r.top + r.height / 2)) / (vh / 2 + r.height / 2);
        bg.style.transform = 'translate3d(0,' + (p * 11).toFixed(2) + '%,0) scale(1.06)';
      });
      vTicking = false;
    };
    window.addEventListener('scroll', function () {
      if (!vTicking) { vTicking = true; requestAnimationFrame(moveVerse); }
    }, { passive: true });
    moveVerse();
  }

  /* --- Параллакс видео на первом экране ------------------------------------ */
  var heroMedia = $('.hero__media');
  if (heroMedia && !reduced) {
    var hTicking = false;
    var moveHero = function () {
      var y = window.scrollY;
      if (y < window.innerHeight * 1.2) {
        heroMedia.style.transform = 'translate3d(0,' + (y * 0.22) + 'px,0)';
      }
      hTicking = false;
    };
    window.addEventListener('scroll', function () {
      if (!hTicking) { hTicking = true; requestAnimationFrame(moveHero); }
    }, { passive: true });
  }

  /* --- Видео: не крутим за пределами экрана и при экономии трафика --------- */
  var heroVideo = $('#heroVideo');
  if (heroVideo) {
    var conn = navigator.connection || {};
    if (conn.saveData || reduced) {
      heroVideo.removeAttribute('autoplay');
      heroVideo.pause();
    } else if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          if (e.isIntersecting) { heroVideo.play().catch(function () {}); }
          else { heroVideo.pause(); }
        });
      }, { threshold: 0.05 }).observe(heroVideo);
    }
  }

  /* --- Частые вопросы ------------------------------------------------------ */
  $$('.q__btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var q = btn.closest('.q');
      var open = q.classList.contains('is-open');
      $$('.q').forEach(function (o) { o.classList.remove('is-open'); });
      q.classList.toggle('is-open', !open);
    });
  });

  /* --- Модалка ------------------------------------------------------------- */
  var modal = $('#modal');
  if (modal) {
    var openModal = function () { modal.classList.add('is-open'); document.body.style.overflow = 'hidden'; };
    var closeModal = function () { modal.classList.remove('is-open'); document.body.style.overflow = ''; };

    var docsLink = $('#docsLink');
    if (docsLink) { docsLink.addEventListener('click', function (e) { e.preventDefault(); openModal(); }); }
    $('#modalX').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
  }

  /* --- Бегущая строка: дублируем содержимое для бесшовности ---------------- */
  $$('.ticker__row').forEach(function (row) {
    row.innerHTML = row.innerHTML + row.innerHTML;
  });

  /* --- Мобильная панель: появляется, когда первый экран уехал -------------- */
  var mbar = $('#mbar');
  var hero = $('.hero');
  if (mbar && hero) {
    var footer = $('.footer');
    var toggleBar = function () {
      var pastHero = window.scrollY > hero.offsetHeight * 0.75;
      // у футера прячем, чтобы не перекрывать реквизиты и ссылки
      var atFooter = footer && footer.getBoundingClientRect().top < window.innerHeight - 80;
      mbar.classList.toggle('is-on', pastHero && !atFooter);
    };
    window.addEventListener('scroll', toggleBar, { passive: true });
    toggleBar();
  }

})();
