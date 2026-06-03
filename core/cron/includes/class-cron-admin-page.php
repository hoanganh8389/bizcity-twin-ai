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

	public static function register(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_post_' . self::ACTION_NAME, [ __CLASS__, 'handle_run_now' ] );
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

			<p>
				<?php
				printf(
					/* translators: %s: db version */
					esc_html__( 'DB version: %s · See core/cron/PHASE-CRON.md', 'bizcity-twin-ai' ),
					esc_html( (string) get_option( BizCity_Cron_Manager::DB_VERSION_OPTION, '—' ) )
				);
				?>
			</p>

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
		</div>
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
		global $wpdb;
		$t = $wpdb->prefix . BizCity_Cron_Manager::TABLE_RUNS;
		$wpdb->suppress_errors( true );
		$rows = (array) $wpdb->get_results(
			"SELECT job_id, started_at, ended_at, duration_ms, status, error, meta FROM {$t} ORDER BY id DESC LIMIT 50",
			ARRAY_A
		);
		$wpdb->suppress_errors( false );
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
}
