jQuery(function ($) {

  // ===== Modal Helpers =====
  function ensureModal() {
    let $m = $("#bc-market-modal");
    if ($m.length) return $m;
    const html = '<div class="bc-modal" id="bc-market-modal" aria-hidden="true"><div class="bc-modal-backdrop"></div><div class="bc-modal-dialog" role="dialog" aria-modal="true"><button class="bc-modal-close" type="button" aria-label="Close">&times;</button><div class="bc-modal-content"><div class="bc-modal-loading">Đang tải...</div></div></div></div>';
    $("body").append(html);
    return $("#bc-market-modal");
  }

  let $modal = ensureModal();
  let $content = $modal.find(".bc-modal-content");

  function openModal() {
    $modal = ensureModal();
    $content = $modal.find(".bc-modal-content");
    $modal.attr("aria-hidden", "false").addClass("is-open");
    $("body").addClass("bc-modal-open");
  }

  function closeModal() {
    $modal.attr("aria-hidden", "true").removeClass("is-open");
    $("body").removeClass("bc-modal-open");
    $content.html('<div class="bc-modal-loading">Đang tải...</div>');
  }

  // Close handlers
  $(document).on("click", "#bc-market-modal .bc-modal-backdrop, #bc-market-modal .bc-modal-close", function (e) {
    e.preventDefault();
    closeModal();
  });

  // ESC to close
  $(document).on("keydown", function (e) {
    if (e.key === "Escape" && $modal.hasClass("is-open")) closeModal();
  });

  // ===== Open Detail =====
  $(document).on("click", ".bc-detail", function (e) {
    e.preventDefault();
    var slug = $(this).data("slug") || $(this).closest(".bc-card").data("slug");
    if (!slug) return;

    openModal();
    $content.html('<div class="bc-modal-loading">Đang tải...</div>');

    $.post(BCMarket.ajax, {
      action: "bizcity_market_plugin_detail",
      nonce: BCMarket.nonce,
      plugin_slug: slug
    }).done(function (res) {
      if (!res || !res.ok) {
        $content.html('<div class="bc-empty">' + (res && res.msg ? res.msg : "Load failed") + "</div>");
        return;
      }
      $content.html(res.html);

      // tabs
      $content.find(".bc-tab").off("click").on("click", function () {
        var tab = $(this).data("tab");
        $content.find(".bc-tab").removeClass("is-active");
        $(this).addClass("is-active");
        $content.find(".bc-pane").removeClass("is-active");
        $content.find('.bc-pane[data-pane="' + tab + '"]').addClass("is-active");
      });
    }).fail(function () {
      $content.html('<div class="bc-empty">Load failed</div>');
    });
  });

  // ===== Buy Handler — REMOVED =====
  // Credit không dùng cho việc mua plugin. Tất cả plugin kích hoạt tự do.

  // ===== API Key Required Modal =====
  function showApiKeyRequired() {
    var $m = ensureModal();
    var $c = $m.find('.bc-modal-content');
    $c.html(
      '<div style="padding:24px;text-align:center;">' +
      '<div style="font-size:48px;margin-bottom:12px;">🔑</div>' +
      '<h2 style="margin:0 0 12px;">Cần đăng ký API Key</h2>' +
      '<p style="color:#50575e;margin-bottom:20px;">Bạn cần đăng ký API Key với BizCity để kích hoạt và quản lý plugin.</p>' +
      '<div style="background:#f0f6fc;border-left:3px solid #2271b1;border-radius:4px;padding:14px 18px;margin-bottom:20px;text-align:left;">' +
      '<p style="margin:0 0 8px;"><strong>Cách cài đặt:</strong></p>' +
      '<ol style="margin:0;padding-left:20px;">' +
      '<li style="margin-bottom:6px;">Truy cập <a href="' + BCMarket.registerUrl + '" target="_blank">' + BCMarket.registerUrl + '</a> để tạo API Key.</li>' +
      '<li style="margin-bottom:6px;">Vào <a href="' + BCMarket.settingsUrl + '">Cài đặt BizCity LLM Router</a> và dán API Key vào ô <em>API Gateway Key</em>.</li>' +
      '<li>Quay lại đây để kích hoạt plugin.</li>' +
      '</ol></div>' +
      '<a href="' + BCMarket.registerUrl + '" target="_blank" class="button button-primary" style="margin-right:8px;">Tạo API Key tại bizcity.vn →</a>' +
      '<a href="' + BCMarket.settingsUrl + '" class="button">Cài đặt API</a>' +
      '</div>'
    );
    openModal();
  }

  // ===== Activate Handler =====
  $(document).on("click", ".bc-activate", function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $btn = $(this);
    var slug = $btn.data("slug");
    if (!slug) return;

    // Check API key before attempting activation
    if (!BCMarket.hasApiKey) {
      showApiKeyRequired();
      return;
    }

    $btn.addClass("is-loading").prop("disabled", true).text("Đang kích hoạt...");

    $.post(BCMarket.ajax, {
      action: "bizcity_market_activate_plugin",
      nonce: BCMarket.nonce,
      plugin_slug: slug
    }).done(function (res) {
      if (!res || !res.ok) {
        if (res && res.need_api_key) { showApiKeyRequired(); }
        else { alert(res && res.msg ? res.msg : "Kích hoạt thất bại"); }
        $btn.removeClass("is-loading").prop("disabled", false).text("⚡ Kích hoạt");
        return;
      }

      // Replace all activate btns & badges for this slug with active state + deactivate btn
      var activeHtml = '<button class="button bc-deactivate" data-slug="' + slug + '">⏸ Tắt</button> <span class="bc-badge bc-badge-active">✓ Đang dùng</span>';

      // Update card grid
      var $card = $('.bc-card[data-slug="' + slug + '"]');
      $card.find(".bc-actions").html(activeHtml);

      // Update modal actions if open
      var modalActiveHtml = '<button class="button bc-deactivate" data-slug="' + slug + '">⏸ Ngừng kích hoạt</button> <span class="bc-badge bc-badge-active">✓ Đang hoạt động</span>';
      $("#bc-market-modal .bc-modal-actions").find('.bc-activate[data-slug="' + slug + '"]').each(function() {
        $(this).replaceWith(modalActiveHtml);
      });
    }).fail(function () {
      alert("Kích hoạt thất bại. Vui lòng thử lại.");
      $btn.removeClass("is-loading").prop("disabled", false).text("⚡ Kích hoạt");
    });
  });

  // ===== Deactivate Handler (new v0.4) =====
  $(document).on("click", ".bc-deactivate", function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $btn = $(this);
    var slug = $btn.data("slug");
    if (!slug) return;

    // Check API key before attempting deactivation
    if (!BCMarket.hasApiKey) {
      showApiKeyRequired();
      return;
    }

    if (!confirm("Bạn muốn ngừng kích hoạt ứng dụng này?")) return;

    $btn.addClass("is-loading").prop("disabled", true).text("Đang xử lý...");

    $.post(BCMarket.ajax, {
      action: "bizcity_market_deactivate_plugin",
      nonce: BCMarket.nonce,
      plugin_slug: slug
    }).done(function (res) {
      if (!res || !res.ok) {
        if (res && res.need_api_key) { showApiKeyRequired(); }
        else { alert(res && res.msg ? res.msg : "Ngừng kích hoạt thất bại"); }
        $btn.removeClass("is-loading").prop("disabled", false).text("⏸ Tắt");
        return;
      }

      // Replace with activate button in card grid
      var $card = $('.bc-card[data-slug="' + slug + '"]');
      $card.find(".bc-actions").html(
        '<button class="button button-primary bc-activate" data-slug="' + slug + '">⚡ Kích hoạt</button>'
      );

      // Replace in modal if open
      var modalHtml = '<button class="button button-primary bc-activate" data-slug="' + slug + '">⚡ Cài đặt &amp; Kích hoạt</button>';
      $("#bc-market-modal .bc-modal-actions").find('.bc-badge-active').remove();
      $("#bc-market-modal .bc-modal-actions").find('.bc-deactivate[data-slug="' + slug + '"]').replaceWith(modalHtml);
    }).fail(function () {
      alert("Ngừng kích hoạt thất bại. Vui lòng thử lại.");
      $btn.removeClass("is-loading").prop("disabled", false).text("⏸ Tắt");
    });
  });

});
