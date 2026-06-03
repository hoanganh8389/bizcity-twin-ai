<?php
/**
 * BizCity Diagnostics — Admin Dashboard Widget (Phase 0.41 L9.e).
 *
 * Surfaces health-at-a-glance on the standard WP wp-admin/index.php dashboard:
 *   - Critical missing tables count.
 *   - Last smoke-test pass/fail aggregate + age.
 *   - CTAs → Diagnostics page + "Run health check".
 *
 * Persisted state:
 *   option `bizcity_diag_last_smoke` = [ ts:int, pass:int, fail:int, skipped:int, duration_ms:int ]
 *     (written by Smoke_Runner::run_all() at the end of every run).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-05-21 (Phase 0.41 L9.e)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Diagnostics_Dashboard_Widget {

	public const LAST_SMOKE_OPTION = 'bizcity_diag_last_smoke';

	private static $instance = null;

	public static function instance(): self {
		return self::$instance ?: ( self::$instance = new self() );
	}

	private function __construct() {
		add_action( 'wp_dashboard_setup', [ $this, 'register' ] );
	}

	public function register(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'bizcity_diag_health',
			'🩺 ' . __( 'BizCity Health', 'bizcity-twin-ai' ),
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		$crit_missing = 0;
		$total_missing = 0;
		if ( class_exists( 'BizCity_Diagnostics_Table_Inspector' ) ) {
			$rows = BizCity_Diagnostics_Table_Inspector::inspect_all();
			foreach ( $rows as $r ) {
				if ( empty( $r['exists'] ) ) {
					$total_missing++;
					if ( ! empty( $r['critical'] ) ) {
						$crit_missing++;
					}
				}
			}
		}

		$last  = get_option( self::LAST_SMOKE_OPTION, [] );
		$ts    = isset( $last['ts'] ) ? (int) $last['ts'] : 0;
		$pass  = isset( $last['pass'] ) ? (int) $last['pass'] : 0;
		$fail  = isset( $last['fail'] ) ? (int) $last['fail'] : 0;
		$skip  = isset( $last['skipped'] ) ? (int) $last['skipped'] : 0;
		$age_d = $ts ? floor( ( time() - $ts ) / DAY_IN_SECONDS ) : null;

		$diag_url = admin_url( 'tools.php?page=bizcity-diagnostics#smoke-test' );
		$run_url  = wp_nonce_url(
			add_query_arg(
				[ 'page' => 'bizcity-diagnostics', 'bizcity_run_all_probes' => '1' ],
				admin_url( 'tools.php' )
			) . '#smoke-test',
			'bizcity_run_all_probes'
		);

		echo '<div class="bizcity-diag-widget">';

		// Critical row.
		if ( $crit_missing > 0 ) {
			echo '<p style="margin:.4em 0;font-size:13px">'
				. '<span style="color:#b32d2e;font-weight:bold;font-size:20px">⚠ ' . (int) $crit_missing . '</span> '
				. esc_html__( 'critical table(s) missing', 'bizcity-twin-ai' )
				. ( $total_missing > $crit_missing ? ' <span style="color:#666">(+ ' . (int) ( $total_missing - $crit_missing ) . ' non-critical)</span>' : '' )
				. '</p>';
		} else {
			echo '<p style="margin:.4em 0;font-size:13px;color:#00674e">'
				. '<span style="font-size:18px">✓</span> '
				. esc_html__( 'All critical tables present', 'bizcity-twin-ai' )
				. ( $total_missing > 0 ? ' <span style="color:#666">(' . (int) $total_missing . ' non-critical missing)</span>' : '' )
				. '</p>';
		}

		// Last smoke run.
		echo '<p style="margin:.4em 0;font-size:13px">';
		if ( $ts ) {
			$age_label = $age_d === 0 ? __( 'today', 'bizcity-twin-ai' )
				: ( $age_d === 1 ? __( 'yesterday', 'bizcity-twin-ai' )
				: sprintf( _n( '%d day ago', '%d days ago', $age_d, 'bizcity-twin-ai' ), $age_d ) );
			$is_stale = $age_d !== null && $age_d > 7;
			$dot      = $fail > 0 ? '<span style="color:#b32d2e">●</span>' : '<span style="color:#00674e">●</span>';
			echo $dot . ' '
				. esc_html__( 'Last smoke test:', 'bizcity-twin-ai' ) . ' '
				. '<strong>' . (int) $pass . '</strong>✓ '
				. '<strong style="color:' . ( $fail ? '#b32d2e' : '#666' ) . '">' . (int) $fail . '</strong>✗ '
				. '<strong style="color:#666">' . (int) $skip . '</strong>↷ '
				. '<span style="color:' . ( $is_stale ? '#b26a00' : '#666' ) . '">(' . esc_html( $age_label )
				. ( $is_stale ? ' — ' . esc_html__( 'overdue', 'bizcity-twin-ai' ) : '' )
				. ')</span>';
		} else {
			echo '<span style="color:#b26a00">●</span> '
				. esc_html__( 'No smoke test run yet.', 'bizcity-twin-ai' );
		}
		echo '</p>';

		// CTAs.
		echo '<p style="margin-top:12px">'
			. '<a class="button button-primary" href="' . esc_url( $run_url ) . '"'
			. ' onclick="return confirm(\'' . esc_js( __( 'Run all probes (~30s)?', 'bizcity-twin-ai' ) ) . '\');">'
			. '▶ ' . esc_html__( 'Run health check', 'bizcity-twin-ai' ) . '</a> '
			. '<a class="button" href="' . esc_url( $diag_url ) . '">'
			. esc_html__( 'Open Diagnostics →', 'bizcity-twin-ai' ) . '</a>'
			. '</p>';

		echo '</div>';
	}
}
