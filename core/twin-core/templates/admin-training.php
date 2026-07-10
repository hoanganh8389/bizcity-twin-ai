<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Admin Training Page — Quick FAQ, Documents, Website
 *
 * Inline editable tables with export/import support.
 *
 * @package BizCity_Twin_Core
 */
defined( 'ABSPATH' ) or die();

// Pre-load Quick FAQ data so the table is available immediately (no AJAX timing gap)
global $wpdb;
$_uid  = get_current_user_id();
$_tbl  = $wpdb->prefix . 'bizcity_knowledge_sources';
$_faqs = [];
if ( bizcity_tbl_exists( $_tbl ) ) { // [2026-06-21 Johnny Chu] R-SHOW-TABLES
	$_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, source_name AS title, content, status, updated_at
		 FROM {$_tbl} WHERE user_id = %d AND source_type = 'quick_faq'
		 ORDER BY updated_at DESC LIMIT 200",
		$_uid
	), ARRAY_A ) ?: [];
	foreach ( $_rows as $_row ) {
		$_json           = json_decode( $_row['content'] ?? '', true );
		$_row['question'] = is_array( $_json ) ? ( $_json['question'] ?? $_json['title'] ?? '' ) : ( $_row['title'] ?? '' );
		$_row['answer']   = is_array( $_json ) ? ( $_json['answer'] ?? $_json['content'] ?? '' ) : ( $_row['content'] ?? '' );
		$_faqs[]          = $_row;
	}
}
?>
<script>var bizcPageContext = 'training';</script>
<style>
/* ─── Training (Maturity-style) — scoped, retired bundle replacement ─── */
.bizcity-mh { max-width:1200px; margin:0 auto; padding:16px 20px 32px;
	font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Inter",sans-serif; color:#1f2937; }
.bizcity-mh .maturity-header { margin:4px 0 18px; }
.bizcity-mh .maturity-header h1 { margin:0; font-size:18px; font-weight:600; color:#111827; letter-spacing:-0.01em; }
.bizcity-mh .maturity-header__overall { font-size:12px; color:#6b7280; margin-top:2px; }
.bizcity-mh .maturity-loading { padding:40px 20px; text-align:center; }
.bizcity-mh .maturity-loading__spinner { border:3px solid #f3f4f6; border-top-color:#111827;
	width:28px; height:28px; border-radius:50%; margin:0 auto 10px; animation:bmh-spin .9s linear infinite; }
@keyframes bmh-spin { to { transform:rotate(360deg); } }
.bizcity-mh .maturity-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
	gap:8px; margin-bottom:18px; }
.bizcity-mh .stat-card { padding:12px 14px; background:#fff; border:1px solid #e5e7eb;
	border-radius:10px; display:flex; align-items:center; gap:10px; text-align:left;
	cursor:pointer; transition:all .15s; }
.bizcity-mh .stat-card:hover { border-color:#d1d5db; background:#fafafa; }
.bizcity-mh .stat-card[aria-selected="true"] { border-color:#111827; }
.bizcity-mh .stat-icon { font-size:12px; width:32px; height:32px; display:inline-flex;
	align-items:center; justify-content:center; background:#f3f4f6; border-radius:8px; flex-shrink:0; }
.bizcity-mh .stat-card[aria-selected="true"] .stat-icon { background:#111827; color:#fff; }
.bizcity-mh .stat-value { font-size:18px; font-weight:600; color:#111827; line-height:1.1; }
.bizcity-mh .stat-label { font-size:11px; color:#6b7280; font-weight:500; }
.bizcity-mh .maturity-tab-panel { display:none; }
.bizcity-mh .maturity-tab-panel.active { display:block; }
.bizcity-mh .maturity-card { border:1px solid #e5e7eb; border-radius:10px; padding:16px 18px;
	background:#fff; }
.bizcity-mh .maturity-card h3 { font-size:14px; font-weight:600; color:#111827; margin:0 0 12px; }
.bizcity-mh .card-desc { font-size:12px; color:#6b7280; margin-bottom:12px; }
.bizcity-mh .tab-header { display:flex; align-items:center; justify-content:space-between;
	gap:8px; flex-wrap:wrap; margin-bottom:8px; }
.bizcity-mh .tab-header .tab-actions { display:flex; gap:6px; flex-wrap:wrap; }
.bizcity-mh .bk-btn-add, .bizcity-mh .bk-btn-export, .bizcity-mh .bk-btn-import,
.bizcity-mh .bk-btn-upload-source, .bizcity-mh .bk-btn-add-url-source, .bizcity-mh .bk-btn-embed-all {
	font-size:12px; padding:5px 10px; background:#fff; color:#374151;
	border:1px solid #e5e7eb; border-radius:6px; cursor:pointer; transition:all .15s;
	display:inline-flex; align-items:center; gap:4px; }
.bizcity-mh .bk-btn-add:hover, .bizcity-mh .bk-btn-export:hover, .bizcity-mh .bk-btn-import:hover,
.bizcity-mh .bk-btn-upload-source:hover, .bizcity-mh .bk-btn-add-url-source:hover, .bizcity-mh .bk-btn-embed-all:hover {
	background:#f9fafb; border-color:#d1d5db; color:#111827; }
.bizcity-mh .bk-btn-add { background:#111827; color:#fff; border-color:#111827; }
.bizcity-mh .bk-btn-add:hover { background:#1f2937; color:#fff; border-color:#1f2937; }
.bizcity-mh .bk-editable-table { font-size:12px; border-collapse:collapse; width:100%; }
.bizcity-mh .bk-editable-table thead th { font-size:10px; text-transform:uppercase;
	letter-spacing:.04em; font-weight:600; color:#9ca3af;
	background:#fafafa; border-bottom:1px solid #e5e7eb; padding:8px 10px; text-align:left; }
.bizcity-mh .bk-editable-table tbody td { padding:8px 10px; border-bottom:1px solid #f3f4f6;
	color:#374151; }
.bizcity-mh .bk-editable-table tbody tr:hover { background:#fafafa; }
.bizcity-mh .badge { font-size:10px; padding:2px 8px; border-radius:999px;
	background:#f3f4f6; color:#374151; border:1px solid #e5e7eb; }
.bizcity-mh .bk-row-delete { background:transparent; border:none; cursor:pointer;
	color:#9ca3af; font-size:14px; padding:2px 6px; border-radius:4px; }
.bizcity-mh .bk-row-delete:hover { color:#dc2626; background:#fef2f2; }
.bizcity-mh .bk-table-footer { padding:10px 0 2px; font-size:11px; color:#9ca3af; }
.bizcity-mh .detail-loading { font-size:12px; color:#9ca3af; padding:12px 0; }
</style>
<div class="wrap bizcity-maturity-wrap bizcity-mh">

	<!-- Header -->
	<div class="maturity-header">
		<div class="maturity-header__title">
			<h1>Twin Knowledge</h1>
			<div class="maturity-header__overall">
				<span class="overall-label">Dữ liệu huấn luyện chủ động</span>
			</div>
		</div>
	</div>

	<!-- Loading -->
	<div class="maturity-loading" id="maturity-loading" style="display:none">
		<div class="maturity-loading__spinner"></div>
		<p>Đang tải dữ liệu...</p>
	</div>

	<!-- Content -->
	<div class="maturity-content" id="maturity-content">

		<!-- Stats Row -->
		<div class="maturity-stats">
			<button class="stat-card" data-tab="quickfaq" aria-selected="true">
				<span class="stat-icon">FAQ</span>
				<span class="stat-meta">
					<span class="stat-value" id="stat-quickfaq"><?php echo count( $_faqs ); ?></span>
					<span class="stat-label">Quick FAQ</span>
				</span>
			</button>
			<button class="stat-card" data-tab="sources">
				<span class="stat-icon">DOC</span>
				<span class="stat-meta">
					<span class="stat-value" id="stat-sources">0</span>
					<span class="stat-label">Tài liệu</span>
				</span>
			</button>
		</div>

		<!-- ═══ TAB: Quick FAQ ═══ -->
		<div class="maturity-tab-panel active" id="panel-quickfaq">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>Quick FAQ — Huấn luyện Hỏi & Đáp</h3>
					<div class="tab-actions">
						<button class="bk-btn-add" data-tab="quickfaq">+ Thêm</button>
						<button class="bk-btn-export" data-tab="quickfaq" data-format="json">JSON</button>
						<button class="bk-btn-export" data-tab="quickfaq" data-format="csv">CSV</button>
						<label class="bk-btn-import">Import<input type="file" accept=".json,.csv" data-tab="quickfaq" hidden></label>
					</div>
				</div>
				<!-- Pre-rendered by PHP — no AJAX needed on first load -->
				<div class="detail-list" id="detail-quickfaq" data-preloaded="1">
					<table class="bk-editable-table" data-tab="quickfaq">
						<thead>
							<tr>
								<th class="bk-col-num">#</th>
								<th class="bk-col-wide">Câu hỏi</th>
								<th class="bk-col-wide">Câu trả lời</th>
								<th>TT</th>
								<th>Cập nhật</th>
								<th class="bk-col-action"></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $_faqs ) ) : ?>
								<tr class="bk-empty-row">
									<td colspan="6" style="text-align:center;color:#9ca3af;padding:24px 0">Chưa có dữ liệu. Nhấn <strong>+ Thêm</strong> để tạo.</td>
								</tr>
							<?php else : foreach ( $_faqs as $i => $_faq ) : ?>
								<tr class="bk-editable-row" data-id="<?php echo (int) $_faq['id']; ?>">
									<td class="bk-row-number"><?php echo $i + 1; ?></td>
									<td contenteditable="true" class="bk-editable bk-col-wide" data-field="question"><?php echo esc_html( $_faq['question'] ); ?></td>
									<td contenteditable="true" class="bk-editable bk-col-wide" data-field="answer"><?php echo esc_html( $_faq['answer'] ); ?></td>
									<td><span class="badge badge--blue"><?php echo esc_html( $_faq['status'] ); ?></span></td>
									<td><?php echo esc_html( substr( $_faq['updated_at'] ?? '', 0, 16 ) ); ?></td>
									<td><button class="bk-row-delete" onclick="window._matDelete('quickfaq',<?php echo (int) $_faq['id']; ?>)" title="Xoá">×</button></td>
								</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
					<div class="bk-table-footer">
						<span class="bk-row-count">Tổng: <strong><?php echo count( $_faqs ); ?></strong></span>
					</div>
				</div>
			</div>
		</div>

		<!-- ═══ TAB: Sources / Tài liệu ═══ -->
		<div class="maturity-tab-panel" id="panel-sources">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>Nguồn tài liệu đào tạo</h3>
					<div class="tab-actions">
						<button class="bk-btn-upload-source" type="button">Tải file lên</button>
						<button class="bk-btn-add-url-source" type="button">Thêm URL</button>
						<button class="bk-btn-embed-all" type="button" title="Embed tất cả nguồn chưa embed">Embed tất cả</button>
					</div>
				</div>

				<!-- Upload area (hidden by default, shown on click) -->
				<div class="bk-source-upload-area" id="source-upload-area" style="display:none">
					<div class="bk-upload-dropzone" id="source-dropzone">
						<div class="bk-upload-icon">📁</div>
						<p>Kéo thả file vào đây hoặc <strong>nhấn để chọn file</strong></p>
						<p class="bk-upload-formats">Hỗ trợ: PDF, TXT, MD, DOCX, CSV, JSON, XLSX, XLS, Image, Audio</p>
						<input type="file" id="source-file-input" multiple accept=".pdf,.txt,.md,.docx,.doc,.csv,.json,.xlsx,.xls,.pptx,.ppt,.jpg,.jpeg,.png,.webp,.gif,.mp3,.wav,.m4a,.ogg,.webm,.flac" style="display:none">
					</div>
					<div class="bk-upload-progress" id="source-upload-progress" style="display:none">
						<div class="bk-upload-progress-bar"><div class="bk-upload-progress-fill" id="source-progress-fill"></div></div>
						<span id="source-upload-status">Đang tải...</span>
					</div>
				</div>

				<!-- URL input area (hidden by default) -->
				<div class="bk-source-url-area" id="source-url-area" style="display:none">
					<div class="bk-url-input-wrap">
						<input type="text" id="source-url-input" class="regular-text" placeholder="https://example.com/article">
						<button type="button" class="button button-primary" id="source-url-submit">Thêm</button>
					</div>
				</div>

				<p class="card-desc">Tệp, văn bản, URL đã upload để huấn luyện AI. Cột <strong>Embed</strong> cho biết trạng thái embedding vector.</p>
				<div class="detail-loading">Đang tải...</div>
				<div class="detail-list" id="detail-sources"></div>
			</div>
		</div>

		<!-- ═══ Knowledge Characters tab REMOVED — đã có trong menu Twin Guru ═══ -->

	</div>
</div>

<script>
/**
 * Training page — minimal bootstrap (mirrors admin-memory.php inline shim).
 * Restores stat-card tab switching after the legacy maturity dashboard JS
 * bundle was retired. Quick FAQ table is pre-rendered server-side; the
 * Sources / Knowledge tabs still need their own loaders (TODO).
 */
(function () {
	function ready(fn) {
		if (document.readyState !== 'loading') { fn(); }
		else { document.addEventListener('DOMContentLoaded', fn); }
	}
	ready(function () {
		var root   = document.querySelector('.bizcity-mh');
		if (!root) return;
		var cards  = root.querySelectorAll('.stat-card[data-tab]');
		var panels = root.querySelectorAll('.maturity-tab-panel');
		cards.forEach(function (btn) {
			btn.addEventListener('click', function () {
				var tab = btn.getAttribute('data-tab');
				cards.forEach(function (b) {
					b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
				});
				panels.forEach(function (p) {
					p.classList.toggle('active', p.id === 'panel-' + tab);
				});
			});
		});
	});
})();
</script>
