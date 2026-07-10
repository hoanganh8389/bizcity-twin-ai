<?php
/**
 * BizCity Diagnostics — integrations.google-hub probe.
 *
 * R-DDV (Diagnostic-Driven Validation) row for the Google Hub canonical
 * 1-API shipped 2026-06-04. Verifies (read-only, no Google call):
 *
 *   1. `BizCity_Google_Hub` helper class loaded.
 *   2. At least one connection path available
 *      (BZGoogle bundle OR bizcity-llm gateway with Bearer key).
 *   3. `get_connect_url()` returns a syntactically valid URL with
 *      required query params (blog_id, user_id, return_url, scopes,
 *      ts, sig OR auth_url from REST fallback).
 *   4. Service catalog (gmail / calendar / drive / …) all build URLs
 *      successfully via `get_service_connect_url()`.
 *
 * Cost: 0 — no outbound HTTP. Safe to run anytime.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-04
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Google_Hub', false ) ) {
	return;
}

final class BizCity_Probe_Google_Hub implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'integrations.google-hub'; }
	public function label(): string       { return 'Google Hub (1-API connect URL)'; }
	public function description(): string {
		return 'Verify BizCity_Google_Hub helper builds valid pretty-URL connect links to bizcity.vn cho Scheduler / Gmail / Drive / Sheets / Docs / Slides / Calendar / Contacts.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 55; }
	public function icon(): string        { return 'admin-links'; }
	public function estimate_ms(): int    { return 30; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Google_Hub' ) ) {
			return new WP_Error(
				'google_hub_missing',
				'BizCity_Google_Hub chưa load. Đảm bảo core/bizcity-llm/includes/class-google-hub.php được require_once trong bootstrap.'
			);
		}
		return true;
	}

	public function run( $ctx ): array {
		// Step 1 — connection path detection.
		$bz_ok  = BizCity_Google_Hub::is_bzgoogle_available();
		$gw_ok  = BizCity_Google_Hub::is_llm_gateway_ready();
		$path   = $bz_ok ? 'bzgoogle' : ( $gw_ok ? 'llm_gateway' : 'none' );
		$ctx->emit_step( [
			'label'  => 'Connection path',
			'status' => $path !== 'none' ? 'pass' : 'fail',
			'detail' => sprintf(
				'bzgoogle=%s · llm_gateway=%s · resolved=%s',
				$bz_ok ? 'yes' : 'no',
				$gw_ok ? 'yes' : 'no',
				$path
			),
		] );

		if ( $path === 'none' ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Không có đường kết nối Google nào khả dụng.',
				'error'    => 'no_connection_path',
				'fix_hint' => 'Cài plugin "Bizcity Tool Google" (bzgoogle) HOẶC cấu hình bizcity_llm_gateway_url + bizcity_llm_api_key.',
			];
		}

		// Step 2 — primary connect URL.
		$primary = BizCity_Google_Hub::get_connect_url( [ 'scopes' => 'profile' ] );
		if ( is_wp_error( $primary ) ) {
			$ctx->emit_step( [
				'label'  => 'Primary connect URL',
				'status' => 'fail',
				'detail' => $primary->get_error_message(),
			] );
			return [
				'status'   => 'fail',
				'summary'  => 'get_connect_url() trả WP_Error: ' . $primary->get_error_message(),
				'error'    => (string) $primary->get_error_code(),
				'fix_hint' => 'Kiểm BZGoogle hub_blog_id (option bzgoogle_hub_blog_id) hoặc gateway URL/key.',
			];
		}

		$parts = wp_parse_url( $primary );
		$valid = ! empty( $parts['host'] ) && ! empty( $parts['scheme'] );
		$query = [];
		if ( ! empty( $parts['query'] ) ) {
			wp_parse_str( $parts['query'], $query );
		}

		// Required keys depend on path.
		$required = ( $path === 'bzgoogle' )
			? [ 'blog_id', 'user_id', 'return_url', 'scopes', 'ts', 'sig' ]
			: []; // llm_gateway returns Google consent URL directly — no required local params.
		$missing  = [];
		foreach ( $required as $k ) {
			if ( empty( $query[ $k ] ) ) {
				$missing[] = $k;
			}
		}

		$step2_ok = $valid && empty( $missing );
		$ctx->emit_step( [
			'label'  => 'Primary connect URL',
			'status' => $step2_ok ? 'pass' : 'fail',
			'detail' => $step2_ok
				? sprintf( '%s://%s%s · %d params', $parts['scheme'], $parts['host'], $parts['path'] ?? '', count( $query ) )
				: ( $missing ? 'missing: ' . implode( ',', $missing ) : 'malformed URL' ),
		] );

		if ( ! $step2_ok ) {
			return [
				'status'   => 'fail',
				'summary'  => $missing ? 'Connect URL thiếu params bắt buộc.' : 'Connect URL malformed.',
				'error'    => 'invalid_connect_url',
				'fix_hint' => 'Kiểm BZGoogle_Google_OAuth::get_connect_url() return value.',
			];
		}

		// Step 3 — service catalog (all 7 services build successfully).
		$services    = array_keys( BizCity_Google_Hub::SERVICES );
		$svc_results = [];
		$svc_failed  = [];
		foreach ( $services as $svc ) {
			$url = BizCity_Google_Hub::get_service_connect_url( $svc );
			if ( is_wp_error( $url ) ) {
				$svc_failed[] = $svc;
				$svc_results[] = "{$svc}=ERR";
			} else {
				$svc_results[] = "{$svc}=ok";
			}
		}
		$step3_ok = empty( $svc_failed );
		$ctx->emit_step( [
			'label'  => 'Service catalog (' . count( $services ) . ')',
			'status' => $step3_ok ? 'pass' : 'fail',
			'detail' => implode( ' · ', $svc_results ),
		] );

		// Step 4 — status snapshot (always returns array).
		$status = BizCity_Google_Hub::get_status();
		$status_ok = is_array( $status ) && isset( $status['path'], $status['hub_domain'], $status['services'] );
		$ctx->emit_step( [
			'label'  => 'Status snapshot',
			'status' => $status_ok ? 'pass' : 'fail',
			'detail' => $status_ok
				? sprintf(
					'connected=%s · email=%s · path=%s · hub=%s',
					$status['connected'] ? 'yes' : 'no',
					$status['email'] ?: '—',
					$status['path'],
					$status['hub_domain']
				)
				: 'invalid status shape',
		] );

		if ( ! $step3_ok || ! $status_ok ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Catalog hoặc status snapshot lỗi.',
				'error'    => $svc_failed ? 'service_url_fail:' . implode( ',', $svc_failed ) : 'invalid_status',
				'fix_hint' => 'Kiểm BizCity_Google_Hub::SERVICES catalog + BZGoogle_Google_OAuth::SCOPE_GROUPS.',
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf(
				'%d services · path=%s · hub=%s · connected=%s',
				count( $services ),
				$path,
				$status['hub_domain'],
				$status['connected'] ? 'yes' : 'no'
			),
		];
	}

	public function cleanup(): void {
		// Read-only probe — nothing to clean up.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Google_Hub';
	return $list;
} );
