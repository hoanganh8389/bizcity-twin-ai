<?php
/**
 * BizCity Memory — Unified Settings Admin Page (Wave 2.8d D6.7).
 *
 * Standalone admin page (under `bizcity-knowledge` parent) cung cấp:
 *   1. Toggle ON/OFF cho `bizcity_memory_unified_enabled` (via option, không
 *      hardcode filter để founder/team có thể flip an toàn).
 *   2. Status panel hiển thị:
 *      - Flag effective state (option vs filter override).
 *      - Unified table existence + row count.
 *      - 5 legacy table existence + row count (`bizcity_memory_users` /
 *        `bizcity_memory_episodic` / `bizcity_memory_rolling` /
 *        `bizcity_memory_session` / `bizcity_memory_notes`).
 *      - D7 readiness checklist (dual-write parity, recall parity, staging
 *        duration, founder sign-off).
 *   3. Link nhanh sang 2 probe D5e + D6 trong Diagnostics page.
 *
 * KHÔNG include phần drop tables — đó là job của D7 migration script,
 * chạy qua wp-cli hoặc Site Provisioner để có audit trail.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Memory
 * @since      Wave 2.8d D6.7 (2026-05-24)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Memory_Unified_Admin' ) ) {
	return;
}

class BizCity_Memory_Unified_Admin {

	const SLUG       = 'bizcity-memory-unified';
	const NONCE_KEY  = 'bizcity_memory_unified_toggle';
	const TS_OPTION  = 'bizcity_memory_unified_enabled_at'; // timestamp khi enable

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',                              [ $this, 'register_menu' ], 30 );
		add_action( 'admin_post_bizcity_memory_unified_save',  [ $this, 'handle_save' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'bizcity-knowledge',
			__( 'Memory Unified', 'bizcity-twin-ai' ),
			'🧬 ' . __( 'Memory Unified', 'bizcity-twin-ai' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bizcity-twin-ai' ) );
		}
		check_admin_referer( self::NONCE_KEY );

		$enable = isset( $_POST['memory_unified_enabled'] )
		       && ( $_POST['memory_unified_enabled'] === '1' );

		update_option( BizCity_Memory_Unified_Installer::FLAG_OPTION, $enable ? '1' : '0' );

		if ( $enable ) {
			// Record timestamp of enable for "staging duration" badge.
			if ( ! get_option( self::TS_OPTION ) ) {
				update_option( self::TS_OPTION, time() );
			}
			// Force installer to create table NOW if missing.
			if ( class_exists( 'BizCity_Memory_Unified_Installer' ) ) {
				BizCity_Memory_Unified_Installer::instance()->maybe_install();
			}
		}

		wp_safe_redirect( add_query_arg(
			[ 'page' => self::SLUG, 'saved' => $enable ? 'on' : 'off' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bizcity-twin-ai' ) );
		}
		global $wpdb;

		$opt_value      = get_option( BizCity_Memory_Unified_Installer::FLAG_OPTION, '0' );
		$opt_enabled    = ( $opt_value === '1' || $opt_value === 1 );
		$effective      = BizCity_Memory_Unified_Installer::is_enabled();
		$filter_active  = $effective !== $opt_enabled;

		$unified_tbl    = BizCity_Memory_Unified_Installer::table();
		$unified_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $unified_tbl ) ) === $unified_tbl );
		$unified_rows   = $unified_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$unified_tbl}" ) : 0;

		$legacy_tables = [
			'bizcity_memory_users'    => 'User memory (facts/preferences)',
			'bizcity_memory_episodic' => 'Episodic (habits/sessions)',
			'bizcity_memory_rolling'  => 'Rolling (active/completed)',
			'bizcity_memory_session'  => 'Session (short-term)',
			'bizcity_memory_notes'    => 'Notes (pinned messages)',
		];
		$legacy_status = [];
		foreach ( $legacy_tables as $suffix => $label ) {
			$tbl = $wpdb->prefix . $suffix;
			$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
			$legacy_status[ $suffix ] = [
				'label'  => $label,
				'table'  => $tbl,
				'exists' => $exists,
				'rows'   => $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" ) : 0,
			];
		}

		$enabled_at      = (int) get_option( self::TS_OPTION, 0 );
		$staging_days    = ( $opt_enabled && $enabled_at > 0 ) ? floor( ( time() - $enabled_at ) / DAY_IN_SECONDS ) : 0;
		$staging_ready   = ( $staging_days >= 7 ); // 1 sprint = 7d
		$saved           = isset( $_GET['saved'] ) ? sanitize_key( (string) $_GET['saved'] ) : '';
		$diagnostics_url = admin_url( 'admin.php?page=bizcity-diagnostics' );
		?>
		<div class="wrap">
			<h1>🧬 <?php esc_html_e( 'Memory Unified — Wave 2.8d D6.7', 'bizcity-twin-ai' ); ?></h1>

			<?php if ( $saved === 'on' ) : ?>
				<div class="notice notice-success is-dismissible"><p><strong>✅ Flag turned ON.</strong> Unified table installed if missing. Dual-write + unified recall now active.</p></div>
			<?php elseif ( $saved === 'off' ) : ?>
				<div class="notice notice-warning is-dismissible"><p><strong>⚠️ Flag turned OFF.</strong> Reverted to legacy memory tables. Staging timer reset on next enable.</p></div>
			<?php endif; ?>

			<?php if ( $filter_active ) : ?>
				<div class="notice notice-info">
					<p>ℹ️ <strong>Filter override active:</strong> code đã `add_filter('bizcity_memory_unified_enabled', …)` → effective state khác option. Option = <code><?php echo $opt_enabled ? '1' : '0'; ?></code>, effective = <code><?php echo $effective ? '1' : '0'; ?></code>.</p>
				</div>
			<?php endif; ?>

			<div style="display:grid; grid-template-columns: 1fr 1fr; gap:24px; margin-top:20px;">

				<!-- Toggle card -->
				<div class="card" style="padding:20px; max-width:none;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Feature flag', 'bizcity-twin-ai' ); ?></h2>
					<p>Bật flag để kích hoạt:</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><strong>Installer:</strong> tạo bảng <code><?php echo esc_html( $unified_tbl ); ?></code> nếu chưa có.</li>
						<li><strong>Dual-write:</strong> mọi <code>upsert_public()</code> + Notes mirror ghi đồng thời sang bảng unified.</li>
						<li><strong>Read cutover:</strong> <code>BizCity_Smart_Gateway::collect_context()</code> route qua <code>BizCity_TwinBrain_Memory_Recall</code> thay vì 3 legacy classes (D6.5 patches).</li>
					</ul>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bizcity_memory_unified_save" />
						<?php wp_nonce_field( self::NONCE_KEY ); ?>
						<label style="display:flex; align-items:center; gap:8px; font-size:15px; margin:16px 0;">
							<input type="checkbox" name="memory_unified_enabled" value="1" <?php checked( $opt_enabled, true ); ?> />
							<span><?php esc_html_e( 'Enable unified memory (dual-write + unified recall)', 'bizcity-twin-ai' ); ?></span>
						</label>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save flag', 'bizcity-twin-ai' ); ?></button>
					</form>

					<?php if ( $opt_enabled ) : ?>
						<hr style="margin:20px 0;" />
						<p><strong>Staging timer:</strong>
							<?php if ( $enabled_at > 0 ) : ?>
								Enabled
								<?php echo esc_html( human_time_diff( $enabled_at, time() ) ); ?>
								<?php esc_html_e( 'ago', 'bizcity-twin-ai' ); ?>
								(<?php echo (int) $staging_days; ?> days)
								<?php if ( $staging_ready ) : ?>
									<span style="color:#46b450; font-weight:600;">✅ ≥ 1 sprint — D7 OK</span>
								<?php else : ?>
									<span style="color:#ffb900; font-weight:600;">⏳ Cần thêm <?php echo (int) ( 7 - $staging_days ); ?>d trước D7</span>
								<?php endif; ?>
							<?php else : ?>
								— (chưa ghi timestamp)
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>

				<!-- Unified table status -->
				<div class="card" style="padding:20px; max-width:none;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Unified table status', 'bizcity-twin-ai' ); ?></h2>
					<table class="widefat striped" style="margin-top:8px;">
						<thead><tr><th>Table</th><th>Exists</th><th>Rows</th></tr></thead>
						<tbody>
							<tr style="background:#f0f6fc;">
								<td><code><?php echo esc_html( $unified_tbl ); ?></code></td>
								<td><?php echo $unified_exists ? '✅' : '❌'; ?></td>
								<td><?php echo (int) $unified_rows; ?></td>
							</tr>
							<?php foreach ( $legacy_status as $row ) : ?>
								<tr>
									<td>
										<code><?php echo esc_html( $row['table'] ); ?></code><br />
										<small style="color:#666;"><?php echo esc_html( $row['label'] ); ?></small>
									</td>
									<td><?php echo $row['exists'] ? '✅ legacy' : '⚪'; ?></td>
									<td><?php echo (int) $row['rows']; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p style="margin-top:12px; color:#666;">
						Sau khi flag ON ≥ 1 sprint + 2 probes PASS, founder ký xác nhận → run D7 migration để drop 5 bảng legacy.
					</p>
				</div>

				<!-- D7 readiness checklist -->
				<div class="card" style="padding:20px; max-width:none; grid-column: 1 / -1;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'D7 readiness checklist', 'bizcity-twin-ai' ); ?></h2>
					<ul style="font-size:14px; line-height:1.8;">
						<li><?php echo $unified_exists ? '✅' : '❌'; ?> Unified table installed (<code><?php echo esc_html( $unified_tbl ); ?></code>)</li>
						<li><?php echo $opt_enabled ? '✅' : '⏳'; ?> Flag ON ở môi trường hiện tại</li>
						<li><?php echo $staging_ready ? '✅' : '⏳'; ?> Flag ON ≥ 1 sprint (7 days) — current <?php echo (int) $staging_days; ?>d</li>
						<li>⏳ Probe <code>core.memory.unified.dual-write-parity</code> PASS — <a href="<?php echo esc_url( $diagnostics_url ); ?>">mở Diagnostics ↗</a></li>
						<li>⏳ Probe <code>core.memory.unified.recall-parity</code> PASS (overlap ≥ 95%)</li>
						<li>⏳ Founder sign-off explicitly (chat / commit message)</li>
						<li>⏳ D7 dry-run script reviewed (chưa ship)</li>
					</ul>
				</div>

			</div>
		</div>
		<?php
	}
}

BizCity_Memory_Unified_Admin::instance();
