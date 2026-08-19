<?php
/**
 * BizCity Diagnostics — modules.twin_gpt.appearance probe.
 *
 * R-DDV: 3-layer evidence for Twin GPT Appearance policy contract.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since      2026-07-18 (PHASE-TWINWEB-UI-SKINS UIS-1/UIS-2)
 */

// [2026-07-18 Johnny Chu] PHASE-TWINWEB-UI-SKINS — DDV probe for Appearance admin/effective skin contract.
defined( 'ABSPATH' ) || exit;

$bizcity_twinweb_plugin_root = defined( 'BIZCITY_TWIN_AI_DIR' )
	? BIZCITY_TWIN_AI_DIR
	: dirname( __DIR__, 3 ) . '/';
$bizcity_twinweb_probe_iface = $bizcity_twinweb_plugin_root . 'core/diagnostics/includes/interface-diagnostics-probe.php';
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) && is_readable( $bizcity_twinweb_probe_iface ) ) {
	require_once $bizcity_twinweb_probe_iface;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_TwinWeb_Appearance', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Appearance implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'modules.twin_gpt.appearance'; }
	public function label(): string       { return 'Twin GPT · Appearance Policy'; }
	public function description(): string {
		return 'Disk / Loader / Runtime: Appearance admin routes, public effective skin contract, default skin and surface policy payload.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 84; }
	public function icon(): string        { return 'palette'; }
	public function estimate_ms(): int    { return 25; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		$rest_file = __DIR__ . '/class-twinweb-rest.php';
		$src = is_readable( $rest_file ) ? (string) file_get_contents( $rest_file ) : '';

		$disk_ok = '' !== $src
			&& false !== strpos( $src, "'/admin/appearance'" )
			&& false !== strpos( $src, "'/skins/effective'" )
			&& false !== strpos( $src, 'build_effective_appearance' )
			&& false !== strpos( $src, 'appearance_skin_catalog' )
			&& false !== strpos( $src, 'invalid_param_generic' );
		$step = array(
			'label'  => 'Disk · class-twinweb-rest.php Appearance route + helper markers',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok
				? 'REST file has admin/effective routes, skin catalog, effective builder and R-ERROR-UX invalid-param payload.'
				: 'Missing Appearance route/helper markers in class-twinweb-rest.php.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_ok ) { $pass = false; }

		$rest_class_ok = class_exists( 'BizCity_TwinWeb_REST' );
		$method_ok = $rest_class_ok
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_get_appearance' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_put_appearance' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_effective_skins' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_effective_config' );
		$step = array(
			'label'  => 'Loader · BizCity_TwinWeb_REST Appearance methods',
			'status' => ( $rest_class_ok && $method_ok ) ? 'pass' : 'fail',
			'detail' => ( $rest_class_ok && $method_ok )
				? 'REST class loaded with admin/effective Appearance methods.'
				: ( ! $rest_class_ok ? 'BizCity_TwinWeb_REST class not loaded.' : 'One or more Appearance methods are missing.' ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $rest_class_ok || ! $method_ok ) { $pass = false; }

		$runtime_ok = false;
		$runtime_detail = 'Runtime skipped because REST class is not loaded.';
		if ( $rest_class_ok && $method_ok && class_exists( 'WP_REST_Request' ) ) {
			$blog_id       = (int) get_current_blog_id();
			$policy_option = 'bizcity_twinweb_appearance_policy_' . $blog_id;
			$cp_option     = 'bizcity_twinweb_cp_ver_' . $blog_id;
			$sentinel      = '__bizcity_twinweb_missing__';
			$old_policy    = get_option( $policy_option, $sentinel );
			$old_cp_ver    = get_option( $cp_option, $sentinel );
			try {
				$rest = BizCity_TwinWeb_REST::instance();
				$admin_data = $this->response_data(
					$rest->admin_get_appearance( new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/admin/appearance' ) )
				);

				$put_req = new WP_REST_Request( 'PUT', '/bizcity-twinweb/v1/admin/appearance' );
				$put_req->set_param( 'policy', array(
					'default_skin' => 'not-a-real-skin',
					'skins'        => array(
						'claude'  => array( 'enabled' => true, 'min_plan' => 'free' ),
						'grok'    => array( 'enabled' => true, 'min_plan' => 'pro' ),
						'unknown' => array( 'enabled' => true, 'min_plan' => 'free' ),
					),
					'surfaces'     => array(
						'page'  => array( 'enabled' => false, 'default_skin' => 'unknown' ),
						'block' => array( 'enabled' => true, 'default_skin' => 'claude' ),
						'float' => array( 'enabled' => true, 'default_skin' => 'grok' ),
					),
				) );
				$put_data = $this->response_data( $rest->admin_put_appearance( $put_req ) );
				$policy   = isset( $put_data['policy'] ) && is_array( $put_data['policy'] ) ? $put_data['policy'] : array();

				$skins_response = $rest->get_effective_skins( new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/skins/effective' ) );
				$skins_data = $this->response_data( $skins_response );
				$appearance = isset( $skins_data['appearance'] ) && is_array( $skins_data['appearance'] ) ? $skins_data['appearance'] : array();

				$skins = isset( $appearance['skins'] ) && is_array( $appearance['skins'] ) ? $appearance['skins'] : array();
				$default_skin = isset( $appearance['default_skin'] ) ? sanitize_key( (string) $appearance['default_skin'] ) : '';
				$skin_ids = array();
				foreach ( $skins as $row ) {
					if ( is_array( $row ) && isset( $row['id'] ) ) {
						$skin_ids[] = sanitize_key( (string) $row['id'] );
					}
				}

				$surfaces = isset( $appearance['surfaces'] ) && is_array( $appearance['surfaces'] ) ? $appearance['surfaces'] : array();
				$runtime_ok = ! empty( $admin_data['catalog']['chatgpt'] )
					&& ! empty( $admin_data['catalog']['claude'] )
					&& isset( $policy['default_skin'] )
					&& 'not-a-real-skin' !== $policy['default_skin']
					&& empty( $policy['skins']['unknown'] )
					&& ! empty( $policy['surfaces']['page']['enabled'] )
					&& ! empty( $put_data['cp_ver'] )
					&& ! empty( $skins_data['success'] )
					&& '' !== $default_skin
					&& in_array( $default_skin, $skin_ids, true )
					&& isset( $surfaces['page'] )
					&& isset( $appearance['cp_ver'] );
				$runtime_detail = $runtime_ok
					? 'Admin GET/PUT sanitizes policy and /skins/effective returns default skin, allowed skins, page surface and cp_ver.'
					: 'Appearance runtime missing admin catalog, sanitized policy, effective skins, page surface or cp_ver.';
			} catch ( Throwable $e ) {
				$runtime_detail = 'Exception while testing Appearance contract: ' . $e->getMessage();
			} finally {
				if ( $sentinel === $old_policy ) {
					delete_option( $policy_option );
				} else {
					update_option( $policy_option, $old_policy, false );
				}
				if ( $sentinel === $old_cp_ver ) {
					delete_option( $cp_option );
				} else {
					update_option( $cp_option, $old_cp_ver, false );
				}
			}
		}

		$step = array(
			'label'  => 'Runtime · Appearance admin round-trip + effective skins',
			'status' => $runtime_ok ? 'pass' : 'fail',
			'detail' => $runtime_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $runtime_ok ) { $pass = false; }

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'Twin GPT Appearance policy contract is ready.' : 'Twin GPT Appearance policy contract is incomplete.',
			'error'    => $pass ? '' : 'twinweb_appearance_contract_failed',
			'fix_hint' => $pass ? '' : 'Check /admin/appearance, /skins/effective and build_effective_appearance() in class-twinweb-rest.php.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe; no artifacts to clean up.
	}

	private function response_data( $response ) {
		if ( is_object( $response ) && method_exists( $response, 'get_data' ) ) {
			$data = $response->get_data();
			return is_array( $data ) ? $data : array();
		}
		if ( is_array( $response ) ) {
			return $response;
		}
		return array();
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( array $probes ) {
	if ( class_exists( 'BizCity_Probe_TwinWeb_Appearance', false ) ) {
		$probes[] = new BizCity_Probe_TwinWeb_Appearance();
	}
	return $probes;
} );