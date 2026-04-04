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
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$_tbl}'" ) === $_tbl ) {
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
<div class="wrap bizcity-maturity-wrap">

	<!-- Header -->
	<div class="maturity-header">
		<div class="maturity-header__title">
			<h1>📚 Đào tạo AI</h1>
			<div class="maturity-header__overall">
				<span class="overall-label">Dữ liệu huấn luyện chủ động</span>
			</div>
		</div>
	</div>

	<!-- Loading -->
	<div class="maturity-loading" id="maturity-loading">
		<div class="maturity-loading__spinner"></div>
		<p>Đang tải dữ liệu...</p>
	</div>

	<!-- Content -->
	<div class="maturity-content" id="maturity-content" style="display:none">

		<!-- Stats Row -->
		<div class="maturity-stats">
			<button class="stat-card" data-tab="quickfaq" aria-selected="true">
				<span class="stat-icon">❓</span>
				<span class="stat-value" id="stat-quickfaq"><?php echo count( $_faqs ); ?></span>
				<span class="stat-label">Quick FAQ</span>
			</button>
			<button class="stat-card" data-tab="sources">
				<span class="stat-icon">📄</span>
				<span class="stat-value" id="stat-sources">0</span>
				<span class="stat-label">Tài liệu</span>
			</button>
			<button class="stat-card" data-tab="knowledge">
				<span class="stat-icon">🎭</span>
				<span class="stat-value" id="stat-knowledge">0</span>
				<span class="stat-label">Tri thức</span>
			</button>
		</div>

		<!-- ═══ TAB: Quick FAQ ═══ -->
		<div class="maturity-tab-panel active" id="panel-quickfaq">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>❓ Quick FAQ — Huấn luyện Hỏi & Đáp</h3>
					<div class="tab-actions">
						<button class="bk-btn-add" data-tab="quickfaq">+ Thêm</button>
						<button class="bk-btn-export" data-tab="quickfaq" data-format="json">📤 JSON</button>
						<button class="bk-btn-export" data-tab="quickfaq" data-format="csv">📤 CSV</button>
						<label class="bk-btn-import">📥 Import<input type="file" accept=".json,.csv" data-tab="quickfaq" hidden></label>
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
									<td><button class="bk-row-delete" onclick="window._matDelete('quickfaq',<?php echo (int) $_faq['id']; ?>)" title="Xoá">🗑️</button></td>
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
					<h3>📄 Nguồn tài liệu đào tạo</h3>
					<div class="tab-actions">
						<button class="bk-btn-upload-source" type="button">📁 Tải file lên</button>
						<button class="bk-btn-add-url-source" type="button">🌐 Thêm URL</button>
						<button class="bk-btn-embed-all" type="button" title="Embed tất cả nguồn chưa embed">⚡ Embed tất cả</button>
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

		<!-- ═══ TAB: Knowledge Characters ═══ -->
		<div class="maturity-tab-panel" id="panel-knowledge">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>🎭 Nhân vật Tri thức</h3>
				</div>
				<p class="card-desc">Các nhân vật AI mang kiến thức chuyên sâu</p>
				<div class="detail-loading">Đang tải...</div>
				<div class="detail-list" id="detail-knowledge"></div>
			</div>
		</div>

	</div>
</div>
