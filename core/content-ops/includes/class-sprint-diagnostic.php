<?php
/**
 * Content Ops — Sprint Diagnostic (PHASE CO-1)
 *
 * URL: /wp-admin/tools.php?page=bizcity-content-ops-sprint-diag
 *
 * Probes (R-DDV):
 *   T-CO-1.1  Schema installed (5 tables + version option)
 *   T-CO-1.2  REST namespace bizcity-content/v1 registered
 *   T-CO-1.3  CPT bizcity_doc + taxonomy bizcity_channel_target registered
 *   T-CO-1.4  SPA bundle artifacts present
 *   T-CO-1.5  Scheduler heartbeat (< 300s PASS · < 900s WARN · else FAIL)
 *   T-CO-1.6  LLM gateway reachable (BizCity_Content_LLM_Proxy::is_ready())
 *   T-CO-1.7  Channel readiness matrix returns ≥ 1 platform (SKIP if registry empty)
 *
 * @package BizCity_Twin_AI
 * @subpackage Content_Ops
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Content_Ops_Sprint_Diagnostic {

	const PAGE_SLUG = 'bizcity-content-ops-sprint-diag';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 99 );
	}

	public static function register_menu(): void {
		// 2026-05-22 — Mount under TwinChat parent (`bizcity-twinchat`) for
		// standalone deployments. Falls back to tools.php if parent missing.
		global $submenu;
		$parent = ( is_array( $submenu ) && isset( $submenu['bizcity-twinchat'] ) ) ? 'bizcity-twinchat' : 'tools.php';

		add_submenu_page(
			$parent,
			'Content Ops — Sprint Diagnostic',
			'Content Ops · Diag',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		echo '<div class="wrap">';
		echo '<h1>BizCity Content Ops — Sprint Diagnostic</h1>';
		echo '<p><strong>Roadmap:</strong> <code>core/content-ops/PHASE-CO-1-FOUNDATION.md</code></p>';
		echo '<p>Mỗi row map 1:1 task. <code>PASS</code> = code live; <code>WARN</code> = degraded; <code>FAIL</code> = missing; <code>SKIP</code> = phụ thuộc phase sau.</p>';

		echo '<h2 style="margin-top:24px">Sprint CO-1 — Foundation</h2>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th style="width:90px">Task</th>'
			. '<th style="width:90px">Status</th>'
			. '<th>Check</th>'
			. '<th>Evidence</th>'
			. '</tr></thead><tbody>';

		self::check_1_schema();
		self::check_2_rest();
		self::check_3_cpt();
		self::check_4_spa_bundle();
		self::check_5_scheduler_heartbeat();
		self::check_6_llm_ready();
		self::check_7_channel_readiness();

		echo '</tbody></table>';
		echo '</div>';
	}

	/* ============================== helpers ============================== */

	private static function badge( string $status ): string {
		$colors = array(
			'PASS' => array( '#46b450', '#fff' ),
			'WARN' => array( '#ffb900', '#000' ),
			'FAIL' => array( '#dc3232', '#fff' ),
			'SKIP' => array( '#999',    '#fff' ),
		);
		$c = $colors[ $status ] ?? array( '#777', '#fff' );
		return sprintf(
			'<span style="display:inline-block;padding:2px 10px;border-radius:3px;font-weight:600;background:%s;color:%s">%s</span>',
			$c[0], $c[1], $status
		);
	}

	private static function row( string $task, string $status, string $check, string $evidence ): void {
		printf(
			'<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $task ),
			self::badge( $status ),
			esc_html( $check ),
			$evidence
		);
	}

	/* =============================== probes ============================== */

	private static function check_1_schema(): void {
		global $wpdb;
		$expected = class_exists( 'BizCity_Content_Ops_Schema' )
			? BizCity_Content_Ops_Schema::tables()
			: array();
		$missing  = array();
		foreach ( $expected as $t ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
			if ( ! $exists ) {
				$missing[] = $t;
			}
		}
		$installed = (string) get_option( 'bizcity_content_ops_db_version', '' );
		$expected_v = defined( 'BizCity_Content_Ops_Schema::VERSION' ) || class_exists( 'BizCity_Content_Ops_Schema' )
			? BizCity_Content_Ops_Schema::VERSION
			: '?';
		$ok      = empty( $missing ) && $installed === $expected_v;
		$status  = $ok ? 'PASS' : 'FAIL';
		$ev      = sprintf(
			'<code>expected=%d · missing=%s · db_version=%s (want %s)</code>',
			count( $expected ),
			esc_html( $missing ? implode( ',', $missing ) : '0' ),
			esc_html( $installed ?: '—' ),
			esc_html( $expected_v )
		);
		self::row( 'T-CO-1.1', $status, '5 tables installed + version option', $ev );
	}

	private static function check_2_rest(): void {
		$routes = function_exists( 'rest_get_server' ) ? rest_get_server()->get_routes() : array();
		$count  = 0;
		foreach ( $routes as $path => $_ ) {
			if ( strpos( $path, '/bizcity-content/v1' ) === 0 ) {
				++$count;
			}
		}
		$status = $count >= 10 ? 'PASS' : ( $count > 0 ? 'WARN' : 'FAIL' );
		$ev = sprintf( '<code>routes registered=%d (expected ≥ 10)</code>', $count );
		self::row( 'T-CO-1.2', $status, 'REST namespace bizcity-content/v1 registered', $ev );
	}

	private static function check_3_cpt(): void {
		$cpt = post_type_exists( BizCity_Content_CPT_Bridge::CPT );
		$tax = taxonomy_exists( BizCity_Content_CPT_Bridge::TAXONOMY );
		$status = ( $cpt && $tax ) ? 'PASS' : 'FAIL';
		$ev = sprintf(
			'<code>cpt %s=%s · taxonomy %s=%s</code>',
			BizCity_Content_CPT_Bridge::CPT,
			$cpt ? 'yes' : 'no',
			BizCity_Content_CPT_Bridge::TAXONOMY,
			$tax ? 'yes' : 'no'
		);
		self::row( 'T-CO-1.3', $status, 'CPT bizcity_doc + taxonomy bizcity_channel_target registered', $ev );
	}

	private static function check_4_spa_bundle(): void {
		$base = dirname( __DIR__ ) . '/assets/dist/';
		$js   = $base . 'content-ops-app.js';
		$css  = $base . 'content-ops-app.css';
		$jsok = is_file( $js ) && filesize( $js ) > 50 * 1024;
		$cssok = is_file( $css ) && filesize( $css ) > 2 * 1024;
		$status = ( $jsok && $cssok ) ? 'PASS' : ( ( is_file( $js ) || is_file( $css ) ) ? 'WARN' : 'FAIL' );
		$ev = sprintf(
			'<code>js: %s (%s bytes)</code><br><code>css: %s (%s bytes)</code>',
			is_file( $js ) ? 'OK' : 'MISSING',
			is_file( $js ) ? number_format( (int) filesize( $js ) ) : '0',
			is_file( $css ) ? 'OK' : 'MISSING',
			is_file( $css ) ? number_format( (int) filesize( $css ) ) : '0'
		);
		self::row( 'T-CO-1.4', $status, 'SPA bundle artifacts present + sane filesize', $ev );
	}

	private static function check_5_scheduler_heartbeat(): void {
		$hb  = (int) get_option( BizCity_Content_Scheduler::HEARTBEAT_OPTION, 0 );
		$age = $hb ? ( time() - $hb ) : null;
		if ( $age === null ) {
			$status = 'WARN';
			$msg    = 'never_ticked';
		} elseif ( $age < 300 ) {
			$status = 'PASS';
			$msg    = $age . 's ago';
		} elseif ( $age < 900 ) {
			$status = 'WARN';
			$msg    = $age . 's ago (>5min)';
		} else {
			$status = 'FAIL';
			$msg    = $age . 's ago (>15min)';
		}
		$next = (int) wp_next_scheduled( BizCity_Content_Scheduler::CRON_HOOK );
		$ev = sprintf(
			'<code>heartbeat=%s · next_cron=%s</code>',
			esc_html( $msg ),
			esc_html( $next ? wp_date( 'Y-m-d H:i:s', $next ) : 'none' )
		);
		self::row( 'T-CO-1.5', $status, 'Scheduler heartbeat fresh (< 5min)', $ev );
	}

	private static function check_6_llm_ready(): void {
		$ready  = class_exists( 'BizCity_Content_LLM_Proxy' ) ? BizCity_Content_LLM_Proxy::is_ready() : false;
		$mode   = class_exists( 'BizCity_LLM_Client' ) && method_exists( 'BizCity_LLM_Client', 'instance' )
			? (string) ( BizCity_LLM_Client::instance()->get_mode() ?? '?' )
			: '?';
		$status = $ready ? 'PASS' : 'FAIL';
		$ev = sprintf( '<code>is_ready=%s · mode=%s</code>', $ready ? 'true' : 'false', esc_html( $mode ) );
		self::row( 'T-CO-1.6', $status, 'LLM gateway reachable (BizCity_LLM_Client::is_ready)', $ev );
	}

	private static function check_7_channel_readiness(): void {
		$m       = class_exists( 'BizCity_Content_Channel_Readiness' )
			? BizCity_Content_Channel_Readiness::matrix()
			: array();
		$total   = count( $m );
		$ready   = 0;
		foreach ( $m as $r ) {
			if ( ! empty( $r['ready'] ) ) {
				++$ready;
			}
		}
		if ( $total === 0 ) {
			$status = 'SKIP';
		} elseif ( $ready > 0 ) {
			$status = 'PASS';
		} else {
			$status = 'WARN';
		}
		$ev = sprintf( '<code>total=%d · ready=%d</code>', $total, $ready );
		self::row( 'T-CO-1.7', $status, 'Channel readiness matrix returns ≥ 1 ready platform', $ev );
	}
}
