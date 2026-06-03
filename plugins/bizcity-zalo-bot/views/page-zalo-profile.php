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

	<?php if ( $tab === 'hotline' ) : ?>

		<div class="bzz-card">
			<h2>📞 Cấu hình Zalo Hotline (ZNS Template)</h2>
			<?php if ( ! empty( $hotline_account['name'] ) ) : ?>
				<p>Đang chỉnh sửa: <strong><?php echo esc_html( $hotline_account['name'] ); ?></strong></p>
			<?php endif; ?>

			<?php if ( empty( $hotline_settings ) ) : ?>
				<div class="bzz-status-warn">
					⚠ WAIC integration <code>WaicChannelIntegration_zalo_hotline</code> chưa load.
					Kiểm tra plugin <code>bizcity-admin-hook-zalo</code> đã active chưa.
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
