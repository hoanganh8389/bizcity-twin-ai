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
?>
<script>var bizcPageContext = 'memory';</script>
<div class="wrap bizcity-maturity-wrap">

	<!-- Header -->
	<div class="maturity-header">
		<div class="maturity-header__title">
			<h1>🧠 Memory Hub</h1>
			<div class="maturity-header__overall">
				<span class="overall-label"><?php esc_html_e( 'Long-term memory & auto-analysis', $td ); ?></span>
			</div>
		</div>
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
			<button class="stat-card" data-tab="memories" aria-selected="true">
				<span class="stat-icon">🧠</span>
				<span class="stat-value" id="stat-memories"><?php echo count( $_mems ); ?></span>
				<span class="stat-label">Long-term Memory</span>
				<span class="stat-desc" style="font-size:10px;color:#9ca3af;display:block;margin-top:2px">user_memory · pri <strong>99</strong></span>
			</button>
			<button class="stat-card" data-tab="episodic">
				<span class="stat-icon">📖</span>
				<span class="stat-value" id="stat-episodic"><?php echo $_ep_count; ?></span>
				<span class="stat-label">Episodic Memory</span>
				<span class="stat-desc" style="font-size:10px;color:#9ca3af;display:block;margin-top:2px">events · pri <strong>90</strong></span>
			</button>
			<button class="stat-card" data-tab="rolling">
				<span class="stat-icon">🔄</span>
				<span class="stat-value" id="stat-rolling"><?php echo $_rl_count; ?></span>
				<span class="stat-label">Rolling Memory</span>
				<span class="stat-desc" style="font-size:10px;color:#9ca3af;display:block;margin-top:2px">goals · pri <strong>90</strong></span>
			</button>
			<button class="stat-card" data-tab="notes">
				<span class="stat-icon">📝</span>
				<span class="stat-value" id="stat-notes"><?php echo $_nt_count; ?></span>
				<span class="stat-label">Research Notes</span>
				<span class="stat-desc" style="font-size:10px;color:#9ca3af;display:block;margin-top:2px">notes · pri <strong>92</strong></span>
			</button>
		</div>

		<!-- Context Pipeline Info Banner -->
		<details class="bizcity-pipeline-banner" style="margin-bottom:16px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;padding:0">
			<summary style="cursor:pointer;padding:10px 14px;font-size:13px;font-weight:600;color:#374151;user-select:none;list-style:none;display:flex;align-items:center;gap:6px">
				<span>🔍</span> Context Pipeline — Cách các vùng memory được đưa vào AI (<?php esc_html_e( 'click để xem', $td ); ?>)
			</summary>
			<div style="padding:12px 16px;font-size:12px;line-height:1.8;color:#4b5563;border-top:1px solid #e5e7eb">
				<p style="margin:0 0 8px;font-weight:600;color:#111827">Filter chain: <code>bizcity_chat_system_prompt</code></p>
				<table style="width:100%;border-collapse:collapse;font-size:12px">
					<thead>
						<tr style="background:#f3f4f6;text-align:left">
							<th style="padding:4px 8px;border:1px solid #e5e7eb">Pri</th>
							<th style="padding:4px 8px;border:1px solid #e5e7eb">Class</th>
							<th style="padding:4px 8px;border:1px solid #e5e7eb">Vùng</th>
							<th style="padding:4px 8px;border:1px solid #e5e7eb">Gated?</th>
							<th style="padding:4px 8px;border:1px solid #e5e7eb">Điều kiện inject</th>
						</tr>
					</thead>
					<tbody>
						<tr><td style="padding:4px 8px;border:1px solid #e5e7eb"><strong>1</strong></td><td style="padding:4px 8px;border:1px solid #e5e7eb">BizCity_Focus_Gate</td><td style="padding:4px 8px;border:1px solid #e5e7eb">Bật/tắt các vùng</td><td style="padding:4px 8px;border:1px solid #e5e7eb">–</td><td style="padding:4px 8px;border:1px solid #e5e7eb">Xác định mode: all / relevant / explicit</td></tr>
						<tr><td style="padding:4px 8px;border:1px solid #e5e7eb"><strong>15</strong></td><td style="padding:4px 8px;border:1px solid #e5e7eb">BizCity_Chat_Engine</td><td style="padding:4px 8px;border:1px solid #e5e7eb">Knowledge Sources (RAG)</td><td style="padding:4px 8px;border:1px solid #e5e7eb">✓ focus</td><td style="padding:4px 8px;border:1px solid #e5e7eb">Character knowledge, quick FAQ</td></tr>
						<tr><td style="padding:4px 8px;border:1px solid #e5e7eb"><strong>48</strong></td><td style="padding:4px 8px;border:1px solid #e5e7eb">BizCity_Response_Texture</td><td style="padding:4px 8px;border:1px solid #e5e7eb">Texture / tone layer</td><td style="padding:4px 8px;border:1px solid #e5e7eb">✓ focus</td><td style="padding:4px 8px;border:1px solid #e5e7eb">Tone / writing style rules</td></tr>
						<tr style="background:#fffbeb"><td style="padding:4px 8px;border:1px solid #e5e7eb"><strong>90</strong></td><td style="padding:4px 8px;border:1px solid #e5e7eb">BizCity_Context_Builder</td><td style="padding:4px 8px;border:1px solid #e5e7eb">🔄 Rolling + 📖 Episodic + Session + Cross + Project</td><td style="padding:4px 8px;border:1px solid #e5e7eb">Rolling/Episodic: ✗<br>Session/Cross/Project: ✓</td><td style="padding:4px 8px;border:1px solid #e5e7eb">Rolling &amp; Episodic luôn inject nếu có data. Session/Cross/Project gated theo focus mode.</td></tr>
						<tr style="background:#fffbeb"><td style="padding:4px 8px;border:1px solid #e5e7eb"><strong>92</strong></td><td style="padding:4px 8px;border:1px solid #e5e7eb">BCN_Research_Memory</td><td style="padding:4px 8px;border:1px solid #e5e7eb">📝 Research Notes</td><td style="padding:4px 8px;border:1px solid #e5e7eb">✗</td><td style="padding:4px 8px;border:1px solid #e5e7eb">Keyword-match tin nhắn hiện tại ↔ title/content/tags</td></tr>
						<tr><td style="padding:4px 8px;border:1px solid #e5e7eb"><strong>97</strong></td><td style="padding:4px 8px;border:1px solid #e5e7eb">BizCity_Companion_Context</td><td style="padding:4px 8px;border:1px solid #e5e7eb">Bond score + Emotional thread</td><td style="padding:4px 8px;border:1px solid #e5e7eb">✓ companion</td><td style="padding:4px 8px;border:1px solid #e5e7eb">BizCity_Emotional_Memory: bond, prefs, milestone, threads</td></tr>
						<tr style="background:#f0fdf4"><td style="padding:4px 8px;border:1px solid #e5e7eb"><strong>99</strong></td><td style="padding:4px 8px;border:1px solid #e5e7eb">BizCity_User_Memory</td><td style="padding:4px 8px;border:1px solid #e5e7eb">🧠 Long-term User Memory</td><td style="padding:4px 8px;border:1px solid #e5e7eb">✓ Focus Gate mode</td><td style="padding:4px 8px;border:1px solid #e5e7eb"><code>all</code>→limit 30 · <code>relevant</code>→keyword filter · <code>explicit</code>→user-set only</td></tr>
					</tbody>
				</table>
				<p style="margin:8px 0 0;color:#6b7280;font-size:11px">★ Priority cao hơn = inject sau = nằm gần user message hơn trong system prompt → được LLM chú ý hơn.</p>
			</div>
		</details>

		<!-- ═══ TAB: User Memory ═══ -->
		<div class="maturity-tab-panel active" id="panel-memories">
			<div class="maturity-card maturity-card--full">
				<div class="tab-header">
					<h3>🧠 Long-term User Memory <span style="font-size:11px;font-weight:400;color:#9ca3af;margin-left:6px">bizcity_memory_users · filter pri 99</span></h3>
					<div class="tab-actions">
						<button class="bk-btn-add" data-tab="memories">+ <?php esc_html_e( 'Add', $td ); ?></button>
						<button class="bk-btn-export" data-tab="memories" data-format="json">📤 JSON</button>
						<button class="bk-btn-export" data-tab="memories" data-format="csv">📤 CSV</button>
						<label class="bk-btn-import">📥 Import<input type="file" accept=".json,.csv" data-tab="memories" hidden></label>
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
					<h3>� Episodic Memory <span style="font-size:11px;font-weight:400;color:#9ca3af;margin-left:6px">bizcity_memory_episodic · filter pri 90</span></h3>
					<div class="tab-actions">
						<button class="bk-btn-add" data-tab="episodic">+ <?php esc_html_e( 'Add', $td ); ?></button>
						<button class="bk-btn-export" data-tab="episodic" data-format="json">📤 JSON</button>
						<button class="bk-btn-export" data-tab="episodic" data-format="csv">📤 CSV</button>
						<label class="bk-btn-import">📥 Import<input type="file" accept=".json,.csv" data-tab="episodic" hidden></label>
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
					<h3>🔄 Rolling Memory <span style="font-size:11px;font-weight:400;color:#9ca3af;margin-left:6px">bizcity_memory_rolling · filter pri 90</span></h3>
					<div class="tab-actions">
						<button class="bk-btn-add" data-tab="rolling">+ <?php esc_html_e( 'Add', $td ); ?></button>
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
					<h3>📝 Research Notes <span style="font-size:11px;font-weight:400;color:#9ca3af;margin-left:6px">memory_notes · filter pri 92</span></h3>
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
