/**
 * BizCity Market — Remote Marketplace JS
 *
 * Renders the remote plugin catalog as a JSON-driven SPA inside
 * the #bc-remote-market container. No server-side PHP rendering.
 *
 * Flow:
 *   1. Page loads → PHP outputs empty #bc-remote-market container
 *   2. JS fetches catalog via AJAX → renders plugin cards
 *   3. Install/update happens via AJAX → PHP downloads + unzips server-side
 *
 * @since 1.2.0
 */
(function () {
  "use strict";

  /* ── Config (injected by PHP via wp_localize_script) ── */
  var C = window.BCRemoteMarket || {};
  if (!C.ajax || !C.nonce) return;

  var $root = document.getElementById("bc-remote-market");
  if (!$root) return;

  /* ── State ── */
  var state = {
    plugins: [],
    categories: [],
    total: 0,
    pages: 0,
    page: 1,
    per_page: 12,
    search: "",
    category: "",
    tier: "free",
    loading: false,
    detail: null, // currently open detail
  };

  /* ── Helpers ── */
  function esc(s) {
    var d = document.createElement("div");
    d.appendChild(document.createTextNode(s || ""));
    return d.innerHTML;
  }
  function ajax(action, params, method) {
    method = method || "GET";
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      if (method === "GET") {
        var qs = "action=" + action + "&nonce=" + C.nonce;
        for (var k in params) {
          if (params[k] !== "" && params[k] !== null && params[k] !== undefined) {
            qs += "&" + encodeURIComponent(k) + "=" + encodeURIComponent(params[k]);
          }
        }
        xhr.open("GET", C.ajax + "?" + qs, true);
        xhr.send();
      } else {
        xhr.open("POST", C.ajax, true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        var body = "action=" + action + "&nonce=" + C.nonce;
        for (var k2 in params) {
          if (params[k2] !== "" && params[k2] !== null && params[k2] !== undefined) {
            body += "&" + encodeURIComponent(k2) + "=" + encodeURIComponent(params[k2]);
          }
        }
        xhr.send(body);
      }
      xhr.onload = function () {
        try {
          var data = JSON.parse(xhr.responseText);
          resolve(data);
        } catch (e) {
          reject(new Error("Invalid JSON"));
        }
      };
      xhr.onerror = function () {
        reject(new Error("Network error"));
      };
    });
  }

  /* ── Render ── */
  function render() {
    $root.innerHTML =
      renderHeader() +
      renderCategories() +
      (state.loading ? renderLoading() : renderGrid()) +
      renderPagination() +
      renderModal();
  }

  function renderHeader() {
    return (
      '<div class="bcr-header">' +
      '<h2>' + esc(C.title || "BizCity Marketplace") + "</h2>" +
      '<form class="bcr-search" onsubmit="return false;">' +
      '<input type="text" id="bcr-search-input" placeholder="' +
      esc(C.searchPlaceholder || "Tìm ứng dụng...") +
      '" value="' +
      esc(state.search) +
      '"/>' +
      '<button type="submit" class="button button-primary" id="bcr-search-btn">Tìm</button>' +
      "</form>" +
      "</div>"
    );
  }

  function renderCategories() {
    if (!state.categories.length) return "";
    var h = '<div class="bcr-cats">';
    h +=
      '<button class="bcr-cat-btn' +
      (!state.category ? " is-active" : "") +
      '" data-cat="">Tất cả</button>';
    for (var i = 0; i < state.categories.length; i++) {
      var c = state.categories[i];
      h +=
        '<button class="bcr-cat-btn' +
        (state.category === c.name ? " is-active" : "") +
        '" data-cat="' +
        esc(c.name) +
        '">' +
        esc(c.name) +
        " (" +
        c.count +
        ")</button>";
    }
    h += "</div>";
    return h;
  }

  function renderLoading() {
    return '<div class="bcr-loading"><span class="spinner is-active"></span> Đang tải...</div>';
  }

  function renderGrid() {
    if (!state.plugins.length) {
      return '<div class="bcr-empty">Không tìm thấy ứng dụng nào.</div>';
    }
    var h = '<div class="bcr-grid">';
    for (var i = 0; i < state.plugins.length; i++) {
      h += renderCard(state.plugins[i]);
    }
    h += "</div>";
    return h;
  }

  function renderCard(p) {
    var slug    = p.slug || "";
    var icon    = p.icon_url || p.cover_url || "";
    var cat     = Array.isArray(p.category) ? p.category.join(", ") : p.category || "";
    var plan    = p.plan || "free";
    var credit  = p.credit_per_use || 0;
    var remoteV = p.version || "";
    var localV  = p.local_version || "";

    // ── Version badge ──
    var verHtml = "";
    if (remoteV) {
      if (p.has_update) {
        verHtml = '<span class="bcr-ver bcr-ver-update" title="Local: v' + esc(localV) + '">v' + esc(remoteV) + ' ↑ New</span>';
      } else if (p.local_installed) {
        verHtml = '<span class="bcr-ver bcr-ver-latest">v' + esc(remoteV) + ' — Latest</span>';
      } else {
        verHtml = '<span class="bcr-ver">v' + esc(remoteV) + '</span>';
      }
    }

    // ── Action buttons ──
    // Install: visible always; disabled if already installed
    var installBtn =
      '<button class="button bcr-install-btn' + (p.local_installed ? ' bcr-btn-disabled' : ' button-primary') + '"' +
      ' data-slug="' + esc(slug) + '" data-url="' + esc(p.download_url || "") + '"' +
      (p.local_installed ? ' disabled title="Đã cài đặt"' : '') +
      '>📥 Cài</button>';

    // Update: only shown when has_update or already installed
    var updateBtn = '';
    if (p.local_installed) {
      if (p.has_update) {
        updateBtn = '<button class="button button-primary bcr-update-btn"' +
          ' data-slug="' + esc(slug) + '" data-url="' + esc(p.download_url || "") + '">' +
          '🔄 Update</button>';
      } else {
        updateBtn = '<button class="button bcr-btn-disabled" disabled title="Bạn đang dùng phiên bản mới nhất">🔄 Update</button>';
      }
    }

    // Activate / Deactivate
    var activateBtn = '';
    if (p.local_active) {
      activateBtn = '<button class="button bcr-deactivate-btn" data-slug="' + esc(slug) + '">⏸ Tắt</button>';
    } else if (p.local_installed) {
      activateBtn = '<button class="button bcr-activate-btn" data-slug="' + esc(slug) + '">⚡ Bật</button>';
    }

    // Lock overlay if not eligible
    var lockBadge = (!p.can_download && !p.local_installed)
      ? '<span class="bcr-badge bcr-badge-locked">🔒 ' + esc(plan) + '</span>' : '';

    var actionsHtml = lockBadge || (installBtn + updateBtn + activateBtn);

    return (
      '<div class="bcr-card' + (p.local_active ? ' bcr-card-active' : '') + '" data-slug="' + esc(slug) + '">' +
      '<div class="bcr-card-thumb bcr-detail-trigger" data-slug="' + esc(slug) + '"' +
      (icon ? ' style="background-image:url(\'' + esc(icon) + "')\"" : "") + ">" +
      (cat ? '<span class="bcr-thumb-badge">' + esc(cat) + "</span>" : "") +
      (p.local_active ? '<span class="bcr-thumb-status bcr-thumb-active">✓</span>' : '') +
      (p.has_update ? '<span class="bcr-thumb-status bcr-thumb-update">↑</span>' : '') +
      "</div>" +
      '<div class="bcr-card-body">' +
      '<div class="bcr-card-title"><a href="#" class="bcr-detail-trigger" data-slug="' +
      esc(slug) + '">' + esc(p.name || slug) + "</a></div>" +
      '<div class="bcr-card-meta">' +
        '<span class="bcr-card-author">' + esc(p.author || "BizCity") + '</span>' +
        verHtml +
      '</div>' +
      '<div class="bcr-card-price">' + (credit > 0 ? credit + " credit / lần" : "Miễn phí") + '</div>' +
      '<div class="bcr-card-actions">' + actionsHtml + "</div>" +
      "</div></div>"
    );
  }

  function renderPagination() {
    if (state.pages <= 1) return "";
    var h = '<div class="bcr-pagination">';
    for (var i = 1; i <= state.pages; i++) {
      h +=
        '<button class="bcr-page-btn' +
        (i === state.page ? " is-active" : "") +
        '" data-page="' +
        i +
        '">' +
        i +
        "</button>";
    }
    h += "</div>";
    return h;
  }

  function renderModal() {
    if (!state.detail) return '<div class="bcr-modal" id="bcr-modal" aria-hidden="true"></div>';
    var p = state.detail;
    var slug = p.slug || "";

    var statusHtml = "";
    if (p.local_active) {
      statusHtml = '<span class="bcr-badge bcr-badge-active">✓ Đang dùng</span>';
    } else if (p.has_update) {
      statusHtml =
        '<button class="button button-primary bcr-update-btn" data-slug="' +
        esc(slug) + '" data-url="' + esc(p.download_url || "") +
        '">🔄 Cập nhật</button>';
    } else if (p.local_installed) {
      statusHtml =
        '<button class="button button-primary bcr-activate-btn" data-slug="' +
        esc(slug) + '">⚡ Kích hoạt</button>';
    } else if (p.can_download) {
      statusHtml =
        '<button class="button button-primary bcr-install-btn" data-slug="' +
        esc(slug) + '" data-url="' + esc(p.download_url || "") +
        '">📥 Cài đặt</button>';
    }

    return (
      '<div class="bcr-modal is-open" id="bcr-modal" aria-hidden="false">' +
      '<div class="bcr-modal-backdrop"></div>' +
      '<div class="bcr-modal-dialog">' +
      '<button class="bcr-modal-close" type="button">&times;</button>' +
      '<div class="bcr-modal-content">' +
      '<div class="bcr-modal-header">' +
      (p.icon_url ? '<img class="bcr-modal-icon" src="' + esc(p.icon_url) + '" alt=""/>' : '') +
      '<div class="bcr-modal-meta">' +
      '<h3>' + esc(p.name || slug) + '</h3>' +
      '<div class="bcr-modal-sub">' + esc(p.author || "BizCity") +
      ' &bull; v' + esc(p.version || "?") +
      ' &bull; ' + (p.total_downloads || 0) + ' downloads</div>' +
      '<div class="bcr-modal-actions">' + statusHtml + '</div>' +
      '</div></div>' +
      '<div class="bcr-modal-desc">' + (p.description || p.short_desc || "Chưa có mô tả.") + '</div>' +
      (p.changelog ? '<div class="bcr-modal-changelog"><h4>Changelog</h4>' + esc(p.changelog) + '</div>' : '') +
      '</div></div></div>'
    );
  }

  /* ── Data fetching ── */
  function loadCatalog() {
    state.loading = true;
    render();

    ajax("bizcity_remote_catalog", {
      search: state.search,
      category: state.category,
      page: state.page,
      per_page: state.per_page,
    })
      .then(function (res) {
        state.loading = false;
        if (!res.ok) {
          state.plugins = [];
          state.total = 0;
          state.pages = 0;
          render();
          return;
        }
        var d = res.data || {};
        state.plugins = d.plugins || [];
        state.total = d.total || 0;
        state.pages = d.pages || 0;
        state.page = d.page || 1;
        state.tier = res.tier || "free";
        render();
      })
      .catch(function () {
        state.loading = false;
        state.plugins = [];
        render();
      });
  }

  function loadCategories() {
    ajax("bizcity_remote_categories", {})
      .then(function (res) {
        if (res.ok && res.data) {
          state.categories = res.data;
          render();
        }
      })
      .catch(function () {});
  }

  function loadDetail(slug) {
    state.detail = { slug: slug, name: "Đang tải..." };
    render();

    ajax("bizcity_remote_detail", { slug: slug })
      .then(function (res) {
        if (res.ok && res.data) {
          state.detail = res.data;
        } else {
          state.detail = null;
        }
        render();
      })
      .catch(function () {
        state.detail = null;
        render();
      });
  }

  /* ── API Key Required Modal ── */
  function showApiKeyRequired() {
    // Remove any existing API key modal
    var existing = document.getElementById('bcr-apikey-modal');
    if (existing) existing.remove();

    var wrap = document.createElement('div');
    wrap.id = 'bcr-apikey-modal';
    wrap.innerHTML =
      '<div class="bcr-modal is-open" aria-hidden="false">' +
      '<div class="bcr-modal-backdrop" id="bcr-apikey-backdrop"></div>' +
      '<div class="bcr-modal-dialog">' +
      '<button class="bcr-modal-close" type="button" id="bcr-apikey-close">&times;</button>' +
      '<div class="bcr-modal-content" style="text-align:center;padding:32px;">' +
      '<div style="font-size:48px;margin-bottom:12px;">🔑</div>' +
      '<h3 style="margin:0 0 12px;">Cần đăng ký API Key</h3>' +
      '<p style="color:#50575e;margin-bottom:20px;">Bạn cần đăng ký API Key với BizCity để cài đặt, kích hoạt và quản lý plugin.</p>' +
      '<div style="background:#f0f6fc;border-left:3px solid #2271b1;border-radius:4px;padding:14px 18px;margin-bottom:20px;text-align:left;">' +
      '<p style="margin:0 0 8px;"><strong>Cách cài đặt:</strong></p>' +
      '<ol style="margin:0;padding-left:20px;">' +
      '<li style="margin-bottom:6px;">Truy cập <a href="' + (C.registerUrl || '#') + '" target="_blank">' + (C.registerUrl || '') + '</a> để tạo API Key.</li>' +
      '<li style="margin-bottom:6px;">Vào <a href="' + (C.settingsUrl || '#') + '">Cài đặt BizCity LLM Router</a> và dán API Key.</li>' +
      '<li>Quay lại đây để cài đặt plugin.</li>' +
      '</ol></div>' +
      '<a href="' + (C.registerUrl || '#') + '" target="_blank" class="button button-primary" style="margin-right:8px;">Tạo API Key tại bizcity.vn →</a>' +
      '<a href="' + (C.settingsUrl || '#') + '" class="button">Cài đặt API</a>' +
      '</div></div></div>';
    document.body.appendChild(wrap);

    // Close handlers
    document.getElementById('bcr-apikey-backdrop').onclick = function() { wrap.remove(); };
    document.getElementById('bcr-apikey-close').onclick = function() { wrap.remove(); };
  }

  /* ── Actions ── */
  function doInstall(slug, downloadUrl, btn) {
    if (!C.hasApiKey) { showApiKeyRequired(); return; }
    if (!downloadUrl) {
      alert("Download URL không khả dụng. Vui lòng thử lại.");
      return;
    }
    btn.disabled = true;
    btn.textContent = "Đang cài đặt...";

    ajax("bizcity_remote_install", { slug: slug, download_url: downloadUrl }, "POST")
      .then(function (res) {
        if (res.ok) {
          // Reload catalog to reflect new status
          state.detail = null;
          loadCatalog();
        } else {
          if (res.need_api_key) { showApiKeyRequired(); }
          else { alert(res.msg || "Cài đặt thất bại."); }
          btn.disabled = false;
          btn.textContent = "📥 Cài đặt";
        }
      })
      .catch(function () {
        alert("Lỗi kết nối. Vui lòng thử lại.");
        btn.disabled = false;
        btn.textContent = "📥 Cài đặt";
      });
  }

  function doUpdate(slug, downloadUrl, btn) {
    if (!C.hasApiKey) { showApiKeyRequired(); return; }
    if (!downloadUrl) {
      alert("Download URL không khả dụng.");
      return;
    }
    btn.disabled = true;
    btn.textContent = "Đang cập nhật...";

    ajax("bizcity_remote_update", { slug: slug, download_url: downloadUrl }, "POST")
      .then(function (res) {
        if (res.ok) {
          state.detail = null;
          loadCatalog();
        } else {
          if (res.need_api_key) { showApiKeyRequired(); }
          else { alert(res.msg || "Cập nhật thất bại."); }
          btn.disabled = false;
          btn.textContent = "🔄 Cập nhật";
        }
      })
      .catch(function () {
        alert("Lỗi kết nối.");
        btn.disabled = false;
        btn.textContent = "🔄 Cập nhật";
      });
  }

  function doActivate(slug, btn) {
    if (!C.hasApiKey) { showApiKeyRequired(); return; }
    if (!C.localNonce) return;
    btn.disabled = true;
    btn.textContent = "Đang kích hoạt...";

    var xhr = new XMLHttpRequest();
    xhr.open("POST", C.ajax, true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.send(
      "action=bizcity_market_activate_plugin&nonce=" + C.localNonce +
      "&plugin_slug=" + encodeURIComponent(slug)
    );
    xhr.onload = function () {
      try {
        var res = JSON.parse(xhr.responseText);
        if (res.ok) {
          state.detail = null;
          loadCatalog();
        } else {
          if (res.need_api_key) { showApiKeyRequired(); }
          else { alert(res.msg || "Kích hoạt thất bại."); }
          btn.disabled = false;
          btn.textContent = "⚡ Kích hoạt";
        }
      } catch (e) {
        btn.disabled = false;
      }
    };
  }

  function doDeactivate(slug, btn) {
    if (!C.hasApiKey) { showApiKeyRequired(); return; }
    if (!C.localNonce) return;
    btn.disabled = true;
    btn.textContent = "Đang tắt...";

    var xhr = new XMLHttpRequest();
    xhr.open("POST", C.ajax, true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.send(
      "action=bizcity_market_deactivate_plugin&nonce=" + C.localNonce +
      "&plugin_slug=" + encodeURIComponent(slug)
    );
    xhr.onload = function () {
      try {
        var res = JSON.parse(xhr.responseText);
        if (res.ok) {
          state.detail = null;
          loadCatalog();
        } else {
          if (res.need_api_key) { showApiKeyRequired(); }
          else { alert(res.msg || "Tắt thất bại."); }
        }
      } catch (e) {}
      btn.disabled = false;
    };
  }

  /* ── Event delegation ── */
  $root.addEventListener("click", function (e) {
    var t = e.target;

    // Search
    if (t.id === "bcr-search-btn" || (t.tagName === "FORM" && t.classList.contains("bcr-search"))) {
      e.preventDefault();
      var input = $root.querySelector("#bcr-search-input");
      state.search = input ? input.value.trim() : "";
      state.page = 1;
      loadCatalog();
      return;
    }

    // Category filter
    if (t.classList.contains("bcr-cat-btn")) {
      e.preventDefault();
      state.category = t.getAttribute("data-cat") || "";
      state.page = 1;
      loadCatalog();
      return;
    }

    // Pagination
    if (t.classList.contains("bcr-page-btn")) {
      e.preventDefault();
      state.page = parseInt(t.getAttribute("data-page"), 10) || 1;
      loadCatalog();
      return;
    }

    // Detail trigger
    if (t.classList.contains("bcr-detail-trigger") || t.closest(".bcr-detail-trigger")) {
      e.preventDefault();
      var el = t.classList.contains("bcr-detail-trigger") ? t : t.closest(".bcr-detail-trigger");
      var slug = el.getAttribute("data-slug");
      if (slug) loadDetail(slug);
      return;
    }

    // Install
    if (t.classList.contains("bcr-install-btn")) {
      e.preventDefault();
      e.stopPropagation();
      doInstall(t.getAttribute("data-slug"), t.getAttribute("data-url"), t);
      return;
    }

    // Update
    if (t.classList.contains("bcr-update-btn")) {
      e.preventDefault();
      e.stopPropagation();
      doUpdate(t.getAttribute("data-slug"), t.getAttribute("data-url"), t);
      return;
    }

    // Activate
    if (t.classList.contains("bcr-activate-btn")) {
      e.preventDefault();
      e.stopPropagation();
      doActivate(t.getAttribute("data-slug"), t);
      return;
    }

    // Deactivate
    if (t.classList.contains("bcr-deactivate-btn")) {
      e.preventDefault();
      e.stopPropagation();
      doDeactivate(t.getAttribute("data-slug"), t);
      return;
    }

    // Modal close
    if (t.classList.contains("bcr-modal-backdrop") || t.classList.contains("bcr-modal-close")) {
      e.preventDefault();
      state.detail = null;
      render();
      return;
    }
  });

  // Enter key in search
  $root.addEventListener("keydown", function (e) {
    if (e.key === "Enter" && e.target.id === "bcr-search-input") {
      e.preventDefault();
      state.search = e.target.value.trim();
      state.page = 1;
      loadCatalog();
    }
  });

  // ESC to close modal
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && state.detail) {
      state.detail = null;
      render();
    }
  });

  /* ── Boot ── */
  render(); // show skeleton
  loadCategories();
  loadCatalog();
})();
