/**
 * Bizcity Twin Shell — child-page bridge.
 *
 * Loaded on every plugin page whose URL matches a registered Twin Shell
 * plugin slug. Notifies the parent /twin/ window whenever the URL or
 * document title changes inside the iframe.
 *
 * No-ops when the page is loaded outside an iframe (window === top).
 */
(function () {
  'use strict';

  if (window === window.top) return;

  var cfg = window.BIZCITY_TWIN_SHELL_BRIDGE || {};
  var shellOrigin = '';
  try {
    shellOrigin = cfg.shellUrl ? new URL(cfg.shellUrl).origin : window.location.origin;
  } catch (e) {
    shellOrigin = window.location.origin;
  }

  function postNav() {
    try {
      window.parent.postMessage({
        source: 'twin-plugin',
        type:   'nav',
        url:    window.location.href,
        title:  document.title || '',
        pluginId: cfg.pluginId || ''
      }, shellOrigin);
    } catch (e) { /* ignore */ }
  }

  function postReady() {
    try {
      window.parent.postMessage({
        source: 'twin-plugin',
        type:   'ready',
        url:    window.location.href,
        pluginId: cfg.pluginId || ''
      }, shellOrigin);
    } catch (e) { /* ignore */ }
  }

  // Hook history.pushState / replaceState.
  ['pushState', 'replaceState'].forEach(function (fn) {
    var orig = history[fn];
    history[fn] = function () {
      var ret = orig.apply(this, arguments);
      try { postNav(); } catch (e) {}
      return ret;
    };
  });

  window.addEventListener('popstate',    postNav);
  window.addEventListener('hashchange',  postNav);

  // Watch document.title changes.
  var lastTitle = document.title;
  setInterval(function () {
    if (document.title !== lastTitle) {
      lastTitle = document.title;
      postNav();
    }
  }, 1000);

  // Listen for parent-initiated navigations.
  window.addEventListener('message', function (ev) {
    if (ev.origin !== shellOrigin) return;
    var data = ev.data;
    if (!data || data.source !== 'twin-shell') return;
    if (data.type === 'navigate' && typeof data.url === 'string') {
      try {
        var u = new URL(data.url, window.location.origin);
        if (u.origin === window.location.origin) {
          window.location.href = u.href;
        }
      } catch (e) { /* ignore */ }
    }
  });

  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    postReady();
  } else {
    document.addEventListener('DOMContentLoaded', postReady);
  }
})();
