<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Cron
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * BizCity_Cron_Admin_Page — Phase 2 admin UI.
 *
 * Tools → BizCity Cron · liệt kê jobs trong registry, last/next run, recent
 * runs (20 row gần nhất / job), retry queue, và nút "Run Now" (POST + nonce).
 *
 * Mọi action đi qua admin-post.php?action=bizcity_cron_run_now (capability
 * `manage_options` + nonce) — KHÔNG dùng GET để side-effect.
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Cron_Admin_Page {

	const MENU_SLUG    = 'bizcity-cron';
	const NONCE_ACTION = 'bizcity_cron_run_now';
	const ACTION_NAME  = 'bizcity_cron_run_now';
	// [2026-07-27 Johnny Chu] CRON-OVERLOAD-OPTIMIZE — quick toggles form action.
	const TOGGLES_NONCE_ACTION = 'bizcity_cron_quick_toggles_save';
	const TOGGLES_ACTION_NAME  = 'bizcity_cron_quick_toggles_save';

	public static function register(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_post_' . self::ACTION_NAME, [ __CLASS__, 'handle_run_now' ] );
		add_action( 'admin_post_' . self::TOGGLES_ACTION_NAME, [ __CLASS__, 'handle_quick_toggles_save' ] );
	}

	/**
	 * Quick cron toggles exposed in Tools > BizCity Cron.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function quick_toggle_specs(): array {
		return array(
			array(
				'option'  => 'bizcity_content_scheduler_cron_enabled',
				'label'   => 'Content Scheduler tick',
				'hook'    => 'bizcity_content_scheduler_tick',
				'default' => 0,
				'note'    => 'core/content-ops queue worker',
			),
			array(
				'option'  => 'bizcity_cg_broadcast_tick_enabled',
				'label'   => 'Channel Gateway broadcast tick',
				'hook'    => 'bizcity_cg_broadcast_tick',
				'default' => 0,
				'note'    => 'core/channel-gateway broadcast dispatcher',
			),
			array(
				'option'  => 'bizcity_scheduler_hil_timeout_enabled',
				'label'   => 'Scheduler HIL timeout sweep',
				'hook'    => 'bizcity_scheduler_hil_timeout',
				'default' => 0,
				'note'    => 'core/scheduler human-in-loop timeout cancel',
			),
			array(
				'option'  => 'bizcity_legacy_twf_task_reminder_enabled',
				'label'   => 'Legacy TWF task reminder',
				'hook'    => 'twf_check_biztask_reminder',
				'default' => 0,
				'note'    => 'core/helper-legacy flow compatibility',
			),
			array(
				'option'  => 'bizcity_shard_metrics_singleton_enabled',
				'label'   => 'Shard metrics singleton',
				'hook'    => 'bizcity_shard_metrics_check',
				'default' => 1,
				'note'    => 'MU monitoring: main-site-only 30-minute schedule',
			),
		);
	}

	public static function add_menu(): void {
		add_management_page(
			__( 'BizCity Cron', 'bizcity-twin-ai' ),
			__( 'BizCity Cron', 'bizcity-twin-ai' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render' ]
		);
	}

	public static function handle_run_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bizcity-twin-ai' ), 403 );
		}
		$raw    = isset( $_POST['job_id'] ) ? wp_unslash( $_POST['job_id'] ) : '';
		// job_id allows letters, digits, dot, underscore, hyphen (NOT sanitize_key which strips dots).
		$job_id = (string) preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $raw );
		check_admin_referer( self::NONCE_ACTION . '_' . $job_id );

		$res = BizCity_Cron_Manager::instance()->run_now( $job_id );
		$qs  = [
			'page'        => self::MENU_SLUG,
			'run_job'     => $job_id,
			'run_ok'      => $res['ok'] ? 1 : 0,
			'run_ms'      => (int) $res['duration_ms'],
		];
		if ( ! $res['ok'] ) {
			$qs['run_err'] = rawurlencode( mb_substr( (string) $res['error'], 0, 200 ) );
		}
		wp_safe_redirect( add_query_arg( $qs, admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Save quick toggles from the admin page.
	 */
	public static function handle_quick_toggles_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bizcity-twin-ai' ), 403 );
		}
		check_admin_referer( self::TOGGLES_NONCE_ACTION );

		$posted = array();
		if ( isset( $_POST['cron_toggle'] ) && is_array( $_POST['cron_toggle'] ) ) {
			$posted = wp_unslash( $_POST['cron_toggle'] );
		}

		foreach ( self::quick_toggle_specs() as $spec ) {
			$option  = (string) ( $spec['option'] ?? '' );
			$enabled = ! empty( $posted[ $option ] ) ? 1 : 0;
			if ( $option === '' ) {
				continue;
			}
			update_option( $option, $enabled, false );

			// [2026-07-27 Johnny Chu] CRON-OVERLOAD-OPTIMIZE — clear stale schedule immediately when turning off.
			$hook = (string) ( $spec['hook'] ?? '' );
			if ( ! $enabled && $hook !== '' ) {
				wp_clear_scheduled_hook( $hook );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                => self::MENU_SLUG,
					'cron_toggles_saved'  => '1',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$mgr  = BizCity_Cron_Manager::instance();
		$jobs = $mgr->all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BizCity Cron — Registry & Runs', 'bizcity-twin-ai' ); ?></h1>

			<?php if ( ! empty( $_GET['run_job'] ) ) :
				$ok = ! empty( $_GET['run_ok'] );
				$ms = isset( $_GET['run_ms'] ) ? (int) $_GET['run_ms'] : 0;
				$err = isset( $_GET['run_err'] ) ? sanitize_text_field( wp_unslash( $_GET['run_err'] ) ) : '';
				?>
				<div class="notice notice-<?php echo $ok ? 'success' : 'error'; ?> is-dismissible">
					<p>
						<strong><?php echo $ok ? '✓' : '✗'; ?>
						Run <code><?php echo esc_html( (string) preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) wp_unslash( $_GET['run_job'] ) ) ); ?></code></strong>
						· <?php echo esc_html( $ms ); ?>ms
						<?php if ( $err !== '' ) : ?>· <?php echo esc_html( $err ); ?><?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $_GET['cron_toggles_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><strong>✓</strong> <?php esc_html_e( 'Quick toggles updated.', 'bizcity-twin-ai' ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: %s: db version */
					esc_html__( 'DB version: %s · See core/cron/PHASE-CRON.md', 'bizcity-twin-ai' ),
					esc_html( (string) get_option( BizCity_Cron_Manager::DB_VERSION_OPTION, '—' ) )
				);
				?>
			</p>

			<?php self::render_quick_toggles_section(); ?>

			<h2><?php esc_html_e( 'Registered jobs', 'bizcity-twin-ai' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Job ID', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Owner', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Hook · Interval', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Next run', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Last run', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Last status', 'bizcity-twin-ai' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $jobs ) ) : ?>
					<tr><td colspan="7"><em><?php esc_html_e( 'No jobs registered yet. Modules need to call BizCity_Cron_Manager::instance()->register([…]).', 'bizcity-twin-ai' ); ?></em></td></tr>
				<?php else : foreach ( $jobs as $j ) :
					$next = $j['next_run_at'] ? human_time_diff( time(), $j['next_run_at'] ) : '—';
					$last = $j['last_run_at'] ? human_time_diff( $j['last_run_at'], time() ) . ' ago' : '—';
					$badge = $j['last_status'] === 'ok' ? 'success' : ( $j['last_status'] === 'error' ? 'error' : 'warning' );
					?>
					<tr>
						<td><code><?php echo esc_html( $j['job_id'] ); ?></code><br><small><?php echo esc_html( $j['description'] ); ?></small></td>
						<td><?php echo esc_html( $j['owner'] ); ?></td>
						<td><code><?php echo esc_html( $j['hook'] ); ?></code><br><small><?php echo esc_html( $j['interval_key'] ); ?></small></td>
						<td><?php echo esc_html( $next ); ?></td>
						<td><?php echo esc_html( $last ); ?></td>
						<td>
							<span class="notice notice-<?php echo esc_attr( $badge ); ?>" style="padding:2px 8px;margin:0">
								<?php echo esc_html( $j['last_status'] ?: '—' ); ?>
							</span>
							<?php if ( ! empty( $j['last_duration'] ) ) : ?>
								<br><small><?php echo (int) $j['last_duration']; ?>ms</small>
							<?php endif; ?>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<?php wp_nonce_field( self::NONCE_ACTION . '_' . $j['job_id'] ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_NAME ); ?>">
								<input type="hidden" name="job_id" value="<?php echo esc_attr( $j['job_id'] ); ?>">
								<button type="submit" class="button button-small"><?php esc_html_e( '▶ Run now', 'bizcity-twin-ai' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:24px"><?php esc_html_e( 'Retry queue (pending only)', 'bizcity-twin-ai' ); ?></h2>
			<?php self::render_retry_table(); ?>

			<h2 style="margin-top:24px"><?php esc_html_e( 'Recent runs (last 50, all jobs)', 'bizcity-twin-ai' ); ?></h2>
			<?php self::render_recent_runs(); ?>

			<h2 style="margin-top:24px">📋 <?php esc_html_e( 'JSONL File Logs', 'bizcity-twin-ai' ); ?></h2>
			<?php self::render_file_logs_section(); ?>
		</div>
		<?php
	}

	/**
	 * Render direct on-page toggles for selected cron jobs.
	 */
	private static function render_quick_toggles_section(): void {
		$specs = self::quick_toggle_specs();
		?>
		<h2><?php esc_html_e( 'Quick Toggle Settings', 'bizcity-twin-ai' ); ?></h2>
		<p><?php esc_html_e( 'Bật/tắt nhanh các cron đã tối ưu. Tắt sẽ clear scheduled hook ngay.', 'bizcity-twin-ai' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::TOGGLES_NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::TOGGLES_ACTION_NAME ); ?>">
			<table class="widefat striped" style="max-width:980px;">
				<thead><tr>
					<th><?php esc_html_e( 'Toggle', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Option Key', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Hook', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Next Run', 'bizcity-twin-ai' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $specs as $spec ) :
					$option   = (string) ( $spec['option'] ?? '' );
					$label    = (string) ( $spec['label'] ?? $option );
					$hook     = (string) ( $spec['hook'] ?? '' );
					$note     = (string) ( $spec['note'] ?? '' );
					$default  = isset( $spec['default'] ) ? (int) $spec['default'] : 0;
					$enabled  = (int) get_option( $option, $default );
					$next_ts  = $hook !== '' ? (int) wp_next_scheduled( $hook ) : 0;
					$next_txt = $next_ts > 0 ? human_time_diff( time(), $next_ts ) : '—';
					?>
					<tr>
						<td>
							<label>
								<input type="checkbox" name="cron_toggle[<?php echo esc_attr( $option ); ?>]" value="1" <?php checked( 1, $enabled ); ?>>
								<strong><?php echo esc_html( $label ); ?></strong>
							</label>
							<?php if ( $note !== '' ) : ?><br><small><?php echo esc_html( $note ); ?></small><?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $option ); ?></code></td>
						<td><code><?php echo esc_html( $hook ); ?></code></td>
						<td>
							<?php echo esc_html( $next_txt ); ?>
							<?php if ( $next_ts > 0 ) : ?><br><small><?php echo esc_html( gmdate( 'Y-m-d H:i:s', $next_ts ) . ' UTC' ); ?></small><?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin-top:10px;">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Quick Toggles', 'bizcity-twin-ai' ); ?></button>
			</p>
		</form>
		<?php
	}

	private static function render_retry_table(): void {
		global $wpdb;
		$t = $wpdb->prefix . BizCity_Cron_Manager::TABLE_RETRIES;
		$wpdb->suppress_errors( true );
		$rows = (array) $wpdb->get_results(
			"SELECT job_id, attempt, status, next_run_at, last_error FROM {$t} WHERE status IN ('pending','dead') ORDER BY next_run_at ASC LIMIT 50",
			ARRAY_A
		);
		$wpdb->suppress_errors( false );
		?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Job', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Attempt', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Status', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Next run', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Last error', 'bizcity-twin-ai' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="5"><em><?php esc_html_e( 'No retries pending. Good.', 'bizcity-twin-ai' ); ?></em></td></tr>
			<?php else : foreach ( $rows as $r ) : ?>
				<tr>
					<td><code><?php echo esc_html( $r['job_id'] ); ?></code></td>
					<td><?php echo (int) $r['attempt']; ?> / 3</td>
					<td><?php echo esc_html( $r['status'] ); ?></td>
					<td><?php echo esc_html( $r['next_run_at'] ); ?></td>
					<td><small><?php echo esc_html( (string) ( $r['last_error'] ?? '' ) ); ?></small></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_recent_runs(): void {
		// [2026-07-27 Johnny Chu] PHASE-0.50-CRON-FILELOG-PRIMARY — route
		// recent-runs through manager API (filelog-primary or SQL fallback).
		$rows = BizCity_Cron_Manager::instance()->recent_runs( '', 50 );
		?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Job', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Started', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Duration', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Status', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Error · Meta', 'bizcity-twin-ai' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="5"><em><?php esc_html_e( 'No runs recorded yet.', 'bizcity-twin-ai' ); ?></em></td></tr>
			<?php else : foreach ( $rows as $r ) :
				$meta_raw = (string) ( $r['meta'] ?? '' );
				$meta_pretty = '';
				if ( $meta_raw !== '' ) {
					$decoded = json_decode( $meta_raw, true );
					if ( is_array( $decoded ) ) {
						$meta_pretty = wp_json_encode(
							$decoded,
							JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
						);
					} else {
						$meta_pretty = $meta_raw;
					}
				}
				?>
				<tr>
					<td><code><?php echo esc_html( $r['job_id'] ); ?></code></td>
					<td><?php echo esc_html( $r['started_at'] ); ?></td>
					<td><?php echo $r['duration_ms'] === null ? '—' : (int) $r['duration_ms'] . 'ms'; ?></td>
					<td><?php echo esc_html( $r['status'] ); ?></td>
					<td>
						<?php if ( ! empty( $r['error'] ) ) : ?>
							<small style="color:#b32d2e;"><?php echo esc_html( (string) $r['error'] ); ?></small><br>
						<?php endif; ?>
						<?php if ( $meta_pretty !== '' ) : ?>
							<details>
								<summary style="cursor:pointer;color:#2271b1;"><?php esc_html_e( 'meta JSON', 'bizcity-twin-ai' ); ?></summary>
								<pre style="max-height:240px;overflow:auto;background:#f6f7f7;padding:6px;font-size:11px;margin:4px 0 0;"><?php echo esc_html( $meta_pretty ); ?></pre>
							</details>
						<?php elseif ( empty( $r['error'] ) ) : ?>
							<small>—</small>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the JSONL File Logs section — statistics + tail view.
	 * [2026-06-14 Johnny Chu] CRON-FILE-LOGGER — admin log viewer
	 */
	private static function render_file_logs_section(): void {
		if ( ! class_exists( 'BizCity_Cron_File_Logger' ) ) {
			echo '<p><em>' . esc_html__( 'BizCity_Cron_File_Logger not loaded.', 'bizcity-twin-ai' ) . '</em></p>';
			return;
		}

		$dates = BizCity_Cron_File_Logger::available_dates();
		// Pick selected date from ?log_date=YYYY-MM-DD, default today.
		$sel_date = isset( $_GET['log_date'] )
			? sanitize_text_field( wp_unslash( (string) $_GET['log_date'] ) )
			: 'today';
		$sel_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $sel_date ) ? $sel_date : 'today';

		$stats = BizCity_Cron_File_Logger::stats( $sel_date );
		$tail  = BizCity_Cron_File_Logger::tail( $sel_date, 100 );

		$actual_date = $sel_date === 'today' ? gmdate( 'Y-m-d' ) : $sel_date;
		?>
		<!-- Date picker -->
		<form method="get" style="margin-bottom:12px;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
			<label for="bz_log_date"><strong><?php esc_html_e( 'Chọn ngày:', 'bizcity-twin-ai' ); ?></strong></label>
			<select id="bz_log_date" name="log_date" onchange="this.form.submit()">
				<option value="today"><?php esc_html_e( 'Hôm nay', 'bizcity-twin-ai' ); ?> (<?php echo esc_html( gmdate( 'Y-m-d' ) ); ?>)</option>
				<?php foreach ( $dates as $d ) : ?>
					<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $sel_date, $d ); ?>><?php echo esc_html( $d ); ?></option>
				<?php endforeach; ?>
			</select>
			<noscript><input type="submit" value="Xem" class="button"></noscript>
		</form>

		<!-- Summary cards -->
		<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
			<?php
			$cards = array(
				array( 'label' => 'Tổng lần chạy',   'value' => $stats['total_runs'],      'color' => '#2271b1' ),
				array( 'label' => '✅ Thành công',    'value' => $stats['ok_count'],         'color' => '#1a8a34' ),
				array( 'label' => '❌ Lỗi',           'value' => $stats['error_count'],      'color' => '#b32d2e' ),
				array( 'label' => 'Avg duration',     'value' => $stats['avg_duration_ms'] . ' ms', 'color' => '#8a4f00' ),
				array( 'label' => 'P95 duration',     'value' => $stats['p95_duration_ms'] . ' ms', 'color' => '#6d3c99' ),
			);
			foreach ( $cards as $c ) : ?>
				<div style="background:#fff;border:1px solid #ddd;border-top:3px solid <?php echo esc_attr( $c['color'] ); ?>;border-radius:4px;padding:10px 16px;min-width:100px;">
					<div style="font-size:11px;color:#666;margin-bottom:4px;"><?php echo esc_html( $c['label'] ); ?></div>
					<div style="font-size:22px;font-weight:700;color:<?php echo esc_attr( $c['color'] ); ?>;"><?php echo esc_html( (string) $c['value'] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>

		<!-- Per-job breakdown -->
		<?php if ( ! empty( $stats['jobs'] ) ) : ?>
		<h3 style="margin-top:0"><?php esc_html_e( 'Theo job', 'bizcity-twin-ai' ); ?></h3>
		<table class="widefat striped" style="margin-bottom:16px">
			<thead><tr>
				<th><?php esc_html_e( 'Job ID', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Lần chạy', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( '✅ OK', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( '❌ Lỗi', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Avg ms', 'bizcity-twin-ai' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $stats['jobs'] as $jid => $jdata ) : ?>
				<tr>
					<td><code><?php echo esc_html( $jid ); ?></code></td>
					<td><?php echo (int) $jdata['runs']; ?></td>
					<td style="color:#1a8a34;"><?php echo (int) $jdata['ok']; ?></td>
					<td style="color:<?php echo (int) $jdata['error'] > 0 ? '#b32d2e' : '#999'; ?>;"><?php echo (int) $jdata['error']; ?></td>
					<td><?php echo (int) $jdata['avg_ms']; ?> ms</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<!-- Trigger breakdown -->
		<?php if ( ! empty( $stats['triggers'] ) ) : ?>
		<h3><?php esc_html_e( 'Triggers nhận được', 'bizcity-twin-ai' ); ?></h3>
		<table class="widefat striped" style="max-width:400px;margin-bottom:16px">
			<thead><tr><th><?php esc_html_e( 'Loại trigger', 'bizcity-twin-ai' ); ?></th><th><?php esc_html_e( 'Số lần', 'bizcity-twin-ai' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $stats['triggers'] as $trig => $cnt ) : ?>
				<tr><td><code><?php echo esc_html( $trig ); ?></code></td><td><?php echo (int) $cnt; ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<!-- Errors list -->
		<?php if ( ! empty( $stats['errors'] ) ) : ?>
		<h3 style="color:#b32d2e;"><?php esc_html_e( 'Các lỗi trong ngày', 'bizcity-twin-ai' ); ?></h3>
		<table class="widefat striped" style="margin-bottom:16px">
			<thead><tr>
				<th><?php esc_html_e( 'Run ID', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Job', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Thời điểm', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Lỗi', 'bizcity-twin-ai' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $stats['errors'] as $e ) : ?>
				<tr>
					<td>#<?php echo (int) $e['run_id']; ?></td>
					<td><code><?php echo esc_html( (string) $e['job_id'] ); ?></code></td>
					<td><?php echo esc_html( (string) $e['ts'] ); ?></td>
					<td style="color:#b32d2e;"><small><?php echo esc_html( (string) $e['error'] ); ?></small></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<!-- Raw JSONL tail -->
		<h3><?php printf( esc_html__( 'JSONL tail — %s (last 100 entries)', 'bizcity-twin-ai' ), esc_html( $actual_date ) ); ?></h3>
		<?php if ( empty( $tail ) ) : ?>
			<p><em><?php esc_html_e( 'Không có dữ liệu log cho ngày này.', 'bizcity-twin-ai' ); ?></em></p>
		<?php else : ?>
		<table class="widefat striped" style="font-size:12px">
			<thead><tr>
				<th><?php esc_html_e( 'Type', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Run ID', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Job', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Timestamp', 'bizcity-twin-ai' ); ?></th>
				<th><?php esc_html_e( 'Detail', 'bizcity-twin-ai' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( array_reverse( $tail ) as $row ) :
				$type    = (string) ( $row['type']   ?? '' );
				$run_id  = (int)    ( $row['run_id'] ?? 0 );
				$job_id  = (string) ( $row['job_id'] ?? '' );
				$ts      = (string) ( $row['ts']     ?? '' );
				$type_color = $type === 'end' && ( $row['status'] ?? '' ) === 'error' ? '#b32d2e' : ( $type === 'trigger' ? '#8a4f00' : '#2271b1' );
				$detail = '';
				if ( $type === 'end' ) {
					$ms = isset( $row['duration_ms'] ) ? (int) $row['duration_ms'] . ' ms' : '—';
					$st = (string) ( $row['status'] ?? '' );
					$detail = $st . ' · ' . $ms;
					if ( ! empty( $row['error'] ) ) { $detail .= ' · ' . esc_html( mb_substr( (string) $row['error'], 0, 80 ) ); }
				} elseif ( $type === 'trigger' ) {
					$detail = 'trigger=' . esc_html( (string) ( $row['trigger'] ?? '' ) );
					if ( ! empty( $row['platform'] ) ) { $detail .= ' · ' . esc_html( (string) $row['platform'] ); }
					if ( ! empty( $row['workflow_name'] ) ) { $detail .= ' · ' . esc_html( (string) $row['workflow_name'] ); }
				} elseif ( $type === 'start' ) {
					$detail = 'hook=' . esc_html( (string) ( $row['hook'] ?? '' ) );
				}
				?>
				<tr>
					<td><span style="color:<?php echo esc_attr( $type_color ); ?>;font-weight:600;"><?php echo esc_html( $type ); ?></span></td>
					<td>#<?php echo $run_id; ?></td>
					<td><code><?php echo esc_html( $job_id ); ?></code></td>
					<td style="white-space:nowrap;"><?php echo esc_html( $ts ); ?></td>
					<td><?php echo $detail; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<!-- GC info -->
		<p style="margin-top:8px;font-size:11px;color:#666;">
			<?php printf(
				esc_html__( 'File log tại: %s | Giữ %d ngày gần nhất | GC tự động hàng đêm', 'bizcity-twin-ai' ),
				esc_html( BizCity_Cron_File_Logger::log_dir() ?: '—' ),
				BizCity_Cron_File_Logger::KEEP_DAYS
			); ?>
		</p>
		<?php
	}
}
