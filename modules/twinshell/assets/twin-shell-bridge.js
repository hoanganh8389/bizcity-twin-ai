/**
 * Bizcity Twin Shell — child-page bridge + TwinRoute SDK (Twin Route Contract v1, R-ROUTE).
 *
 * Loaded on every plugin page whose URL matches a registered Twin Shell
 * plugin slug (or, since 2026-09-18, an `admin_page`). Notifies the parent
 * /twin/ window whenever the URL or document title changes inside the iframe.
 *
 * The automatic postMessage machinery below is UNCHANGED in shape and
 * behaviour from before `window.TwinRoute` existed — no-ops when the page is
 * loaded outside an iframe (window === top), same message types (`nav`,
 * `ready`), same payload fields. Existing consumers (twin-shell.js's message
 * handler, the manual injection in class-twinchat-public-page.php) need no
 * changes.
 *
 * [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0-RULE-URL-ROUTE P2 — adds
 * `window.TwinRoute`, the app-facing API from TRC v1 §4.5: `report()` for
 * apps that keep route in state instead of the URL, `onSet()` for a future
 * `route:set` push from the shell (not sent yet — see its own doc comment),
 * `relay()` for a wrapper page that iframes the plugin's real app (same
 * mechanism already shipped by hand for CRM's `/crm/` wrapper — see
 * bizcity-twin-crm.php), and `href()`, the client-side twin of the PHP
 * `BizCity_Twin_Route::url()` helper. `window.TwinRoute` is defined even on
 * a non-framed top-level page, so `href()` still works there; only the
 * postMessage machinery is gated on actually being framed.
 */
