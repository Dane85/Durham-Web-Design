// Durham Web Design: shared page behaviour (theme, header, mobile menu, reveal, year).
(function () {
  document.body.classList.remove('no-js');
  var root = document.documentElement;

  var y = document.getElementById('year');
  if (y) y.textContent = new Date().getFullYear();

  var tbtn = document.getElementById('themeToggle');
  if (tbtn) {
    tbtn.addEventListener('click', function () {
      var t = root.getAttribute('data-theme');
      var dark = t ? t === 'dark' : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
      var next = dark ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('dwd-theme', next); } catch (e) {}
    });
  }

  var header = document.querySelector('.site-header');
  if (header) {
    var onScroll = function () { header.classList.toggle('scrolled', window.scrollY > 8); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  var menuBtn = document.getElementById('menuBtn'), nav = document.getElementById('mobileNav');
  if (menuBtn && nav) {
    var setMenu = function (open) {
      nav.setAttribute('data-open', open ? 'true' : 'false');
      menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      menuBtn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
      document.body.style.overflow = open ? 'hidden' : '';
    };
    menuBtn.addEventListener('click', function () { setMenu(nav.getAttribute('data-open') !== 'true'); });
    nav.addEventListener('click', function (e) { if (e.target.closest('a')) setMenu(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setMenu(false); });
  }

  var els = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    els.forEach(function (el) { io.observe(el); });
  } else {
    els.forEach(function (el) { el.classList.add('in'); });
  }
  setTimeout(function () { els.forEach(function (el) { el.classList.add('in'); }); }, 2500);
})();
