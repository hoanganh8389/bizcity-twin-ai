<?php
/**
 * BizCity Zalo Bot — Frontend Profile View (multi-tab full-fidelity)
 *
 * Mirrors the entire admin menu (class-admin-menu.php) on the public route
 * `/tool-zalo-bizcity/?tab=...` so users không phải vào wp-admin để cấu hình.
 *
 * Variables in scope (set by BizCity_Tool_Zalo_Page::render()):
 *   $tab              — bots | listener | testapi | connections | logs | hotline
 *   $saved            — bool (just saved?)
 *   $hotline_account  — array of decrypted zalo_hotline row 0
 *   $hotline_settings — array of field defs from WaicChannelIntegration_zalo_hotline::getSettings()
 *   $nonce_field      — pre-rendered nonce <input> (for hotline tab)
 *   $post_url         — admin-post.php URL
 *   $waic_dialog      — link back to WAIC integrations dialog
 *   $admin_menu       — BizCity_Zalo_Bot_Admin_Menu instance | null
 *   $user_id          — current WP user id
 *
 * @package BizCity\ZaloBot
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$base_url = home_url( '/' . BizCity_Tool_Zalo_Page::SLUG . '/' );

$tabs = array(
	'bots'        => array( '🤖 Bots OA',          'render_page' ),
	'listener'    => array( '📡 Webhook Listener', 'render_listener_page' ),
	'testapi'     => array( '🧪 Test API',         'render_test_api_page' ),
	'connections' => array( '🔗 Kết nối Zalo',     'render_connections_page' ),
	'logs'        => array( '📜 Nhật ký',          'render_logs_page' ),
	'hotline'     => array( '📞 Hotline (ZNS)',    null ),
	// [2026-07-08 Johnny Chu] HOTFIX — firewall/blocking guide tab
	'firewall'    => array( '🛡️ Bị chặn?',         null ),
);
?>
<style>
.bzz-wrap { max-width: 1200px; margin: 24px auto; padding: 0 16px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
.bzz-header { display:flex; align-items:center; gap:12px; margin-bottom:8px; }
.bzz-header h1 { margin:0; font-size:22px; color:#0f172a; }
.bzz-sub { color:#64748b; font-size:14px; margin:0 0 18px; }
.bzz-tabs { display:flex; gap:4px; flex-wrap:wrap; border-bottom:2px solid #0084ff; margin-bottom:20px; }
.bzz-tab { padding:10px 18px; cursor:pointer; text-decoration:none; background:#f1f5f9; color:#475569; font-weight:600; border-radius:8px 8px 0 0; font-size:14px; }
.bzz-tab.active { background:#0084ff; color:#fff; }
.bzz-saved { background:#dcfce7; border-left:4px solid #16a34a; padding:12px 16px; border-radius:6px; margin-bottom:16px; color:#15803d; }
.bzz-status-warn { background:#fef3c7; border-left:4px solid #f59e0b; padding:12px 16px; border-radius:6px; color:#92400e; margin:12px 0; }
.bzz-card { background:#fff; border-radius:12px; padding:24px; margin-bottom:16px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
.bzz-card h2 { margin-top:0; font-size:18px; color:#0f172a; }
.bzz-row { margin-bottom:16px; }
.bzz-label { display:block; font-weight:600; font-size:13px; color:#334155; margin-bottom:6px; }
.bzz-input { width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; box-sizing:border-box; background:#fff; }
.bzz-desc { color:#64748b; font-size:12px; margin-top:4px; }
.bzz-btn { display:inline-block; padding:10px 20px; border-radius:8px; font-weight:600; cursor:pointer; border:none; font-size:14px; text-decoration:none; }
.bzz-btn-primary { background:#0084ff; color:#fff; }
.bzz-btn-link { background:transparent; color:#0084ff; }
/* Soften wp-admin chrome inside frontend wrapper */
.bzz-admin-embed { width:100%; max-width:100%; overflow-x:auto; }
.bzz-admin-embed .wrap { margin:0; max-width:none; padding:0; }
.bzz-admin-embed h1 { font-size:20px; }

/* ── Reset wp-admin .wp-list-table khi render ngoài wp-admin ──
   Theme frontend KHÔNG có wp-admin CSS nên `.fixed` (table-layout) +
   width inline `width:280px;` ở <th> bị các theme khác override sai
   khiến bảng phình ~3000px và bị position:fixed (do theme rule
   `.widefat`). Force layout lại để bảng vừa wrapper. */