(function () {
  'use strict';

  var cfg = window.BIZCITY_TWIN_SHELL_BRIDGE || {};
  var shellOrigin = '';
  try {
    shellOrigin = cfg.shellUrl ? new URL(cfg.shellUrl).origin : window.location.origin;
  } catch (e) {
    shellOrigin = window.location.origin;
  }

  var framed = window !== window.top;
  var onSetHandlers = [];

  function postNav() {
    if (!framed) return;
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
    if (!framed) return;
    try {
      window.parent.postMessage({
        source: 'twin-plugin',
        type:   'ready',
        url:    window.location.href,
        pluginId: cfg.pluginId || ''
      }, shellOrigin);
    } catch (e) { /* ignore */ }
  }

  if (framed) {
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

    // Listen for parent-initiated navigations, and `route:set` (TRC §4.4).
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
      } else if (data.type === 'route:set' && typeof data.route === 'string') {
        onSetHandlers.forEach(function (fn) {
          try { fn(data.route); } catch (e2) {}
        });
      }
    });

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
      postReady();
    } else {
      document.addEventListener('DOMContentLoaded', postReady);
    }
  }

  // ── window.TwinRoute — Twin Route Contract v1 app-facing API ──────────────
  var TwinRoute = {
    /**
     * Explicitly report the plugin's current route (a string starting with
     * '/', e.g. '/inbox/13/conv/88'). Use this ONLY when the route lives in
     * app state (Redux, a store) and would NOT be caught by the automatic
     * hashchange/pushState listeners above — a HashRouter/BrowserRouter app
     * that navigates normally already triggers `postNav()` and never needs
     * to call this.
     *
     * @param {string} route
     * @param {{title?: string}} [opts]
     */
    report: function (route, opts) {
      if (!framed) return;
      opts = opts || {};
      try {
        var url = new URL(window.location.href);
        if (typeof route === 'string') {
          url.hash = route.charAt(0) === '#' ? route : '#' + route;
        }
        window.parent.postMessage({
          source: 'twin-plugin',
          type:   'nav',
          url:    url.href,
          title:  opts.title || document.title || '',
          pluginId: cfg.pluginId || ''
        }, shellOrigin);
      } catch (e) { /* ignore */ }
    },

    /**
     * Register a handler for `route:set` messages the shell sends to restore
     * a specific route without a full iframe reload. `twin-shell.js` does
     * not send this message today — it restores deep links by reloading the
     * iframe `src` instead (see `ensureIframe()`) — so a handler registered
     * here will not fire yet. Kept for forward compatibility with TRC §4.4;
     * safe to call, never throws.
     *
     * @param {(route: string) => void} fn
     */
    onSet: function (fn) {
      if (typeof fn === 'function') { onSetHandlers.push(fn); }
    },

    /**
     * For a WRAPPER page that itself iframes the plugin's real app (TRC
     * §4.6) — relays the child iframe's route up through THIS page's own
     * history (so the shell's poll on THIS page picks it up), and forwards
     * this page's own restored hash down into the child on first load.
     * Equivalent to the hand-rolled mechanism already shipped for CRM's
     * `/crm/` wrapper (`bizcity-twin-crm.php`) — a NEW wrapper can call this
     * instead of writing its own copy of that script. Not yet used to
     * replace CRM's own working mirror script (kept as-is to avoid
     * re-touching validated code without a concrete reason).
     *
     * @param {HTMLIFrameElement} iframeEl
     */
    relay: function (iframeEl) {
      if (!iframeEl) return;

      function currentChildHref() {
        try {
          var loc = iframeEl.contentWindow && iframeEl.contentWindow.location;
          if (loc && loc.origin === window.location.origin) { return loc.href; }
        } catch (e) {}
        return '';
      }

      function mirrorFromChild() {
        var href = currentChildHref();
        if (!href) return;
        try {
          var u = new URL(href, window.location.origin);
          var next = window.location.pathname + window.location.search + u.hash;
          var cur  = window.location.pathname + window.location.search + window.location.hash;
          if (next !== cur) {
            window.history.replaceState(null, '', next);
          }
        } catch (e) {}
      }

      // Forward THIS page's own hash (restored by the shell from `_iurl`/`r`) down into the
      // child before it loads, so the child boots at the right route instead of its default.
      if (window.location.hash) {
        try {
          var src = iframeEl.getAttribute('src') || '';
          if (src) { iframeEl.setAttribute('src', src + window.location.hash); }
        } catch (e) {}
      }

      iframeEl.addEventListener('load', function () {
        try {
          var w = iframeEl.contentWindow;
          if (!w) return;
          w.addEventListener('hashchange', mirrorFromChild);
          w.addEventListener('popstate', mirrorFromChild);
          if (w.history && !w.history.__twinRouteRelayPatched) {
            ['pushState', 'replaceState'].forEach(function (fn) {
              var orig = w.history[fn];
              if (typeof orig !== 'function') return;
              w.history[fn] = function () {
                var ret = orig.apply(this, arguments);
                setTimeout(mirrorFromChild, 0);
                return ret;
              };
            });
            w.history.__twinRouteRelayPatched = true;
          }
        } catch (e) {}
        mirrorFromChild();
        // [2026-09-19 Johnny Chu - Chu Hoàng Anh] BUGFIX — `load` fires again on every FULL
        // reload inside the child, not only once; without this guard each reload stacked one
        // more interval on top of the last, forever.
        if (!iframeEl.__twinRouteRelayInterval) {
          // Fallback for SPA route changes that bypass every hooked API above.
          iframeEl.__twinRouteRelayInterval = setInterval(mirrorFromChild, 300);
        }
      });
    },

    /**
     * Build a canonical URL that opens `pluginId` at `route` inside the
     * shell — the client-side twin of `BizCity_Twin_Route::url()` (PHP).
     * `route` is joined by the shell to the plugin's registry entry, never
     * used as a URL by itself (R-ROUTE-7).
     *
     * Admin heuristic: this page cannot check `current_user_can()`, so it
     * assumes the wp-admin wrapper is reachable when THIS page's own URL is
     * itself a `page=` admin screen (the common case for every known
     * caller — an admin-only tool page linking to another plugin). When
     * unsure, it falls back to `/twin/`, which works for any logged-in user.
     *
     * @param {string} pluginId
     * @param {string} [route]
     * @return {string}
     */
    href: function (pluginId, route) {
      var sp = new URLSearchParams();
      sp.set('plugin', String(pluginId || ''));
      if (route) { sp.set('r', route); }
      var onAdminPage = /(^|[?&])page=/.test(window.location.search);
      if (onAdminPage) {
        sp.set('page', 'bizcity-twinchat');
        return '/wp-admin/admin.php?' + sp.toString();
      }
      return '/twin/?' + sp.toString();
    }
  };

  window.TwinRoute = TwinRoute;
})();
