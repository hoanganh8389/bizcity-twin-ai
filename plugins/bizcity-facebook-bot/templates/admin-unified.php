<?php
/**
 * Unified Facebook Bots Admin Page
 * Slug: bizcity-facebook-bots
 *
 * Single-screen 4-tab UI matching the public /tool-facebook/ design language
 * (bztfb-* CSS namespace + Facebook blue accents). Each tab keeps its own
 * scroll inside the panel so the page itself never overflows the viewport.
 *
 * Vars expected in scope:
 *   $app_id, $app_secret_masked, $verify_token, $webhook_url,
 *   $oauth_url, $legacy_pages (array), $db_bots (array),
 *   $listener_status (array), $recent_clients (array),
 *   $action_links (array of href => label),
 *   $active_tab (string)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$active_tab = $active_tab ?? ( isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'pages' );
if ( ! in_array( $active_tab, array( 'pages', 'inbox', 'posts', 'settings' ), true ) ) {
	$active_tab = 'pages';
}
?>
<style>
/* Unified BizCity Channels admin (mirrors /tool-facebook/ tokens). */
.bzfb-wrap{max-width:1280px;margin:12px auto 0;padding:0 16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1c1e21}
.bzfb-wrap h1{display:flex;align-items:center;gap:8px;margin:0 0 4px;font-size:20px}
.bzfb-wrap .bzfb-sub{margin:0 0 12px;color:#65676b;font-size:13px}
.bzfb-tabs{display:flex;gap:4px;border-bottom:2px solid #1877f2;margin-bottom:16px;flex-wrap:wrap}
.bzfb-tab{padding:10px 18px;cursor:pointer;border:none;background:#f0f2f5;color:#65676b;font-weight:600;border-radius:8px 8px 0 0;font-size:13px;line-height:1;transition:all .15s}
.bzfb-tab:hover{background:#e4e6eb;color:#1877f2}
.bzfb-tab.active{background:#1877f2;color:#fff}
.bzfb-panel{display:none}
.bzfb-panel.active{display:block}
.bzfb-grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(320px,1fr))}
.bzfb-card{background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid #e4e6eb}
.bzfb-card h2{margin:0 0 8px;font-size:15px;display:flex;align-items:center;gap:6px}
.bzfb-card .bzfb-help{font-size:12px;color:#65676b;margin:0 0 10px}
.bzfb-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:6px;border:1px solid transparent;cursor:pointer;font-weight:600;font-size:13px;text-decoration:none;line-height:1.2;transition:all .15s}
.bzfb-btn-primary{background:#1877f2;color:#fff}
.bzfb-btn-primary:hover{background:#166fe5;color:#fff}
.bzfb-btn-ghost{background:#f0f2f5;color:#1c1e21;border-color:#dcdfe2}
.bzfb-btn-ghost:hover{background:#e4e6eb}
.bzfb-btn-danger{background:#fee2e2;color:#b91c1c;border-color:#fca5a5}
.bzfb-btn-danger:hover{background:#fecaca}
.bzfb-input,.bzfb-textarea{width:100%;box-sizing:border-box;padding:8px 12px;border:1px solid #dcdfe2;border-radius:6px;font-size:13px;font-family:inherit}
.bzfb-textarea{min-height:80px;resize:vertical;font-family:ui-monospace,"SF Mono",Menlo,Consolas,monospace;font-size:12px}
.bzfb-input:focus,.bzfb-textarea:focus{outline:none;border-color:#1877f2;box-shadow:0 0 0 2px rgba(24,119,242,.18)}
.bzfb-field{margin-bottom:10px}
.bzfb-label{display:block;margin-bottom:4px;font-weight:600;font-size:12px;color:#1c1e21}
.bzfb-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.bzfb-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;line-height:1.4}
.bzfb-pill-green{background:#d1fae5;color:#065f46}
.bzfb-pill-red{background:#fee2e2;color:#b91c1c}
.bzfb-pill-amber{background:#fef3c7;color:#92400e}
.bzfb-pill-blue{background:#dbeafe;color:#1e40af}
.bzfb-table{width:100%;border-collapse:collapse;font-size:13px}
.bzfb-table th,.bzfb-table td{padding:8px 10px;border-bottom:1px solid #e4e6eb;text-align:left;vertical-align:middle}
.bzfb-table th{background:#f0f2f5;font-weight:600;font-size:11px;color:#65676b;text-transform:uppercase;letter-spacing:.04em}
.bzfb-table tr:hover td{background:#fafbfc}
.bzfb-table code{background:#f0f2f5;padding:1px 6px;border-radius:4px;font-size:11px}
.bzfb-empty{padding:18px;text-align:center;color:#65676b;background:#f8fafc;border:1px dashed #dcdfe2;border-radius:8px;font-size:13px}
.bzfb-scroll{max-height:calc(100vh - 260px);overflow:auto;padding-right:4px}
.bzfb-link-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px}
.bzfb-link-grid a{display:flex;align-items:center;gap:6px;padding:10px 12px;background:#f0f2f5;border:1px solid #dcdfe2;border-radius:8px;color:#1c1e21;text-decoration:none;font-size:13px;font-weight:500}
.bzfb-link-grid a:hover{background:#e7f3ff;border-color:#1877f2;color:#1877f2}
.bzfb-status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:4px}
.bzfb-status-dot.on{background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.18)}
.bzfb-status-dot.off{background:#ef4444}
@media(max-width:782px){.bzfb-wrap{padding:0 8px}.bzfb-grid{grid-template-columns:1fr}}
</style>

<div class="bzfb-wrap">
	<h1>📘 Facebook Bots</h1>
	<p class="bzfb-sub">Quản lý kết nối Page · Inbox · Bài đăng · Cấu hình App. Tất cả trong 1 màn hình — không cần cuộn trang.</p>

	<div class="bzfb-tabs" role="tablist">
		<button type="button" class="bzfb-tab <?php echo $active_tab === 'pages' ? 'active' : ''; ?>" data-tab="pages">🔗 Pages &amp; Connect</button>
		<button type="button" class="bzfb-tab <?php echo $active_tab === 'inbox' ? 'active' : ''; ?>" data-tab="inbox">💬 Inbox</button>
		<button type="button" class="bzfb-tab <?php echo $active_tab === 'posts' ? 'active' : ''; ?>" data-tab="posts">📰 Posts &amp; Comments</button>
		<button type="button" class="bzfb-tab <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" data-tab="settings">⚙️ Settings &amp; Tools</button>
	</div>

	<!-- ─────────────── TAB 1: PAGES & CONNECT ─────────────── -->
	<div class="bzfb-panel <?php echo $active_tab === 'pages' ? 'active' : ''; ?>" id="bzfb-panel-pages">
		<div class="bzfb-scroll">
			<div class="bzfb-grid">
				<div class="bzfb-card" style="grid-column:1/-1">
					<h2>🔌 Kết nối Fanpage qua Facebook OAuth</h2>
					<p class="bzfb-help">Bấm nút bên dưới để authorize App. Sau khi cấp quyền, các Page bạn quản trị sẽ tự lưu vào cả option <code>fb_pages_connected</code> (legacy) và bảng <code>wp_bizcity_facebook_bots</code>.</p>
					<?php if ( empty( $app_id ) ) : ?>
						<div class="bzfb-pill bzfb-pill-amber" style="margin-bottom:8px">⚠️ Chưa có App ID — vào tab <strong>Settings</strong> để cấu hình</div>
					<?php else : ?>
						<a href="<?php echo esc_url( $oauth_url ); ?>" class="bzfb-btn bzfb-btn-primary">🔗 Đăng nhập Facebook &amp; chọn Page</a>
						<a href="https://developers.facebook.com/apps/<?php echo esc_attr( $app_id ); ?>/" target="_blank" rel="noopener" class="bzfb-btn bzfb-btn-ghost" style="margin-left:6px">↗ Mở Facebook Developer Console</a>
					<?php endif; ?>
				</div>

				<div class="bzfb-card" style="grid-column:1/-1">
					<h2>✅ Pages đã kết nối <span class="bzfb-pill bzfb-pill-blue"><?php echo count( $legacy_pages ) + count( $db_bots ); ?> total</span></h2>
					<?php
					// Merge: index by page_id, prefer DB row when both exist.
					$rows = array();
					foreach ( $legacy_pages as $p ) {
						$pid = (string) ( $p['id'] ?? '' );
						if ( $pid === '' ) { continue; }
						$rows[ $pid ] = array(
							'id'     => $pid,
							'name'   => (string) ( $p['name'] ?? $pid ),
							'token'  => (string) ( $p['access_token'] ?? '' ),
							'source' => 'option',
							'bot_id' => 0,
							'status' => 'oauth',
						);
					}
					foreach ( $db_bots as $b ) {
						$pid = (string) ( $b->page_id ?? '' );
						if ( $pid === '' ) { continue; }
						$rows[ $pid ] = array(
							'id'     => $pid,
							'name'   => (string) ( $b->bot_name ?? $pid ),
							'token'  => (string) ( $b->page_access_token ?? '' ),
							'source' => 'db',
							'bot_id' => (int) $b->id,
							'status' => (string) ( $b->status ?? 'active' ),
						);
					}
					?>
					<?php if ( empty( $rows ) ) : ?>
						<div class="bzfb-empty">Chưa có Page nào. Hãy bấm nút <strong>Đăng nhập Facebook</strong> phía trên để kết nối.</div>
					<?php else : ?>
						<table class="bzfb-table">
							<thead><tr><th>Page</th><th>Page ID</th><th>Token</th><th>Nguồn</th><th>Trạng thái</th><th style="text-align:right">Hành động</th></tr></thead>
							<tbody>
							<?php foreach ( $rows as $r ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $r['name'] ); ?></strong></td>
									<td><code><?php echo esc_html( $r['id'] ); ?></code></td>
									<td><code><?php echo esc_html( substr( $r['token'], 0, 8 ) ); ?>•••</code></td>
									<td>
										<?php if ( $r['source'] === 'db' ) : ?>
											<span class="bzfb-pill bzfb-pill-blue">DB bot #<?php echo (int) $r['bot_id']; ?></span>
										<?php else : ?>
											<span class="bzfb-pill bzfb-pill-amber">option</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $r['status'] === 'active' || $r['status'] === 'oauth' ) : ?>
											<span class="bzfb-pill bzfb-pill-green">● Active</span>
										<?php else : ?>
											<span class="bzfb-pill bzfb-pill-red">○ <?php echo esc_html( $r['status'] ); ?></span>
										<?php endif; ?>
									</td>
									<td style="text-align:right">
										<a class="bzfb-btn bzfb-btn-ghost" target="_blank" rel="noopener" href="https://facebook.com/<?php echo esc_attr( $r['id'] ); ?>" style="padding:4px 10px;font-size:12px">↗ Mở</a>
										<?php if ( $r['bot_id'] ) : ?>
											<a class="bzfb-btn bzfb-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bots&action=edit&bot_id=' . $r['bot_id'] ) ); ?>" style="padding:4px 10px;font-size:12px">✏ Sửa</a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<!-- ─────────────── TAB 2: INBOX ─────────────── -->
	<div class="bzfb-panel <?php echo $active_tab === 'inbox' ? 'active' : ''; ?>" id="bzfb-panel-inbox">
		<div class="bzfb-scroll">
			<div class="bzfb-grid">
				<div class="bzfb-card" style="grid-column:1/-1">
					<h2>💬 Khách gần đây</h2>
					<p class="bzfb-help">Danh sách hội thoại gần nhất qua Messenger / comment bot. Click "Mở Inbox đầy đủ" để vào CRM Inbox với composer.</p>
					<?php if ( empty( $recent_clients ) ) : ?>
						<div class="bzfb-empty">Chưa có hội thoại nào.</div>
					<?php else : ?>
						<table class="bzfb-table">
							<thead><tr><th>Client</th><th>Page</th><th>Tin cuối</th><th>Thời gian</th></tr></thead>
							<tbody>
								<?php foreach ( $recent_clients as $c ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $c['name'] ?? $c['client_id'] ?? '?' ); ?></strong><br><code style="font-size:10px"><?php echo esc_html( $c['client_id'] ?? '' ); ?></code></td>
									<td><?php echo esc_html( $c['page_name'] ?? $c['page_id'] ?? '' ); ?></td>
									<td><?php echo esc_html( mb_substr( (string) ( $c['last_message'] ?? '' ), 0, 80 ) ); ?></td>
									<td><?php echo esc_html( $c['last_at'] ?? '' ); ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
					<div style="margin-top:12px">
						<a class="bzfb-btn bzfb-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bot-inbox' ) ); ?>">📨 Mở Inbox đầy đủ</a>
						<a class="bzfb-btn bzfb-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-twin-crm-inbox' ) ); ?>">🎯 Mở CRM Inbox</a>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- ─────────────── TAB 3: POSTS & COMMENTS ─────────────── -->
	<div class="bzfb-panel <?php echo $active_tab === 'posts' ? 'active' : ''; ?>" id="bzfb-panel-posts">
		<div class="bzfb-scroll">
			<div class="bzfb-grid">
				<div class="bzfb-card">
					<h2>📰 Quản lý bài đăng</h2>
					<p class="bzfb-help">Xem &amp; quản lý các bài AI đã đăng lên Page (lịch sử, schedule, edit).</p>
					<a class="bzfb-btn bzfb-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bot-posts' ) ); ?>">→ Mở trang Posts</a>
					<a class="bzfb-btn bzfb-btn-ghost" href="<?php echo esc_url( home_url( '/tool-facebook/' ) ); ?>" target="_blank" rel="noopener" style="margin-left:6px">↗ Tạo bài (User UI)</a>
				</div>
				<div class="bzfb-card">
					<h2>💭 Quản lý comment</h2>
					<p class="bzfb-help">Comment listener, AI auto-reply, hide/delete spam.</p>
					<a class="bzfb-btn bzfb-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bot-comments' ) ); ?>">→ Mở trang Comments</a>
				</div>
				<div class="bzfb-card">
					<h2>🏢 Liên kết Business</h2>
					<p class="bzfb-help">Liên kết App với Business Manager để mở quyền advanced.</p>
					<a class="bzfb-btn bzfb-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bot-business' ) ); ?>">→ Cấu hình Business</a>
				</div>
			</div>
		</div>
	</div>

	<!-- ─────────────── TAB 4: SETTINGS & TOOLS ─────────────── -->
	<div class="bzfb-panel <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" id="bzfb-panel-settings">
		<div class="bzfb-scroll">
			<div class="bzfb-grid">

				<div class="bzfb-card" style="grid-column:1/-1">
					<h2>🔑 Facebook App Credentials</h2>
					<p class="bzfb-help">Lấy từ <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">developers.facebook.com</a> → My App → Settings → Basic.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'bztfb_settings_nonce' ); ?>
						<input type="hidden" name="action" value="bztfb_save_settings">
						<div class="bzfb-row" style="align-items:flex-start">
							<div class="bzfb-field" style="flex:1;min-width:240px">
								<label class="bzfb-label">App ID</label>
								<input class="bzfb-input" type="text" name="bztfb_app_id" value="<?php echo esc_attr( $app_id ); ?>" placeholder="123456789012345">
							</div>
							<div class="bzfb-field" style="flex:1;min-width:240px">
								<label class="bzfb-label">App Secret</label>
								<input class="bzfb-input" type="text" name="bztfb_app_secret" value="<?php echo esc_attr( $app_secret_masked ); ?>" placeholder="••••••••">
							</div>
							<div class="bzfb-field" style="flex:1;min-width:200px">
								<label class="bzfb-label">Verify Token</label>
								<input class="bzfb-input" type="text" name="bztfb_verify_token" value="<?php echo esc_attr( $verify_token ); ?>" placeholder="bizfbhook">
							</div>
						</div>
						<button type="submit" class="bzfb-btn bzfb-btn-primary">💾 Lưu cấu hình</button>
					</form>
				</div>

				<div class="bzfb-card">
					<h2>🌐 Webhook URL</h2>
					<p class="bzfb-help">Dán URL này vào Facebook App → Messenger → Webhooks → Callback URL. Verify token = ô bên trên.</p>
					<input class="bzfb-input" type="text" readonly value="<?php echo esc_attr( $webhook_url ); ?>" onclick="this.select()">
				</div>

				<div class="bzfb-card">
					<h2>📡 Listener Status</h2>
					<?php $is_on = ! empty( $listener_status['running'] ); ?>
					<p style="margin:0 0 10px">
						<span class="bzfb-status-dot <?php echo $is_on ? 'on' : 'off'; ?>"></span>
						<strong><?php echo $is_on ? 'Đang chạy' : 'Đang dừng'; ?></strong>
						<?php if ( ! empty( $listener_status['note'] ) ) : ?>
							<small style="color:#65676b">— <?php echo esc_html( $listener_status['note'] ); ?></small>
						<?php endif; ?>
					</p>
					<a class="bzfb-btn bzfb-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bot-listener' ) ); ?>">→ Quản lý Listener</a>
				</div>

				<div class="bzfb-card" style="grid-column:1/-1">
					<h2>🛠️ Tools &amp; Debug</h2>
					<div class="bzfb-link-grid">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bot-test-api' ) ); ?>">🧪 Test API</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bot-logs' ) ); ?>">📋 Nhật ký</a>
						<?php if ( ! empty( $action_links['migration'] ) ) : ?>
							<a href="<?php echo esc_url( $action_links['migration'] ); ?>">🔄 Migration Tools</a>
						<?php endif; ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bot-connect' ) ); ?>">🔗 Trang Connect (legacy)</a>
						<a href="<?php echo esc_url( home_url( '/tool-facebook/' ) ); ?>" target="_blank" rel="noopener">↗ /tool-facebook/ (User UI)</a>
					</div>
				</div>

			</div>
		</div>
	</div>
</div>

<script>
(function(){
	var tabs   = document.querySelectorAll('.bzfb-tab');
	var panels = document.querySelectorAll('.bzfb-panel');
	function activate(name){
		tabs.forEach(function(t){ t.classList.toggle('active', t.dataset.tab === name); });
		panels.forEach(function(p){ p.classList.toggle('active', p.id === 'bzfb-panel-' + name); });
		try { history.replaceState(null, '', location.pathname + location.search.replace(/[?&]tab=[^&]*/, '') + (location.search.includes('?') ? '&' : '?') + 'tab=' + name); } catch(e){}
	}
	tabs.forEach(function(t){ t.addEventListener('click', function(){ activate(t.dataset.tab); }); });
})();
</script>