.bzz-admin-embed table.wp-list-table,
.bzz-admin-embed table.widefat {
	position: static !important;
	float: none !important;
	width: 100% !important;
	max-width: 100% !important;
	table-layout: auto !important;
	border-collapse: collapse;
	background: #fff;
}
.bzz-admin-embed table.wp-list-table th,
.bzz-admin-embed table.wp-list-table td,
.bzz-admin-embed table.widefat th,
.bzz-admin-embed table.widefat td {
	padding: 10px 12px;
	border-bottom: 1px solid #e2e8f0;
	background: transparent !important;
	color: #0f172a;
	text-align: left;
	vertical-align: middle;
}
.bzz-admin-embed table.wp-list-table thead th,
.bzz-admin-embed table.widefat thead th {
	background: #f8fafc !important;
	font-weight: 600;
	font-size: 13px;
	color: #475569;
}
.bzz-admin-embed input[type="text"],
.bzz-admin-embed input[type="url"],
.bzz-admin-embed input[type="number"],
.bzz-admin-embed select,
.bzz-admin-embed textarea {
	padding: 8px 12px;
	border: 1px solid #cbd5e1;
	border-radius: 6px;
	font-size: 14px;
	background: #fff;
	box-sizing: border-box;
}
.bzz-admin-embed .button,
.bzz-admin-embed .page-title-action {
	display: inline-block;
	padding: 8px 14px;
	background: #f1f5f9;
	border: 1px solid #cbd5e1;
	border-radius: 6px;
	color: #0f172a;
	text-decoration: none;
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
	line-height: 1.2;
}
.bzz-admin-embed .button-primary {
	background: #0084ff !important;
	border-color: #0084ff !important;
	color: #fff !important;
}
.bzz-admin-embed .form-table th { width: 200px; }
.bzz-admin-embed .description { color: #64748b; font-size: 12px; }
.bzz-admin-embed .notice { padding: 12px 16px; border-radius: 6px; background: #fef3c7; border-left: 4px solid #f59e0b; margin: 12px 0; }
.bzz-admin-embed .dashicons { vertical-align: middle; }

/* ── Firewall tab ── */
.bzz-fw-section { margin-bottom:28px; }
.bzz-fw-section h3 { font-size:16px; color:#0f172a; margin:0 0 8px; display:flex; align-items:center; gap:8px; }
.bzz-fw-badge { display:inline-block; padding:2px 10px; border-radius:99px; font-size:12px; font-weight:700; }
.bzz-fw-badge-warn { background:#fef3c7; color:#92400e; }
.bzz-fw-badge-ok   { background:#dcfce7; color:#15803d; }
.bzz-fw-steps { margin:12px 0 0 0; padding-left:0; list-style:none; }
.bzz-fw-steps li { padding:10px 14px; background:#f8fafc; border-left:3px solid #cbd5e1; border-radius:0 6px 6px 0; margin-bottom:8px; font-size:13px; line-height:1.6; }
.bzz-fw-steps li strong { color:#0f172a; }
.bzz-fw-steps code { background:#e2e8f0; padding:1px 6px; border-radius:4px; font-size:12px; }
.bzz-fw-cf-img { max-width:100%; border-radius:8px; border:1px solid #e2e8f0; margin-top:12px; }
.bzz-fw-tip { background:#eff6ff; border-left:4px solid #3b82f6; padding:12px 16px; border-radius:0 8px 8px 0; font-size:13px; margin-top:12px; color:#1e3a5f; }
.bzz-fw-danger { background:#fef2f2; border-left:4px solid #ef4444; padding:12px 16px; border-radius:0 8px 8px 0; font-size:13px; margin-top:12px; color:#7f1d1d; }
</style>

<div class="bzz-wrap">

	<div class="bzz-header">
		<h1>💬 Zalo Bizcity Studio</h1>
	</div>
	<p class="bzz-sub">Cấu hình Zalo Bot OA + Hotline ZNS — đầy đủ tính năng như trong wp-admin.</p>

	<?php if ( $saved ) : ?>
		<div class="bzz-saved">✅ Đã lưu cấu hình. Hệ thống vừa chạy <code>doTest()</code> để xác minh.</div>
	<?php endif; ?>

	<div class="bzz-tabs">
		<?php foreach ( $tabs as $key => $info ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', $key, $base_url ) ); ?>"
			   class="bzz-tab <?php echo $tab === $key ? 'active' : ''; ?>">
				<?php echo esc_html( $info[0] ); ?>
			</a>
		<?php endforeach; ?>
	</div>

	<?php if ( $tab === 'firewall' ) : ?>

		<div class="bzz-card">
			<h2>🛡️ Hướng dẫn xử lý khi webhook Zalo bị chặn</h2>
			<p style="color:#64748b;font-size:14px;margin-top:0">Webhook Zalo Bot gửi <code>POST /zalohook/</code> từ server Zalo về site bạn. Nếu request bị chặn ở tầng CDN, firewall hoặc security plugin, bot sẽ không nhận được tin nhắn dù Ping / setWebhook vẫn OK.</p>

			<!-- ============ Cloudflare ============ -->
			<div class="bzz-fw-section">
				<h3>☁️ Cloudflare WAF <span class="bzz-fw-badge bzz-fw-badge-warn">Thường gặp nhất</span></h3>
				<p style="font-size:13px;color:#475569">Cloudflare kích hoạt <strong>Browser Integrity Check</strong> mặc định. Request từ Zalo server không có browser fingerprint nên bị block 403/1010 âm thầm.</p>
				<ul class="bzz-fw-steps">
					<li>1️⃣ Vào <strong>Cloudflare Dashboard</strong> → chọn domain → <strong>Security → WAF → Custom rules</strong></li>
					<li>2️⃣ Nhấn <strong>Create rule</strong> (hoặc Edit nếu đã có rule):<br>
						<code>Rule name</code>: <strong>Allow Zalo Webhook</strong></li>
					<li>3️⃣ Cấu hình điều kiện:<br>
						<code>Field</code>: <strong>URI Path</strong> &nbsp;|&nbsp;
						<code>Operator</code>: <strong>contains</strong> &nbsp;|&nbsp;
						<code>Value</code>: <strong>zalo</strong></li>
					<li>4️⃣ <code>Then take action</code>: chọn <strong>Skip</strong></li>
					<li>5️⃣ Trong danh sách "<em>WAF components to skip</em>" → mở "<em>More components to skip</em>" → tick ☑ <strong>Browser Integrity Check</strong></li>
					<li>6️⃣ <em>(Tuỳ chọn nâng cao)</em> Nếu vẫn bị block: tick thêm <strong>All managed rules</strong> để tắt toàn bộ managed rules cho path <code>/zalohook/</code></li>
					<li>7️⃣ Nhấn <strong>Deploy</strong> → chờ ~30 giây → test lại bằng tab <strong>Test API</strong></li>
				</ul>
				<div class="bzz-fw-tip">💡 <strong>Mẹo:</strong> Nếu site dùng nhiều webhook (Facebook, Telegram, …) hãy đặt value là <code>/zalohook</code> (full path) thay vì <code>zalo</code> để rule chính xác hơn.</div>
				<div class="bzz-fw-tip">🔍 <strong>Cách kiểm tra nhanh:</strong> Tắt Cloudflare proxy (chuyển cloud sang DNS only ⚪) → test webhook → nếu bot nhận tin thì đúng là Cloudflare chặn → bật lại proxy và cấu hình rule như trên.</div>
			</div>

			<!-- ============ Rate Limiting ============ -->
			<div class="bzz-fw-section">
				<h3>⏱️ Cloudflare Rate Limiting</h3>
				<p style="font-size:13px;color:#475569">Khi Zalo server gửi nhiều sự kiện liên tiếp (broadcast, nhóm đông), rate limit có thể kick-in.</p>
				<ul class="bzz-fw-steps">
					<li>Vào <strong>Security → WAF → Rate limiting rules</strong> → kiểm tra rule nào đang áp dụng cho <code>/zalohook/</code></li>
					<li>Tạo exception hoặc tăng threshold riêng cho path <code>/zalohook/</code></li>
				</ul>
			</div>

			<!-- ============ Hosting / Server Firewall ============ -->
			<div class="bzz-fw-section">
				<h3>🖥️ Firewall server / Hosting</h3>
				<ul class="bzz-fw-steps">
					<li><strong>ModSecurity (cPanel/Plesk):</strong> Vào cPanel → <em>ModSecurity</em> → <em>Disable</em> tạm thời → test → nếu OK thì thêm whitelist rule cho User-Agent của Zalo (<code>ZaloBot</code>) hoặc IP range của Zalo</li>
					<li><strong>ConfigServer Firewall (CSF):</strong> Thêm IP server Zalo vào <code>/etc/csf/csf.allow</code>. IP Zalo thay đổi theo thời gian — cách bền hơn là whitelist User-Agent ở tầng Nginx/Apache</li>
					<li><strong>Fail2ban:</strong> Kiểm tra <code>/var/log/fail2ban.log</code> xem IP Zalo có bị ban không (<code>grep zalohook /var/log/nginx/access.log</code>)</li>
				</ul>
			</div>

			<!-- ============ WordPress Security Plugins ============ -->
			<div class="bzz-fw-section">
				<h3>🔌 Security plugin WordPress</h3>
				<ul class="bzz-fw-steps">
					<li><strong>Wordfence:</strong> Wordfence → <em>Firewall → Brute Force Protection</em> → whitelist URL <code>/zalohook/</code> trong mục "Allowlisted URLs". Nếu dùng Learning Mode hãy gửi thử ít nhất 1 request thật để Wordfence học pattern.</li>
					<li><strong>iThemes Security / Solid Security:</strong> Security → <em>Network Brute Force / Local Brute Force</em> → thêm <code>/zalohook/</code> vào danh sách ngoại lệ</li>
					<li><strong>All-In-One Security (AIOS):</strong> WP Security → <em>Firewall → Block Suspicious Requests</em> → thêm ngoại lệ cho <code>zalohook</code></li>
					<li><strong>Jetpack Protect:</strong> Kiểm tra <em>Security → Protect → Allowlist IPs</em> → thêm IP range Zalo</li>
				</ul>
			</div>

			<!-- ============ Dấu hiệu nhận biết ============ -->
			<div class="bzz-fw-section">
				<h3>🔍 Dấu hiệu nhận biết đang bị chặn</h3>
				<ul class="bzz-fw-steps">
					<li>Tab <strong>Webhook Listener</strong> → không có user nào dù đã nhắn tin từ app Zalo</li>
					<li>Log trong <code>uploads/sites/{id}/bizcity-cg-logs/YYYY-MM-DD.jsonl</code> không có dòng <code>webhook_router_intake</code> với <code>body_len &gt; 0</code></li>
					<li>Ping / setWebhook trả về <strong>OK</strong> nhưng không có tin nào về — đây là dấu hiệu đặc trưng của block tầng CDN (Cloudflare vẫn cho phép GET nhưng chặn POST body từ bot server)</li>
					<li>HTTP status 403, 1010, 503 trong Cloudflare Security Events</li>
				</ul>
			</div>

			<!-- ============ Quy trình debug nhanh ============ -->
			<div class="bzz-fw-section">
				<h3>🧪 Quy trình debug nhanh (5 phút)</h3>
				<ul class="bzz-fw-steps">
					<li>1️⃣ Tab <strong>Test API</strong> → <em>Probe webhook</em> → xem log có <code>body_len &gt; 0</code> không → nếu không có thì body bị nuốt trước PHP</li>
					<li>2️⃣ Cloudflare → <strong>Security → Events</strong> → lọc theo domain → tìm action <code>block / challenge</code> gần thời điểm gửi tin</li>
					<li>3️⃣ Tạm tắt Cloudflare proxy (<em>DNS Only</em>) → test lại → nếu OK → lỗi ở Cloudflare</li>
					<li>4️⃣ Tạm deactivate security plugin → test lại → nếu OK → lỗi ở plugin</li>
					<li>5️⃣ SSH vào server: <code>tail -f /var/log/nginx/access.log | grep zalohook</code> → xem request có tới server không</li>
				</ul>
				<div class="bzz-fw-danger">⚠️ <strong>Lưu ý bảo mật:</strong> Sau khi cấu hình whitelist, Zalo đã tích hợp xác thực bằng <code>X-Bot-Api-Secret-Token</code> header. Mỗi bot có một secret riêng (cấu hình trong tab Bots OA). Webhook chỉ được xử lý khi secret khớp — whitelist Cloudflare chỉ cho request đi qua tới PHP, PHP vẫn kiểm tra secret nên an toàn.</div>
			</div>
		</div>

	<?php elseif ( $tab === 'hotline' ) : ?>

		<div class="bzz-card">
			<h2>📞 Cấu hình Zalo Hotline (ZNS Template)</h2>
			<?php if ( ! empty( $hotline_account['name'] ) ) : ?>
				<p>Đang chỉnh sửa: <strong><?php echo esc_html( $hotline_account['name'] ); ?></strong></p>
			<?php endif; ?>

			<?php if ( empty( $hotline_settings ) ) : ?>
				<div class="bzz-status-warn">
					<?php // [2026-07-21 Johnny Chu] R-GW-8 — do not tell standalone clients to activate legacy mu-plugin. ?>
					⚠ WAIC integration <code>WaicChannelIntegration_zalo_hotline</code> chưa load.
					Kiểm tra Channel Gateway / Zalo Hotline adapter trong <code>bizcity-twin-ai</code> đã load chưa.
				</div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<?php echo $nonce_field; // phpcs:ignore ?>
					<input type="hidden" name="action"     value="<?php echo esc_attr( BizCity_Tool_Zalo_Page::ACTION ); ?>">
					<input type="hidden" name="integ_code" value="zalo_hotline">

					<?php
					foreach ( $hotline_settings as $key => $cfg ) {
						if ( strpos( $key, '_' ) === 0 && empty( $cfg['type'] ) ) continue;
						$type     = $cfg['type'] ?? 'input';
						$label    = $cfg['label'] ?? $key;
						$plh      = $cfg['plh'] ?? '';
						$desc     = $cfg['desc'] ?? '';
						$readonly = ! empty( $cfg['readonly'] );
						$encrypt  = ! empty( $cfg['encrypt'] );
						$value    = $hotline_account[ $key ] ?? ( $cfg['default'] ?? '' );

						if ( $type === 'html' ) {
							echo '<div class="bzz-row">' . wp_kses_post( $cfg['content'] ?? '' ) . '</div>';
							continue;
						}

						echo '<div class="bzz-row">';
						echo '<label class="bzz-label">' . esc_html( $label );
						if ( $encrypt ) echo ' 🔒';
						echo '</label>';

						if ( $type === 'select' && ! empty( $cfg['options'] ) ) {
							echo '<select class="bzz-input" name="fields[' . esc_attr( $key ) . ']"' . ( $readonly ? ' disabled' : '' ) . '>';
							foreach ( $cfg['options'] as $ov => $ol ) {
								echo '<option value="' . esc_attr( $ov ) . '"' . selected( (string) $value, (string) $ov, false ) . '>' . esc_html( $ol ) . '</option>';
							}
							echo '</select>';
						} else {
							$input_type = ( $encrypt && $value ) ? 'password' : 'text';
							echo '<input type="' . esc_attr( $input_type ) . '" class="bzz-input"';
							echo ' name="fields[' . esc_attr( $key ) . ']"';
							echo ' value="' . esc_attr( $value ) . '"';
							echo ' placeholder="' . esc_attr( $plh ) . '"';
							if ( $readonly ) echo ' readonly';
							echo ' />';
						}

						if ( $desc ) echo '<div class="bzz-desc">' . wp_kses_post( $desc ) . '</div>';
						echo '</div>';
					}
					?>

					<div style="margin-top:20px">
						<button type="submit" class="bzz-btn bzz-btn-primary">💾 Lưu cấu hình Hotline ZNS</button>
						<a href="<?php echo esc_url( $waic_dialog ); ?>" class="bzz-btn bzz-btn-link" target="_blank">⚙ Mở dialog WAIC (nâng cao)</a>
					</div>
				</form>
			<?php endif; ?>
		</div>

	<?php else : /* bots / listener / testapi / connections / logs */ ?>

		<?php if ( ! $admin_menu ) : ?>
			<div class="bzz-status-warn">
				⚠ <code>BizCity_Zalo_Bot_Admin_Menu</code> chưa load — plugin
				<code>bizcity-zalo-bot</code> có thể chưa active đầy đủ.
			</div>
		<?php else :
			$method = $tabs[ $tab ][1];
			if ( $method && method_exists( $admin_menu, $method ) ) : ?>
				<div class="bzz-admin-embed">
					<?php
					// Reuse admin renderer wholesale. Method internals echo
					// `<div class="wrap">…` markup with admin URLs / data-attrs;
					// we already enqueued admin.css + admin.js + dashicons so the
					// AJAX buttons (Test / Set Webhook / Get Updates / …) work.
					$admin_menu->{$method}();
					?>
				</div>
			<?php else : ?>
				<div class="bzz-status-warn">
					⚠ Method <code><?php echo esc_html( $method ); ?>()</code> không tồn tại trên
					<code>BizCity_Zalo_Bot_Admin_Menu</code> — kiểm tra phiên bản plugin.
				</div>
			<?php endif; ?>
		<?php endif; ?>

	<?php endif; ?>

</div>
