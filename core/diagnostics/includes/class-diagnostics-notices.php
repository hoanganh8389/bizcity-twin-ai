<?php
/**
 * Diagnostics Notices — surfaces soft-guard banners in wp-admin so operators
 * see schema gaps immediately instead of fishing through error_log.
 *
 * Soft-guard convention: any module that bails out because a required table
 * is missing sets a transient `bizcity_<feature>_table_missing` carrying
 * `[ 'table' => …, 'blog_id' => …, 'at' => unix_ts ]`. This class scans a
 * known list of transients + the live inspector snapshot and renders a single
 * banner consolidating all current issues.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-05-20
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Diagnostics_Notices {

	private static $instance = null;

	/**
	 * Transient keys that modules set when they detect a missing-table error.
	 *
	 * Naming convention: `bizcity_<module>_table_missing` where `<module>` is
	 * the module slug with dots → underscores (e.g. `research.rest` →
	 * `research_rest`). New modules can either be added here OR appear via the
	 * `bizcity_diagnostics_soft_guard_transients` filter (see render_notices).
	 */
	const SOFT_GUARD_TRANSIENTS = [
		'bizcity_scheduler_table_missing',
		'bizcity_learning_table_missing',
		'bizcity_studio_table_missing',
		// PHASE-0.41 Lát 4 — modules wired into the Error Reporter trait.
		'bizcity_research_table_missing',
		'bizcity_research_rest_table_missing',
		'bizcity_intent_table_missing',
		'bizcity_channel_table_missing',
		'bizcity_twinbrain_table_missing',
		'bizcity_twinbrain_rest_table_missing',
		'bizcity_twinchat_sources_table_missing',
		'bizcity_kg_source_progress_table_missing',
	];

	public static function instance(): self {
		return self::$instance ?: ( self::$instance = new self() );
	}

	private function __construct() {
		add_action( 'admin_notices', [ $this, 'render_notices' ] );

		// Phase 0.41 L9.a T5d — Critical-regression banner (Health Check entry).
		add_action( 'admin_notices', [ $this, 'render_critical_regression_notice' ] );

		// Dismiss handler: ?bizcity_diag_dismiss_critical=1&_wpnonce=...
		add_action( 'admin_init', [ $this, 'maybe_handle_dismiss' ] );

		// Allow callers to push a soft-guard report via action:
		//   do_action( 'bizcity_diagnostics_notice', $key, [...] );
		add_action( 'bizcity_diagnostics_notice', [ $this, 'capture' ], 10, 2 );
	}

	public function capture( string $key, array $data = [] ): void {
		// Normalize module slugs like "research.rest" → "research_rest" so
		// the resulting transient key is sanitize_key-safe and discoverable
		// via the SOFT_GUARD_TRANSIENTS list / filter.
		$slug = str_replace( [ '.', '-', ' ' ], '_', strtolower( $key ) );
		$key  = sanitize_key( 'bizcity_' . $slug . '_table_missing' );
		set_transient( $key, $data + [ 'at' => time() ], HOUR_IN_SECONDS );
	}

	public function render_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$messages = [];

		/**
		 * Allow modules to register additional soft-guard transient keys
		 * without editing this class.
		 *
		 * @param string[] $keys Array of full transient keys.
		 */
		$keys = (array) apply_filters(
			'bizcity_diagnostics_soft_guard_transients',
			self::SOFT_GUARD_TRANSIENTS
		);

		foreach ( $keys as $k ) {
			$data = get_transient( $k );
			if ( ! is_array( $data ) || empty( $data['table'] ) ) {
				continue;
			}
			$messages[] = sprintf(
				/* translators: 1: table name, 2: blog id, 3: relative time */
				esc_html__( 'BizCity Diagnostics: bảng %1$s không tồn tại trên shard hiện tại (blog %2$d). Phát hiện %3$s.', 'bizcity-twin-ai' ),
				'<code>' . esc_html( $data['table'] ) . '</code>',
				(int) ( $data['blog_id'] ?? 0 ),
				esc_html( human_time_diff( (int) $data['at'], time() ) . ' trước' )
			);
		}

		if ( ! $messages ) {
			return;
		}

		$url = admin_url( 'tools.php?page=bizcity-diagnostics' );
		echo '<div class="notice notice-warning"><p><strong>⚠️ BizCity Diagnostics</strong></p><ul style="margin-left:18px;list-style:disc">';
		foreach ( $messages as $m ) {
			echo '<li>' . wp_kses_post( $m ) . '</li>';
		}
		echo '</ul><p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Mở bảng chẩn đoán', 'bizcity-twin-ai' ) . '</a></p></div>';
	}

	/**
	 * Phase 0.41 L9.a T5d — Critical-regression admin banner.
	 *
	 * Surfaces a top-level wp-admin notice when any critical-tagged table is
	 * missing on the current shard, encouraging the operator to open the
	 * Health-Check Wizard (FE on TwinChat) or the Smoke-Test tab (BE here).
	 *
	 * Throttle: per-user dismissal stored in user_meta with 24h cap so a
	 * dismissed banner re-appears the next day if regression persists.
	 */
	public function render_critical_regression_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! class_exists( 'BizCity_Diagnostics_Table_Inspector' ) ) {
			return;
		}

		// Dismissal cap — re-show 24h after last dismissal.
		$dismiss_key = 'bizcity_diag_critical_dismissed_at';
		$last        = (int) get_user_meta( get_current_user_id(), $dismiss_key, true );
		if ( $last && ( time() - $last ) < DAY_IN_SECONDS ) {
			return;
		}

		// Quickly bail if a soft-guard already rendered above (avoid duplicate).
		// We only want the entry-point banner; details live in the diagnostics page.
		$rows = BizCity_Diagnostics_Table_Inspector::inspect_all();
		$critical_missing = [];
		foreach ( $rows as $r ) {
			if ( ! empty( $r['critical'] ) && empty( $r['exists'] ) ) {
				$critical_missing[] = $r['physical'];
			}
		}
		if ( ! $critical_missing ) {
			return;
		}

		$diag_url = admin_url( 'tools.php?page=bizcity-diagnostics#smoke-test' );
		$dismiss_url = wp_nonce_url(
			add_query_arg( [ 'bizcity_diag_dismiss_critical' => '1' ], admin_url() ),
			'bizcity_diag_dismiss_critical'
		);
		$count = count( $critical_missing );
		$preview = implode( ', ', array_slice( $critical_missing, 0, 3 ) );
		if ( $count > 3 ) {
			$preview .= sprintf( ' (+%d)', $count - 3 );
		}
		?>
		<div class="notice notice-error" style="border-left-color:#b32d2e">
			<p style="margin:8px 0">
				<strong>🩺 <?php esc_html_e( 'BizCity Health Check — Critical regression detected', 'bizcity-twin-ai' ); ?></strong><br>
				<?php
				printf(
					/* translators: 1: count, 2: comma list */
					esc_html__( '%1$d bảng critical đang thiếu trên shard hiện tại: %2$s', 'bizcity-twin-ai' ),
					(int) $count,
					'<code>' . esc_html( $preview ) . '</code>'
				);
				?>
			</p>
			<p style="margin:8px 0">
				<a class="button button-primary" href="<?php echo esc_url( $diag_url ); ?>">
					▶ <?php esc_html_e( 'Run Health Check (Smoke Test)', 'bizcity-twin-ai' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( $diag_url ); ?>" style="margin-left:6px">
					<?php esc_html_e( 'Xem chi tiết bảng thiếu', 'bizcity-twin-ai' ); ?>
				</a>
				<a class="button-link" href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:12px;color:#666">
					<?php esc_html_e( 'Ẩn 24h', 'bizcity-twin-ai' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle `?bizcity_diag_dismiss_critical=1&_wpnonce=...` dismissal.
	 */
	public function maybe_handle_dismiss(): void {
		if ( empty( $_GET['bizcity_diag_dismiss_critical'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? (string) $_GET['_wpnonce'] : '';
		if ( ! wp_verify_nonce( $nonce, 'bizcity_diag_dismiss_critical' ) ) {
			return;
		}
		update_user_meta( get_current_user_id(), 'bizcity_diag_critical_dismissed_at', time() );
		$ref = wp_get_referer() ?: admin_url();
		wp_safe_redirect( remove_query_arg( [ 'bizcity_diag_dismiss_critical', '_wpnonce' ], $ref ) );
		exit;
	}
}
