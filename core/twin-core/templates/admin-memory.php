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
 * Admin Memory Page — Memory, Episodic, Rolling, Research (Notes)
 *
 * Inline editable tables with auto-save on blur.
 *
 * @package BizCity_Twin_Core
 */
defined( 'ABSPATH' ) or die();
$td = 'bizcity-twin-ai';

// Pre-load User Memory so the table is available immediately (no AJAX timing gap)
global $wpdb;
$_uid    = get_current_user_id();
$_um_tbl = $wpdb->prefix . 'bizcity_memory_users';
$_mems   = [];
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$_um_tbl}'" ) === $_um_tbl ) {
	$_mems = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, memory_type, memory_key, memory_text AS content,
		        score AS importance, times_seen, updated_at
		 FROM {$_um_tbl} WHERE user_id = %d
		 ORDER BY updated_at DESC LIMIT 200",
		$_uid
	), ARRAY_A ) ?: [];
}
$_mem_types = [ 'fact', 'preference', 'identity', 'goal', 'pain', 'constraint', 'habit', 'relationship', 'request' ];

// Pre-load counts for Episodic, Rolling, and Research zones
$_ep_tbl   = $wpdb->prefix . 'bizcity_memory_episodic';
$_rl_tbl   = $wpdb->prefix . 'bizcity_memory_rolling';
$_nt_tbl   = $wpdb->prefix . 'bizcity_memory_notes';
$_ep_count = 0;
$_rl_count = 0;
$_nt_count = 0;

if ( $wpdb->get_var( "SHOW TABLES LIKE '{$_ep_tbl}'" ) === $_ep_tbl ) {
	$_ep_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$_ep_tbl} WHERE user_id = %d", $_uid ) );
}
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$_rl_tbl}'" ) === $_rl_tbl ) {
	$_rl_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$_rl_tbl} WHERE user_id = %d", $_uid ) );
}
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$_nt_tbl}'" ) === $_nt_tbl ) {
	$_nt_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$_nt_tbl} WHERE user_id = %d", $_uid ) );
}

// Pre-load Quick FAQ (stored in knowledge_sources with source_type=quick_faq)
$_faq_tbl = $wpdb->prefix . 'bizcity_knowledge_sources';
$_faqs    = [];
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$_faq_tbl}'" ) === $_faq_tbl ) {
	$_faq_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, source_name AS title, content, status, updated_at
		 FROM {$_faq_tbl} WHERE user_id = %d AND source_type = 'quick_faq'
		 ORDER BY updated_at DESC LIMIT 200",
		$_uid
	), ARRAY_A ) ?: [];
	foreach ( $_faq_rows as $_row ) {
		$_json            = json_decode( $_row['content'] ?? '', true );
		$_row['question'] = is_array( $_json ) ? ( $_json['question'] ?? $_json['title'] ?? '' ) : ( $_row['title'] ?? '' );
		$_row['answer']   = is_array( $_json ) ? ( $_json['answer'] ?? $_json['content'] ?? '' ) : ( $_row['content'] ?? '' );
		$_faqs[]          = $_row;
	}
}
?>
<script>var bizcPageContext = 'memory';</script>

<style>
/* ─── Memory Hub — minimal TwinChat-aligned overrides (scoped) ─── */
.bizcity-mh {
	max-width: 1200px;
	margin: 0 auto;
	padding: 16px 20px 32px;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Inter", sans-serif;
	color: #1f2937;
}
.bizcity-mh .maturity-loading { padding: 40px 20px; }
.bizcity-mh .maturity-loading__spinner {
	border: 3px solid #f3f4f6; border-top-color: #111827;
	width: 28px; height: 28px;
}

