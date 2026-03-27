/* global BizIntentBrowser, jQuery */
/**
 * BizCity Intent — Data Browser JS
 *
 * Generic table browser for intent + planner DB tables.
 * Load via AJAX, paginate, filter, export JSON,
 * click-through to related records, checkbox select + bulk delete.
 *
 * Config passed via wp_localize_script as BizIntentBrowser:
 *   ajax_url, nonce, slug, table, columns[], filters[], links{}, base_url,
 *   action_browse, action_export, action_detail, action_delete, page_prefix
 */
(function ($) {
    'use strict';

    const C = window.BizIntentBrowser || {};
    if ( !C.table ) return;

    let state = {
        page:     1,
        per:      50,
        sort:     'id',
        dir:      'DESC',
        search:   '',
        filters:  {},
        total:    0,
        selected: new Set(),
        expanded: {},  // { rowId: sectionData } cache
    };

    /* ── Init ──────────────────────────────────────────────── */
    $(function () {
        $('#bdb-title').text( C.slug.replace( /-/g, ' ' ).replace( /\b\w/g, c => c.toUpperCase() ) );
        $('#bdb-crumb-table').text( C.slug );
        buildFilterInputs();
        buildTableHeader();
        applyUrlParams();
        loadData();
        bindEvents();
    });

    /* ── Build filter inputs from config ───────────────────── */
    function buildFilterInputs() {
        const $box = $('#bdb-filter-fields');
        (C.filters || []).forEach(function (col) {
            const label = col.replace( /_/g, ' ' );
            $box.append(
                '<label class="bdb-filter-label">' + escHtml(label) +
                '<input type="text" class="bdb-input bdb-filter-input" data-col="' + escHtml(col) + '" placeholder="' + escHtml(col) + '" />' +
                '</label>'
            );
        });
    }

    /* ── Build table header ────────────────────────────────── */
    function buildTableHeader() {
        let html = '<th class="bdb-cb-col"><input type="checkbox" id="bdb-select-all" title="Select all" /></th>';
        (C.columns || []).forEach(function (col) {
            const label = col.replace( /_/g, ' ' );
            html += '<th class="bdb-sortable" data-col="' + escHtml(col) + '">' + escHtml(label) + ' <span class="bdb-sort-arrow"></span></th>';
        });
        html += '<th>Actions</th>';
        $('#bdb-thead tr').html( html );
    }

    /* ── Apply URL params (for cross-linking) ──────────────── */
    function applyUrlParams() {
        const params = new URLSearchParams( window.location.search );
        (C.filters || []).forEach(function (col) {
            const val = params.get( 'f_' + col );
            if ( val ) {
                state.filters[ col ] = val;
                $( '.bdb-filter-input[data-col="' + col + '"]' ).val( val );
            }
        });
    }

    /* ── Load data via AJAX ────────────────────────────────── */
    function loadData() {
        const colSpan = C.columns.length + 2;
        const params = {
            action: C.action_browse,
            nonce:  C.nonce,
            table:  C.table,
            page:   state.page,
            per:    state.per,
            sort:   state.sort,
            dir:    state.dir,
            search: state.search,
        };
        Object.keys( state.filters ).forEach(function (col) {
            if ( state.filters[col] ) params[ 'f_' + col ] = state.filters[col];
        });

        $('#bdb-tbody').html( '<tr><td class="bdb-loading" colspan="' + colSpan + '">Loading...</td></tr>' );
        state.expanded = {}; // Clear expand cache on new data load

        $.get( C.ajax_url, params )
        .done(function (res) {
            if ( res.success ) {
                renderRows( res.data.rows );
                state.total = res.data.total;
                updatePagination();
                updateBadge();
                updateBulkBar();
            } else {
                $('#bdb-tbody').html( '<tr><td class="bdb-error" colspan="' + colSpan + '">Error: ' + escHtml(res.data || 'Unknown') + '</td></tr>' );
            }
        })
        .fail(function () {
            $('#bdb-tbody').html( '<tr><td class="bdb-error" colspan="' + colSpan + '">Request failed</td></tr>' );
        });
    }

    /* ── Render table rows ─────────────────────────────────── */
    function renderRows( rows ) {
        const colSpan = C.columns.length + 2;
        const $tbody = $('#bdb-tbody');
        if ( !rows || !rows.length ) {
            $tbody.html( '<tr><td class="bdb-empty" colspan="' + colSpan + '">No records found</td></tr>' );
            return;
        }

        let html = '';
        rows.forEach(function (row) {
            const rid      = row.id;
            const checked  = state.selected.has( String(rid) ) ? ' checked' : '';
            const selClass = checked ? ' bdb-row-selected' : '';
            html += '<tr data-id="' + escHtml(rid) + '" class="' + selClass + '">';
            // Checkbox
            html += '<td class="bdb-cb-col"><input type="checkbox" class="bdb-row-cb" value="' + escHtml(rid) + '"' + checked + ' /></td>';
            C.columns.forEach(function (col) {
                const val  = row[col] ?? '';
                const link = C.links[col];
                html += '<td>';
                if ( link && val ) {
                    const href = C.base_url + '?page=' + C.page_prefix + link + '&f_' + col + '=' + encodeURIComponent(val);
                    html += '<a class="bdb-cross-link" href="' + escHtml(href) + '" title="Browse ' + escHtml(link) + ' where ' + escHtml(col) + '=' + escHtml(val) + '">' + formatCell(col, val) + '</a>';
                } else if ( col === 'status' || col === 'ok' || col === 'active' || col === 'outcome' || col === 'level' ) {
                    html += statusBadge( col, val );
                } else {
                    html += formatCell( col, val );
                }
                html += '</td>';
            });
            html += '<td>';
            html += '<button class="button button-small bdb-expand-btn" data-id="' + escHtml(rid) + '" title="Expand related">&#9654;</button> ';
            html += '<button class="button button-small bdb-view-btn" data-id="' + escHtml(rid) + '">View</button>';
            html += '</td>';
            html += '</tr>';
        });
        $tbody.html( html );
        syncSelectAll();
    }

    /* ── Cell formatting ───────────────────────────────────── */
    function formatCell( col, val ) {
        if ( val === null || val === undefined ) return '<span class="bdb-null">null</span>';
        const str = String(val);
        if ( str.length > 80 && ( str.charAt(0) === '{' || str.charAt(0) === '[' ) ) {
            return '<span class="bdb-json-preview" title="' + escHtml(str) + '">' + escHtml(str.substring(0, 60)) + '…</span>';
        }
        if ( str.length > 120 ) {
            return '<span title="' + escHtml(str) + '">' + escHtml(str.substring(0, 100)) + '…</span>';
        }
        return escHtml( str );
    }

    function statusBadge( col, val ) {
        if ( col === 'ok' ) {
            return val == 1
                ? '<span class="bdb-badge-ok">✅ OK</span>'
                : '<span class="bdb-badge-fail">❌ FAIL</span>';
        }
        if ( col === 'active' ) {
            return val == 1
                ? '<span class="bdb-badge-ok">✅ Active</span>'
                : '<span class="bdb-badge-fail">❌ Inactive</span>';
        }
        if ( col === 'level' ) {
            const cls = 'bdb-level-' + String(val).replace( /[^a-z0-9_]/gi, '' );
            return '<span class="bdb-status ' + cls + '">' + escHtml(val) + '</span>';
        }
        const cls = 'bdb-status-' + String(val).replace( /[^a-z0-9_]/gi, '' );
        return '<span class="bdb-status ' + cls + '">' + escHtml(val) + '</span>';
    }

    /* ── Pagination ────────────────────────────────────────── */
    function updatePagination() {
        const totalPages = Math.max( 1, Math.ceil( state.total / state.per ) );
        $('#bdb-page-info').text( 'Page ' + state.page + ' / ' + totalPages + ' (' + state.total + ' records)' );
        $('#bdb-prev').prop( 'disabled', state.page <= 1 );
        $('#bdb-next').prop( 'disabled', state.page >= totalPages );
    }

    function updateBadge() {
        $('#bdb-total-badge').text( state.total );
    }

    /* ── Events ────────────────────────────────────────────── */
    function bindEvents() {
        // Apply filters
        $('#bdb-btn-apply').on( 'click', applyFilters );
        $('#bdb-search').on( 'keypress', function (e) { if (e.which === 13) applyFilters(); } );
        $('.bdb-filter-input').on( 'keypress', function (e) { if (e.which === 13) applyFilters(); } );

        // Clear filters
        $('#bdb-btn-clear').on( 'click', function () {
            state.filters = {};
            state.search  = '';
            state.page    = 1;
            $('.bdb-filter-input').val( '' );
            $('#bdb-search').val( '' );
            loadData();
        });

        // Pagination
        $('#bdb-prev').on( 'click', function () { if (state.page > 1) { state.page--; loadData(); } });
        $('#bdb-next').on( 'click', function () {
            const totalPages = Math.ceil( state.total / state.per );
            if ( state.page < totalPages ) { state.page++; loadData(); }
        });
        $('#bdb-per-page').on( 'change', function () {
            state.per  = parseInt( $(this).val(), 10 );
            state.page = 1;
            loadData();
        });

        // Sort
        $(document).on( 'click', '.bdb-sortable', function () {
            const col = $(this).data('col');
            if ( state.sort === col ) {
                state.dir = state.dir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                state.sort = col;
                state.dir  = 'DESC';
            }
            state.page = 1;
            updateSortArrows();
            loadData();
        });

        // View record
        $(document).on( 'click', '.bdb-view-btn', function () {
            openRecordDetail( $(this).data('id') );
        });

        // Expand row
        $(document).on( 'click', '.bdb-expand-btn', function () {
            const $btn = $(this);
            const rid  = String( $btn.data('id') );
            const $row = $btn.closest('tr');
            const $next = $row.next('tr.bdb-expand-row');

            // Toggle: if already expanded, collapse
            if ( $next.length && $next.data('expand-id') === rid ) {
                $next.remove();
                $btn.html('&#9654;').removeClass('bdb-expand-active');
                return;
            }

            // Collapse any other expanded row
            $('#bdb-tbody .bdb-expand-row').remove();
            $('#bdb-tbody .bdb-expand-btn').html('&#9654;').removeClass('bdb-expand-active');

            $btn.html('&#9660;').addClass('bdb-expand-active');

            const colSpan = C.columns.length + 2;
            const $expandRow = $('<tr class="bdb-expand-row" data-expand-id="' + escHtml(rid) + '">' +
                '<td colspan="' + colSpan + '" class="bdb-expand-cell"><div class="bdb-expand-loading">Loading related data...</div></td></tr>');
            $row.after( $expandRow );

            // Check cache
            if ( state.expanded[ rid ] ) {
                renderExpandPanel( $expandRow.find('.bdb-expand-cell'), state.expanded[ rid ] );
                return;
            }

            // AJAX fetch
            $.get( C.ajax_url, {
                action: C.action_expand,
                nonce:  C.nonce,
                table:  C.table,
                id:     rid,
            })
            .done(function (res) {
                if ( res.success && res.data.sections ) {
                    state.expanded[ rid ] = res.data.sections;
                    renderExpandPanel( $expandRow.find('.bdb-expand-cell'), res.data.sections );
                } else {
                    $expandRow.find('.bdb-expand-cell').html('<div class="bdb-expand-empty">No related data found</div>');
                }
            })
            .fail(function () {
                $expandRow.find('.bdb-expand-cell').html('<div class="bdb-expand-error">Failed to load related data</div>');
            });
        });

        // Export
        $('#bdb-btn-export').on( 'click', function () { doExport(false); } );
        $('#bdb-btn-export-related').on( 'click', function () { doExport(true); } );

        // Modal close
        $('#bdb-modal-close, #bdb-modal-backdrop').on( 'click', closeModal );
        $(document).on( 'keydown', function (e) { if (e.key === 'Escape') closeModal(); } );

        // ── Checkbox / bulk select ──
        $(document).on( 'change', '#bdb-select-all', function () {
            const isChecked = this.checked;
            $('#bdb-tbody .bdb-row-cb').each(function () {
                this.checked = isChecked;
                const id = String( $(this).val() );
                if ( isChecked ) {
                    state.selected.add( id );
                    $(this).closest('tr').addClass('bdb-row-selected');
                } else {
                    state.selected.delete( id );
                    $(this).closest('tr').removeClass('bdb-row-selected');
                }
            });
            updateBulkBar();
        });

        $(document).on( 'change', '.bdb-row-cb', function () {
            const id = String( $(this).val() );
            if ( this.checked ) {
                state.selected.add( id );
                $(this).closest('tr').addClass('bdb-row-selected');
            } else {
                state.selected.delete( id );
                $(this).closest('tr').removeClass('bdb-row-selected');
            }
            syncSelectAll();
            updateBulkBar();
        });

        $('#bdb-btn-deselect').on( 'click', function () {
            state.selected.clear();
            $('#bdb-tbody .bdb-row-cb').prop( 'checked', false );
            $('#bdb-select-all').prop( 'checked', false );
            $('#bdb-tbody tr').removeClass('bdb-row-selected');
            updateBulkBar();
        });

        $('#bdb-btn-delete').on( 'click', function () {
            const count = state.selected.size;
            if ( !count ) return;
            if ( !confirm( 'Delete ' + count + ' record(s) from ' + C.table + '? This cannot be undone.' ) ) return;
            doBulkDelete();
        });
    }

    function applyFilters() {
        state.filters = {};
        $('.bdb-filter-input').each(function () {
            const col = $(this).data('col');
            const val = $(this).val().trim();
            if ( val ) state.filters[col] = val;
        });
        state.search = $('#bdb-search').val().trim();
        state.page   = 1;
        loadData();
    }

    function updateSortArrows() {
        $('.bdb-sort-arrow').text( '' );
        const $th = $( '.bdb-sortable[data-col="' + state.sort + '"]' );
        $th.find( '.bdb-sort-arrow' ).text( state.dir === 'ASC' ? ' ▲' : ' ▼' );
    }

    /* ── Checkbox helpers ──────────────────────────────────── */
    function syncSelectAll() {
        const $cbs    = $('#bdb-tbody .bdb-row-cb');
        const total   = $cbs.length;
        const checked = $cbs.filter(':checked').length;
        $('#bdb-select-all').prop( 'checked', total > 0 && checked === total );
    }

    function updateBulkBar() {
        const count = state.selected.size;
        if ( count > 0 ) {
            $('#bdb-bulk-bar').removeClass('bdb-hidden');
            $('#bdb-bulk-count').text( count + ' selected' );
        } else {
            $('#bdb-bulk-bar').addClass('bdb-hidden');
        }
    }

    /* ── Bulk delete ───────────────────────────────────────── */
    function doBulkDelete() {
        const ids = Array.from( state.selected );
        $('#bdb-btn-delete').prop( 'disabled', true ).text( 'Deleting...' );

        $.post( C.ajax_url, {
            action: C.action_delete,
            nonce:  C.nonce,
            table:  C.table,
            ids:    ids.join(','),
        })
        .done(function (res) {
            if ( res.success ) {
                state.selected.clear();
                loadData();
            } else {
                alert( 'Delete failed: ' + (res.data || 'Unknown error') );
            }
        })
        .fail(function () {
            alert( 'Delete request failed' );
        })
        .always(function () {
            $('#bdb-btn-delete').prop( 'disabled', false ).text( '🗑 Delete Selected' );
            updateBulkBar();
        });
    }

    /* ── Export ─────────────────────────────────────────────── */
    function doExport( withRelated ) {
        const params = new URLSearchParams({
            action:  C.action_export,
            nonce:   C.nonce,
            table:   C.table,
            sort:    state.sort,
            dir:     state.dir,
            search:  state.search,
        });
        if ( withRelated ) params.set( 'related', '1' );
        Object.keys( state.filters ).forEach(function (col) {
            if ( state.filters[col] ) params.set( 'f_' + col, state.filters[col] );
        });
        window.location.href = C.ajax_url + '?' + params.toString();
    }

    /* ── Record detail modal ───────────────────────────────── */
    function openRecordDetail( rowId ) {
        $('#bdb-modal-body').html( '<p class="bdb-loading">Loading record...</p>' );
        $('#bdb-modal, #bdb-modal-backdrop').show();

        $.get( C.ajax_url, {
            action: C.action_detail,
            nonce:  C.nonce,
            table:  C.table,
            id:     rowId,
        })
        .done(function (res) {
            if ( !res.success ) {
                $('#bdb-modal-body').html( '<p class="bdb-error">Error: ' + escHtml(res.data || 'Unknown') + '</p>' );
                return;
            }
            renderRecordDetail( res.data.record, res.data.related );
        })
        .fail(function () {
            $('#bdb-modal-body').html( '<p class="bdb-error">Request failed</p>' );
        });
    }

    function renderRecordDetail( record, related ) {
        let html = '<div class="bdb-detail-record">';
        html += '<h4>Record #' + escHtml(record.id) + '</h4>';
        html += '<table class="bdb-detail-table">';
        Object.keys(record).forEach(function (key) {
            const val = record[key];
            html += '<tr>';
            html += '<th>' + escHtml(key) + '</th>';
            html += '<td>' + renderValue(key, val) + '</td>';
            html += '</tr>';
        });
        html += '</table></div>';

        // Quick navigation links
        if ( record.conversation_id || record.session_id || record.trace_id || record.intent_key || record.playbook_key || record.executor_trace_id ) {
            html += '<div class="bdb-detail-links"><h4>🔗 Quick Links</h4><div class="bdb-link-chips">';

            if ( record.conversation_id ) {
                html += linkChip( 'int-conversations', 'conversation_id', record.conversation_id, '💬 Conversation' );
                html += linkChip( 'int-turns', 'conversation_id', record.conversation_id, '🔄 Turns' );
                html += linkChip( 'int-prompt-logs', 'conversation_id', record.conversation_id, '📝 Prompt Logs' );
                html += linkChip( 'int-logs', 'conversation_id', record.conversation_id, '🐛 Debug Logs' );
            }
            if ( record.session_id ) {
                html += linkChip( 'int-prompt-logs', 'session_id', record.session_id, '🗂 Session Logs' );
                html += linkChip( 'int-conversations', 'session_id', record.session_id, '💬 Session Convs' );
            }
            if ( record.trace_id ) {
                html += linkChip( 'int-logs', 'trace_id', record.trace_id, '🐛 Trace Logs' );
            }
            if ( record.intent_key ) {
                html += linkChip( 'plan-candidates', 'intent_key', record.intent_key, '🎯 Candidates' );
                html += linkChip( 'plan-playbooks', 'intent_key', record.intent_key, '📖 Playbooks' );
            }
            if ( record.playbook_key ) {
                html += linkChip( 'plan-playbooks', 'playbook_key', record.playbook_key, '📖 Playbook' );
                html += linkChip( 'plan-cache', 'playbook_key', record.playbook_key, '💾 Cache' );
            }
            if ( record.executor_trace_id ) {
                // Link to executor data browser pages
                html += '<a class="bdb-link-chip" href="' + escHtml( C.base_url + '?page=bizcity-exec-traces&f_trace_id=' + encodeURIComponent(record.executor_trace_id) ) + '">⚙ Executor Trace<code>' + escHtml( String(record.executor_trace_id).substring(0, 24) ) + '</code></a>';
            }
            if ( record.tool_key ) {
                html += linkChip( 'plan-candidates', 'tool_key', record.tool_key, '🎯 Tool Candidates' );
                html += linkChip( 'plan-tool-stats', 'tool_key', record.tool_key, '📊 Tool Stats' );
            }

            html += '</div></div>';
        }

        // Related records
        if ( related ) {
            html += '<div class="bdb-detail-related">';
            Object.keys(related).forEach(function (key) {
                const items = related[key];
                if ( !items || ( Array.isArray(items) && !items.length ) ) return;
                html += '<h4>Related: ' + escHtml(key) + '</h4>';
                if ( Array.isArray(items) ) {
                    html += '<table class="bdb-mini-table"><thead><tr>';
                    Object.keys(items[0]).forEach(function (col) { html += '<th>' + escHtml(col) + '</th>'; });
                    html += '</tr></thead><tbody>';
                    items.forEach(function (item) {
                        html += '<tr>';
                        Object.keys(item).forEach(function (col) {
                            html += '<td>' + formatCell(col, item[col]) + '</td>';
                        });
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                } else if ( typeof items === 'object' ) {
                    html += '<table class="bdb-detail-table">';
                    Object.keys(items).forEach(function (k2) {
                        html += '<tr><th>' + escHtml(k2) + '</th><td>' + renderValue(k2, items[k2]) + '</td></tr>';
                    });
                    html += '</table>';
                }
            });
            html += '</div>';
        }

        $('#bdb-modal-title').text( C.table + ' #' + record.id );
        $('#bdb-modal-body').html( html );
    }

    function renderValue( key, val ) {
        if ( val === null || val === undefined ) return '<span class="bdb-null">null</span>';
        if ( typeof val === 'object' ) {
            return '<pre class="bdb-json-block">' + escHtml(JSON.stringify(val, null, 2)) + '</pre>';
        }
        const str = String(val);
        if ( str.length > 200 ) {
            return '<pre class="bdb-json-block">' + escHtml(str) + '</pre>';
        }
        return escHtml(str);
    }

    function linkChip( pageSlug, filterCol, filterVal, label ) {
        const href = C.base_url + '?page=' + C.page_prefix + pageSlug + '&f_' + filterCol + '=' + encodeURIComponent(filterVal);
        return '<a class="bdb-link-chip" href="' + escHtml(href) + '">' + label + '<code>' + escHtml( String(filterVal).substring(0, 24) ) + '</code></a>';
    }

    function closeModal() {
        $('#bdb-modal, #bdb-modal-backdrop').hide();
    }

    /* ── Expand panel renderer ────────────────────────── */
    function renderExpandPanel( $cell, sections ) {
        const keys = Object.keys( sections );
        if ( !keys.length ) {
            $cell.html( '<div class="bdb-expand-empty">No related data found</div>' );
            return;
        }

        let html = '<div class="bdb-expand-panel">';

        // Tab navigation
        html += '<div class="bdb-expand-tabs">';
        keys.forEach(function (key, idx) {
            const sec   = sections[key];
            const count = sec.rows ? sec.rows.length : 0;
            const cls   = idx === 0 ? ' bdb-tab-active' : '';
            html += '<button class="bdb-expand-tab' + cls + '" data-tab="' + escHtml(key) + '">';
            html += escHtml( sec.label || key );
            html += ' <span class="bdb-tab-count">' + count + '</span>';
            html += '</button>';
        });
        html += '</div>';

        // Tab panels
        keys.forEach(function (key, idx) {
            const sec  = sections[key];
            const disp = idx === 0 ? '' : ' style="display:none"';
            html += '<div class="bdb-expand-tab-panel" data-tab="' + escHtml(key) + '"' + disp + '>';

            if ( sec.link ) {
                html += '<div class="bdb-expand-view-all"><a href="' + escHtml( C.base_url + sec.link ) + '" class="button button-small">View all →</a></div>';
            }

            if ( sec.rows && sec.rows.length ) {
                html += renderMiniTable( sec.rows );
            } else {
                html += '<p class="bdb-expand-empty">No records</p>';
            }
            html += '</div>';
        });

        html += '</div>';
        $cell.html( html );

        // Tab click handler
        $cell.find('.bdb-expand-tab').on('click', function () {
            const tab = $(this).data('tab');
            $cell.find('.bdb-expand-tab').removeClass('bdb-tab-active');
            $(this).addClass('bdb-tab-active');
            $cell.find('.bdb-expand-tab-panel').hide();
            $cell.find('.bdb-expand-tab-panel[data-tab="' + tab + '"]').show();
        });
    }

    function renderMiniTable( rows ) {
        if ( !rows || !rows.length ) return '';
        const cols = Object.keys( rows[0] );
        let html = '<div class="bdb-expand-table-wrap"><table class="bdb-expand-table"><thead><tr>';
        cols.forEach(function (col) {
            html += '<th>' + escHtml( col.replace(/_/g, ' ') ) + '</th>';
        });
        html += '</tr></thead><tbody>';
        rows.forEach(function (row) {
            html += '<tr>';
            cols.forEach(function (col) {
                const val = row[col];
                html += '<td>';
                if ( col === 'status' || col === 'ok' || col === 'active' || col === 'outcome' || col === 'level' ) {
                    html += statusBadge( col, val );
                } else if ( col === 'conversation_id' && val ) {
                    const href = C.base_url + '?page=' + C.page_prefix + 'int-conversations&f_conversation_id=' + encodeURIComponent(val);
                    html += '<a class="bdb-cross-link" href="' + escHtml(href) + '">' + escHtml(String(val).substring(0, 32)) + '</a>';
                } else if ( col === 'trace_id' && val ) {
                    const href = C.base_url + '?page=bizcity-exec-traces&f_trace_id=' + encodeURIComponent(val);
                    html += '<a class="bdb-cross-link" href="' + escHtml(href) + '">' + escHtml(String(val).substring(0, 24)) + '</a>';
                } else if ( col === 'task_id' && val ) {
                    const href = C.base_url + '?page=bizcity-exec-trace-tasks&f_task_id=' + encodeURIComponent(val);
                    html += '<a class="bdb-cross-link" href="' + escHtml(href) + '">' + escHtml(String(val).substring(0, 24)) + '</a>';
                } else {
                    html += formatCell( col, val );
                }
                html += '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        return html;
    }

    /* ── Utilities ─────────────────────────────────────────── */
    function escHtml( str ) {
        if ( str == null ) return '';
        return String(str)
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

}(jQuery));
