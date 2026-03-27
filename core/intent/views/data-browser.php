<?php
/**
 * BizCity Intent — Data Browser View
 *
 * Generic table browser for intent + planner DB tables.
 * JS handles: load data via AJAX, pagination, filtering, export, bulk delete.
 *
 * @package BizCity_Intent
 * @since   3.4.0
 */
defined( 'ABSPATH' ) or die( 'OOPS...' );

$page_slug = str_replace( 'bizcity-idb-', '', sanitize_text_field( $_GET['page'] ?? '' ) );
$is_intent  = strpos( $page_slug, 'int-' ) === 0;
$is_planner = strpos( $page_slug, 'plan-' ) === 0;
$section_icon  = $is_planner ? '🧠' : '💡';
$section_label = $is_planner ? 'Planner' : 'Intent';
?>
<div class="wrap bdb-wrap" data-slug="<?php echo esc_attr( $page_slug ); ?>">

    <h1 class="bdb-header">
        <span class="bdb-icon"><?php echo $section_icon; ?></span>
        <span id="bdb-title"><?php echo esc_html( $section_label ); ?> Data Browser</span>
        <span class="bdb-badge" id="bdb-total-badge" title="Total records">—</span>
    </h1>

    <!-- ── Navigation breadcrumb ── -->
    <nav class="bdb-breadcrumb">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-intent-monitor' ) ); ?>">Intent Monitor</a>
        <span class="bdb-sep">›</span>
        <span><?php echo esc_html( $section_label ); ?></span>
        <span class="bdb-sep">›</span>
        <span id="bdb-crumb-table"><?php echo esc_html( $page_slug ); ?></span>
    </nav>

    <!-- ── Filters bar ── -->
    <div class="bdb-filters" id="bdb-filters">
        <div class="bdb-filter-group" id="bdb-filter-fields">
            <!-- Filter inputs are injected by JS based on page config -->
        </div>
        <div class="bdb-filter-actions">
            <input type="search" id="bdb-search" placeholder="🔍 Free text search..." class="bdb-input" />
            <button class="button button-primary" id="bdb-btn-apply">Apply</button>
            <button class="button" id="bdb-btn-clear">Clear</button>
            <span class="bdb-separator">|</span>
            <button class="button" id="bdb-btn-export" title="Export current filtered view as JSON">
                📥 Export JSON
            </button>
            <button class="button" id="bdb-btn-export-related" title="Export with all related records across tables">
                📦 Export + Related
            </button>
        </div>
    </div>

    <!-- ── Bulk action bar (hidden until checkboxes selected) ── -->
    <div class="bdb-bulk-bar bdb-hidden" id="bdb-bulk-bar">
        <span class="bdb-bulk-count" id="bdb-bulk-count">0 selected</span>
        <button class="button bdb-btn-danger" id="bdb-btn-delete" title="Delete selected records">🗑 Delete Selected</button>
        <button class="button" id="bdb-btn-deselect">Deselect All</button>
    </div>

    <!-- ── Data table ── -->
    <div class="bdb-table-wrap">
        <table class="bdb-table" id="bdb-table">
            <thead id="bdb-thead">
                <tr><!-- Column headers injected by JS --></tr>
            </thead>
            <tbody id="bdb-tbody">
                <tr><td class="bdb-loading" colspan="20">Loading...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- ── Pagination ── -->
    <div class="bdb-pagination" id="bdb-pagination">
        <button class="button" id="bdb-prev" disabled>‹ Prev</button>
        <span id="bdb-page-info">Page 1</span>
        <button class="button" id="bdb-next" disabled>Next ›</button>
        <select id="bdb-per-page" class="bdb-input">
            <option value="25">25 / page</option>
            <option value="50" selected>50 / page</option>
            <option value="100">100 / page</option>
            <option value="200">200 / page</option>
        </select>
    </div>

    <!-- ── Record detail modal ── -->
    <div class="bdb-modal-backdrop" id="bdb-modal-backdrop" style="display:none;"></div>
    <div class="bdb-modal" id="bdb-modal" style="display:none;">
        <div class="bdb-modal-header">
            <h3 id="bdb-modal-title">Record Detail</h3>
            <span class="bdb-modal-close" id="bdb-modal-close">✕</span>
        </div>
        <div class="bdb-modal-body" id="bdb-modal-body">
            <!-- Record detail + related records rendered by JS -->
        </div>
    </div>

</div>