/* Compact title row replacing maturity-header */
.bizcity-mh__head {
	display: flex; align-items: center; justify-content: space-between;
	gap: 12px; margin: 4px 0 18px;
}
.bizcity-mh__head h1 {
	margin: 0; font-size: 18px; font-weight: 600; color: #111827;
	letter-spacing: -0.01em;
}
.bizcity-mh__head .bizcity-mh__sub {
	font-size: 12px; color: #6b7280; font-weight: 400;
}
.bizcity-mh__head .bizcity-mh__pipeline-toggle {
	font-size: 12px; color: #6b7280; background: transparent;
	border: 1px solid #e5e7eb; border-radius: 6px; padding: 5px 10px;
	cursor: pointer; transition: all .15s;
}
.bizcity-mh__head .bizcity-mh__pipeline-toggle:hover { background: #f9fafb; color: #111827; }

/* Stat cards — neutral, no gradient, no border accent on selected */
.bizcity-mh .maturity-stats {
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 8px; margin-bottom: 18px;
}
.bizcity-mh .stat-card {
	padding: 12px 14px;
	background: #fff;
	border: 1px solid #e5e7eb;
	border-radius: 10px;
	box-shadow: none;
	flex-direction: row;
	align-items: center;
	gap: 10px;
	text-align: left;
}
.bizcity-mh .stat-card:hover {
	transform: none;
	box-shadow: none;
	border-color: #d1d5db;
	background: #fafafa;
}
.bizcity-mh .stat-card[aria-selected="true"] {
	border-color: #111827;
	background: #fff;
	box-shadow: none;
}
.bizcity-mh .stat-icon {
	font-size: 18px; margin: 0;
	width: 32px; height: 32px;
	display: inline-flex; align-items: center; justify-content: center;
	background: #f3f4f6; border-radius: 8px;
	flex-shrink: 0;
}
.bizcity-mh .stat-card[aria-selected="true"] .stat-icon {
	background: #111827; color: #fff;
}
.bizcity-mh .stat-card > span:nth-child(2),
.bizcity-mh .stat-card > span:nth-child(3),
.bizcity-mh .stat-card > span:nth-child(4) {
	display: block;
	text-align: left;
}
.bizcity-mh .stat-value {
	font-size: 18px; font-weight: 600; color: #111827; line-height: 1.1;
}
.bizcity-mh .stat-label {
	font-size: 11px; color: #6b7280; text-transform: none;
	letter-spacing: 0; font-weight: 500;
}
.bizcity-mh .stat-desc {
	font-size: 10px !important; color: #9ca3af !important;
	margin-top: 1px !important;
}
/* Use a flex column inside the card for label+value+desc */
.bizcity-mh .stat-card {
	display: flex;
}
.bizcity-mh .stat-card > .stat-icon { order: 0; }
.bizcity-mh .stat-card .stat-meta {
	display: flex; flex-direction: column; flex: 1; min-width: 0;
}

/* Cards */
.bizcity-mh .maturity-card {
	border: 1px solid #e5e7eb;
	border-radius: 10px;
	box-shadow: none;
	padding: 16px 18px;
}
.bizcity-mh .maturity-card h3 {
	font-size: 14px; font-weight: 600; color: #111827;
	margin-bottom: 12px;
}
.bizcity-mh .maturity-card h3 span { font-weight: 400; }
.bizcity-mh .card-desc {
	font-size: 12px; color: #6b7280; margin-bottom: 12px;
}

/* Tab header buttons — quieter */
.bizcity-mh .tab-header { gap: 8px; }
.bizcity-mh .tab-header .tab-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.bizcity-mh .bk-btn-add,
.bizcity-mh .bk-btn-export,
.bizcity-mh .bk-btn-import {
	font-size: 12px; padding: 5px 10px;
	background: #fff; color: #374151;
	border: 1px solid #e5e7eb; border-radius: 6px;
	cursor: pointer; transition: all .15s;
	display: inline-flex; align-items: center; gap: 4px;
}
.bizcity-mh .bk-btn-add:hover,
.bizcity-mh .bk-btn-export:hover,
.bizcity-mh .bk-btn-import:hover {
	background: #f9fafb; border-color: #d1d5db; color: #111827;
}
.bizcity-mh .bk-btn-add { background: #111827; color: #fff; border-color: #111827; }
.bizcity-mh .bk-btn-add:hover { background: #1f2937; color: #fff; border-color: #1f2937; }

/* Tables */
.bizcity-mh .bk-editable-table {
	font-size: 12px; border-collapse: collapse; width: 100%;
}
.bizcity-mh .bk-editable-table thead th {
	font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em;
	font-weight: 600; color: #9ca3af;
	background: #fafafa; border-bottom: 1px solid #e5e7eb;
	padding: 8px 10px; text-align: left;
}
.bizcity-mh .bk-editable-table tbody td {
	padding: 8px 10px; border-bottom: 1px solid #f3f4f6;
	color: #374151;
}
.bizcity-mh .bk-editable-table tbody tr:hover { background: #fafafa; }
.bizcity-mh .bk-editable[contenteditable="true"]:focus {
	outline: 2px solid #111827; outline-offset: -2px; border-radius: 3px;
	background: #fff;
}
.bizcity-mh .badge {
	font-size: 10px; padding: 2px 8px; border-radius: 999px;
	background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb;
}
.bizcity-mh .bk-row-delete {
	background: transparent; border: none; cursor: pointer;
	color: #9ca3af; font-size: 14px; padding: 2px 6px; border-radius: 4px;
}
.bizcity-mh .bk-row-delete:hover { color: #dc2626; background: #fef2f2; }
.bizcity-mh .bk-table-footer {
	padding: 10px 0 2px; font-size: 11px; color: #9ca3af;
}

/* Pipeline panel — collapsed by default, plain */
.bizcity-mh__pipeline {
	margin: 0 0 18px;
	border: 1px solid #e5e7eb; border-radius: 8px;
	background: #fafafa; font-size: 12px;
}
.bizcity-mh__pipeline summary {
	list-style: none; cursor: pointer;
	padding: 10px 14px; font-weight: 500; color: #374151;
}
.bizcity-mh__pipeline summary::-webkit-details-marker { display: none; }
.bizcity-mh__pipeline[open] summary { border-bottom: 1px solid #e5e7eb; }
.bizcity-mh__pipeline-body { padding: 12px 14px; color: #4b5563; line-height: 1.7; }
.bizcity-mh__pipeline table { width: 100%; border-collapse: collapse; font-size: 11px; }
.bizcity-mh__pipeline th, .bizcity-mh__pipeline td {
	padding: 5px 8px; border: 1px solid #e5e7eb; text-align: left;
}
.bizcity-mh__pipeline th { background: #f3f4f6; font-weight: 600; color: #374151; }

@media (max-width: 720px) {
	.bizcity-mh { padding: 12px; }
	.bizcity-mh .maturity-stats { grid-template-columns: 1fr 1fr; }
}
</style>

<div class="wrap bizcity-maturity-wrap bizcity-mh">

	<!-- Compact head replaces maturity-header -->
	<div class="bizcity-mh__head">
		<div>
			<h1>Memory Hub</h1>
			<div class="bizcity-mh__sub">Quick FAQ · Long-term · Episodic · Rolling · Research notes</div>
		</div>
		<button type="button" class="bizcity-mh__pipeline-toggle"
			onclick="var p=document.getElementById('bizcity-mh-pipeline'); if(p){p.open=!p.open;}">
			Context pipeline
		</button>
	</div>

	<!-- Loading -->
	<div class="maturity-loading" id="maturity-loading">
		<div class="maturity-loading__spinner"></div>
		<p><?php esc_html_e( 'Loading data...', $td ); ?></p>
	</div>

	<!-- Content -->
	<div class="maturity-content" id="maturity-content" style="display:none">

		<!-- Stats Row -->
		<div class="maturity-stats">
			<button class="stat-card" data-tab="quickfaq" aria-selected="true">
				<span class="stat-icon">❓</span>
				<span class="stat-meta">
					<span class="stat-value" id="stat-quickfaq"><?php echo count( $_faqs ); ?></span>
					<span class="stat-label">Quick FAQ</span>
					<span class="stat-desc">knowledge_sources · pri 15</span>
				</span>
			</button>
			<button class="stat-card" data-tab="memories">
				<span class="stat-icon">🧠</span>
				<span class="stat-meta">
					<span class="stat-value" id="stat-memories"><?php echo count( $_mems ); ?></span>
					<span class="stat-label">Long-term</span>
					<span class="stat-desc">user_memory · pri 99</span>
				</span>
			</button>
			<button class="stat-card" data-tab="episodic">
				<span class="stat-icon">📖</span>
				<span class="stat-meta">
					<span class="stat-value" id="stat-episodic"><?php echo $_ep_count; ?></span>
					<span class="stat-label">Episodic</span>
					<span class="stat-desc">events · pri 90</span>
				</span>
			</button>
			<button class="stat-card" data-tab="rolling">
				<span class="stat-icon">🔄</span>
				<span class="stat-meta">
					<span class="stat-value" id="stat-rolling"><?php echo $_rl_count; ?></span>
					<span class="stat-label">Rolling</span>
					<span class="stat-desc">goals · pri 90</span>
				</span>
			</button>
			<button class="stat-card" data-tab="notes">
				<span class="stat-icon">📝</span>
				<span class="stat-meta">
					<span class="stat-value" id="stat-notes"><?php echo $_nt_count; ?></span>
					<span class="stat-label">Research</span>
					<span class="stat-desc">notes · pri 92</span>
				</span>
			</button>
		</div>

		<!-- Context Pipeline Info — collapsed by default, plain styling -->
		<details class="bizcity-mh__pipeline" id="bizcity-mh-pipeline">
			<summary>Context pipeline — cách các vùng memory được đưa vào AI</summary>
			<div class="bizcity-mh__pipeline-body">
				<p style="margin:0 0 8px"><strong>Filter chain:</strong> <code>bizcity_chat_system_prompt</code></p>
				<table>
					<thead>
						<tr><th>Pri</th><th>Vùng</th><th>Gated?</th><th>Điều kiện inject</th></tr>
					</thead>
					<tbody>
						<tr><td>15</td><td>Quick FAQ / Knowledge Sources (RAG)</td><td>✓ focus</td><td>Character knowledge, quick FAQ</td></tr>
						<tr><td>48</td><td>Texture / tone</td><td>✓ focus</td><td>Tone / writing style rules</td></tr>
						<tr><td>90</td><td>Rolling + Episodic + Session/Cross/Project</td><td>R/E: ✗ · S/C/P: ✓</td><td>Rolling &amp; Episodic luôn inject nếu có data</td></tr>
						<tr><td>92</td><td>Research Notes</td><td>✗</td><td>Keyword-match tin nhắn ↔ title/content/tags</td></tr>
						<tr><td>97</td><td>Companion (bond + emotional)</td><td>✓ companion</td><td>Bond, prefs, milestone, threads</td></tr>
						<tr><td>99</td><td>Long-term User Memory</td><td>✓ Focus Gate</td><td><code>all</code>→30 · <code>relevant</code>→keyword · <code>explicit</code>→user-set</td></tr>
					</tbody>
				</table>
				<p style="margin:8px 0 0;color:#9ca3af;font-size:11px">★ Priority cao hơn = inject sau = nằm gần user message hơn → LLM chú ý hơn.</p>
			</div>
		</details>

		<!-- ═══ TAB: Quick FAQ ═══ -->
		<div class="maturity-tab-panel active" id="panel-quickfaq">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>Quick FAQ <span style="font-size:11px;color:#9ca3af;margin-left:6px">knowledge_sources · pri 15</span></h3>
					<div class="tab-actions">
						<button class="bk-btn-add" data-tab="quickfaq">+ Thêm</button>
						<button class="bk-btn-export" data-tab="quickfaq" data-format="json">JSON</button>
						<button class="bk-btn-export" data-tab="quickfaq" data-format="csv">CSV</button>
						<label class="bk-btn-import">Import<input type="file" accept=".json,.csv" data-tab="quickfaq" hidden></label>
					</div>
				</div>
				<p class="card-desc">Câu hỏi &amp; trả lời ngắn gọn — Twin AI ưu tiên match nhanh các câu hỏi thường gặp. Inject vào RAG ở priority 15 (early), gated theo Focus Gate.</p>
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
									<td><span class="badge"><?php echo esc_html( $_faq['status'] ); ?></span></td>
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

		<!-- ═══ TAB: User Memory ═══ -->
		<div class="maturity-tab-panel" id="panel-memories">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>Long-term User Memory <span style="font-size:11px;font-weight:400;color:#9ca3af;margin-left:6px">bizcity_memory_users · pri 99</span></h3>
					<div class="tab-actions">
						<button class="bk-btn-add" data-tab="memories">+ Thêm</button>
						<button class="bk-btn-export" data-tab="memories" data-format="json">JSON</button>
						<button class="bk-btn-export" data-tab="memories" data-format="csv">CSV</button>
						<label class="bk-btn-import">Import<input type="file" accept=".json,.csv" data-tab="memories" hidden></label>
					</div>
				</div>
				<p class="card-desc">Ký ức dài hạn về user — tích lũy qua tất cả các phiên chat. Được inject vào system prompt ở priority <strong>99</strong> (vị trí cao nhất, gần với lệnh user nhất). Focus Gate quyết định mode: <code>all</code> (tối đa 30), <code>relevant</code> (keyword-match), hoặc <code>explicit</code> (chỉ user tự dặn). Gồm 2 tầng: <em>explicit</em> (user chủ động dặn AI) và <em>extracted</em> (AI tự rút ra từ hội thoại).</p>
				<!-- Pre-rendered by PHP — no AJAX needed on first load -->
				<div class="detail-list" id="detail-memories" data-preloaded="1">
					<table class="bk-editable-table" data-tab="memories">
						<thead>
							<tr>
								<th class="bk-col-num">#</th>
								<th>Loại</th>
								<th class="bk-col-wide">Nội dung</th>
								<th>Điểm</th>
								<th>Key</th>
								<th>Lần</th>
								<th>Cập nhật</th>
								<th class="bk-col-action"></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $_mems ) ) : ?>
								<tr class="bk-empty-row">
									<td colspan="8" style="text-align:center;color:#9ca3af;padding:24px 0">Chưa có dữ liệu. Nhấn <strong>+ Thêm</strong> để tạo.</td>
								</tr>
							<?php else : foreach ( $_mems as $i => $_mem ) : ?>
								<tr class="bk-editable-row" data-id="<?php echo (int) $_mem['id']; ?>">
									<td class="bk-row-number"><?php echo $i + 1; ?></td>
									<td>
										<select class="bk-cell-select" data-field="memory_type">
											<?php foreach ( $_mem_types as $_mt ) : ?>
												<option value="<?php echo esc_attr( $_mt ); ?>"<?php selected( $_mem['memory_type'], $_mt ); ?>><?php echo esc_html( $_mt ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
									<td contenteditable="true" class="bk-editable bk-col-wide" data-field="content"><?php echo esc_html( $_mem['content'] ); ?></td>
									<td contenteditable="true" class="bk-editable" data-field="importance"><?php echo esc_html( $_mem['importance'] ); ?></td>
									<td class="td-key"><?php echo esc_html( $_mem['memory_key'] ); ?></td>
									<td><?php echo esc_html( $_mem['times_seen'] ); ?></td>
									<td><?php echo esc_html( substr( $_mem['updated_at'] ?? '', 0, 16 ) ); ?></td>
									<td><button class="bk-row-delete" onclick="window._matDelete('memories',<?php echo (int) $_mem['id']; ?>)" title="Xoá">🗑️</button></td>
								</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
					<div class="bk-table-footer">
						<span class="bk-row-count">Tổng: <strong><?php echo count( $_mems ); ?></strong></span>
					</div>
				</div>
				<div class="maturity-row" style="margin-top:16px">
					<div class="maturity-card maturity-card--full" style="padding:12px">
						<div class="chart-container" style="height:180px"><canvas id="chart-memory-types"></canvas></div>
					</div>
				</div>
			</div>
		</div>

		<!-- ═══ TAB: Episodic Memory ═══ -->
		<div class="maturity-tab-panel" id="panel-episodic">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>Episodic Memory <span style="font-size:11px;font-weight:400;color:#9ca3af;margin-left:6px">bizcity_memory_episodic · pri 90</span></h3>
					<div class="tab-actions">
						<button class="bk-btn-add" data-tab="episodic">+ Thêm</button>
						<button class="bk-btn-export" data-tab="episodic" data-format="json">JSON</button>
						<button class="bk-btn-export" data-tab="episodic" data-format="csv">CSV</button>
						<label class="bk-btn-import">Import<input type="file" accept=".json,.csv" data-tab="episodic" hidden></label>
					</div>
				</div>
				<p class="card-desc">Ghi nhớ các <em>sự kiện đáng chú ý</em> từ lịch sử hội thoại: mục tiêu thành công/huỷ, điểm đau, sự hài lòng, thói quen dùng tool, quyết định quan trọng. Inject qua <strong>BizCity_Context_Builder</strong> ở priority 90, không bị Focus Gate chặn — luôn xuất hiện nếu có data. Ưu tiên inject các event liên quan đến goal hiện tại trước.</p>
				<div class="detail-loading"><?php esc_html_e( 'Loading...', $td ); ?></div>
				<div class="detail-list" id="detail-episodic"></div>
				<div class="maturity-row" style="margin-top:16px">
					<div class="maturity-card maturity-card--full" style="padding:12px">
						<div class="chart-container" style="height:180px"><canvas id="chart-episodic-types"></canvas></div>
					</div>
				</div>
			</div>
		</div>

		<!-- ═══ TAB: Rolling Memory ═══ -->
		<div class="maturity-tab-panel" id="panel-rolling">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>Rolling Memory <span style="font-size:11px;font-weight:400;color:#9ca3af;margin-left:6px">bizcity_memory_rolling · pri 90</span></h3>
					<div class="tab-actions">
						<button class="bk-btn-add" data-tab="rolling">+ Thêm</button>
					</div>
				</div>
				<p class="card-desc">Theo dõi <em>mục tiêu đang chạy</em> (active goals) và tóm tắt các mục tiêu vừa hoàn thành (15 phút gần nhất). Inject qua <strong>BizCity_Context_Builder</strong> ở priority 90, không bị Focus Gate chặn. Được hiển thị là <code>🔄 ROLLING MEMORY</code> trong system prompt. Tự động tóm tắt mỗi N lượt hội thoại — bạn có thể chỉnh sửa hoặc xoá thủ công.</p>
				<div class="detail-loading"><?php esc_html_e( 'Loading...', $td ); ?></div>
				<div class="detail-list" id="detail-rolling"></div>
			</div>
		</div>

		<!-- ═══ TAB: Notes / Research ═══ -->
		<div class="maturity-tab-panel" id="panel-notes">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>Research Notes <span style="font-size:11px;font-weight:400;color:#9ca3af;margin-left:6px">memory_notes · pri 92</span></h3>
					<div class="tab-actions">
						<button class="bk-btn-add" data-tab="notes">+ <?php esc_html_e( 'Add', $td ); ?></button>
					</div>
				</div>
				<p class="card-desc">Ghi chú nghiên cứu tích lũy từ các phiên chat — bao gồm <code>research_auto</code> (AI tự tóm tắt), <code>chat_pinned</code> (user ghim), và <code>manual</code> (tạo tay). Inject qua <strong>BCN_Research_Memory</strong> ở priority 92 (sau rolling/episodic, trước companion). Không bị Focus Gate chặn nhưng chỉ inject khi có <em>keyword match</em> giữa nội dung note và tin nhắn hiện tại của user (title, content, tags).</p>
				<div class="detail-loading"><?php esc_html_e( 'Loading...', $td ); ?></div>
				<div class="detail-list" id="detail-notes"></div>
				<div class="maturity-row" style="margin-top:16px">
					<div class="maturity-card maturity-card--full" style="padding:12px">
						<div class="chart-container" style="height:180px"><canvas id="chart-note-types"></canvas></div>
					</div>
				</div>
			</div>
		</div>

	</div>
</div>
