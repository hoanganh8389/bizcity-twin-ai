<?php
/**
 * Unified Zalo Bot Admin Page — 4-tab single-screen UI
 * Slug: bizcity-zalo-bot-dashboard
 *
 * Variables expected in scope (from render_unified_dashboard()):
 *   $active_tab (string)   — 'bots' | 'assign' | 'test' | 'logs'
 *   $all_bots (array)      — all bots (active + inactive)
 *   $total_assignments (int)
 *   $recent_logs (array)   — 8 most recent
 *   $gateway_available (bool)
 *   $all_assignments (array)
 *   $wp_users (array)      — WP users for assign dropdown
 *   $assign_message (string)
 *   $assign_message_type (string)
 *   $logs (array)          — 100 most recent logs
 *   $log_bot_id_filter (int)
 *   $db (BizCity_Zalo_Bot_Database)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! in_array( $active_tab, array( 'bots', 'assign', 'test', 'logs' ), true ) ) {
	$active_tab = 'bots';
}
?>
<style>
.bzz-wrap{max-width:1280px;margin:12px auto 0;padding:0 16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1c1e21}
.bzz-wrap h1{display:flex;align-items:center;gap:8px;margin:0 0 4px;font-size:20px}
.bzz-wrap .bzz-sub{margin:0 0 10px;color:#65676b;font-size:13px}
/* Tabs — Zalo blue */
.bzz-tabs{display:flex;gap:4px;border-bottom:2px solid #0068ff;margin-bottom:16px;flex-wrap:wrap}
.bzz-tab{padding:10px 18px;border:none;background:#f0f2f5;color:#65676b;font-weight:600;border-radius:8px 8px 0 0;font-size:13px;line-height:1;transition:all .15s;text-decoration:none;display:inline-block;cursor:pointer}
.bzz-tab:hover{background:#e4e6eb;color:#0068ff}
.bzz-tab.active{background:#0068ff;color:#fff}
/* Stats */
.bzz-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:14px}
.bzz-stat{background:#fff;border:1px solid #e4e6eb;border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.bzz-stat-icon{width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;flex-shrink:0}
.bzz-icon-bots{background:linear-gradient(135deg,#0068ff,#0050d0)}
.bzz-icon-assigns{background:linear-gradient(135deg,#10b981,#059669)}
.bzz-icon-events{background:linear-gradient(135deg,#f59e0b,#d97706)}
.bzz-icon-gw-on{background:linear-gradient(135deg,#3b82f6,#2563eb)}
.bzz-icon-gw-off{background:linear-gradient(135deg,#ef4444,#dc2626)}
.bzz-stat-num{font-size:20px;font-weight:800;line-height:1;margin-bottom:2px}
.bzz-stat-label{font-size:10px;color:#65676b;text-transform:uppercase;letter-spacing:.04em;font-weight:600}
/* Cards & grid */
.bzz-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:900px){.bzz-grid{grid-template-columns:1fr}}
.bzz-card{background:#fff;border-radius:10px;padding:14px 18px;box-shadow:0 1px 3px rgba(0,0,0,.05);border:1px solid #e4e6eb}
.bzz-card h2{margin:0 0 10px;font-size:14px;display:flex;align-items:center;gap:6px;padding-bottom:8px;border-bottom:1px solid #f0f2f5}
.bzz-card-body{overflow:auto;max-height:calc(100vh - 440px);min-height:160px}
/* Scroll container for tab content */
.bzz-scroll{max-height:calc(100vh - 210px);overflow:auto;padding-bottom:20px}
/* Buttons */
.bzz-btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:6px;border:1px solid transparent;text-decoration:none;font-weight:600;font-size:12px;line-height:1.2;cursor:pointer;transition:all .15s}
.bzz-btn-primary{background:#0068ff;color:#fff}
.bzz-btn-primary:hover{background:#0050d0;color:#fff}
.bzz-btn-ghost{background:#f0f2f5;color:#1c1e21;border-color:#dcdfe2}
.bzz-btn-ghost:hover{background:#e4e6eb}
.bzz-btn-danger{background:#fee2e2;color:#b91c1c;border-color:#fca5a5}
.bzz-btn-danger:hover{background:#fecaca}
/* Actions bar */
.bzz-actions{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
/* Tables */
.bzz-table{width:100%;border-collapse:collapse;font-size:12px}
.bzz-table th,.bzz-table td{padding:6px 8px;border-bottom:1px solid #f0f2f5;text-align:left;vertical-align:middle}
.bzz-table th{background:#f8fafc;font-weight:600;font-size:10px;color:#65676b;text-transform:uppercase;letter-spacing:.04em;position:sticky;top:0;z-index:1}
.bzz-table tr:hover td{background:#fafbfc}
.bzz-table code{background:#f0f2f5;padding:1px 5px;border-radius:3px;font-size:10px}
/* Pills */
.bzz-pill{display:inline-flex;align-items:center;gap:3px;padding:1px 8px;border-radius:10px;font-size:10px;font-weight:600;line-height:1.5}
.bzz-pill-green{background:#d1fae5;color:#065f46}
.bzz-pill-amber{background:#fef3c7;color:#92400e}
.bzz-pill-blue{background:#dbeafe;color:#1e40af}
.bzz-pill-slate{background:#f1f5f9;color:#475569}
/* Misc */
.bzz-empty{text-align:center;color:#65676b;padding:24px 12px;background:#f8fafc;border:1px dashed #dcdfe2;border-radius:8px;font-size:12px}
.bzz-gateway{padding:10px 12px;border-radius:8px;font-size:12px;line-height:1.6;margin-top:12px}
.bzz-gateway.on{background:#ecfdf5;border-left:3px solid #10b981}
.bzz-gateway.off{background:#fef2f2;border-left:3px solid #ef4444}
.bzz-input,.bzz-select,.bzz-textarea{width:100%;box-sizing:border-box;padding:7px 11px;border:1px solid #dcdfe2;border-radius:6px;font-size:13px;font-family:inherit}
.bzz-textarea{min-height:70px;resize:vertical;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
.bzz-input:focus,.bzz-select:focus,.bzz-textarea:focus{outline:none;border-color:#0068ff;box-shadow:0 0 0 2px rgba(0,104,255,.18)}
.bzz-field{margin-bottom:10px}
.bzz-label{display:block;margin-bottom:4px;font-weight:600;font-size:12px}
.bzz-section{background:#fff;border:1px solid #e4e6eb;border-radius:10px;padding:16px 20px;margin-bottom:14px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.bzz-section h3{margin:0 0 12px;font-size:14px;display:flex;align-items:center;gap:6px;padding-bottom:8px;border-bottom:1px solid #f0f2f5}
</style>

<div class="wrap bzz-wrap">
	<h1>
		<span class="dashicons dashicons-format-chat" style="font-size:24px;width:24px;height:24px;color:#0068ff"></span>
		Bots - Zalo
	</h1>
	<p class="bzz-sub">Quản lý Bots OA · Gán user · Test API · Nhật ký — tất cả trong 1 màn hình.</p>

	<div class="bzz-tabs" role="tablist">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bot-dashboard&tab=bots' ) ); ?>" class="bzz-tab <?php echo $active_tab === 'bots' ? 'active' : ''; ?>">🤖 Bots OA</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bot-dashboard&tab=assign' ) ); ?>" class="bzz-tab <?php echo $active_tab === 'assign' ? 'active' : ''; ?>">👤 Gán &amp; Kết nối</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bot-dashboard&tab=test' ) ); ?>" class="bzz-tab <?php echo $active_tab === 'test' ? 'active' : ''; ?>">📡 Listener &amp; Test</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bot-dashboard&tab=logs' ) ); ?>" class="bzz-tab <?php echo $active_tab === 'logs' ? 'active' : ''; ?>">📋 Logs &amp; Memory</a>
	</div>

	<!-- ─────────────── TAB 1: BOTS OA ─────────────── -->
	<?php if ( $active_tab === 'bots' ) : ?>
	<div class="bzz-scroll">

		<!-- Stats -->
		<div class="bzz-stats">
			<div class="bzz-stat">
				<div class="bzz-stat-icon bzz-icon-bots">🤖</div>
				<div><div class="bzz-stat-num"><?php echo count( $all_bots ); ?></div><div class="bzz-stat-label">Bots active</div></div>
			</div>
			<div class="bzz-stat">
				<div class="bzz-stat-icon bzz-icon-assigns">👤</div>
				<div><div class="bzz-stat-num"><?php echo (int) $total_assignments; ?></div><div class="bzz-stat-label">Bot ↔ User</div></div>
			</div>
			<div class="bzz-stat">
				<div class="bzz-stat-icon bzz-icon-events">📋</div>
				<div><div class="bzz-stat-num"><?php echo count( $recent_logs ); ?></div><div class="bzz-stat-label">Sự kiện gần</div></div>
			</div>
			<div class="bzz-stat">
				<div class="bzz-stat-icon <?php echo $gateway_available ? 'bzz-icon-gw-on' : 'bzz-icon-gw-off'; ?>"><?php echo $gateway_available ? '✅' : '❌'; ?></div>
				<div><div class="bzz-stat-num" style="font-size:13px;font-weight:700"><?php echo $gateway_available ? 'Connected' : 'Off'; ?></div><div class="bzz-stat-label">Gateway</div></div>
			</div>
		</div>

		<!-- Quick actions -->
		<div class="bzz-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bots&action=add' ) ); ?>" class="bzz-btn bzz-btn-primary">➕ Tạo Bot mới</a>
		</div>

		<!-- Bots table -->
		<div class="bzz-card" style="margin-bottom:14px">
			<h2>🤖 Danh sách Bots <span class="bzz-pill bzz-pill-blue"><?php echo count( $all_bots ); ?> bot</span></h2>
			<div class="bzz-card-body">
			<?php if ( empty( $all_bots ) ) : ?>
				<div class="bzz-empty">Chưa có bot nào. <a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bots&action=add' ) ); ?>">Tạo bot đầu tiên →</a></div>
			<?php else : ?>
				<table class="bzz-table">
					<thead>
						<tr>
							<th style="width:40px">#</th>
							<th>Tên Bot</th>
							<th style="width:80px">Trạng thái</th>
							<th style="width:80px">Users gán</th>
							<th>Webhook URL</th>
							<th style="width:140px;text-align:right">Hành động</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $all_bots as $bot ) :
						$assigned_users = BizCity_Zalo_Bot_Dashboard::get_users_for_bot( (int) $bot->id );
					?>
						<tr>
							<td><code><?php echo (int) $bot->id; ?></code></td>
							<td><strong><?php echo esc_html( $bot->bot_name ); ?></strong></td>
							<td>
								<?php if ( $bot->status === 'active' ) : ?>
									<span class="bzz-pill bzz-pill-green">● Active</span>
								<?php else : ?>
									<span class="bzz-pill bzz-pill-amber">○ Inactive</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $assigned_users ) ) : ?>
									<span class="bzz-pill bzz-pill-blue">👤 <?php echo count( $assigned_users ); ?></span>
								<?php else : ?>
									<span class="bzz-pill bzz-pill-slate">—</span>
								<?php endif; ?>
							</td>
							<td>
								<input type="text" readonly value="<?php echo esc_url( home_url( '/zalohook/' ) ); ?>" onclick="this.select()" style="width:100%;border:1px solid #e4e6eb;border-radius:4px;padding:3px 7px;font-size:11px;background:#f8fafc;color:#374151" />
							</td>
							<td style="text-align:right;white-space:nowrap">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bots&action=edit&bot_id=' . $bot->id ) ); ?>" class="bzz-btn bzz-btn-ghost" style="padding:3px 8px;font-size:11px">✏ Sửa</a>
								<button type="button" class="bzz-btn bzz-btn-primary set-webhook-btn" data-bot-id="<?php echo esc_attr( $bot->id ); ?>" style="padding:3px 8px;font-size:11px">🚀 Kích hoạt</button>
								<button type="button" class="bzz-btn bzz-btn-ghost get-me-btn" data-bot-id="<?php echo esc_attr( $bot->id ); ?>" style="padding:3px 8px;font-size:11px">GetMe</button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			</div>
		</div>

		<!-- Recent activity -->
		<div class="bzz-card">
			<h2>📋 Hoạt động gần đây</h2>
			<div class="bzz-card-body">
			<?php if ( empty( $recent_logs ) ) : ?>
				<div class="bzz-empty">Chưa có sự kiện nào ghi lại.</div>
			<?php else : ?>
				<table class="bzz-table">
					<thead><tr><th style="width:80px">Lúc</th><th style="width:45px">Bot</th><th>Sự kiện</th><th>Tin nhắn</th></tr></thead>
					<tbody>
					<?php foreach ( $recent_logs as $log ) : ?>
						<tr>
							<td><?php echo esc_html( date( 'd/m H:i', strtotime( $log->created_at ) ) ); ?></td>
							<td><code><?php echo esc_html( $log->bot_id ); ?></code></td>
							<td><code style="font-size:10px"><?php echo esc_html( $log->event_name ); ?></code></td>
							<td><?php echo esc_html( mb_substr( (string) ( $log->text ?: '-' ), 0, 60 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			</div>
		</div>

		<!-- Gateway status -->
		<div class="bzz-gateway <?php echo $gateway_available ? 'on' : 'off'; ?>">
			<?php if ( $gateway_available ) : ?>
				<strong>✅ Gateway connected</strong> — <code>bizcity-admin-hook-zalo</code> đã active. Tin nhắn route qua <code>bizcity_gateway_send_message()</code>, auto resolve user_id từ bot assignment.
			<?php else : ?>
				<strong>❌ Gateway off</strong> — plugin <code>bizcity-admin-hook-zalo</code> chưa active. Automation &amp; AI chat sẽ không khả dụng.
			<?php endif; ?>
		</div>

	</div>
	<?php endif; ?>

	<!-- ─────────────── TAB 2: GÁN & KẾT NỐI ─────────────── -->
	<?php if ( $active_tab === 'assign' ) : ?>
	<div class="bzz-scroll">

		<?php if ( $assign_message ) : ?>
			<div class="notice notice-<?php echo esc_attr( $assign_message_type ); ?> is-dismissible" style="padding:12px 14px;margin-bottom:14px;border-radius:6px">
				<?php echo esc_html( $assign_message ); ?>
			</div>
		<?php endif; ?>

		<!-- Assign form -->
		<div class="bzz-section">
			<h3>➕ Gán Bot cho tài khoản quản trị</h3>
			<p style="font-size:13px;color:#65676b;margin:0 0 14px">Mỗi Zalo Bot gán với 1 WP user. Khi tin nhắn đến, hệ thống tự resolve user_id để dùng trong automation &amp; AI chat.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bot-dashboard&tab=assign' ) ); ?>">
				<?php wp_nonce_field( 'bzcz_assign_bots' ); ?>
				<input type="hidden" name="bzcz_assign_action" value="assign" />
				<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
					<div class="bzz-field" style="flex:1;min-width:200px">
						<label class="bzz-label" for="assign_bot_id">Chọn Bot</label>
						<select name="assign_bot_id" id="assign_bot_id" class="bzz-select">
							<option value="">-- Chọn Bot --</option>
							<?php foreach ( $all_bots as $bot ) : ?>
								<option value="<?php echo esc_attr( $bot->id ); ?>">
									#<?php echo esc_html( $bot->id ); ?> — <?php echo esc_html( $bot->bot_name ); ?> (<?php echo esc_html( $bot->status ); ?>)
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="bzz-field" style="flex:2;min-width:280px">
						<label class="bzz-label" for="assign_user_id">Chọn User quản trị</label>
						<select name="assign_user_id" id="assign_user_id" class="bzz-select">
							<option value="">-- Chọn User --</option>
							<?php foreach ( $wp_users as $u ) : ?>
								<option value="<?php echo esc_attr( $u->ID ); ?>">
									#<?php echo esc_html( $u->ID ); ?> — <?php echo esc_html( $u->display_name ); ?> (<?php echo esc_html( $u->user_email ); ?>) [<?php echo esc_html( implode( ', ', $u->roles ) ); ?>]
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="bzz-field" style="flex-shrink:0">
						<button type="submit" class="bzz-btn bzz-btn-primary" style="padding:8px 20px;font-size:13px">🔗 Gán</button>
					</div>
				</div>
			</form>
		</div>

		<!-- Assignments table -->
		<div class="bzz-section">
			<h3>📋 Bot ↔ User đã gán <span class="bzz-pill bzz-pill-blue"><?php echo count( $all_assignments ); ?></span></h3>
			<?php if ( empty( $all_assignments ) ) : ?>
				<div class="bzz-empty">Chưa có gán nào. Dùng form ở trên để gán.</div>
			<?php else : ?>
				<div style="overflow:auto">
				<table class="bzz-table">
					<thead>
						<tr>
							<th>Bot</th>
							<th style="width:70px">User ID</th>
							<th>Tên User</th>
							<th>Email</th>
							<th style="width:80px">Vai trò</th>
							<th style="width:90px;text-align:right">Hành động</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $all_assignments as $a ) : ?>
						<tr>
							<td>
								<strong>#<?php echo esc_html( $a['bot_id'] ); ?></strong> <?php echo esc_html( $a['bot_name'] ); ?>
								<?php if ( $a['bot_status'] === 'active' ) : ?>
									<span class="bzz-pill bzz-pill-green">Active</span>
								<?php else : ?>
									<span class="bzz-pill bzz-pill-amber">Inactive</span>
								<?php endif; ?>
							</td>
							<td><code>#<?php echo esc_html( $a['user_id'] ); ?></code></td>
							<td><?php echo esc_html( $a['user_display_name'] ); ?></td>
							<td><code style="font-size:10px"><?php echo esc_html( $a['user_email'] ); ?></code></td>
							<td><?php echo esc_html( $a['user_roles'] ); ?></td>
							<td style="text-align:right">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bot-dashboard&tab=assign' ) ); ?>" style="display:inline" onsubmit="return confirm('Gỡ gán này?')">
									<?php wp_nonce_field( 'bzcz_assign_bots' ); ?>
									<input type="hidden" name="bzcz_assign_action" value="unassign" />
									<input type="hidden" name="unassign_bot_id" value="<?php echo esc_attr( $a['bot_id'] ); ?>" />
									<input type="hidden" name="unassign_user_id" value="<?php echo esc_attr( $a['user_id'] ); ?>" />
									<button type="submit" class="bzz-btn bzz-btn-danger" style="padding:3px 9px;font-size:11px">🗑 Gỡ</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</div>

		<!-- How it works -->
		<div class="bzz-section">
			<h3>💡 Cách hoạt động</h3>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">
				<div style="background:#f0f4ff;padding:14px;border-radius:8px;border-left:4px solid #0068ff">
					<strong>1. Gán Bot ↔ User</strong>
					<p style="margin:6px 0 0;font-size:12px;line-height:1.5;color:#374151">Mapping lưu vào usermeta: <code>_bizcity_zalo_bot_ids</code></p>
				</div>
				<div style="background:#ecfdf5;padding:14px;border-radius:8px;border-left:4px solid #10b981">
					<strong>2. Auto Resolve User</strong>
					<p style="margin:6px 0 0;font-size:12px;line-height:1.5;color:#374151">Webhook tự tìm WP user_id tương ứng với bot nhận tin.</p>
				</div>
				<div style="background:#fef3c7;padding:14px;border-radius:8px;border-left:4px solid #f59e0b">
					<strong>3. Gateway Integration</strong>
					<p style="margin:6px 0 0;font-size:12px;line-height:1.5;color:#374151">Fire automation &amp; AI chat với context của user quản trị.</p>
				</div>
			</div>
		</div>

	</div>
	<?php endif; ?>

	<!-- ─────────────── TAB 3: LISTENER & TEST ─────────────── -->
	<?php if ( $active_tab === 'test' ) : ?>
	<div class="bzz-scroll">
		<?php if ( empty( $all_bots ) ) : ?>
			<div class="bzz-empty" style="margin:20px 0">Chưa có bot nào. <a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bots&action=add' ) ); ?>">Tạo bot đầu tiên →</a></div>
		<?php else : ?>

		<!-- Bot selector (shared by listener + test) -->
		<div class="bzz-section">
			<h3>🤖 Chọn Bot</h3>
			<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
				<!-- Listener bot selector -->
				<div>
					<label class="bzz-label" style="margin-bottom:4px">Listener bot</label>
					<select id="listener-bot-select" class="bzz-select" style="width:220px">
						<option value="">-- Chọn bot --</option>
						<?php foreach ( $all_bots as $bot ) : ?>
							<option value="<?php echo esc_attr( $bot->id ); ?>" data-webhook-url="<?php echo esc_url( home_url( '/zalohook/' ) ); ?>">
								<?php echo esc_html( $bot->bot_name ); ?>
								<?php if ( $bot->webhook_secret ) : ?>(🔒 Secured)<?php endif; ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<!-- Test API bot selector -->
				<div>
					<label class="bzz-label" style="margin-bottom:4px">Test API bot</label>
					<select id="test-api-bot-select" class="bzz-select" style="width:220px">
						<option value="">-- Chọn bot --</option>
						<?php foreach ( $all_bots as $bot ) : ?>
							<option value="<?php echo esc_attr( $bot->id ); ?>" data-bot-token="<?php echo esc_attr( $bot->bot_token ); ?>">
								<?php echo esc_html( $bot->bot_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<div id="bot-info-row" style="display:none;margin-top:10px">
				<div id="bot-info-content" style="font-size:12px;color:#374151"></div>
			</div>
		</div>

		<!-- Listener controls -->
		<div class="bzz-section">
			<h3>📡 Webhook Listener</h3>
			<p style="font-size:13px;color:#65676b;margin:0 0 12px">Lắng nghe webhook Zalo theo thời gian thực. Khi có tin nhắn gửi đến bot, dữ liệu hiển thị bên dưới.</p>
			<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
				<button type="button" class="bzz-btn bzz-btn-primary" id="btn-start-listening" disabled>▶ Bắt đầu nghe</button>
				<button type="button" class="bzz-btn bzz-btn-ghost" id="btn-stop-listening" style="display:none">⏸ Dừng</button>
			</div>
			<div id="listener-status-container" style="display:none;margin-top:10px"></div>
			<div id="listener-results-container" style="display:none;margin-top:10px">
				<div style="background:#f8fafc;border:1px solid #e4e6eb;border-radius:8px;padding:14px">
					<strong style="font-size:13px">Dữ liệu nhận được:</strong>
					<div id="listener-results-content" style="margin-top:8px"></div>
				</div>
			</div>
			<div style="background:#e7f5ff;border-left:3px solid #0068ff;padding:10px 14px;margin-top:12px;border-radius:4px;font-size:12px;color:#374151">
				<strong>Hướng dẫn:</strong> Chọn bot → bấm <em>Bắt đầu nghe</em> → mở Zalo gửi tin nhắn đến bot → dữ liệu webhook hiển thị tự động (auto-stop sau 5 phút).
			</div>
		</div>

		<!-- sendMessage -->
		<div class="bzz-section">
			<h3>💬 sendMessage — Gửi tin nhắn text</h3>
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
				<div>
					<div class="bzz-field">
						<label class="bzz-label" for="send-message-chat-id">Chat ID / User ID</label>
						<div style="display:flex;gap:8px">
							<select id="send-message-user-select" class="bzz-select" style="width:200px;flex-shrink:0">
								<option value="">-- Chọn từ danh sách --</option>
							</select>
							<input type="text" id="send-message-chat-id" class="bzz-input" placeholder="hoặc nhập thủ công" />
						</div>
					</div>
					<div class="bzz-field">
						<label class="bzz-label" for="send-message-text">Nội dung</label>
						<textarea id="send-message-text" class="bzz-textarea" rows="3">Hello</textarea>
					</div>
					<button type="button" class="bzz-btn bzz-btn-primary" id="btn-send-message">📤 Gửi tin nhắn</button>
					<div id="send-message-result" style="margin-top:10px"></div>
				</div>

				<!-- sendPhoto -->
				<div>
					<div class="bzz-field">
						<label class="bzz-label" for="send-photo-chat-id">Chat ID (ảnh)</label>
						<div style="display:flex;gap:8px">
							<select id="send-photo-user-select" class="bzz-select" style="width:200px;flex-shrink:0">
								<option value="">-- Chọn từ danh sách --</option>
							</select>
							<input type="text" id="send-photo-chat-id" class="bzz-input" placeholder="hoặc nhập thủ công" />
						</div>
					</div>
					<div class="bzz-field">
						<label class="bzz-label" for="send-photo-url">Photo URL</label>
						<input type="url" id="send-photo-url" class="bzz-input" value="https://placehold.co/600x400" />
					</div>
					<div class="bzz-field">
						<label class="bzz-label" for="send-photo-caption">Caption</label>
						<textarea id="send-photo-caption" class="bzz-textarea" rows="2">My photo</textarea>
					</div>
					<button type="button" class="bzz-btn bzz-btn-primary" id="btn-send-photo">🖼 Gửi ảnh</button>
					<div id="send-photo-result" style="margin-top:10px"></div>
				</div>
			</div>
		</div>

		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- ─────────────── TAB 4: LOGS & MEMORY ─────────────── -->
	<?php if ( $active_tab === 'logs' ) : ?>
	<div class="bzz-scroll">

		<!-- Memory builder -->
		<div class="bzz-section">
			<h3>🧠 Xây dựng Ký ức (AI)</h3>
			<p style="font-size:13px;color:#65676b;margin:0 0 12px">Phân tích logs bằng LLM để trích xuất ký ức dài hạn về người dùng (sở thích, vấn đề, mục tiêu).</p>
			<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
				<div class="bzz-field">
					<label class="bzz-label" for="memory-bot-select">Bot</label>
					<select id="memory-bot-select" class="bzz-select" style="width:200px">
						<option value="0">Tất cả Bot</option>
						<?php foreach ( $all_bots as $bot ) : ?>
							<option value="<?php echo esc_attr( $bot->id ); ?>"><?php echo esc_html( $bot->bot_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="bzz-field">
					<label class="bzz-label" for="memory-limit">Số logs</label>
					<input type="number" id="memory-limit" class="bzz-input" value="100" min="10" max="500" style="width:90px" />
				</div>
				<button type="button" class="bzz-btn bzz-btn-primary" id="btn-build-memory">🔍 Phân tích</button>
			</div>
			<div id="memory-build-result" style="margin-top:10px"></div>
		</div>

		<!-- Log filter -->
		<div class="bzz-section" style="margin-bottom:10px">
			<h3>🔍 Lọc nhật ký</h3>
			<form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
				<input type="hidden" name="page" value="bizcity-zalo-bot-dashboard" />
				<input type="hidden" name="tab" value="logs" />
				<div class="bzz-field" style="margin:0">
					<label class="bzz-label">Chọn Bot</label>
					<select name="bot_id" id="log-bot-filter" class="bzz-select" style="width:220px" onchange="this.form.submit()">
						<option value="">Tất cả Bot</option>
						<?php foreach ( $all_bots as $bot ) : ?>
							<option value="<?php echo esc_attr( $bot->id ); ?>" <?php selected( $log_bot_id_filter, $bot->id ); ?>>
								<?php echo esc_html( $bot->bot_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<button type="submit" class="bzz-btn bzz-btn-ghost">Lọc</button>
			</form>
		</div>

		<!-- Logs table -->
		<div class="bzz-card">
			<h2>📋 Nhật ký sự kiện <span class="bzz-pill bzz-pill-blue"><?php echo count( $logs ); ?> bản ghi</span></h2>
			<div class="bzz-card-body">
			<?php if ( empty( $logs ) ) : ?>
				<div class="bzz-empty">Chưa có sự kiện nào. Bot nhận webhook từ Zalo thì sẽ ghi lại ở đây.</div>
			<?php else : ?>
				<table class="bzz-table">
					<thead>
						<tr>
							<th style="width:120px">Thời gian</th>
							<th style="width:50px">Bot</th>
							<th style="width:160px">Sự kiện</th>
							<th style="width:140px">Client ID</th>
							<th style="width:100px">Tên</th>
							<th>Tin nhắn</th>
							<th style="width:80px">Chi tiết</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td><?php echo esc_html( date( 'd/m/y H:i:s', strtotime( $log->created_at ) ) ); ?></td>
							<td><code><?php echo esc_html( $log->bot_id ); ?></code></td>
							<td><code style="font-size:10px"><?php echo esc_html( $log->event_name ); ?></code></td>
							<td><code style="font-size:10px;word-break:break-all"><?php echo esc_html( $log->client_id ?: $log->user_id ?: '-' ); ?></code></td>
							<td><?php echo esc_html( $log->display_name ?: '-' ); ?></td>
							<td><?php echo esc_html( mb_substr( (string) ( $log->text ?: '-' ), 0, 60 ) ); ?></td>
							<td><details><summary style="cursor:pointer;font-size:11px;color:#0068ff">JSON</summary><pre style="font-size:10px;max-height:200px;overflow:auto;margin:4px 0 0;background:#f8fafc;padding:8px;border-radius:4px"><?php echo esc_html( $log->event_data ); ?></pre></details></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			</div>
		</div>

	</div>
	<?php endif; ?>

</div><!-- .bzz-wrap -->
