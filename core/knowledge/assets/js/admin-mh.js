/**
 * Memory Hub — Full CRUD + Chart.js
 *
 * Loaded only on ?page=bizcity-knowledge-memory-hub
 * Depends: jQuery, Chart.js, bizcity_knowledge_vars (ajaxurl, nonce)
 *
 * Features:
 *  - Quick FAQ: contenteditable blur-save, delete, add row, export JSON/CSV, import
 *  - Long-term Memory: contenteditable + select blur-save, delete, add row, export/import
 *  - Episodic: lazy-load on tab activate, delete, add row
 *  - Rolling: lazy-load on tab activate, delete
 *  - Research Notes: lazy-load on tab activate, add, delete
 *  - Charts: doughnut per zone (Chart.js 4)
 *
 * @package BizCity_Twin_AI
 * @since   2026-05-06
 */
(function ($) {
    'use strict';

    /* ── Config ── */
    var AJ = bizcity_knowledge_vars.ajaxurl;
    var NC = bizcity_knowledge_vars.nonce;
    var MEM_TYPES = ['fact','preference','identity','goal','pain','constraint','habit','relationship','request'];

    /* ── State ── */
    var loaded   = {};     // which lazy tabs have been fetched
    var charts   = {};     // Chart.js instances by canvas id
    var saving   = false;  // debounce guard

    /* ================================================================
     * 1. BOOTSTRAP — override inline shim (take over tab switching
     *    and hook up lazy-load + CRUD).
     * ================================================================ */
    $(function () {
        // Remove the old inline shim's click handlers by cloning stat-cards.
        var $cards = $('.bizcity-mh .stat-card[data-tab]');
        $cards.each(function () {
            var $old = $(this);
            var $new = $old.clone(false);
            $old.replaceWith($new);
        });

        // Re-query after clone.
        bindTabSwitching();

        // CRUD event delegation for both preloaded + lazily-loaded rows.
        bindCrudDelegation();

        // Activate first tab (quickfaq) — render chart if applicable.
        activateTab('quickfaq', true);
    });

    /* ================================================================
     * 2. TAB SWITCHING
     * ================================================================ */
    function bindTabSwitching() {
        $(document).on('click', '.bizcity-mh .stat-card[data-tab]', function () {
            var tab = $(this).data('tab');
            activateTab(tab, false);
        });
    }

    function activateTab(tab, isInit) {
        var $cards  = $('.bizcity-mh .stat-card[data-tab]');
        var $panels = $('.bizcity-mh .maturity-tab-panel');

        $cards.each(function () {
            $(this).attr('aria-selected', $(this).data('tab') === tab ? 'true' : 'false');
        });
        $panels.each(function () {
            var show = $(this).attr('id') === 'panel-' + tab;
            $(this).toggleClass('active', show);
        });

        // Lazy-load for non-preloaded panels.
        if (!loaded[tab]) {
            var preloaded = $('#detail-' + tab).data('preloaded');
            if (!preloaded) {
                lazyLoad(tab);
            } else {
                loaded[tab] = true;
                maybeDrawChart(tab);
            }
        } else {
            maybeDrawChart(tab);
        }
    }

    function lazyLoad(tab) {
        if (loaded[tab]) return;

        var $spinner = $('#panel-' + tab + ' .detail-loading');
        var $list    = $('#detail-' + tab);
        $spinner.show();

        var actionMap = {
            episodic: 'bizcity_mh_episodic_list',
            rolling:  'bizcity_mh_rolling_list',
            notes:    'bizcity_mh_notes_list',
            files:    'bizcity_mh_files_list'
        };
        var action = actionMap[tab];
        if (!action) { loaded[tab] = true; return; }

        var data = { action: action, nonce: NC, page: 1, per_page: 50 };
        if (tab === 'files') {
            try {
                var qs = new URLSearchParams(window.location.search);
                var nb = qs.get('nb') || '';
                if (nb) { data.notebook_id = parseInt(nb, 10); }
            } catch (e) {}
        }

        $.post(AJ, data)
            .done(function (r) {
                $spinner.hide();
                if (r.success) {
                    loaded[tab] = true;
                    if (tab === 'files') {
                        renderFilesGrid(r.data.rows, r.data.total);
                    } else {
                        renderList(tab, r.data.rows, r.data.total);
                    }
                    maybeDrawChart(tab);
                } else {
                    $list.html('<p style="color:#dc2626">Lỗi: ' + esc(r.data.message) + '</p>');
                }
            })
            .fail(function () {
                $spinner.hide();
                $list.html('<p style="color:#dc2626">Kết nối thất bại.</p>');
            });
    }

    /* ================================================================
     * 3. RENDER LISTS (Episodic / Rolling / Notes)
     * ================================================================ */
    function renderList(tab, rows, total) {
        var $list = $('#detail-' + tab);
        if (!rows || rows.length === 0) {
            $list.html('<p style="text-align:center;color:#9ca3af;padding:24px 0">Chưa có dữ liệu.</p>');
            updateStatCount(tab, 0);
            return;
        }
        updateStatCount(tab, total);

        var html = '<table class="bk-editable-table" data-tab="' + esc(tab) + '"><thead><tr>';
        html += buildThead(tab);
        html += '</tr></thead><tbody>';
        for (var i = 0; i < rows.length; i++) {
            html += buildRow(tab, rows[i], i + 1);
        }
        html += '</tbody></table>';
        html += '<div class="bk-table-footer"><span class="bk-row-count">Tổng: <strong>' + total + '</strong></span></div>';
        $list.html(html);
    }

    function buildThead(tab) {
        if (tab === 'episodic') {
            return '<th>#</th><th>Loại</th><th>Key</th><th class="bk-col-wide">Nội dung</th><th>Quan trọng</th><th>Goal</th><th>Lần cuối</th><th></th>';
        }
        if (tab === 'rolling') {
            return '<th>#</th><th>Goal</th><th class="bk-col-wide">Tóm tắt</th><th>Status</th><th>Score</th><th>Turns</th><th>Cập nhật</th><th></th>';
        }
        if (tab === 'notes') {
            return '<th>#</th><th>Loại</th><th class="bk-col-wide">Tiêu đề</th><th class="bk-col-wide">Nội dung</th><th>Tags</th><th>⭐</th><th>Cập nhật</th><th></th>';
        }
        return '';
    }

    function buildRow(tab, row, idx) {
        var del = '';
        if (tab === 'episodic') {
            del = '<button class="bk-row-delete" data-tab="episodic" data-id="' + row.id + '" title="Xoá">×</button>';
            return '<tr class="bk-editable-row" data-id="' + row.id + '">'
                + '<td class="bk-row-number">' + idx + '</td>'
                + '<td><span class="badge">' + esc(row.event_type) + '</span></td>'
                + '<td style="font-size:11px;word-break:break-all">' + esc(row.event_key) + '</td>'
                + '<td class="bk-col-wide">' + esc(row.event_text) + '</td>'
                + '<td style="text-align:center">' + esc(row.importance) + '</td>'
                + '<td>' + esc(row.source_goal) + '</td>'
                + '<td>' + esc((row.last_seen || '').substring(0,16)) + '</td>'
                + '<td>' + del + '</td>'
                + '</tr>';
        }
        if (tab === 'rolling') {
            del = '<button class="bk-row-delete" data-tab="rolling" data-id="' + row.id + '" title="Xoá">×</button>';
            return '<tr class="bk-editable-row" data-id="' + row.id + '">'
                + '<td class="bk-row-number">' + idx + '</td>'
                + '<td>' + esc(row.goal_label || row.goal) + '</td>'
                + '<td class="bk-col-wide">' + esc(row.window_summary) + '</td>'
                + '<td><span class="badge">' + esc(row.status) + '</span></td>'
                + '<td>' + esc(row.user_goal_score) + '/' + esc(row.bot_satisfaction_score) + '</td>'
                + '<td style="text-align:center">' + esc(row.total_turns) + '</td>'
                + '<td>' + esc((row.updated_at || '').substring(0,16)) + '</td>'
                + '<td>' + del + '</td>'
                + '</tr>';
        }
        if (tab === 'notes') {
            del = '<button class="bk-row-delete" data-tab="notes" data-id="' + row.id + '" title="Xoá">×</button>';
            var star = row.is_starred == '1' ? '⭐' : '';
            return '<tr class="bk-editable-row" data-id="' + row.id + '">'
                + '<td class="bk-row-number">' + idx + '</td>'
                + '<td><span class="badge">' + esc(row.note_type) + '</span></td>'
                + '<td contenteditable="true" class="bk-editable bk-col-wide" data-field="title">' + esc(row.title) + '</td>'
                + '<td contenteditable="true" class="bk-editable bk-col-wide" data-field="content">' + esc(row.content) + '</td>'
                + '<td>' + esc(row.tags) + '</td>'
                + '<td style="text-align:center">' + star + '</td>'
                + '<td>' + esc((row.updated_at || '').substring(0,16)) + '</td>'
                + '<td>' + del + '</td>'
                + '</tr>';
        }
        return '';
    }

    /* ================================================================
     * 3b. RENDER FILES GRID (bzdoc_documents — Doc/Slide/Sheet đã sinh)
     *     Style matches TwinChat Brain home FilesWorkspace
     * ================================================================ */
    var DOC_TYPE_ICON = {
        document:     '📄',
        presentation: '📊',
        spreadsheet:  '📈',
        pdf:          '📕',
        image:        '🖼️'
    };

    function docEditUrl(id) {
        try {
            var base = (window.bizcity_knowledge_vars && bizcity_knowledge_vars.site_url)
                ? bizcity_knowledge_vars.site_url
                : window.location.origin;
            return base + '/tool-doc/?id=' + id;
        } catch (e) { return '/tool-doc/?id=' + id; }
    }

    function renderFilesGrid(rows, total) {
        var $list = $('#detail-files');
        if (!rows || rows.length === 0) {
            $list.html('<p style="text-align:center;color:#9ca3af;padding:24px 0">Chưa có file nào.</p>');
            updateStatCount('files', 0);
            return;
        }
        updateStatCount('files', total);

        var html = '<div class="bk-files-grid">';
        for (var i = 0; i < rows.length; i++) { html += buildFileCard(rows[i]); }
        html += '</div>';
        html += '<div class="bk-table-footer"><span class="bk-row-count">Tổng: <strong>' + total + '</strong></span></div>';
        $list.html(html);
    }

    function buildFileCard(row) {
        var icon   = DOC_TYPE_ICON[row.doc_type] || '📄';
        var title  = row.title || '(Untitled)';
        var type   = (row.doc_type || 'document').toUpperCase();
        var date   = formatFileDate(row.updated_at || row.created_at || '');
        var href   = docEditUrl(row.id);

        return '<div class="bk-file-card" data-id="' + esc(row.id) + '">'
            + '<button class="bk-file-card__del" data-tab="files" data-id="' + esc(row.id) + '" title="Xóa">×</button>'
            + '<a href="' + esc(href) + '" target="_blank" rel="noopener" style="display:block;text-decoration:none;color:inherit">'
            +   '<span class="bk-file-card__icon">' + icon + '</span>'
            +   '<div class="bk-file-card__title">' + esc(title) + '</div>'
            +   '<div class="bk-file-card__meta">'
            +     '<span class="bk-file-card__type">' + esc(type) + '</span>'
            +     '<span>' + esc(date) + '</span>'
            +   '</div>'
            + '</a>'
            + '</div>';
    }

    function formatFileDate(s) {
        if (!s) return '';
        try { return new Date(s.replace(' ', 'T') + 'Z').toLocaleDateString('vi-VN'); }
        catch (e) { return s.substring(0, 10); }
    }

    /* ================================================================
     * 4. CRUD EVENT DELEGATION
     * ================================================================ */
    function bindCrudDelegation() {
        var $doc = $(document);

        /* ── Delete button ── */
        $doc.on('click', '.bizcity-mh .bk-row-delete, .bizcity-mh .bk-file-card__del', function () {
            var $btn = $(this);
            var tab  = $btn.data('tab');
            var id   = parseInt($btn.data('id') || $btn.closest('tr, .bk-file-card').data('id'), 10);
            if (!tab || !id) return;
            var $target = $btn.closest('tr.bk-editable-row');
            if (!$target.length) { $target = $btn.closest('.bk-file-card'); }
            deleteRow(tab, id, $target);
        });

        /* ── Add row button ── */
        $doc.on('click', '.bizcity-mh .bk-btn-add', function () {
            var tab = $(this).data('tab');
            addRow(tab);
        });

        /* ── Blur save (contenteditable) ── */
        $doc.on('blur', '.bizcity-mh .bk-editable[contenteditable="true"]', function () {
            var $cell = $(this);
            var $row  = $cell.closest('tr.bk-editable-row');
            var tab   = $cell.closest('table').data('tab');
            saveRow(tab, $row);
        });

        /* ── Select change (memory_type for memories tab) ── */
        $doc.on('change', '.bizcity-mh .bk-cell-select', function () {
            var $sel = $(this);
            var $row = $sel.closest('tr.bk-editable-row');
            saveRow('memories', $row);
        });

        /* ── Doc-type filter for Files tab ── */
        $doc.on('change', '#bk-files-type-filter', function () {
            var docType = $(this).val();
            loaded['files'] = false;
            var $spinner = $('#panel-files .detail-loading');
            var $list    = $('#detail-files');
            $spinner.show(); $list.empty();
            var data = { action: 'bizcity_mh_files_list', nonce: NC, page: 1, per_page: 50, doc_type: docType };
            try {
                var nb = new URLSearchParams(window.location.search).get('nb') || '';
                if (nb) { data.notebook_id = parseInt(nb, 10); }
            } catch (e) {}
            $.post(AJ, data).done(function (r) {
                $spinner.hide();
                if (r.success) {
                    loaded['files'] = true;
                    renderFilesGrid(r.data.rows, r.data.total);
                } else {
                    $list.html('<p style="color:#dc2626">Lỗi: ' + esc(r.data.message) + '</p>');
                }
            }).fail(function () {
                $spinner.hide();
                $list.html('<p style="color:#dc2626">Kết nối thất bại.</p>');
            });
        });

        /* ── Export ── */
        $doc.on('click', '.bizcity-mh .bk-btn-export', function () {
            var tab    = $(this).data('tab');
            var format = $(this).data('format');
            exportTab(tab, format);
        });

        /* ── Import file ── */
        $doc.on('change', '.bizcity-mh .bk-btn-import input[type=file]', function () {
            var tab  = $(this).data('tab');
            var file = this.files[0];
            if (file) importFile(tab, file);
        });
    }

    /* ================================================================
     * 5. DELETE
     * ================================================================ */

    // Global helper referenced by PHP-rendered onclick
    window._matDelete = function (tab, id) {
        var $row = $('#panel-' + tab + ' tr[data-id="' + id + '"]');
        deleteRow(tab, id, $row);
    };

    function deleteRow(tab, id, $row) {
        if (!confirm('Xoá dòng này?')) return;

        var actionMap = {
            quickfaq: { action: 'bizcity_mh_faq_delete',      key: 'source_id' },
            memories: { action: 'bizcity_knowledge_delete_memory', key: 'memory_id' },
            episodic: { action: 'bizcity_mh_episodic_delete',  key: 'id' },
            rolling:  { action: 'bizcity_mh_rolling_delete',   key: 'id' },
            notes:    { action: 'bizcity_mh_notes_delete',     key: 'note_id' },
            files:    { action: 'bizcity_mh_files_delete',     key: 'doc_id' }
        };
        var cfg = actionMap[tab];
        if (!cfg) return;

        var data = { action: cfg.action, nonce: NC };
        data[cfg.key] = id;

        $.post(AJ, data)
            .done(function (r) {
                if (r.success) {
                    $row.fadeOut(300, function () { $(this).remove(); refreshCount(tab); });
                } else {
                    alert('Xoá thất bại: ' + (r.data ? r.data.message : ''));
                }
            });
    }

    /* ================================================================
     * 6. SAVE ROW (Quick FAQ + Long-term Memory + Notes)
     * ================================================================ */
    function saveRow(tab, $row) {
        if (saving) return;
        var id = parseInt($row.data('id'), 10);

        if (tab === 'quickfaq') {
            var question = $row.find('[data-field="question"]').text().trim();
            var answer   = $row.find('[data-field="answer"]').text().trim();
            if (question === '' && answer === '') return;
            saving = true;
            $.post(AJ, { action: 'bizcity_mh_faq_upsert', nonce: NC, source_id: id, question: question, answer: answer })
                .done(function (r) {
                    saving = false;
                    if (r.success) {
                        if (r.data.op === 'created') {
                            $row.data('id', r.data.id);
                            $row.attr('data-id', r.data.id);
                            // Update delete onclick
                            $row.find('.bk-row-delete').attr('data-id', r.data.id);
                        }
                        flashRow($row, true);
                    } else {
                        flashRow($row, false);
                    }
                })
                .fail(function () { saving = false; flashRow($row, false); });
        }

        if (tab === 'memories') {
            var content     = $row.find('[data-field="content"]').text().trim();
            var importance  = parseInt($row.find('[data-field="importance"]').text().trim(), 10) || 50;
            var memType     = $row.find('.bk-cell-select[data-field="memory_type"]').val() || 'fact';
            var memKey      = $row.find('.td-key').text().trim();
            if (content === '') return;
            saving = true;
            $.post(AJ, { action: 'bizcity_mh_memory_upsert', nonce: NC, memory_id: id, memory_type: memType, memory_key: memKey, content: content, importance: importance })
                .done(function (r) {
                    saving = false;
                    if (r.success) {
                        if (r.data.op === 'created') {
                            $row.data('id', r.data.id);
                            $row.attr('data-id', r.data.id);
                            $row.find('.bk-row-delete').attr('onclick', "window._matDelete('memories'," + r.data.id + ")");
                        }
                        flashRow($row, true);
                        maybeDrawChart('memories'); // refresh chart after type changes
                    } else {
                        flashRow($row, false);
                    }
                })
                .fail(function () { saving = false; flashRow($row, false); });
        }

        if (tab === 'notes') {
            var title   = $row.find('[data-field="title"]').text().trim();
            var ncont   = $row.find('[data-field="content"]').text().trim();
            if (title === '' && ncont === '') return;
            saving = true;
            $.post(AJ, { action: 'bizcity_mh_notes_upsert', nonce: NC, note_id: id, title: title, content: ncont })
                .done(function (r) {
                    saving = false;
                    if (r.success) {
                        if (r.data.op === 'created') {
                            $row.data('id', r.data.id);
                            $row.attr('data-id', r.data.id);
                            $row.find('.bk-row-delete').attr('data-id', r.data.id);
                        }
                        flashRow($row, true);
                    } else {
                        flashRow($row, false);
                    }
                })
                .fail(function () { saving = false; flashRow($row, false); });
        }
    }

    /* ================================================================
     * 7. ADD ROW
     * ================================================================ */
    function addRow(tab) {
        if (tab === 'quickfaq') {
            var $tbody = $('#detail-quickfaq table tbody');
            removePlaceholder($tbody);
            var idx = $tbody.find('tr.bk-editable-row').length + 1;
            var $tr = $('<tr class="bk-editable-row" data-id="0">'
                + '<td class="bk-row-number">' + idx + '</td>'
                + '<td contenteditable="true" class="bk-editable bk-col-wide" data-field="question"></td>'
                + '<td contenteditable="true" class="bk-editable bk-col-wide" data-field="answer"></td>'
                + '<td><span class="badge">ready</span></td>'
                + '<td>—</td>'
                + '<td><button class="bk-row-delete" data-tab="quickfaq" data-id="0" title="Xoá">×</button></td>'
                + '</tr>');
            $tbody.append($tr);
            $tr.find('[data-field="question"]').trigger('focus');
        }

        if (tab === 'memories') {
            var $tbody = $('#detail-memories table tbody');
            removePlaceholder($tbody);
            var idx = $tbody.find('tr.bk-editable-row').length + 1;
            var selOpts = MEM_TYPES.map(function (t) {
                return '<option value="' + t + '">' + t + '</option>';
            }).join('');
            var $tr = $('<tr class="bk-editable-row" data-id="0">'
                + '<td class="bk-row-number">' + idx + '</td>'
                + '<td><select class="bk-cell-select" data-field="memory_type">' + selOpts + '</select></td>'
                + '<td contenteditable="true" class="bk-editable bk-col-wide" data-field="content"></td>'
                + '<td contenteditable="true" class="bk-editable" data-field="importance">50</td>'
                + '<td class="td-key"></td>'
                + '<td>0</td>'
                + '<td>—</td>'
                + '<td><button class="bk-row-delete" onclick="window._matDelete(\'memories\',0)" title="Xoá">🗑️</button></td>'
                + '</tr>');
            $tbody.append($tr);
            $tr.find('[data-field="content"]').trigger('focus');
        }

        if (tab === 'notes') {
            if (!loaded[tab]) { lazyLoad(tab); return; }
            var $tbody = $('#detail-notes table tbody');
            if (!$tbody.length) {
                // table not yet rendered — build skeleton
                var $list = $('#detail-notes');
                $list.html('<table class="bk-editable-table" data-tab="notes"><thead><tr>' + buildThead('notes') + '</tr></thead><tbody></tbody></table><div class="bk-table-footer"><span class="bk-row-count">Tổng: <strong>0</strong></span></div>');
                $tbody = $('#detail-notes table tbody');
            }
            removePlaceholder($tbody);
            var idx = $tbody.find('tr.bk-editable-row').length + 1;
            var $tr = $('<tr class="bk-editable-row" data-id="0">'
                + '<td class="bk-row-number">' + idx + '</td>'
                + '<td><span class="badge">manual</span></td>'
                + '<td contenteditable="true" class="bk-editable bk-col-wide" data-field="title"></td>'
                + '<td contenteditable="true" class="bk-editable bk-col-wide" data-field="content"></td>'
                + '<td>[]</td><td></td><td>—</td>'
                + '<td><button class="bk-row-delete" data-tab="notes" data-id="0" title="Xoá">×</button></td>'
                + '</tr>');
            $tbody.append($tr);
            $tr.find('[data-field="title"]').trigger('focus');
        }
    }

    function removePlaceholder($tbody) {
        $tbody.find('.bk-empty-row').remove();
    }

    /* ================================================================
     * 8. COUNT REFRESH
     * ================================================================ */
    function refreshCount(tab) {
        var $panel = $('#panel-' + tab);
        var count;
        if (tab === 'files') {
            count = $panel.find('.bk-file-card').length;
        } else {
            count = $panel.find('tr.bk-editable-row').length;
        }
        updateStatCount(tab, count);
        $panel.find('.bk-row-count strong').text(count);
    }

    function updateStatCount(tab, n) {
        $('#stat-' + tab).text(n);
    }

    /* ================================================================
     * 9. FLASH FEEDBACK
     * ================================================================ */
    function flashRow($row, ok) {
        var color = ok ? '#d1fae5' : '#fee2e2';
        $row.css('background', color);
        setTimeout(function () { $row.css('background', ''); }, 800);
    }

    /* ================================================================
     * 10. EXPORT (JSON + CSV)
     * ================================================================ */
    function exportTab(tab, format) {
        var rows = [];

        if (tab === 'quickfaq') {
            $('#detail-quickfaq tr.bk-editable-row').each(function () {
                rows.push({
                    id:       $(this).data('id'),
                    question: $(this).find('[data-field="question"]').text().trim(),
                    answer:   $(this).find('[data-field="answer"]').text().trim()
                });
            });
        } else if (tab === 'memories') {
            $('#detail-memories tr.bk-editable-row').each(function () {
                rows.push({
                    id:          $(this).data('id'),
                    memory_type: $(this).find('.bk-cell-select').val(),
                    content:     $(this).find('[data-field="content"]').text().trim(),
                    importance:  $(this).find('[data-field="importance"]').text().trim()
                });
            });
        }

        if (!rows.length) { alert('Không có dữ liệu để xuất.'); return; }

        var blob, filename;
        if (format === 'json') {
            blob     = new Blob([JSON.stringify(rows, null, 2)], { type: 'application/json' });
            filename = 'memory-hub-' + tab + '.json';
        } else {
            var keys = Object.keys(rows[0]);
            var csv  = keys.join(',') + '\n';
            rows.forEach(function (r) {
                csv += keys.map(function (k) {
                    var v = String(r[k] || '').replace(/"/g, '""');
                    return '"' + v + '"';
                }).join(',') + '\n';
            });
            blob     = new Blob([csv], { type: 'text/csv' });
            filename = 'memory-hub-' + tab + '.csv';
        }

        var url = URL.createObjectURL(blob);
        var a   = document.createElement('a');
        a.href  = url;
        a.download = filename;
        a.click();
        setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
    }

    /* ================================================================
     * 11. IMPORT (JSON + CSV)
     * ================================================================ */
    function importFile(tab, file) {
        var reader = new FileReader();
        reader.onload = function (e) {
            var text = e.target.result;
            var rows;
            if (file.name.endsWith('.json')) {
                try { rows = JSON.parse(text); } catch (ex) { alert('JSON không hợp lệ.'); return; }
            } else {
                rows = csvToObjects(text);
            }
            if (!Array.isArray(rows) || !rows.length) { alert('File rỗng hoặc không hợp lệ.'); return; }

            if (!confirm('Import ' + rows.length + ' dòng vào ' + tab + '? Các dòng sẽ được thêm mới (không ghi đè).')) return;

            var done = 0;
            var failed = 0;

            function upsertNext(i) {
                if (i >= rows.length) {
                    alert('Import xong: ' + done + ' thành công, ' + failed + ' thất bại.');
                    if (tab === 'episodic' || tab === 'rolling' || tab === 'notes') {
                        loaded[tab] = false;
                        lazyLoad(tab);
                    }
                    return;
                }
                var r   = rows[i];
                var data = { nonce: NC, source_id: 0, memory_id: 0, note_id: 0 };

                if (tab === 'quickfaq') {
                    data.action   = 'bizcity_mh_faq_upsert';
                    data.question = r.question || r.title || '';
                    data.answer   = r.answer   || r.content || '';
                } else if (tab === 'memories') {
                    data.action      = 'bizcity_mh_memory_upsert';
                    data.memory_type = r.memory_type || 'fact';
                    data.memory_key  = r.memory_key  || '';
                    data.content     = r.content     || r.memory_text || '';
                    data.importance  = r.importance  || r.score || 50;
                } else {
                    upsertNext(i + 1); return; // unsupported tab
                }

                $.post(AJ, data)
                    .done(function (resp) {
                        if (resp.success) { done++; } else { failed++; }
                        upsertNext(i + 1);
                    })
                    .fail(function () { failed++; upsertNext(i + 1); });
            }

            upsertNext(0);
        };
        reader.readAsText(file, 'UTF-8');
    }

    function csvToObjects(text) {
        var lines  = text.split(/\r?\n/).filter(Boolean);
        if (!lines.length) return [];
        var keys = parseCsvLine(lines[0]);
        var out  = [];
        for (var i = 1; i < lines.length; i++) {
            var vals = parseCsvLine(lines[i]);
            var obj  = {};
            keys.forEach(function (k, j) { obj[k] = vals[j] || ''; });
            out.push(obj);
        }
        return out;
    }

    function parseCsvLine(line) {
        var re  = /("(?:[^"]|"")*"|[^,]*)/g;
        var out = [];
        var m;
        while ((m = re.exec(line)) !== null) {
            var v = m[1];
            if (v.charAt(0) === '"') { v = v.slice(1, -1).replace(/""/g, '"'); }
            out.push(v);
            if (re.lastIndex === line.length) break;
            re.lastIndex++; // skip comma
        }
        return out;
    }

    /* ================================================================
     * 12. CHARTS (Chart.js 4)
     * ================================================================ */
    function maybeDrawChart(tab) {
        if (typeof Chart === 'undefined') return;

        if (tab === 'memories') {
            drawMemoryTypesChart();
        } else if (tab === 'episodic') {
            drawEpisodicChart();
        } else if (tab === 'notes') {
            drawNotesChart();
        }
    }

    function drawMemoryTypesChart() {
        var counts = {};
        $('#detail-memories tr.bk-editable-row').each(function () {
            var t = $(this).find('.bk-cell-select').val() || 'fact';
            counts[t] = (counts[t] || 0) + 1;
        });
        renderDoughnut('chart-memory-types', counts, 'Long-term by Type');
    }

    function drawEpisodicChart() {
        var counts = {};
        $('#detail-episodic tr.bk-editable-row').each(function () {
            var t = $(this).find('.badge').first().text() || 'fact';
            counts[t] = (counts[t] || 0) + 1;
        });
        renderDoughnut('chart-episodic-types', counts, 'Episodic by Type');
    }

    function drawNotesChart() {
        var counts = {};
        $('#detail-notes tr.bk-editable-row').each(function () {
            var t = $(this).find('.badge').first().text() || 'manual';
            counts[t] = (counts[t] || 0) + 1;
        });
        renderDoughnut('chart-note-types', counts, 'Notes by Type');
    }

    var PALETTE = [
        '#6366f1','#f59e0b','#10b981','#ef4444','#3b82f6',
        '#8b5cf6','#ec4899','#14b8a6','#f97316','#84cc16'
    ];

    function renderDoughnut(canvasId, counts, label) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return;

        var keys = Object.keys(counts);
        if (!keys.length) return;

        // Destroy existing
        if (charts[canvasId]) { charts[canvasId].destroy(); }

        charts[canvasId] = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: keys,
                datasets: [{
                    label: label,
                    data:  keys.map(function (k) { return counts[k]; }),
                    backgroundColor: keys.map(function (_, i) { return PALETTE[i % PALETTE.length]; }),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { boxWidth: 12, font: { size: 11 } }
                    }
                }
            }
        });
    }

    /* ================================================================
     * 13. UTILITIES
     * ================================================================ */
    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})(jQuery);
