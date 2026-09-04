<?php
/**
 * Read-only Runtime DDV for the legacy CRM conversation reconciliation preview.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_CRM_Reconciliation_Preview', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Reconciliation_Preview implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.channel.crm_reconciliation_preview'; }
	public function label(): string { return 'CRM legacy conversation reconciliation preview'; }
	public function description(): string { return 'Verifies current-tenant, read-only preview and REST dry-run guard for legacy Zalo conversation classification.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 39; }
	public function icon(): string { return 'scan-search'; }
	public function estimate_ms(): int { return 300; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_CRM_Conversation_Reconciliation_Preview' ) || ! class_exists( 'BizCity_CRM_REST_Controller' ) ) {
			return 'Conversation preview runner or CRM REST controller is not loaded.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-01 Johnny Chu] R-CRM-LEGACY-PREVIEW — prove preview-only classification stays within routed tenant and performs no mutation.
		$root = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/bizcity-twin-ai' : '';
		$file = $root . '/plugins/bizcity-twin-crm/includes/admin/class-conversation-reconciliation-preview.php';
		$disk_ok = is_file( $file ) && is_readable( $file );
		$ctx->emit_step( array( 'label' => 'Disk · reconciliation preview runner', 'status' => $disk_ok ? 'pass' : 'fail', 'detail' => $disk_ok ? 'Read-only preview artifact exists.' : 'Preview runner missing or unreadable.' ) );
		$loader_ok = class_exists( 'BizCity_CRM_Conversation_Reconciliation_Preview', false ) && method_exists( 'BizCity_CRM_Conversation_Reconciliation_Preview', 'preview' );
		$ctx->emit_step( array( 'label' => 'Loader · reconciliation preview class', 'status' => $loader_ok ? 'pass' : 'fail', 'detail' => $loader_ok ? 'Preview class and method are loaded.' : 'Preview class/method is unavailable.' ) );
		if ( ! $disk_ok || ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Reconciliation preview artifact is unavailable.', 'error' => 'preview_runner_unavailable', 'fix_hint' => 'Load the guarded preview runner before registering the reconciliation REST route.' );
		}

		global $wpdb;
		$before_queries = isset( $wpdb->queries ) && is_array( $wpdb->queries ) ? count( $wpdb->queries ) : null;
		$report = BizCity_CRM_Conversation_Reconciliation_Preview::preview( array(
			'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'limit' => 5,
		) );
		$after_queries = isset( $wpdb->queries ) && is_array( $wpdb->queries ) ? count( $wpdb->queries ) : null;
		$mutating_queries = array();
		if ( null !== $before_queries && null !== $after_queries && $after_queries >= $before_queries ) {
			foreach ( array_slice( $wpdb->queries, $before_queries ) as $query_row ) {
				$query = is_array( $query_row ) ? (string) ( $query_row[0] ?? '' ) : (string) $query_row;
				if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|START|COMMIT|ROLLBACK)\b/i', $query ) ) {
					$mutating_queries[] = strtoupper( preg_replace( '/^\s*([A-Z]+).*/i', '$1', $query ) );
				}
			}
		}
		$report_ok = is_array( $report )
			&& ! empty( $report['dry_run'] )
			&& empty( $report['blocked_reason'] )
			&& isset( $report['routing']['ok'] ) && $report['routing']['ok']
			&& is_array( $report['items'] ?? null )
			&& (int) ( $report['scanned'] ?? 0 ) <= 5
			&& empty( $mutating_queries );
		$ctx->emit_step( array( 'label' => 'Runtime · current-tenant read-only preview', 'status' => $report_ok ? 'pass' : 'fail', 'detail' => sprintf( 'dry_run=%s; scanned=%d; routing=%s; mutating_queries=%s', ! empty( $report['dry_run'] ) ? 'true' : 'false', (int) ( $report['scanned'] ?? 0 ), ! empty( $report['routing']['ok'] ) ? 'ok' : 'blocked', empty( $mutating_queries ) ? '0' : implode( ',', $mutating_queries ) ) ) );

		// [2026-09-01 Johnny Chu] R-CRM-LEGACY-PREVIEW — inspect the REST hook without calling rest_get_server(), which would start rest_api_init provisioning from a read-only probe.
		$route_exists = has_action( 'rest_api_init', array( 'BizCity_CRM_REST_Controller', 'register_routes' ) ) !== false;
		$ctx->emit_step( array( 'label' => 'Loader · reconciliation REST route hook', 'status' => $route_exists ? 'pass' : 'fail', 'detail' => $route_exists ? 'CRM REST controller is registered for normal rest_api_init lifecycle.' : 'CRM REST controller hook is missing.' ) );

		$unsafe_request = new WP_REST_Request( 'POST', '/bizcity-crm/v1/admin/reconcile-conversations' );
		$unsafe_request->set_param( 'dry_run', false );
		$unsafe_response = BizCity_CRM_REST_Controller::post_reconcile_conversations( $unsafe_request );
		$unsafe_data = $unsafe_response instanceof WP_REST_Response ? (array) $unsafe_response->get_data() : array();
		$unsafe_ok = $unsafe_response instanceof WP_REST_Response
			&& (int) $unsafe_response->get_status() === 400
			&& (string) ( $unsafe_data['code'] ?? '' ) === 'invalid_param'
			&& (string) ( $unsafe_data['hint'] ?? '' ) !== ''
			&& (string) ( $unsafe_data['help_code'] ?? '' ) === 'invalid_param_generic';
		$ctx->emit_step( array( 'label' => 'Runtime · dry_run=false guard', 'status' => $unsafe_ok ? 'pass' : 'fail', 'detail' => $unsafe_ok ? 'Mutation mode is rejected with standard error payload.' : 'Unsafe non-preview mode was not rejected as expected.' ) );

		if ( ! $report_ok || ! $route_exists || ! $unsafe_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Conversation reconciliation preview contract failed.', 'error' => 'reconciliation_preview_contract_failed', 'fix_hint' => 'Keep preview scoped to the current routed tenant and reject dry_run=false before any reconciliation mutation.' );
		}
		return array( 'status' => 'pass', 'summary' => 'Legacy conversation preview is current-tenant, read-only, and mutation mode is fail-closed.' );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_CRM_Reconciliation_Preview';
	return $list;
} );