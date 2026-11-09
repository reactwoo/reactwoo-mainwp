(function () {
  var ROOT_SEL = '.rw-maint';
  var STORAGE_KEY = 'rw-maint-theme';
  var META_KEY = 'rw_maint_theme';

  function root() {
    return document.querySelector(ROOT_SEL);
  }

  function systemDark() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  function readStored() {
    try {
      var v = localStorage.getItem(STORAGE_KEY);
      if (v === 'light' || v === 'dark' || v === 'system') return v;
    } catch (e) {}
    var el = root();
    if (el && el.getAttribute('data-theme-pref')) {
      var p = el.getAttribute('data-theme-pref');
      if (p === 'light' || p === 'dark' || p === 'system') return p;
    }
    return 'system';
  }

  function effective(pref) {
    if (pref === 'dark') return 'dark';
    if (pref === 'light') return 'light';
    return systemDark() ? 'dark' : 'light';
  }

  function apply(pref) {
    var el = root();
    if (!el) return;
    var mode = effective(pref);
    el.setAttribute('data-theme', mode);
    el.setAttribute('data-theme-pref', pref);
    document.documentElement.setAttribute('data-rw-maint-theme', mode);
    var buttons = el.querySelectorAll('[data-rw-theme]');
    for (var i = 0; i < buttons.length; i++) {
      var btn = buttons[i];
      var on = btn.getAttribute('data-rw-theme') === pref;
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      btn.classList.toggle('is-active', on);
    }
  }

  function persist(pref) {
    try { localStorage.setItem(STORAGE_KEY, pref); } catch (e) {}
    // Best-effort user meta via localized REST/admin-ajax if present.
    if (window.rwMaintTheme && window.rwMaintTheme.ajaxUrl && window.rwMaintTheme.nonce) {
      var body = new FormData();
      body.append('action', 'rw_portal_maint_theme');
      body.append('nonce', window.rwMaintTheme.nonce);
      body.append('theme', pref);
      try {
        navigator.sendBeacon
          ? navigator.sendBeacon(window.rwMaintTheme.ajaxUrl, body)
          : fetch(window.rwMaintTheme.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' });
      } catch (e) {}
    }
  }

  function init() {
    var pref = readStored();
    apply(pref);
    var el = root();
    if (!el) return;
    el.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-rw-theme]');
      if (!btn || !el.contains(btn)) return;
      var next = btn.getAttribute('data-rw-theme');
      if (next !== 'light' && next !== 'dark' && next !== 'system') return;
      persist(next);
      apply(next);
    });
    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      var onChange = function () {
        if (readStored() === 'system') apply('system');
      };
      if (mq.addEventListener) mq.addEventListener('change', onChange);
      else if (mq.addListener) mq.addListener(onChange);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
