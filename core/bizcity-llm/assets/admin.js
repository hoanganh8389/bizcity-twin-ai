/* BizCity LLM — Admin JS */
/* global jQuery, bizcityLLM */

(function ($) {
    'use strict';

    const { ajax_url, nonce, i18n } = bizcityLLM;

    /* ── Toggle key visibility ── */
    $(document).on('click', '.bizcity-llm-toggle-key', function () {
        const $input = $(this).prev('input');
        const type = $input.attr('type') === 'password' ? 'text' : 'password';
        $input.attr('type', type);
        $(this).text(type === 'text' ? '🔒' : '👁');
    });

    /* ── Test connection ── */
    $('#bizcity-llm-test-btn').on('click', function () {
        const $btn = $(this);
        const $result = $('#bizcity-llm-test-result');
        $btn.prop('disabled', true);
        $result.html('<span class="bizcity-llm-spinner"></span>' + i18n.testing);

        $.post(ajax_url, { action: 'bizcity_llm_test_key', nonce: nonce })
            .done(function (res) {
                $result.html(res.success ? i18n.test_ok + ' ' + res.data : i18n.test_fail + res.data);
            })
            .fail(function () { $result.html(i18n.test_fail + 'Request failed.'); })
            .always(function () { $btn.prop('disabled', false); });
    });

    /* ── Test Tavily ── */
    $('#bizcity-tavily-test-btn').on('click', function () {
        const $btn = $(this);
        const $result = $('#bizcity-tavily-test-result');
        const apiKey = $('#bizcity_tavily_api_key').val().trim();
        $btn.prop('disabled', true);
        $result.html('<span class="bizcity-llm-spinner"></span>' + i18n.testing);

        $.post(ajax_url, { action: 'bizcity_tavily_test_api_key', nonce: nonce, api_key: apiKey })
            .done(function (res) { $result.html(res.success ? '✅ ' + res.data : '❌ ' + res.data); })
            .fail(function () { $result.html('❌ Request failed.'); })
            .always(function () { $btn.prop('disabled', false); });
    });

    /* ── Refresh live model list ── */
    $('#bizcity-llm-refresh-models').on('click', function () {
        const $btn = $(this);
        const $browser = $('#bizcity-llm-model-browser');
        const $list = $('#bizcity-llm-model-list');
        $btn.prop('disabled', true).text(i18n.fetching);

        $.post(ajax_url, { action: 'bizcity_llm_fetch_models', nonce: nonce })
            .done(function (res) {
                if (!res.success) { alert(i18n.error_prefix + res.data); return; }
                renderModelTable(res.data, $list);
                $browser.show();
                $btn.text(i18n.models_loaded.replace('{n}', res.data.length));
            })
            .fail(function () { alert(i18n.models_load_fail); })
            .always(function () { $btn.prop('disabled', false); });
    });

    function renderModelTable(models, $container) {
        let html = '<table><thead><tr><th>Model ID</th><th>' + i18n.name + '</th><th>Context</th><th></th></tr></thead><tbody>';
        models.forEach(function (m) {
            html += '<tr>';
            html += '<td>' + escHtml(m.id) + '</td>';
            html += '<td>' + escHtml(m.name) + '</td>';
            html += '<td>' + (m.context || '-') + '</td>';
            html += '<td><a href="#" class="bizcity-llm-copy-id" data-id="' + escHtml(m.id) + '">📋</a></td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        $container.html(html);
    }

    /* ── Copy model ID ── */
    $(document).on('click', '.bizcity-llm-copy-id', function (e) {
        e.preventDefault();
        const id = $(this).data('id');
        navigator.clipboard.writeText(id).then(function () {
            alert(i18n.copy_ok);
        });
    });

    /* ── Custom model toggle ── */
    $(document).on('click', '.bizcity-llm-custom-toggle', function (e) {
        e.preventDefault();
        const $this = $(this);
        const $select = $this.siblings('.bizcity-llm-model-select');
        const $custom = $this.siblings('.bizcity-llm-model-custom');
        if ($custom.is(':visible')) {
            $custom.hide().prop('disabled', true);
            $select.show().prop('disabled', false);
            $this.text('✏️ ' + i18n.custom);
        } else {
            $select.hide().prop('disabled', true);
            $custom.show().prop('disabled', false);
            $this.text('📋 ' + i18n.select_from_list);
        }
    });

    /* ── Filter model browser ── */
    $(document).on('input', '#bizcity-llm-filter', function () {
        const q = $(this).val().toLowerCase();
        $('#bizcity-llm-model-list tbody tr').each(function () {
            $(this).toggle($(this).text().toLowerCase().indexOf(q) > -1);
        });
    });

    /* ── Register API Key ── */
    $('#bizcity-llm-register-btn').on('click', function () {
        const $btn = $(this);
        const $result = $('#bizcity-llm-register-result');
        if (!confirm(i18n.confirm_register)) return;
        $btn.prop('disabled', true);
        $result.html('<span class="bizcity-llm-spinner"></span> ' + i18n.registering);
        $.post(ajax_url, { action: 'bizcity_llm_register_key', nonce: nonce })
            .done(function (res) {
                if (res.success) {
                    $result.html('✅ ' + res.data.message + ' (' + res.data.key_preview + ')');
                    setTimeout(function () { location.reload(); }, 1500);
                } else {
                    $result.html('❌ ' + res.data);
                }
            })
            .fail(function () { $result.html('❌ Request failed.'); })
            .always(function () { $btn.prop('disabled', false); });
    });

    /* ── Load more usage log ── */
    $('#bizcity-llm-load-more-log').on('click', function () {
        const $btn = $(this);
        const page = parseInt($btn.data('page'), 10);
        $btn.prop('disabled', true).text(i18n.loading);
        $.post(ajax_url, { action: 'bizcity_llm_usage_log', nonce: nonce, page: page })
            .done(function (res) {
                if (!res.success || !res.data.rows.length) {
                    $btn.text(i18n.no_more_data).prop('disabled', true);
                    return;
                }
                const $tbody = $btn.closest('.bizcity-llm-card').find('table.widefat tbody');
                res.data.rows.forEach(function (r) {
                    $tbody.append(
                        '<tr>' +
                        '<td>' + escHtml(r.created_at) + '</td>' +
                        '<td>' + escHtml(r.mode) + '</td>' +
                        '<td>' + escHtml(r.purpose) + '</td>' +
                        '<td><code style="font-size:11px">' + escHtml(r.model_used || r.model_requested) + '</code>' +
                            (r.fallback_used == 1 ? ' 🔶' : '') + '</td>' +
                        '<td style="text-align:right">' + (r.tokens_prompt||0) + '/' + (r.tokens_completion||0) + '</td>' +
                        '<td style="text-align:right">' + (r.latency_ms||0) + '</td>' +
                        '<td>' + (r.success == 1 ? '✅' : '❌') + '</td>' +
                        '<td style="max-width:200px;word-break:break-all;color:#dc2626">' + escHtml((r.error||'').substring(0,120)) + '</td>' +
                        '</tr>'
                    );
                });
                $btn.data('page', page + 1).text(i18n.load_more);
            })
            .fail(function () { $btn.text(i18n.load_error); })
            .always(function () { $btn.prop('disabled', false); });
    });

    /* ── Purge old log ── */
    $('#bizcity-llm-purge-log').on('click', function () {
        if (!confirm(i18n.confirm_purge)) return;
        const $result = $('#bizcity-llm-log-result');
        $.post(ajax_url, { action: 'bizcity_llm_purge_log', nonce: nonce, days: 90 })
            .done(function (res) {
                $result.html(res.success ? '✅ ' + res.data : '❌ ' + res.data);
                if (res.success) setTimeout(function () { location.reload(); }, 1500);
            })
            .fail(function () { $result.html('❌ Request failed.'); });
    });

    function escHtml(s) {
        const el = document.createElement('span');
        el.textContent = s || '';
        return el.innerHTML;
    }
})(jQuery);
