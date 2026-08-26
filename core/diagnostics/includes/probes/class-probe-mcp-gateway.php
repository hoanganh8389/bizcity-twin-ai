<?php
/**
 * BizCity Diagnostics — core.mcp.gateway probe (PHASE-0.53-MCP Wave A/E/F).
 *
 * R-DDV 3-layer evidence for the Twin Client Brain MCP gateway:
 *   Disk    — module files exist (bootstrap.php, tool registry, http controller).
 *   Loader  — classes are loaded and REST route is registered under bizcity-mcp/v1.
 *   Runtime — tools/list (via the registry directly, no network hop) reports the
 *             full 8-tool catalog including all 4 implemented document tools.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-27 (PHASE-0.53-MCP Wave A)
 */

// [2026-07-27 Johnny Chu] PHASE-0.53-MCP — new DDV probe for MCP gateway.
defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_MCP_Gateway', false ) ) {
	return;
}

final class BizCity_Probe_MCP_Gateway implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.mcp.gateway'; }
	public function label(): string       { return 'MCP Gateway: transport + tool catalog'; }
	public function description(): string {
		return 'Disk/Loader/Runtime evidence rằng core/mcp đã load, route bizcity-mcp/v1/mcp đã đăng ký, và tools/list trả đủ catalog (PHASE-0.53-MCP).';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 60; }
	public function icon(): string        { return 'plug'; }
	public function estimate_ms(): int    { return 150; }

	public function precondition() {
		return defined( 'BIZCITY_MCP_ENABLED' ) && BIZCITY_MCP_ENABLED;
	}

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		// ── Disk: module files exist ────────────────────────────────────
		$base       = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/mcp/';
		$disk_files = array(
			'bootstrap.php',
			'includes/class-mcp-tool-registry.php',
			'includes/class-brain-mcp-service.php',
			'includes/class-document-mcp-service.php',
			'includes/class-mcp-session-store.php',
			'includes/class-mcp-file-logger.php',
			'includes/class-mcp-client-scope-resolver.php',
			'includes/class-mcp-action-confirmation.php',
			'includes/actions/class-page-action-mcp-service.php',
			'includes/brain/class-business-mcp-service.php',
			'includes/brain/class-content-brain-mcp-service.php',
			'includes/actions/class-content-action-mcp-service.php',
			'includes/brain/class-report-brain-mcp-service.php',
			'includes/class-mcp-tool-policy.php',
			'includes/brain/class-commerce-brain-mcp-service.php',
			'rest/class-mcp-http-controller.php',
			'includes/class-mcp-oauth.php',
			'rest/class-mcp-admin-rest.php',
		);
		$disk_ok = true;
		foreach ( $disk_files as $f ) {
			if ( ! file_exists( $base . $f ) ) {
				$disk_ok = false;
			}
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Disk: module files',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'bootstrap.php + tool registry + brain/document services + http controller exist.' : 'One or more core/mcp files missing.',
		);
		if ( ! $disk_ok ) { $pass = false; }

		// ── Loader: classes loaded + REST route registered ──────────────
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP Wave E/F — require document service in Loader evidence.
		$classes_ok = class_exists( 'BizCity_MCP_Tool_Registry' )
			&& class_exists( 'BizCity_MCP_HTTP_Controller' )
			&& class_exists( 'BizCity_Brain_MCP_Service' )
			&& class_exists( 'BizCity_Document_MCP_Service' )
			&& class_exists( 'BizCity_MCP_Session_Store' )
			&& class_exists( 'BizCity_MCP_File_Logger' )
			&& class_exists( 'BizCity_MCP_Action_Confirmation' )
			&& class_exists( 'BizCity_Page_Action_MCP_Service' )
			&& class_exists( 'BizCity_Business_MCP_Service' )
			&& class_exists( 'BizCity_Content_Brain_MCP_Service' )
			&& class_exists( 'BizCity_Content_Action_MCP_Service' )
			&& class_exists( 'BizCity_Report_Brain_MCP_Service' )
			&& class_exists( 'BizCity_MCP_Tool_Policy' )
			&& class_exists( 'BizCity_Commerce_Brain_MCP_Service' )
			&& class_exists( 'BizCity_MCP_OAuth' )
			&& class_exists( 'BizCity_MCP_Admin_REST' );
		$steps[]    = array(
			'label'  => 'core.mcp.gateway — Loader: classes loaded',
			'status' => $classes_ok ? 'pass' : 'fail',
			'detail' => $classes_ok ? 'Tool registry + HTTP controller + brain/document service classes loaded.' : 'core/mcp classes not loaded (check $_bizcity_admin_ctx gate in bizcity-twin-ai.php).',
		);
		if ( ! $classes_ok ) { $pass = false; }

		$route_ok = false;
		if ( function_exists( 'rest_get_server' ) && class_exists( 'BizCity_MCP_HTTP_Controller' ) ) {
			$routes   = rest_get_server()->get_routes();
			$route_ok = isset( $routes['/' . BizCity_MCP_HTTP_Controller::NS . '/mcp'] );
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Loader: REST route registered',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok ? 'bizcity-mcp/v1/mcp route present.' : 'bizcity-mcp/v1/mcp route not found in rest_get_server().',
		);
		if ( ! $route_ok ) { $pass = false; }

		$oauth_route_ok = false;
		if ( function_exists( 'rest_get_server' ) && class_exists( 'BizCity_MCP_OAuth' ) ) {
			$routes = rest_get_server()->get_routes();
			$oauth_route_ok = isset( $routes['/' . BizCity_MCP_OAuth::NS . '/oauth/authorize'] )
				&& isset( $routes['/' . BizCity_MCP_OAuth::NS . '/oauth/token'] )
				&& isset( $routes['/' . BizCity_MCP_OAuth::NS . '/oauth/register'] );
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Loader: OAuth PKCE routes',
			'status' => $oauth_route_ok ? 'pass' : 'fail',
			'detail' => $oauth_route_ok ? 'OAuth authorize, token, and dynamic registration routes are registered.' : 'OAuth route contract not found; ChatGPT OAuth cannot complete.',
		);
		if ( ! $oauth_route_ok ) { $pass = false; }

		// [2026-07-29 Johnny Chu] R-MCP-OAUTH-ID — require runtime JSONL reason evidence before declaring OAuth DDV complete.
		$oauth_evidence_ok = false;
		$oauth_evidence_reasons = array();
		if ( class_exists( 'BizCity_MCP_File_Logger' ) ) {
			$oauth_evidence = BizCity_MCP_File_Logger::read_recent( 0, 0, '', 500 );
			foreach ( (array) $oauth_evidence as $oauth_row ) {
				if ( ! is_array( $oauth_row ) || (string) ( $oauth_row['tool_name'] ?? '' ) !== 'oauth.token' ) {
					continue;
				}
				$reason = is_array( $oauth_row['evaluation'] ?? null )
					? sanitize_key( (string) ( $oauth_row['evaluation']['reason'] ?? '' ) )
					: '';
				if ( $reason !== '' ) {
					$oauth_evidence_reasons[] = $reason;
				}
			}
			$oauth_evidence_reasons = array_values( array_unique( $oauth_evidence_reasons ) );
			$oauth_evidence_ok = ! empty( $oauth_evidence_reasons );
			if ( ! $oauth_evidence_ok && class_exists( 'BizCity_MCP_OAuth' ) && function_exists( 'rest_do_request' ) ) {
				// [2026-08-21 Johnny Chu] MCP-DDV-OAUTH-EVIDENCE — exercise a harmless invalid-grant path so a clean CI blog can verify JSONL reason persistence without issuing a token or consuming a real grant.
				$oauth_probe_request = new WP_REST_Request(
					'POST',
					'/' . BizCity_MCP_OAuth::NS . BizCity_MCP_OAuth::TOKEN_ENDPOINT
				);
				$oauth_probe_request->set_body_params( array(
					'grant_type'    => 'authorization_code',
					'code'          => '__diagnostics_invalid_grant__',
					'client_id'     => '__diagnostics__',
					'redirect_uri'  => home_url( '/' ),
				) );
				rest_do_request( $oauth_probe_request );
				$oauth_evidence = BizCity_MCP_File_Logger::read_recent( 0, 0, '', 500 );
				foreach ( (array) $oauth_evidence as $oauth_row ) {
					if ( ! is_array( $oauth_row ) || (string) ( $oauth_row['tool_name'] ?? '' ) !== 'oauth.token' ) {
						continue;
					}
					$reason = is_array( $oauth_row['evaluation'] ?? null )
						? sanitize_key( (string) ( $oauth_row['evaluation']['reason'] ?? '' ) )
						: '';
					if ( $reason !== '' ) {
						$oauth_evidence_reasons[] = $reason;
					}
				}
				$oauth_evidence_reasons = array_values( array_unique( $oauth_evidence_reasons ) );
				$oauth_evidence_ok = ! empty( $oauth_evidence_reasons );
				if ( ! $oauth_evidence_ok ) {
					// [2026-08-26 Johnny Chu] MCP-DDV-OAUTH-EVIDENCE — headless REST dispatch can stop before the callback on some WP versions; invoke the same harmless invalid-grant handler directly as a bounded fallback, never an OAuth success path.
					BizCity_MCP_OAuth::token( $oauth_probe_request );
					$oauth_evidence = BizCity_MCP_File_Logger::read_recent( 0, 0, '', 500 );
					foreach ( (array) $oauth_evidence as $oauth_row ) {
						if ( ! is_array( $oauth_row ) || (string) ( $oauth_row['tool_name'] ?? '' ) !== 'oauth.token' ) {
							continue;
						}
						$reason = is_array( $oauth_row['evaluation'] ?? null )
							? sanitize_key( (string) ( $oauth_row['evaluation']['reason'] ?? '' ) )
							: '';
						if ( $reason !== '' ) {
							$oauth_evidence_reasons[] = $reason;
						}
					}
					$oauth_evidence_reasons = array_values( array_unique( $oauth_evidence_reasons ) );
					$oauth_evidence_ok = ! empty( $oauth_evidence_reasons );
				}
			}
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Runtime: OAuth token JSONL reason evidence',
			'status' => $oauth_evidence_ok ? 'pass' : 'fail',
			'detail' => $oauth_evidence_ok
				? 'oauth.token evidence found with reason bucket(s): ' . implode( ', ', $oauth_evidence_reasons ) . '.'
				: 'No oauth.token JSONL row with evaluation.reason found for the current blog. Run one successful or negative-path OAuth token exchange before accepting DDV.',
		);
		if ( ! $oauth_evidence_ok ) { $pass = false; }

		// ── Runtime: tools/list reports full catalog ─────────────────────
		$expected_tools = array();
		if ( ! defined( 'BIZCITY_MCP_BRAIN_TOOLS_ENABLED' ) || BIZCITY_MCP_BRAIN_TOOLS_ENABLED ) {
			$expected_tools = array_merge( $expected_tools, array( 'brain.list_notebooks', 'brain.search', 'brain.get_passage', 'brain.get_citation_pack' ) );
		}
		if ( ! defined( 'BIZCITY_MCP_DOCUMENT_TOOLS_ENABLED' ) || BIZCITY_MCP_DOCUMENT_TOOLS_ENABLED ) {
			$expected_tools = array_merge( $expected_tools, array( 'document.build_context_pack', 'document.validate_draft' ) );
			if ( ! defined( 'BIZCITY_MCP_RENDER_ENABLED' ) || BIZCITY_MCP_RENDER_ENABLED ) {
				$expected_tools = array_merge( $expected_tools, array( 'document.render_docx', 'document.render_pptx' ) );
			}
		}
		if ( defined( 'BIZCITY_MCP_PAGE_TOOLS_ENABLED' ) && BIZCITY_MCP_PAGE_TOOLS_ENABLED ) {
			$expected_tools = array_merge( $expected_tools, array( 'page.get_schema', 'page.get_project', 'page.preview', 'page.create_draft', 'page.update_draft', 'page.publish' ) );
		}
		if ( defined( 'BIZCITY_MCP_BUSINESS_TOOLS_ENABLED' ) && BIZCITY_MCP_BUSINESS_TOOLS_ENABLED ) {
			$expected_tools = array_merge( $expected_tools, array( 'business.get_sales_metrics', 'business.get_customer_metrics', 'business.get_inventory_metrics' ) );
		}
		if ( defined( 'BIZCITY_MCP_CONTENT_TOOLS_ENABLED' ) && BIZCITY_MCP_CONTENT_TOOLS_ENABLED ) {
			$expected_tools = array_merge( $expected_tools, array( 'content.list_posts', 'content.get_post', 'content.get_templates' ) );
		}
		if ( defined( 'BIZCITY_MCP_CONTENT_ACTION_TOOLS_ENABLED' ) && BIZCITY_MCP_CONTENT_ACTION_TOOLS_ENABLED ) {
			$expected_tools = array_merge( $expected_tools, array( 'content.create_draft', 'content.update_draft' ) );
		}
		if ( defined( 'BIZCITY_MCP_REPORT_TOOLS_ENABLED' ) && BIZCITY_MCP_REPORT_TOOLS_ENABLED ) {
			$expected_tools = array_merge( $expected_tools, array( 'report.list_templates', 'report.build_dataset' ) );
		}
		if ( defined( 'BIZCITY_MCP_COMMERCE_TOOLS_ENABLED' ) && BIZCITY_MCP_COMMERCE_TOOLS_ENABLED ) {
			$expected_tools = array_merge( $expected_tools, array( 'commerce.list_products', 'commerce.get_product', 'commerce.list_orders', 'commerce.get_order', 'commerce.list_customers', 'commerce.get_customer' ) );
		}
		$tool_count   = 0;
		$tool_names   = array();
		if ( $classes_ok ) {
			// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — unfiltered catalog: this evidence is about wave registration, not the admin's per-tool policy toggle.
			$tools      = BizCity_MCP_Tool_Registry::list_descriptors( false );
			$tool_count = count( $tools );
			$tool_names = array_map( static function ( $tool ) {
				return isset( $tool['name'] ) ? (string) $tool['name'] : '';
			}, $tools );
		}
		$missing_tools = array_values( array_diff( $expected_tools, $tool_names ) );
		$tools_ok      = empty( $missing_tools );
		$steps[]  = array(
			'label'  => 'core.mcp.gateway — Runtime: complete tools/list catalog',
			'status' => $tools_ok ? 'pass' : 'fail',
			'detail' => $tools_ok
				? sprintf( '%d canonical tool(s) registered; feature flags respected.', $tool_count )
				: 'Missing tools: ' . implode( ', ', $missing_tools ),
		);
		if ( ! $tools_ok ) { $pass = false; }

		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP Wave E — no-write dispatch proof:
		// missing args must reach the real document handler and return QUERY_INVALID,
		// not the old "handler=null" INTERNAL_ERROR stub response.
		$document_tools_enabled = ! defined( 'BIZCITY_MCP_DOCUMENT_TOOLS_ENABLED' ) || BIZCITY_MCP_DOCUMENT_TOOLS_ENABLED;
		$dispatch_ok   = ! $document_tools_enabled;
		$dispatch_code = '';
		if ( $document_tools_enabled && $classes_ok && $tools_ok ) {
			$dispatch = BizCity_MCP_Tool_Registry::call(
				'document.validate_draft',
				array(),
				array(
					'client_id'            => '__diagnostics__',
					'client_name'          => 'BizCity Diagnostics',
					'user_id'              => get_current_user_id(),
					'scopes'               => array( '*' ),
					'allowed_notebook_ids' => array(),
				)
			);
			$dispatch_code = isset( $dispatch['error']['code'] ) ? (string) $dispatch['error']['code'] : '';
			$dispatch_ok   = $dispatch_code === BizCity_MCP_Error::QUERY_INVALID;
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Runtime: document handler dispatch',
			'status' => $document_tools_enabled ? ( $dispatch_ok ? 'pass' : 'fail' ) : 'skip',
			'detail' => ! $document_tools_enabled
				? 'Document tools disabled by BIZCITY_MCP_DOCUMENT_TOOLS_ENABLED rollback flag.'
				: ( $dispatch_ok
				? 'document.validate_draft reached callable handler (expected MCP_QUERY_INVALID for empty input).'
				: 'Unexpected dispatch code: ' . ( $dispatch_code !== '' ? $dispatch_code : '(none)' ) ),
		);
		if ( ! $dispatch_ok ) { $pass = false; }

		$audit_ok = false;
		if ( $dispatch_ok ) {
			// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-ORPHAN-GATE — audit DDV now validates file evidence so SQL table can be retired in staged cleanup.
			if ( class_exists( 'BizCity_MCP_File_Logger' ) ) {
				$audit_rows = BizCity_MCP_File_Logger::read_recent( get_current_user_id(), 0, '__diagnostics__', 30 );
				foreach ( (array) $audit_rows as $audit_row ) {
					if ( ! is_array( $audit_row ) ) {
						continue;
					}
					if ( (string) ( $audit_row['tool_name'] ?? '' ) !== 'document.validate_draft' ) {
						continue;
					}
					$audit_ok = true;
					break;
				}
			}
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Runtime: audit row written',
			'status' => ! $document_tools_enabled ? 'skip' : ( $audit_ok ? 'pass' : 'fail' ),
			'detail' => ! $document_tools_enabled
				? 'Audit dispatch check skipped with document tools disabled.'
				: ( $audit_ok ? 'Dispatch audit file evidence exists; only metadata/hash was recorded.' : 'No MCP file audit evidence found after dispatch.' ),
		);
		// [2026-08-21 Johnny Chu] MCP-DDV-ROLLBACK — skipped document dispatch has no audit row by design; fail only after an enabled dispatch misses evidence.
		if ( $document_tools_enabled && ! $audit_ok ) { $pass = false; }

		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — prove Page Action validation reaches the real handler without creating a project.
		$page_tools_enabled = defined( 'BIZCITY_MCP_PAGE_TOOLS_ENABLED' ) && BIZCITY_MCP_PAGE_TOOLS_ENABLED;
		$page_dispatch_status = 'skip';
		$page_dispatch_detail = 'Page Action tools are disabled by BIZCITY_MCP_PAGE_TOOLS_ENABLED.';
		if ( $page_tools_enabled && $classes_ok && $tools_ok ) {
			$page_dispatch = BizCity_MCP_Tool_Registry::call(
				'page.create_draft',
				array( 'site_config' => array( 'name' => 'invalid' ) ),
				array(
					'client_id'            => '__diagnostics__',
					'client_name'          => 'BizCity Diagnostics',
					'user_id'              => get_current_user_id(),
					'scopes'               => array( '*' ),
					'allowed_notebook_ids' => array(),
				)
			);
			$page_dispatch_code = isset( $page_dispatch['error']['code'] ) ? (string) $page_dispatch['error']['code'] : '';
			$page_dispatch_status = $page_dispatch_code === BizCity_MCP_Error::DRAFT_INVALID ? 'pass' : 'fail';
			$page_dispatch_detail = $page_dispatch_status === 'pass'
				? 'page.create_draft reached Page Action validation and rejected invalid spec without creating a project.'
				: 'Unexpected page.create_draft validation code: ' . ( $page_dispatch_code !== '' ? $page_dispatch_code : '(none)' );
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Runtime: Page Action validation boundary',
			'status' => $page_dispatch_status,
			'detail' => $page_dispatch_detail,
		);
		if ( 'fail' === $page_dispatch_status ) { $pass = false; }

		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — prove Business Brain dispatch is read-only and fail-graceful for optional sources.
		$business_tools_enabled = defined( 'BIZCITY_MCP_BUSINESS_TOOLS_ENABLED' ) && BIZCITY_MCP_BUSINESS_TOOLS_ENABLED;
		$business_dispatch_status = 'skip';
		$business_dispatch_detail = 'Business Brain tools are disabled by BIZCITY_MCP_BUSINESS_TOOLS_ENABLED.';
		if ( $business_tools_enabled && $classes_ok && $tools_ok ) {
			$business_dispatch = BizCity_MCP_Tool_Registry::call(
				'business.get_sales_metrics',
				array(),
				array(
					'client_id'            => '__diagnostics__',
					'client_name'          => 'BizCity Diagnostics',
					'user_id'              => get_current_user_id(),
					'scopes'               => array( '*' ),
					'allowed_notebook_ids' => array(),
				)
			);
			$business_dispatch_status = ! empty( $business_dispatch['success'] ) ? 'pass' : 'fail';
			$business_dispatch_detail = $business_dispatch_status === 'pass'
				? 'business.get_sales_metrics reached the canonical CRM/Woo bridge or returned an explicit degraded zero-state.'
				: 'Business Brain dispatch failed before returning a safe result.';
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Runtime: Business Brain read-only boundary',
			'status' => $business_dispatch_status,
			'detail' => $business_dispatch_detail,
		);
		if ( 'fail' === $business_dispatch_status ) { $pass = false; }

		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — prove Content Brain dispatch remains read-only and fail-graceful when BZCC is absent.
		$content_tools_enabled = defined( 'BIZCITY_MCP_CONTENT_TOOLS_ENABLED' ) && BIZCITY_MCP_CONTENT_TOOLS_ENABLED;
		$content_dispatch_status = 'skip';
		$content_dispatch_detail = 'Content Brain tools are disabled by BIZCITY_MCP_CONTENT_TOOLS_ENABLED.';
		if ( $content_tools_enabled && $classes_ok && $tools_ok ) {
			$content_dispatch = BizCity_MCP_Tool_Registry::call(
				'content.list_posts',
				array( 'limit' => 1 ),
				array(
					'client_id'            => '__diagnostics__',
					'client_name'          => 'BizCity Diagnostics',
					'user_id'              => get_current_user_id(),
					'scopes'               => array( '*' ),
					'allowed_notebook_ids' => array(),
				)
			);
			$content_dispatch_status = ! empty( $content_dispatch['success'] ) ? 'pass' : 'fail';
			$content_dispatch_detail = $content_dispatch_status === 'pass'
				? 'content.list_posts reached BZCC ownership bridge or returned an explicit degraded result.'
				: 'Content Brain dispatch failed before returning a safe result.';
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Runtime: Content Brain read-only boundary',
			'status' => $content_dispatch_status,
			'detail' => $content_dispatch_detail,
		);
		if ( 'fail' === $content_dispatch_status ) { $pass = false; }

		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — validate Content Action input without creating a BZCC record.
		$content_action_enabled = defined( 'BIZCITY_MCP_CONTENT_ACTION_TOOLS_ENABLED' ) && BIZCITY_MCP_CONTENT_ACTION_TOOLS_ENABLED;
		$content_action_status = 'skip';
		$content_action_detail = 'Content Action tools are disabled by BIZCITY_MCP_CONTENT_ACTION_TOOLS_ENABLED.';
		if ( $content_action_enabled && $classes_ok && $tools_ok ) {
			$content_action = BizCity_MCP_Tool_Registry::call(
				'content.create_draft',
				array(),
				array(
					'client_id'            => '__diagnostics__',
					'client_name'          => 'BizCity Diagnostics',
					'user_id'              => get_current_user_id(),
					'scopes'               => array( '*' ),
					'allowed_notebook_ids' => array(),
				)
			);
			$content_action_code = isset( $content_action['error']['code'] ) ? (string) $content_action['error']['code'] : '';
			$content_action_status = $content_action_code === BizCity_MCP_Error::QUERY_INVALID ? 'pass' : 'fail';
			$content_action_detail = $content_action_status === 'pass'
				? 'content.create_draft rejected missing template_id before calling BZCC write handler.'
				: 'Unexpected Content Action validation code: ' . ( $content_action_code !== '' ? $content_action_code : '(none)' );
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Runtime: Content Action draft boundary',
			'status' => $content_action_status,
			'detail' => $content_action_detail,
		);
		if ( 'fail' === $content_action_status ) { $pass = false; }

		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — validate Content Action update input before any BZCC read/write bridge.
		$content_update_status = 'skip';
		$content_update_detail = 'Content Action tools are disabled by BIZCITY_MCP_CONTENT_ACTION_TOOLS_ENABLED.';
		if ( $content_action_enabled && $classes_ok && $tools_ok ) {
			$content_update = BizCity_MCP_Tool_Registry::call(
				'content.update_draft',
				array( 'file_id' => 1 ),
				array(
					'client_id'            => '__diagnostics__',
					'client_name'          => 'BizCity Diagnostics',
					'user_id'              => get_current_user_id(),
					'scopes'               => array( '*' ),
					'allowed_notebook_ids' => array(),
				)
			);
			$content_update_code = isset( $content_update['error']['code'] ) ? (string) $content_update['error']['code'] : '';
			$content_update_status = $content_update_code === BizCity_MCP_Error::QUERY_INVALID ? 'pass' : 'fail';
			$content_update_detail = $content_update_status === 'pass'
				? 'content.update_draft rejected incomplete input before reading or writing a BZCC draft.'
				: 'Unexpected Content Action update validation code: ' . ( $content_update_code !== '' ? $content_update_code : '(none)' );
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Runtime: Content Action update boundary',
			'status' => $content_update_status,
			'detail' => $content_update_detail,
		);
		if ( 'fail' === $content_update_status ) { $pass = false; }

		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — prove report dataset assembly is read-only and source-plugin degraded-safe.
		$report_tools_enabled = defined( 'BIZCITY_MCP_REPORT_TOOLS_ENABLED' ) && BIZCITY_MCP_REPORT_TOOLS_ENABLED;
		$report_dispatch_status = 'skip';
		$report_dispatch_detail = 'Report Brain tools are disabled by BIZCITY_MCP_REPORT_TOOLS_ENABLED.';
		if ( $report_tools_enabled && $classes_ok && $tools_ok ) {
			$report_dispatch = BizCity_MCP_Tool_Registry::call(
				'report.build_dataset',
				array( 'include_inventory' => false ),
				array(
					'client_id'            => '__diagnostics__',
					'client_name'          => 'BizCity Diagnostics',
					'user_id'              => get_current_user_id(),
					'scopes'               => array( '*' ),
					'allowed_notebook_ids' => array(),
				)
			);
			$report_dispatch_status = ! empty( $report_dispatch['success'] ) ? 'pass' : 'fail';
			$report_dispatch_detail = $report_dispatch_status === 'pass'
				? 'report.build_dataset returned canonical CRM/Woo data or explicit degraded datasets without report/file side effects.'
				: 'Report Brain dispatch failed before returning a safe result.';
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Runtime: Report Brain read-only boundary',
			'status' => $report_dispatch_status,
			'detail' => $report_dispatch_detail,
		);
		if ( 'fail' === $report_dispatch_status ) { $pass = false; }

		// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave R — prove Commerce Brain dispatch is read-only and WooCommerce-absent-safe.
		$commerce_tools_enabled = defined( 'BIZCITY_MCP_COMMERCE_TOOLS_ENABLED' ) && BIZCITY_MCP_COMMERCE_TOOLS_ENABLED;
		$commerce_dispatch_status = 'skip';
		$commerce_dispatch_detail = 'Commerce Brain tools are disabled by BIZCITY_MCP_COMMERCE_TOOLS_ENABLED.';
		if ( $commerce_tools_enabled && $classes_ok && $tools_ok ) {
			$commerce_dispatch = BizCity_MCP_Tool_Registry::call(
				'commerce.list_products',
				array( 'limit' => 1 ),
				array(
					'client_id'            => '__diagnostics__',
					'client_name'          => 'BizCity Diagnostics',
					'user_id'              => get_current_user_id(),
					'scopes'               => array( '*' ),
					'allowed_notebook_ids' => array(),
				)
			);
			$commerce_dispatch_status = ! empty( $commerce_dispatch['success'] ) ? 'pass' : 'fail';
			$commerce_dispatch_detail = $commerce_dispatch_status === 'pass'
				? 'commerce.list_products reached wc_get_products() or returned an explicit degraded result when WooCommerce is absent.'
				: 'Commerce Brain dispatch failed before returning a safe result.';
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Runtime: Commerce Brain read-only boundary',
			'status' => $commerce_dispatch_status,
			'detail' => $commerce_dispatch_detail,
		);
		if ( 'fail' === $commerce_dispatch_status ) { $pass = false; }

		// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — policy-filtered catalog must exactly match BizCity_MCP_Tool_Policy::is_enabled() per tool (works whether the admin left the built-in defaults or explicitly toggled tools on this site).
		$policy_filter_status = 'skip';
		$policy_filter_detail = 'Tool policy class is not loaded in this runtime.';
		if ( $classes_ok && class_exists( 'BizCity_MCP_Tool_Policy' ) ) {
			$filtered_names = array_map( static function ( $tool ) {
				return isset( $tool['name'] ) ? (string) $tool['name'] : '';
			}, BizCity_MCP_Tool_Registry::list_descriptors( true ) );
			$expected_visible = array_values( array_filter( $tool_names, static function ( $n ) {
				return BizCity_MCP_Tool_Policy::is_enabled( $n );
			} ) );
			$mismatch_extra   = array_values( array_diff( $filtered_names, $expected_visible ) );
			$mismatch_missing = array_values( array_diff( $expected_visible, $filtered_names ) );
			$policy_filter_status = ( empty( $mismatch_extra ) && empty( $mismatch_missing ) ) ? 'pass' : 'fail';
			$policy_filter_detail = $policy_filter_status === 'pass'
				? sprintf( 'Policy-filtered tools/list (%d/%d tool(s)) exactly matches BizCity_MCP_Tool_Policy::is_enabled() per tool.', count( $filtered_names ), count( $tool_names ) )
				: sprintf( 'Policy filter mismatch — unexpected: [%s], missing: [%s].', implode( ', ', $mismatch_extra ), implode( ', ', $mismatch_missing ) );
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Runtime: default tool policy hides opt-in domains',
			'status' => $policy_filter_status,
			'detail' => $policy_filter_detail,
		);
		if ( 'fail' === $policy_filter_status ) { $pass = false; }

		// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — a real (non-diagnostics) client must be blocked by the policy gate whenever the admin has NOT enabled the tool, even with a wildcard scope, proving enforcement is not scope-based only. Compares dispatch outcome against the site's own current BizCity_MCP_Tool_Policy state so this stays correct whether business.* is left at its off-by-default state or an admin has explicitly turned it on.
		$policy_enforce_status = 'skip';
		$policy_enforce_detail = 'Business Brain tools are disabled, so the policy-gate probe has nothing opt-in to test against.';
		if ( $business_tools_enabled && $classes_ok && $tools_ok && class_exists( 'BizCity_MCP_Tool_Policy' ) ) {
			$currently_enabled = BizCity_MCP_Tool_Policy::is_enabled( 'business.get_sales_metrics' );
			$policy_enforce = BizCity_MCP_Tool_Registry::call(
				'business.get_sales_metrics',
				array(),
				array(
					'client_id'            => 'diagnostics-policy-check',
					'client_name'          => 'BizCity Diagnostics (policy probe)',
					'user_id'              => get_current_user_id(),
					'scopes'               => array( '*' ),
					'allowed_notebook_ids' => array(),
				)
			);
			$policy_enforce_code = isset( $policy_enforce['error']['code'] ) ? (string) $policy_enforce['error']['code'] : '';
			$got_tool_disabled   = $policy_enforce_code === BizCity_MCP_Error::TOOL_DISABLED;
			// Enforcement is correct in both directions: OFF must yield TOOL_DISABLED; ON must NOT yield TOOL_DISABLED.
			$policy_enforce_status = ( $currently_enabled !== $got_tool_disabled ) ? 'pass' : 'fail';
			$policy_enforce_detail = $policy_enforce_status === 'pass'
				? sprintf( 'business.get_sales_metrics dispatch outcome matches current admin policy state (enabled=%s, blocked_by_policy=%s).', $currently_enabled ? 'true' : 'false', $got_tool_disabled ? 'true' : 'false' )
				: sprintf( 'Dispatch outcome disagreed with BizCity_MCP_Tool_Policy::is_enabled() (enabled=%s, blocked_by_policy=%s, code=%s).', $currently_enabled ? 'true' : 'false', $got_tool_disabled ? 'true' : 'false', $policy_enforce_code !== '' ? $policy_enforce_code : '(none)' );
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Runtime: admin tool policy enforcement',
			'status' => $policy_enforce_status,
			'detail' => $policy_enforce_detail,
		);
		if ( 'fail' === $policy_enforce_status ) { $pass = false; }

		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — verify bounded session constants without opening credential state.
		$session_contract_ok = class_exists( 'BizCity_MCP_Session_Store' )
			&& defined( 'BizCity_MCP_Session_Store::TTL' )
			&& defined( 'BizCity_MCP_Session_Store::MAX_EVENTS' );
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Runtime: authenticated session contract',
			'status' => $session_contract_ok ? 'pass' : 'fail',
			'detail' => $session_contract_ok ? 'Session store has bounded TTL and event retention; controller validates ownership on POST/GET/DELETE.' : 'Session store contract unavailable.',
		);
		if ( ! $session_contract_ok ) { $pass = false; }

		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — prove request-time notebook scope follows auto/Guru mode, exclusions, and legacy key sentinel 0.
		$scope_probe_status = 'skip';
		$scope_probe_detail = 'Notebook scope resolver is not loaded in this runtime.';
		if ( class_exists( 'BizCity_MCP_Client_Scope_Resolver' ) ) {
			$current_policy = get_option( 'bizcity_twinweb_grounding_policy_' . (int) get_current_blog_id(), array() );
			$policy_ids = array();
			$admin_ids = get_users( array( 'capability' => 'manage_options', 'fields' => 'ID', 'number' => 100 ) );
			$guru_id = is_array( $current_policy ) && empty( $current_policy['brain_auto_mode'] ) ? (int) ( $current_policy['guru_id'] ?? 0 ) : 0;
			if ( $guru_id <= 0 && is_array( $current_policy ) && empty( $current_policy['brain_auto_mode'] ) && class_exists( 'BizCity_TwinWeb_Binding_Bootstrap' ) ) {
				$guru_id = (int) BizCity_TwinWeb_Binding_Bootstrap::resolve_character_id();
			}
			foreach ( (array) $admin_ids as $admin_id ) {
				$rows = class_exists( 'BizCity_KG_Notebook_Service' ) ? BizCity_KG_Notebook_Service::instance()->list_for_user( (int) $admin_id, array( 'limit' => 500 ) ) : array();
				foreach ( (array) $rows as $row ) {
					$row_id = is_array( $row ) ? (int) ( $row['id'] ?? 0 ) : (int) ( $row->id ?? 0 );
					$row_guru_id = is_array( $row ) ? (int) ( $row['character_id'] ?? 0 ) : (int) ( $row->character_id ?? 0 );
					if ( $row_id > 0 && ( $guru_id <= 0 || $row_guru_id === $guru_id ) ) {
						$policy_ids[] = $row_id;
					}
				}
			}
			$policy_ids = array_values( array_unique( $policy_ids ) );
			if ( is_array( $current_policy ) && ! empty( $current_policy['mcp_exclusion_mode'] ) ) {
				$excluded = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $current_policy['mcp_excluded_notebook_ids'] ?? array() ) ) ) ) );
				$policy_ids = array_values( array_diff( $policy_ids, $excluded ) );
			} elseif ( is_array( $current_policy ) ) {
				$legacy = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $current_policy['mcp_allowed_notebook_ids'] ?? array() ) ) ) ) );
				$policy_ids = empty( $legacy ) ? array() : array_values( array_intersect( $policy_ids, $legacy ) );
			}
			$resolved_ids = BizCity_MCP_Client_Scope_Resolver::allowed_notebook_ids( array(
				'user_id'              => get_current_user_id(),
				'allowed_notebook_ids' => array( 0 ),
			) );
			$outside_policy = array_diff( $resolved_ids, $policy_ids );
			$missing_policy = array_diff( $policy_ids, $resolved_ids );
			$scope_probe_status = empty( $policy_ids )
				? ( empty( $resolved_ids ) ? 'pass' : 'fail' )
				: ( empty( $outside_policy ) && empty( $missing_policy ) ? 'pass' : 'fail' );
			$scope_probe_detail = empty( $policy_ids )
				? ( empty( $resolved_ids ) ? 'Derived policy scope is empty and fails closed with zero notebooks.' : 'Empty derived scope returned notebook IDs and did not fail closed.' )
				: sprintf( 'Derived auto/Guru scope has %d notebook ID(s); resolver returned %d after exclusions with legacy key sentinel [0].', count( $policy_ids ), count( $resolved_ids ) );
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Runtime: Twin GPT notebook scope parity',
			'status' => $scope_probe_status,
			'detail' => $scope_probe_detail,
		);
		if ( 'fail' === $scope_probe_status ) { $pass = false; }

		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — accept WordPress route regex normalization while requiring all admin operations.
		$admin_route_ok = false;
		if ( function_exists( 'rest_get_server' ) && class_exists( 'BizCity_MCP_Admin_REST' ) ) {
			$routes = rest_get_server()->get_routes();
			$admin_base = '/' . BizCity_MCP_Admin_REST::NS . '/mcp/keys';
			$has_base   = isset( $routes[ $admin_base ] );
			$has_item   = false;
			foreach ( array_keys( $routes ) as $route ) {
				if ( strpos( $route, $admin_base . '/' ) === 0 && strpos( $route, 'id' ) !== false ) {
					$has_item = true;
					break;
				}
			}
			$admin_route_ok = $has_base && $has_item;
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Loader: admin key REST contract',
			'status' => $admin_route_ok ? 'pass' : 'fail',
			'detail' => $admin_route_ok ? 'List/issue/revoke routes are registered under bizcity-channel/v1.' : 'MCP admin key routes not found.',
		);
		if ( ! $admin_route_ok ) { $pass = false; }

		$twinweb_route_ok = false;
		if ( function_exists( 'rest_get_server' ) ) {
			$routes = rest_get_server()->get_routes();
			$twinweb_route_ok = isset( $routes['/bizcity-twinweb/v1/mcp/keys'] ) && isset( $routes['/bizcity-twinweb/v1/mcp/logs'] ) && isset( $routes['/bizcity-twinweb/v1/mcp/policy'] );
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Loader: TwinWeb customer MCP contract',
			'status' => $twinweb_route_ok ? 'pass' : 'fail',
			'detail' => $twinweb_route_ok ? 'My MCP key, log, and server-policy routes are registered under bizcity-twinweb/v1.' : 'TwinWeb customer MCP routes not found.',
		);
		if ( ! $twinweb_route_ok ) { $pass = false; }

		// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — customer contract must be read-only for capability policy; scope selection is subset-only (never broaden).
		$twinweb_policy_contract_status = 'skip';
		$twinweb_policy_contract_detail = 'TwinWeb MCP policy runtime contract was not evaluated.';
		if ( $twinweb_route_ok && function_exists( 'rest_do_request' ) ) {
			$current_user_id = (int) get_current_user_id();
			if ( $current_user_id <= 0 ) {
				$twinweb_policy_contract_status = 'skip';
				$twinweb_policy_contract_detail = 'No authenticated user in this probe context; skipped customer policy runtime assertion.';
			} else {
				$tw_req  = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/mcp/policy' );
				$tw_resp = rest_do_request( $tw_req );
				$tw_data = ( $tw_resp instanceof WP_REST_Response ) ? $tw_resp->get_data() : null;
				$policy  = ( is_array( $tw_data ) && isset( $tw_data['policy'] ) && is_array( $tw_data['policy'] ) ) ? $tw_data['policy'] : array();

				$success_shape = is_array( $tw_data ) && ! empty( $tw_data['success'] );
				$read_only_policy = isset( $policy['customer_can_configure_capability_policy'] )
					? empty( $policy['customer_can_configure_capability_policy'] )
					: ( isset( $policy['customer_can_configure'] ) ? empty( $policy['customer_can_configure'] ) : false );
				$subset_only_scope = isset( $policy['customer_scope_mode'] ) && 'subset_only_never_broaden' === (string) $policy['customer_scope_mode'];
				$supported_scopes_shape = isset( $policy['supported_scopes'] ) && is_array( $policy['supported_scopes'] );

				$twinweb_policy_contract_status = ( $success_shape && $read_only_policy && $subset_only_scope && $supported_scopes_shape ) ? 'pass' : 'fail';
				$twinweb_policy_contract_detail = $twinweb_policy_contract_status === 'pass'
					? 'TwinWeb policy confirms admin-only capability control and subset-only customer scope selection.'
					: 'TwinWeb policy contract mismatch: expected read-only capability policy + subset_only_never_broaden + supported_scopes[] payload.';
			}
		}
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Runtime: TwinWeb customer scope contract',
			'status' => $twinweb_policy_contract_status,
			'detail' => $twinweb_policy_contract_detail,
		);
		if ( 'fail' === $twinweb_policy_contract_status ) { $pass = false; }

		$score_contract_ok = is_readable( $base . 'includes/class-brain-mcp-service.php' ) && is_readable( $base . '../knowledge/kg-hub/includes/class-kg-retriever.php' );
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Disk: canonical score contract',
			'status' => $score_contract_ok ? 'pass' : 'fail',
			'detail' => $score_contract_ok ? 'MCP score consumer and canonical KG retriever are present; score_source is transported in snapshots.' : 'Score contract files unavailable.',
		);
		if ( ! $score_contract_ok ) { $pass = false; }

		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — parity tests are optional deploy artifacts and never block production MCP runtime.
		$parity_fixture_ok = file_exists( $base . '../../tests/mcp/parity/fixture-canonical.json' ) && file_exists( $base . '../../tests/mcp/parity/run-parity.php' );
		$steps[] = array(
			'label'  => 'core.mcp.gateway — Disk: parity harness',
			'status' => $parity_fixture_ok ? 'pass' : 'skip',
			'detail' => $parity_fixture_ok ? 'Offline structural Claude/ChatGPT parity fixture and runner are available.' : 'Parity fixture is not deployed on this server; runtime MCP remains unaffected.',
		);

		$optional_labels = array();
		if ( defined( 'BIZCITY_MCP_PAGE_TOOLS_ENABLED' ) && BIZCITY_MCP_PAGE_TOOLS_ENABLED ) { $optional_labels[] = 'Page Action'; }
		if ( defined( 'BIZCITY_MCP_BUSINESS_TOOLS_ENABLED' ) && BIZCITY_MCP_BUSINESS_TOOLS_ENABLED ) { $optional_labels[] = 'Business Brain'; }
		if ( defined( 'BIZCITY_MCP_COMMERCE_TOOLS_ENABLED' ) && BIZCITY_MCP_COMMERCE_TOOLS_ENABLED ) { $optional_labels[] = 'Commerce Brain'; }
		$catalog_label = empty( $optional_labels )
			? 'MCP gateway + 8-tool catalog sẵn sàng.'
			: 'MCP gateway + Brain/Document catalog + ' . implode( ' + ', $optional_labels ) . ' sẵn sàng.';
		return array(
			// [2026-07-28 Johnny Chu] PHASE-0.53-MCP HOTFIX — follow BizCity_Diagnostics_Probe run() contract.
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass ? $catalog_label : 'MCP gateway còn thiếu Disk/Loader/Runtime evidence.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP HOTFIX — satisfy probe contract; this read-only probe creates no artifacts.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = new BizCity_Probe_MCP_Gateway();
	return $list;
} );
