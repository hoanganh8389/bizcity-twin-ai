<?php
/**
 * BizCity Diagnostics — automation.ad_image probe (Scenario Builder MVP).
 *
 * R-DDV — verify route `POST bizcity-automation/v1/workflows/{id}/ad-image`:
 *
 *   Layer 1 · DISK: class-automation-rest.php exists + has method generate_ad_image.
 *   Layer 2 · LOADER: class loaded + route registered in REST server.
 *   Layer 3 · RUNTIME loopback (rest_do_request, no live LLM call):
 *     - POST với workflow_id=0  → expect 200 + ok=false + code=workflow_not_found.
 *     - Tạo workflow probe, POST với qr_url='' → 200 + ok=false + code=invalid_qr_url.
 *     - Cả hai chứng minh: route reachable, permission pass, handler chạy
 *       fail-OPEN per R-GW-8.4 thay vì throw 500.
 *
 * KHÔNG gọi LLM thật vì cost API + tránh đăng tin spam vào job logs.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      Scenario Builder MVP (2026-06-01)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_Automation_Ad_Image implements BizCity_Diagnostics_Probe {

	const ROUTE_RE   = '/bizcity-automation/v1/workflows/(?P<id>\d+)/ad-image';
	const SLUG_PREFIX = '__healthtest_adimage_';

	public function id(): string          { return 'automation.ad_image'; }
	public function label(): string       { return 'Automation · Ad-image proxy (route loopback)'; }
	public function description(): string {
		return 'Verify POST /bizcity-automation/v1/workflows/{id}/ad-image — route registered, permission pass, fail-OPEN handler reachable (no live LLM call).';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 40; }
	public function icon(): string        { return 'format-image'; }
	public function estimate_ms(): int    { return 600; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Automation_REST' ) ) {
			return new WP_Error( 'class_missing', 'BizCity_Automation_REST chưa load.' );
		}
		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			return new WP_Error( 'class_missing', 'BizCity_Automation_Repo_Workflows chưa load.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();

		// ── Layer 1 · DISK ─────────────────────────────────────────────
		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
		$rest_path  = $plugin_dir . '/bizcity-twin-ai/core/automation/includes/class-automation-rest.php';
		$disk_ok    = is_readable( $rest_path );
		$has_method = $disk_ok && strpos( (string) file_get_contents( $rest_path ), 'function generate_ad_image' ) !== false;

		$steps[] = $s = array(
			'label'  => 'Disk · class-automation-rest.php :: generate_ad_image',
			'status' => ( $disk_ok && $has_method ) ? 'pass' : 'fail',
			'detail' => $disk_ok ? ( $has_method ? 'method present' : 'method MISSING' ) : 'file MISSING',
		);
		$ctx->emit_step( $s );
		if ( ! $disk_ok || ! $has_method ) {
			return self::fail( $steps, 'generate_ad_image method thiếu trên disk', 'method_missing',
				'Verify deploy của core/automation/includes/class-automation-rest.php.' );
		}

		// ── Layer 2 · LOADER ───────────────────────────────────────────
		$loaded = method_exists( 'BizCity_Automation_REST', 'generate_ad_image' );
		$steps[] = $s = array(
			'label'  => 'Loader · BizCity_Automation_REST::generate_ad_image()',
			'status' => $loaded ? 'pass' : 'fail',
			'detail' => $loaded ? 'callable' : 'NOT callable',
		);
		$ctx->emit_step( $s );
		if ( ! $loaded ) {
			return self::fail( $steps, 'method not loaded', 'method_not_loaded', 'OPcache flush?' );
		}

		$routes      = rest_get_server()->get_routes();
		$route_found = isset( $routes[ self::ROUTE_RE ] );
		$steps[] = $s = array(
			'label'  => 'Loader · REST route registered',
			'status' => $route_found ? 'pass' : 'fail',
			'detail' => $route_found ? self::ROUTE_RE : 'route MISSING',
		);
		$ctx->emit_step( $s );
		if ( ! $route_found ) {
			return self::fail( $steps, 'Route ad-image chưa register', 'route_missing',
				'Verify BizCity_Automation_REST::register_routes() chạy trên rest_api_init.' );
		}

		// ── Layer 3 · RUNTIME loopback (workflow_not_found) ────────────
		$req1 = new WP_REST_Request( 'POST', '/bizcity-automation/v1/workflows/0/ad-image' );
		$req1->set_header( 'content-type', 'application/json' );
		$req1->set_body( wp_json_encode( array( 'preset' => 'cover', 'qr_url' => 'https://example.test/qr' ) ) );
		$res1  = rest_do_request( $req1 );
		$data1 = $res1 ? $res1->get_data() : null;
		$pass1 = $res1 && $res1->get_status() === 200
			&& is_array( $data1 )
			&& empty( $data1['ok'] )
			&& ( $data1['code'] ?? '' ) === 'workflow_not_found';

		$steps[] = $s = array(
			'label'  => 'Runtime · loopback wf_id=0 → workflow_not_found',
			'status' => $pass1 ? 'pass' : 'fail',
			'detail' => $pass1
				? 'HTTP 200 + ok=false + code=workflow_not_found'
				: ( 'http=' . ( $res1 ? $res1->get_status() : 'null' ) . ' code=' . ( is_array( $data1 ) ? ( $data1['code'] ?? 'n/a' ) : 'n/a' ) ),
		);
		$ctx->emit_step( $s );
		if ( ! $pass1 ) {
			return self::fail( $steps, 'Loopback workflow_not_found không trả đúng shape fail-OPEN.',
				'loopback_failed', 'Xem generate_ad_image() — phải trả 200 + ok=false thay vì WP_Error.' );
		}

		// ── Layer 3b · RUNTIME loopback (invalid_qr_url) ───────────────
		$wf = BizCity_Automation_Repo_Workflows::create( array(
			'slug'         => self::SLUG_PREFIX . wp_generate_password( 6, false, false ),
			'name'         => '__healthtest ad-image',
			'trigger_type' => 'manual',
			'graph_json'   => wp_json_encode( array(
				'nodes' => array( array( 'id' => 't1', 'type' => 'trigger', 'data' => array( 'blockId' => 'trigger.manual' ) ) ),
				'edges' => array(),
			) ),
			'enabled' => 1,
		) );
		if ( is_wp_error( $wf ) ) {
			return self::fail( $steps, 'Tạo workflow probe fail', 'create_failed', $wf->get_error_message() );
		}
		$wid = (int) $wf['id'];

		$req2 = new WP_REST_Request( 'POST', '/bizcity-automation/v1/workflows/' . $wid . '/ad-image' );
		$req2->set_header( 'content-type', 'application/json' );
		$req2->set_body( wp_json_encode( array( 'preset' => 'cover', 'qr_url' => '' ) ) );
		$res2  = rest_do_request( $req2 );
		$data2 = $res2 ? $res2->get_data() : null;
		$pass2 = $res2 && $res2->get_status() === 200
			&& is_array( $data2 )
			&& empty( $data2['ok'] )
			&& ( $data2['code'] ?? '' ) === 'invalid_qr_url';

		$steps[] = $s = array(
			'label'  => 'Runtime · loopback empty qr_url → invalid_qr_url',
			'status' => $pass2 ? 'pass' : 'fail',
			'detail' => $pass2
				? 'HTTP 200 + ok=false + code=invalid_qr_url'
				: ( 'http=' . ( $res2 ? $res2->get_status() : 'null' ) . ' code=' . ( is_array( $data2 ) ? ( $data2['code'] ?? 'n/a' ) : 'n/a' ) ),
		);
		$ctx->emit_step( $s );

		// Cleanup probe workflow.
		BizCity_Automation_Repo_Workflows::hard_delete( $wid );
		$steps[] = array( 'label' => 'Cleanup', 'status' => 'pass', 'detail' => 'probe wf wiped' );

		if ( ! $pass2 ) {
			return self::fail( $steps, 'Loopback invalid_qr_url không trả đúng shape.', 'loopback_failed',
				'Xem generate_ad_image() — phải trả 200 + ok=false code=invalid_qr_url khi qr_url thiếu.' );
		}

		return array(
			'status'  => 'pass',
			'summary' => 'Route ad-image registered + handler reachable + fail-OPEN shape OK (2 loopbacks).',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		global $wpdb;
		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) { return; }
		$tbl = BizCity_Automation_Repo_Workflows::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tbl} WHERE slug LIKE %s", self::SLUG_PREFIX . '%' ) );
	}

	private static function fail( array $steps, string $summary, string $error, string $hint ): array {
		return array(
			'status'   => 'fail',
			'summary'  => $summary,
			'error'    => $error,
			'fix_hint' => $hint,
			'steps'    => $steps,
		);
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Automation_Ad_Image';
	return $list;
} );
