<?php
/**
 * BizCity_MCP_Tool_Registry — registers MCP tool descriptors + dispatches
 * `tools/call` to their handlers with scope enforcement and a uniform
 * response envelope (BizCity_MCP_Error).
 *
 * Document.* tools (Wave E/F: build_context_pack, validate_draft,
 * render_docx, render_pptx) delegate to BizCity_Document_MCP_Service.
 * DOCX/PPTX tools prepare validated browser-render packages for the existing
 * bizcity-doc exporters; no duplicate server-side binary renderer is created.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-07-27 (PHASE-0.53-MCP Wave A)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-27 Johnny Chu] PHASE-0.53-MCP — new file, tool registry + dispatcher.
final class BizCity_MCP_Tool_Registry {

	/** @var array<string,array> */
	private static $tools  = array();
	private static $booted = false;

	// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-3 — descriptor contract of the one MCP standard (fixture bridge.tools_list.owner.json).
	const TOOL_CONTRACT = 'bizcity-mcp-tool@1.0.0';

	/**
	 * [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-3 — mirror of docs/contracts/BIZCITY-MCP-STANDARD-v1.json
	 * "existing_tool_modes": modes for tools registered before 0.88 so a delegated (cell) principal can reach them by mode.
	 * Keep in sync with the JSON (bin/validate-mcp-standard.mjs checks every pair). Tools not listed (document.*, page.*,
	 * content.*, brain.context.*) have no mode and are never offered to delegated principals.
	 */
	const EXISTING_TOOL_MODES = array(
		'brain.order.summary'           => 'orders',
		'business.get_customer_metrics' => 'sales',
		'report.list_templates'         => 'sales',
		'report.build_dataset'          => 'sales',
		'pipeline.get_metrics'          => 'sales',
		'commerce.list_products'        => 'stock',
		'commerce.get_product'          => 'stock',
		'commerce.list_orders'          => 'orders',
		'commerce.get_order'            => 'orders',
		'commerce.list_customers'       => 'customers',
		'commerce.get_customer'         => 'customers',
		'brain.get_citation_pack'       => 'notebook',
	);

	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		self::register_brain_tools();
		self::register_document_tools();
		self::register_page_tools();
		self::register_business_tools();
		self::register_content_brain_tools();
		self::register_content_action_tools();
		self::register_report_brain_tools();
		self::register_pipeline_brain_tools();
		self::register_commerce_tools();
		// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1 — other services (CRM, orders, inventory, booking, automation: lane CL-B)
		// register their canonical tools here through self::register() with the same descriptor keys.
		do_action( 'bizcity_mcp_register_tools' );
	}

	/**
	 * Descriptor keys (all optional except handler):
	 *  title, description, input_schema, read_only, destructive, idempotent, required_scope, handler(args, ctx);
	 *  [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-3 —
	 *  mode (agent mode or '*business'; null = not offered to delegated principals), scopes (default [required_scope]),
	 *  confirm ('never'|'always'), preview(args, ctx) → array|WP_Error (REQUIRED when confirm = always), llm_alias,
	 *  fallback_pack, output_schema, open_world, alias_of (canonical name when this entry is a deprecated alias), since.
	 *
	 * @return bool false when the descriptor is refused (confirm=always without a preview).
	 */
	public static function register( $name, array $descriptor ) {
		$tool = array_merge( array(
			'name'           => $name,
			'title'          => $name,
			'description'    => '',
			'input_schema'   => array( 'type' => 'object', 'properties' => new stdClass(), 'required' => array() ),
			'read_only'      => true,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => null,
			// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-3 — one-MCP-standard keys.
			'mode'           => null,
			'scopes'         => null,
			'confirm'        => 'never',
			'llm_alias'      => null,
			'fallback_pack'  => null,
			'output_schema'  => null,
			'open_world'     => false,
			'preview'        => null,
			'alias_of'       => null,
			'since'          => null,
		), $descriptor );
		if ( null === $tool['mode'] && isset( self::EXISTING_TOOL_MODES[ $name ] ) ) {
			$tool['mode'] = self::EXISTING_TOOL_MODES[ $name ];
		}
		$tool['scopes']  = array_values( array_unique( array_filter( array_map( 'strval', is_array( $tool['scopes'] ) && $tool['scopes'] ? $tool['scopes'] : array( $tool['required_scope'] ) ) ) ) );
		$tool['confirm'] = 'always' === $tool['confirm'] ? 'always' : 'never';
		if ( 'always' === $tool['confirm'] && ! is_callable( $tool['preview'] ) ) {
			// A write that must be confirmed cannot be offered without a side-effect-free preview (Q88-1).
			error_log( '[bizcity-mcp] tool ' . $name . ' refused: confirm=always needs a preview callable.' );
			return false;
		}
		self::$tools[ $name ] = $tool;
		return true;
	}

	/**
	 * @param bool  $apply_policy When true (default, used by the real `tools/list`
	 * protocol response), tools the site admin has turned off via
	 * BizCity_MCP_Tool_Policy are omitted. Diagnostics passes false to inspect
	 * the full wave-level registered catalog regardless of the admin policy.
	 * @param array $ctx [2026-10-01 Claude Opus 5.5] PHASE-0.88 L3-1 — auth context; a delegated (cell) principal sees the
	 * tools whose mode is one of its modes (no deprecated aliases) and the admin allowlist does not apply (Q88-6).
	 * @return array Tool descriptors for the MCP `tools/list` response.
	 */
	public static function list_descriptors( $apply_policy = true, array $ctx = array() ) {
		self::boot();
		$delegated = class_exists( 'BizCity_MCP_Delegation' ) && BizCity_MCP_Delegation::is_delegated( $ctx );
		$out = array();
		foreach ( self::$tools as $name => $t ) {
			if ( $delegated ) {
				if ( ! BizCity_MCP_Delegation::tool_listed( $t, $ctx ) ) {
					continue;
				}
			} elseif ( $apply_policy && ! self::policy_allows( $name, $ctx ) ) {
				// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — hide tools the admin disabled from the advertised catalog.
				continue;
			}
			$out[] = self::descriptor( $name, $t );
		}
		return $out;
	}

	/**
	 * [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-3 — the public descriptor (bizcity-mcp-tool@1): outputSchema when set,
	 * annotations incl. openWorldHint, `_meta.bizcity`. Key order follows fixture bridge.tools_list.owner.json.
	 */
	private static function descriptor( $name, array $t ) {
		$d = array(
			'name'        => $name,
			'title'       => $t['title'],
			'description' => $t['description'],
			'inputSchema' => $t['input_schema'],
		);
		// bizcity-mcp-tool@1 makes outputSchema mandatory: a tool without its own schema advertises the generic envelope
		// (success + data.as_of), which every result now satisfies.
		$d['outputSchema'] = is_array( $t['output_schema'] ) && $t['output_schema'] ? $t['output_schema'] : self::envelope_schema( array() );
		$d['annotations'] = array(
			'readOnlyHint'    => (bool) $t['read_only'],
			'destructiveHint' => (bool) $t['destructive'],
			'idempotentHint'  => (bool) $t['idempotent'],
			'openWorldHint'   => (bool) $t['open_world'],
		);
		$meta = array(
			'contract'         => self::TOOL_CONTRACT,
			'mode'             => null === $t['mode'] ? null : (string) $t['mode'],
			'scopes'           => array_values( $t['scopes'] ),
			'confirm'          => $t['confirm'],
			'llm_alias'        => null === $t['llm_alias'] ? null : (string) $t['llm_alias'],
			'fallback_pack'    => null === $t['fallback_pack'] ? null : (string) $t['fallback_pack'],
			'deprecated_alias' => null,
			'since'            => null === $t['since'] ? null : (string) $t['since'],
		);
		if ( ! empty( $t['alias_of'] ) ) {
			$meta['deprecated_alias_of'] = (string) $t['alias_of'];
		}
		$d['_meta'] = array( 'bizcity' => $meta );
		return $d;
	}

	/**
	 * [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-4 — admin allowlist for external clients. A canonical tool the admin has
	 * never decided on inherits the state of its deprecated alias (brain.search ON ⇒ knowledge.search ON), so renaming does
	 * not silently close a tool ChatGPT/Claude already use, nor open one the admin closed.
	 */
	private static function policy_allows( $name, array $ctx ) {
		if ( ! class_exists( 'BizCity_MCP_Tool_Policy' ) ) {
			return true;
		}
		if ( BizCity_MCP_Tool_Policy::is_enabled( $name, $ctx ) ) {
			return true;
		}
		if ( array_key_exists( $name, BizCity_MCP_Tool_Policy::get_enabled_map() ) ) {
			return false;
		}
		foreach ( self::$tools as $other => $t ) {
			if ( (string) $t['alias_of'] === (string) $name && BizCity_MCP_Tool_Policy::is_enabled( $other, $ctx ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tests only: forget every registered tool so a test can boot a clean catalog.
	 */
	public static function reset() {
		self::$tools  = array();
		self::$booted = false;
	}

	/**
	 * Full tool catalog with admin-policy metadata for the MCP Access settings
	 * screen (Channel Gateway SPA). Unlike list_descriptors(), this always
	 * returns every registered tool (never filtered) so the checkbox UI can
	 * show currently-disabled tools too.
	 *
	 * @return array<int,array>
	 */
	public static function catalog_for_settings() {
		self::boot();
		$out = array();
		foreach ( self::$tools as $name => $t ) {
			$out[] = array(
				'name'            => $name,
				'title'           => $t['title'],
				'description'     => $t['description'],
				'group'           => self::group_for( $name ),
				'layer'           => ! empty( $t['read_only'] ) ? 'brain' : 'action',
				'required_scope'  => $t['required_scope'],
				'default_enabled' => class_exists( 'BizCity_MCP_Tool_Policy' ) ? BizCity_MCP_Tool_Policy::default_enabled_for( $name ) : true,
				'enabled'         => class_exists( 'BizCity_MCP_Tool_Policy' ) ? BizCity_MCP_Tool_Policy::is_enabled( $name ) : true,
			);
		}
		return $out;
	}

	/**
	 * @return string[] Every currently-registered tool name (all waves loaded on this deploy).
	 */
	public static function all_registered_tool_names() {
		self::boot();
		return array_keys( self::$tools );
	}

	private static function group_for( $name ) {
		$prefix = strstr( $name, '.', true );
		$prefix = $prefix ? $prefix : $name;
		$labels = array(
			'brain'    => 'Brain (đọc tri thức)',
			'document' => 'Document (soạn văn bản)',
			'page'     => 'Landing Page (PageBuilder)',
			'business' => 'Business Metrics (KPI)',
			'content'  => 'Content (đăng/quản lý bài viết)',
			'report'   => 'Report (báo cáo)',
			'commerce' => 'WooCommerce (sản phẩm/đơn hàng/khách hàng)',
			// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1 — groups of the canonical one-MCP-standard names.
			'knowledge' => 'Tri thức (sổ ghi chú)',
			'sales'     => 'Doanh số',
			'order'     => 'Đơn hàng',
			// [2026-10-01 Claude Opus 5.5] PHASE-0.88 CL-B — groups of the action tools.
			'crm'        => 'Khách hàng (CRM)',
			'staff'      => 'Nhân sự',
			'inventory'  => 'Tồn kho',
			'booking'    => 'Lịch hẹn',
			'automation' => 'Tự động hoá',
		);
		return isset( $labels[ $prefix ] ) ? $labels[ $prefix ] : $prefix;
	}

	/**
	 * Dispatch a `tools/call`. Always returns the BizCity_MCP_Error envelope
	 * shape (never throws, never returns WP_Error) so the HTTP controller
	 * can serialize it directly.
	 *
	 * @return array
	 */
	public static function call( $name, array $args, array $ctx ) {
		self::boot();
		$t0 = microtime( true );
		// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — effective runtime access is the intersection of:
		// (1) rollback-flag registration (tool exists in self::$tools),
		// (2) admin capability policy (BizCity_MCP_Tool_Policy),
		// (3) client/key scopes (BizCity_MCP_Auth::has_scope).
		// Any failed gate must fail-closed before handler dispatch.

		if ( ! isset( self::$tools[ $name ] ) ) {
			return self::finish_call( $name, $args, $ctx, BizCity_MCP_Error::fail( $name, BizCity_MCP_Error::TOOL_NOT_FOUND, 'Tool không tồn tại trong catalog.', false, array(), array(), $ctx ), $t0 );
		}
		$tool = self::$tools[ $name ];
		if ( class_exists( 'BizCity_MCP_Delegation' ) && BizCity_MCP_Delegation::is_delegated( $ctx ) ) {
			// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L3-1 (Q88-6) — a delegated (cell) principal is gated by the tool's mode,
			// not by the admin allowlist for external clients; tools without a mode are never available to it.
			if ( ! BizCity_MCP_Delegation::tool_allowed( $tool, $ctx ) ) {
				return self::finish_call( $name, $args, $ctx, BizCity_MCP_Error::fail( $name, BizCity_MCP_Error::MODE_NOT_ALLOWED, 'Người này chưa được dùng mục này qua Agent.', false, array( 'mode' => (string) $tool['mode'] ), array(), $ctx ), $t0 );
			}
		} elseif ( ! self::policy_allows( $name, $ctx ) ) {
			// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — admin tool allowlist gate, independent from and enforced before the scope check.
			return self::finish_call( $name, $args, $ctx, BizCity_MCP_Error::fail( $name, BizCity_MCP_Error::TOOL_DISABLED, 'Tool này đã bị quản trị viên tắt trong MCP Settings.', false, array(), array(), $ctx ), $t0 );
		}

		// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-3 — every scope of the tool is required (default [required_scope]).
		foreach ( $tool['scopes'] as $scope ) {
			if ( ! BizCity_MCP_Auth::has_scope( $ctx, $scope ) ) {
				return self::finish_call( $name, $args, $ctx, BizCity_MCP_Error::fail( $name, BizCity_MCP_Error::SCOPE_DENIED, 'Client thiếu scope: ' . $scope . '.', false, array(), array(), $ctx ), $t0 );
			}
		}
		if ( ! is_callable( $tool['handler'] ) ) {
			return self::finish_call( $name, $args, $ctx, BizCity_MCP_Error::fail( $name, BizCity_MCP_Error::INTERNAL_ERROR, 'Tool chưa được triển khai.', false, array(), array( 'duration_ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ) ), $ctx ), $t0 );
		}

		// [2026-10-01 Claude Opus 5.5] PHASE-0.88 D-MCP-2 — write.idempotency.json site_rules: a write retried with the same
		// idempotency_key replays the stored envelope BEFORE the confirm wrapper (a consumed token is never re-checked).
		$idem = self::idempotency_transient( $name, $tool, $ctx );
		if ( '' === $idem ) {
			return self::run_tool( $name, $tool, $args, $ctx, $t0 );
		}
		$stored = get_transient( $idem );
		if ( is_array( $stored ) && ! empty( $stored['__running'] ) ) {
			return self::finish_call( $name, $args, $ctx, BizCity_MCP_Error::fail( $name, BizCity_MCP_Error::WRITE_IN_PROGRESS, 'Thao tác này đang được xử lý, chưa có kết quả.', true, array(), array(), $ctx ), $t0 );
		}
		if ( is_array( $stored ) && isset( $stored['success'] ) ) {
			$stored['meta']['replayed'] = true;
			return self::finish_call( $name, $args, $ctx, $stored, $t0 );
		}
		set_transient( $idem, array( '__running' => 1 ), self::IDEM_RUNNING_TTL );
		$envelope = self::run_tool( $name, $tool, $args, $ctx, $t0 );
		if ( ! empty( $envelope['success'] ) && is_array( $envelope['data'] ?? null ) && 'needs_confirmation' === ( $envelope['data']['status'] ?? '' ) ) {
			delete_transient( $idem ); // A preview is not the result of the write: never replay it.
		} else {
			set_transient( $idem, $envelope, self::IDEM_RESULT_TTL ); // success or failure (failed_envelope_cached).
		}
		return $envelope;
	}

	/** [2026-10-01 Claude Opus 5.5] PHASE-0.88 D-MCP-2 — write.idempotency.json site_rules TTLs (seconds). */
	const IDEM_RESULT_TTL  = 900;
	const IDEM_RUNNING_TTL = 120;

	/**
	 * [2026-10-01 Claude Opus 5.5] PHASE-0.88 D-MCP-2 — transient name for a write call carrying an idempotency_key, '' when
	 * the rule does not apply (read-only tool or no key). Only the hash is stored in the name; arguments are never stored.
	 */
	private static function idempotency_transient( $name, array $tool, array $ctx ) {
		$key = isset( $ctx['idempotency_key'] ) ? substr( (string) preg_replace( '/[^A-Za-z0-9._:\-]/', '', (string) $ctx['idempotency_key'] ), 0, 200 ) : '';
		if ( '' === $key || ! empty( $tool['read_only'] ) ) {
			return '';
		}
		return 'bizcity_mcp_idem_' . hash( 'sha256', get_current_blog_id() . '|' . (int) ( $ctx['user_id'] ?? 0 ) . '|' . $name . '|' . $key );
	}

	/** Confirm wrapper + handler → envelope (audited through finish_call). */
	private static function run_tool( $name, array $tool, array $args, array $ctx, $t0 ) {
		$confirm = 'always' === $tool['confirm'];
		try {
			if ( $confirm ) {
				// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-7 (Q88-1) — "xem trước → cam kết": the wrapper owns the token, handlers never do.
				$gate = self::confirm_gate( $name, $tool, $args, $ctx );
				if ( is_wp_error( $gate ) ) {
					return self::finish_call( $name, $args, $ctx, BizCity_MCP_Error::from_wp_error( $name, $gate, array( 'duration_ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ) ), $ctx ), $t0 );
				}
				if ( isset( $gate['preview_result'] ) ) {
					return self::finish_call( $name, $args, $ctx, BizCity_MCP_Error::success( $name, $gate['preview_result'], array( 'duration_ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ) ), '', $ctx ), $t0 );
				}
				$args = $gate['args'];
			}
			$result = call_user_func( $tool['handler'], $args, $ctx );
		} catch ( \Throwable $e ) {
			// PHP 7.4-safe: \Throwable catches both Exception and Error.
			error_log( '[bizcity-mcp] tool ' . $name . ' threw: ' . $e->getMessage() );
			return self::finish_call( $name, $args, $ctx, BizCity_MCP_Error::fail( $name, BizCity_MCP_Error::INTERNAL_ERROR, 'Lỗi nội bộ khi chạy tool.', true, array(), array( 'duration_ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ) ), $ctx ), $t0 );
		}

		$extra = array( 'duration_ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ) );

		if ( is_wp_error( $result ) ) {
			return self::finish_call( $name, $args, $ctx, BizCity_MCP_Error::from_wp_error( $name, $result, $extra, $ctx ), $t0 );
		}
		if ( $confirm && is_array( $result ) && ! isset( $result['status'] ) ) {
			$result['status'] = 'done';
		}
		return self::finish_call( $name, $args, $ctx, BizCity_MCP_Error::success( $name, self::with_as_of( $result ), $extra, '', $ctx ), $t0 );
	}

	/**
	 * [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-3 — every 0.88 result carries `as_of` (UTC ISO 8601) unless the handler
	 * already set it (e.g. a cached dataset with its own freshness). Lists are left alone (adding a key would turn them
	 * into objects).
	 */
	private static function with_as_of( $data ) {
		if ( ! is_array( $data ) || isset( $data['as_of'] ) ) {
			return $data;
		}
		if ( array() !== $data && array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			return $data;
		}
		$data['as_of'] = gmdate( 'c' );
		return $data;
	}

	/**
	 * [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-7 — confirm.flow.json. No confirm_token ⇒ run preview() (no write), issue a
	 * one-time token bound to tool + client + user + args_hash and return {preview_result}. With a token ⇒ consume it (args
	 * mismatch ⇒ MCP_CONFIRM_ARGS_CHANGED, token kept; invalid / used / expired / other identity ⇒ MCP_CONFIRM_INVALID) and
	 * return {args} without confirm_token for the handler.
	 *
	 * @return array|WP_Error
	 */
	private static function confirm_gate( $name, array $tool, array $args, array $ctx ) {
		$token = isset( $args['confirm_token'] ) ? (string) $args['confirm_token'] : '';
		unset( $args['confirm_token'] );
		$hash = BizCity_MCP_Action_Confirmation::args_hash( $args );
		if ( '' === $token ) {
			$preview = call_user_func( $tool['preview'], $args, $ctx );
			if ( is_wp_error( $preview ) ) {
				return $preview;
			}
			$issued = BizCity_MCP_Action_Confirmation::issue( $name, 0, $ctx, $hash );
			return array( 'preview_result' => array(
				'as_of'         => gmdate( 'c' ),
				'status'        => 'needs_confirmation',
				'preview'       => is_array( $preview ) ? $preview : array( 'summary' => (string) $preview ),
				'confirm_token' => (string) $issued['confirmation_token'],
				'expires_at'    => (string) $issued['expires_at'],
			) );
		}
		$ok = BizCity_MCP_Action_Confirmation::consume( $token, $name, 0, $ctx, $hash );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return array( 'args' => $args );
	}

	private static function finish_call( $name, array $args, array $ctx, array $envelope, $started_at ) {
		$duration = (int) ( ( microtime( true ) - $started_at ) * 1000 );
		if ( isset( $envelope['meta']['duration_ms'] ) && (int) $envelope['meta']['duration_ms'] < $duration ) {
			$envelope['meta']['duration_ms'] = $duration;
		}
		self::write_audit( $name, $args, $ctx, $envelope, $duration );
		return $envelope;
	}

	/**
	 * Audit only metadata. Never persist tool arguments or response bodies:
	 * schema/draft/content may contain confidential tenant data.
	 */
	private static function write_audit( $name, array $args, array $ctx, array $envelope, $duration ) {
		// [2026-08-01 Johnny Chu] PHASE-1.25-LOG-JSONL — file evidence is now the
		// canonical MCP audit store; keep SQL projection opt-in for rollback only.
		$input_meta = array(
			'arg_count' => count( $args ),
			'arg_keys'  => array_slice( array_map( 'sanitize_key', array_keys( $args ) ), 0, 50 ),
		);
		$output_meta = array( 'success' => ! empty( $envelope['success'] ) );
		if ( isset( $envelope['data'] ) && is_array( $envelope['data'] ) ) {
			$output_meta['data_keys'] = array_slice( array_map( 'sanitize_key', array_keys( $envelope['data'] ) ), 0, 50 );
		}
		$error_code = isset( $envelope['error']['code'] ) ? (string) $envelope['error']['code'] : '';
		$evaluation = self::evaluation_meta( $envelope, $error_code );
		$scores     = self::score_meta( $envelope );
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — write file evidence before the DB audit insert.
		if ( class_exists( 'BizCity_MCP_File_Logger' ) ) {
			BizCity_MCP_File_Logger::write( array(
				'trace_id'     => isset( $envelope['meta']['trace_id'] ) ? (string) $envelope['meta']['trace_id'] : BizCity_MCP_Error::trace_id(),
				'blog_id'      => get_current_blog_id(),
				'user_id'      => (int) ( $ctx['user_id'] ?? 0 ),
				'key_id'       => (int) ( $ctx['key_id'] ?? 0 ),
				'client_id'    => (string) ( $ctx['client_id'] ?? '' ),
				'client_name'  => (string) ( $ctx['client_name'] ?? '' ),
				'tool_name'    => $name,
				'status'       => ! empty( $envelope['success'] ) ? 'success' : 'error',
				'error_code'   => $error_code,
				'duration_ms'  => max( 0, (int) $duration ),
				'request_hash' => hash( 'sha256', wp_json_encode( $args ) ),
				'evaluation'   => $evaluation,
				'scores'       => $scores,
			) );
		}
		// [2026-08-01 Johnny Chu] PHASE-1.29-LOG-ORPHAN — SQL audit INSERT
		// path removed; the JSONL write above is the sole audit persistence path.
	}

	/**
	 * [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-3 — outputSchema of the core/mcp envelope (structuredContent): success +
	 * data with the required `as_of` and the tool's own data properties. Public so other services (CL-B) reuse it.
	 *
	 * @param array    $data_properties JSON-schema properties of `data` besides as_of
	 * @param string[] $data_required   required keys of `data` besides as_of
	 */
	public static function envelope_schema( array $data_properties, array $data_required = array() ) {
		return array(
			'type'       => 'object',
			'required'   => array( 'success', 'data' ),
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'data'    => array(
					'type'       => 'object',
					'required'   => array_values( array_unique( array_merge( array( 'as_of' ), $data_required ) ) ),
					'properties' => array_merge( array( 'as_of' => array( 'type' => 'string' ) ), $data_properties ),
				),
			),
		);
	}

	private static function evaluation_meta( array $envelope, $error_code ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — summarize citation/claim validation outcomes without storing draft text.
		$data   = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();
		$report = isset( $data['report'] ) && is_array( $data['report'] ) ? $data['report'] : array();
		$deep   = isset( $report['deep'] ) && is_array( $report['deep'] ) ? $report['deep'] : array();
		$unsupported = isset( $deep['unsupported_claims'] ) && is_array( $deep['unsupported_claims'] ) ? $deep['unsupported_claims'] : array();
		$low_overlap = 0;
		foreach ( $unsupported as $item ) {
			if ( is_array( $item ) && (string) ( $item['reason'] ?? '' ) === 'low_evidence_overlap' ) {
				$low_overlap++;
			}
		}
		return array(
			'canonical_valid'          => ! empty( $report['ok'] ),
			'claim_count'              => (int) ( $deep['claims_checked'] ?? 0 ),
			'claims_without_evidence'  => count( array_filter( $unsupported, static function ( $item ) { return is_array( $item ) && (string) ( $item['reason'] ?? '' ) === 'no_supporting_citation'; } ) ),
			'proposal_without_citation'=> count( array_filter( (array) ( $deep['proposal_claims'] ?? array() ), static function ( $item ) { return is_array( $item ) && empty( $item['citations'] ); } ) ),
			'deprecated_only'           => count( (array) ( $deep['deprecated_evidence'] ?? array() ) ),
			'mixed_canonical_identity'  => ! empty( $deep['cross_identity_mixing'] ),
			'citation_invalid'          => $error_code === BizCity_MCP_Error::CITATION_INVALID,
			'lexical_overlap_low'       => $low_overlap,
		);
	}

	private static function score_meta( array $envelope ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — summarize canonical score provenance for per-key evidence.
		$data = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();
		$passages = isset( $data['passages'] ) && is_array( $data['passages'] ) ? $data['passages'] : array();
		$counts = array( 'vector' => 0, 'keyword' => 0, 'graph_relation' => 0, 'expanded_relation' => 0, 'other' => 0 );
		foreach ( $passages as $passage ) {
			$source = isset( $passage['score']['source'] ) ? (string) $passage['score']['source'] : (string) ( $passage['score_source'] ?? 'other' );
			if ( strpos( $source, 'vector' ) !== false ) { $counts['vector']++; }
			elseif ( strpos( $source, 'keyword' ) !== false ) { $counts['keyword']++; }
			elseif ( strpos( $source, 'graph_relation' ) !== false ) { $counts['graph_relation']++; }
			elseif ( strpos( $source, 'expanded' ) !== false ) { $counts['expanded_relation']++; }
			else { $counts['other']++; }
		}
		return array( 'passage_count' => count( $passages ), 'score_source_counts' => $counts, 'deterministic' => ! empty( $data['deterministic'] ) );
	}

	private static function register_brain_tools() {
		if ( ( defined( 'BIZCITY_MCP_BRAIN_TOOLS_ENABLED' ) && ! BIZCITY_MCP_BRAIN_TOOLS_ENABLED ) || ! class_exists( 'BizCity_Brain_MCP_Service' ) ) {
			return;
		}
		$svc = BizCity_Brain_MCP_Service::instance();

		// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-4 — knowledge.* are the canonical names; brain.* stay one version as
		// deprecated aliases (same handler, same schema). Delegated callers only read notebooks they own
		// (BizCity_MCP_Client_Scope_Resolver honours ctx.allowed_notebook_ids for delegated contexts).
		$list_notebooks_schema = array(
			'type' => 'object',
			'properties' => array(
				'query'           => array( 'type' => 'string' ),
				'limit'           => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
				'cursor'          => array( 'type' => array( 'string', 'null' ) ),
				'include_counts'  => array( 'type' => 'boolean', 'default' => true ),
				'include_archived'=> array( 'type' => 'boolean', 'default' => false ),
			),
		);
		$search_schema = array(
			'type' => 'object',
			'required' => array( 'query' ),
			'properties' => array(
				'query'              => array( 'type' => 'string', 'minLength' => 1 ),
				'notebook_ids'       => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'retrieval_profile'  => array( 'type' => 'string', 'default' => 'kg-rag-strict-v1' ),
				'top_k'              => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 8 ),
				'graph_depth'        => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 3, 'default' => 2 ),
				'deterministic'      => array( 'type' => 'boolean', 'default' => true ),
				'citation_mode'      => array( 'type' => 'string', 'enum' => array( 'strict' ), 'default' => 'strict' ),
				'include_entities'   => array( 'type' => 'boolean', 'default' => true ),
				'include_relations'  => array( 'type' => 'boolean', 'default' => true ),
				'include_full_content'=> array( 'type' => 'boolean', 'default' => false ),
				'snapshot_ttl_seconds'=> array( 'type' => 'integer', 'minimum' => 60, 'default' => 3600 ),
			),
		);
		$passage_schema = array(
			'type' => 'object',
			'properties' => array(
				'retrieval_snapshot_id' => array( 'type' => 'string' ),
				'citation_id'          => array( 'type' => 'string', 'pattern' => '^src:\\d+#p\\d+$' ),
				'source_id'            => array( 'type' => 'integer', 'minimum' => 1 ),
				'passage_id'           => array( 'type' => 'integer', 'minimum' => 1 ),
			),
		);

		// @mcp bizcity-mcp-standard@1 tool knowledge.search
		self::register( 'knowledge.search', array(
			'title'          => 'Tìm trong sổ tri thức',
			'description'    => 'Tìm đoạn trích liên quan trong các sổ tri thức (notebook) của chính người dùng, kèm citation_id để trích dẫn. Dùng khi cần trả lời dựa trên ghi chú, tài liệu đã lưu.',
			'input_schema'   => $search_schema,
			'output_schema'  => self::envelope_schema( array(
				'retrieval_snapshot_id' => array( 'type' => 'string' ),
				'passages'              => array( 'type' => 'array' ),
				'allowed_citations'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			) ),
			'read_only'      => true,
			'idempotent'     => false, // snapshot creation is a side effect, same as brain.search
			'required_scope' => 'brain.read',
			'handler'        => array( $svc, 'search' ),
			'mode'           => 'notebook',
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'notebook_search',
			'fallback_pack'  => 'owner_knowledge',
			'since'          => '0.88.2',
		) );

		// @mcp bizcity-mcp-standard@1 tool knowledge.get_passage
		self::register( 'knowledge.get_passage', array(
			'title'          => 'Lấy đoạn trích tri thức',
			'description'    => 'Lấy toàn văn một đoạn trích theo citation_id (trong một lần tìm) hoặc theo source_id + passage_id.',
			'input_schema'   => $passage_schema,
			'output_schema'  => self::envelope_schema( array( 'passage' => array( 'type' => 'object' ) ) ),
			'required_scope' => 'brain.read',
			'handler'        => array( $svc, 'get_passage' ),
			'mode'           => 'notebook',
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'knowledge_get_passage',
			'fallback_pack'  => null,
			'since'          => '0.88.2',
		) );

		// @mcp bizcity-mcp-standard@1 tool knowledge.list_notebooks
		self::register( 'knowledge.list_notebooks', array(
			'title'          => 'Danh sách sổ tri thức',
			'description'    => 'Liệt kê các sổ tri thức (notebook) người dùng được đọc, kèm số nguồn và số đoạn.',
			'input_schema'   => $list_notebooks_schema,
			'output_schema'  => self::envelope_schema( array(
				'notebooks'   => array( 'type' => 'array' ),
				'next_cursor' => array( 'type' => array( 'string', 'null' ) ),
			) ),
			'required_scope' => 'brain.read',
			'handler'        => array( $svc, 'list_notebooks' ),
			'mode'           => 'notebook',
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'knowledge_list_notebooks',
			'fallback_pack'  => 'notebook_meta',
			'since'          => '0.88.2',
		) );

		self::register( 'brain.list_notebooks', array(
			'title'          => 'List notebooks',
			'description'    => 'Trả danh sách notebook mà client hiện tại được quyền đọc (ACL qua BizCity_KG_Notebook_Service).',
			'input_schema'   => $list_notebooks_schema,
			'required_scope' => 'brain.read',
			'handler'        => array( $svc, 'list_notebooks' ),
			'mode'           => 'notebook',
			'alias_of'       => 'knowledge.list_notebooks', // [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-4 — deprecated alias
		) );

		self::register( 'brain.search', array(
			'title'          => 'Graph RAG search (canonical retrieval snapshot)',
			'description'    => 'Chạy BizCity_KG_Retriever::ask() canonical và tạo một immutable retrieval snapshot với citation_id cho từng passage.',
			'mode'           => 'notebook',
			'alias_of'       => 'knowledge.search', // [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-4 — deprecated alias
			'input_schema'   => $search_schema,
			'read_only'      => true,
			'idempotent'     => false, // snapshot creation is a side effect (new row), even though content is deterministic for unchanged KG state.
			'required_scope' => 'brain.read',
			'handler'        => array( $svc, 'search' ),
		) );

		self::register( 'brain.get_passage', array(
			'title'          => 'Get passage (strict source+passage pair)',
			'description'    => 'Lấy full nội dung 1 passage theo citation_id (trong 1 snapshot) hoặc theo source_id+passage_id trực tiếp.',
			'input_schema'   => $passage_schema,
			'mode'           => 'notebook',
			'alias_of'       => 'knowledge.get_passage', // [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-4 — deprecated alias
			'required_scope' => 'brain.read',
			'handler'        => array( $svc, 'get_passage' ),
		) );

		self::register( 'brain.get_citation_pack', array(
			'title'          => 'Get citation pack from a retrieval snapshot',
			'description'    => 'Đóng gói full content của một tập citation_id trong snapshot để dán thẳng vào prompt LLM, kèm citation_rules.',
			'input_schema'   => array(
				'type' => 'object',
				'required' => array( 'retrieval_snapshot_id' ),
				'properties' => array(
					'retrieval_snapshot_id' => array( 'type' => 'string' ),
					'citation_ids'          => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'pattern' => '^src:\\d+#p\\d+$' ) ),
					'include_full_content'  => array( 'type' => 'boolean', 'default' => true ),
					'max_total_chars'       => array( 'type' => 'integer', 'minimum' => 1000, 'default' => 60000 ),
					'format'                => array( 'type' => 'string', 'enum' => array( 'structured' ), 'default' => 'structured' ),
				),
			),
			'required_scope' => 'brain.read',
			'handler'        => array( $svc, 'get_citation_pack' ),
		) );

		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D4 — these tools are
		// deliberately read-only and share one Brain retrieval facade with Twin GPT.
		self::register( 'brain.context.search', array(
			'title'          => 'Context Bank bounded search',
			'description'    => 'Trả Context Retrieval Pack bounded, server-authorized từ Context Bank; MCP không đọc ledger/archive trực tiếp.',
			'input_schema'   => array(
				'type' => 'object',
				'properties' => array(
					'mode' => array( 'type' => 'string', 'enum' => array( 'context_bank', 'hybrid', 'recent_identity' ), 'default' => 'context_bank' ),
					'query' => array( 'type' => 'string' ),
					'filters' => array( 'type' => 'object' ),
					'budget' => array( 'type' => 'object' ),
					'cursor' => array( 'type' => 'string' ),
				),
			),
			'read_only'      => true,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( $svc, 'context_search' ),
		) );

		self::register( 'brain.context.evidence', array(
			'title'          => 'Context Bank bounded evidence',
			'description'    => 'Trả một bounded metadata evidence excerpt theo source_ref; không trả filesystem path, ciphertext hoặc raw archive body.',
			'input_schema'   => array(
				'type' => 'object',
				'required' => array( 'source_ref' ),
				'properties' => array(
					'source_ref' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 191 ),
					'mode' => array( 'type' => 'string', 'default' => 'context_bank' ),
					'filters' => array( 'type' => 'object' ),
				),
			),
			'read_only'      => true,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( $svc, 'context_evidence' ),
		) );

		self::register( 'brain.order.summary', array(
			'title'          => 'Bounded order lifecycle summary',
			'description'    => 'Trả bounded order lifecycle summary từ server-authorized Context Retrieval Pack; không gọi Woo/CRM trực tiếp.',
			'input_schema'   => array(
				'type' => 'object',
				'required' => array( 'order_ref' ),
				'properties' => array(
					'order_ref' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191 ),
					'budget' => array( 'type' => 'object' ),
				),
			),
			'read_only'      => true,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'order.read',
			'handler'        => array( $svc, 'order_summary' ),
		) );
	}

	/**
	 * Wave E/F document tools. Binary DOCX/PPTX export stays in bizcity-doc's
	 * browser runtime; render tools return a validated handoff package containing
	 * schema + canonical module URL + export function.
	 */
	private static function register_document_tools() {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP Wave E/F — replace document stubs with working service handlers.
		if ( ( defined( 'BIZCITY_MCP_DOCUMENT_TOOLS_ENABLED' ) && ! BIZCITY_MCP_DOCUMENT_TOOLS_ENABLED ) || ! class_exists( 'BizCity_Document_MCP_Service' ) ) {
			return;
		}
		$svc = BizCity_Document_MCP_Service::instance();

		self::register( 'document.build_context_pack', array(
			'title'          => 'Build document context pack',
			'description'    => 'Tạo context pack immutable từ một hoặc nhiều retrieval snapshot, recheck ACL và giới hạn block/ký tự.',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'retrieval_snapshot_id'  => array( 'type' => 'string' ),
					'retrieval_snapshot_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'citation_ids'            => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'pattern' => '^src:\\d+#p\\d+$' ) ),
					'document_type'           => array( 'type' => 'string', 'enum' => array( 'document', 'presentation' ), 'default' => 'document' ),
					'max_blocks'              => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 24 ),
					'max_total_chars'         => array( 'type' => 'integer', 'minimum' => 1000, 'maximum' => 250000, 'default' => 60000 ),
					'ttl_seconds'             => array( 'type' => 'integer', 'minimum' => 60, 'maximum' => DAY_IN_SECONDS, 'default' => 3600 ),
				),
			),
			'read_only'      => false,
			'idempotent'     => false,
			'required_scope' => 'document.context.build',
			'handler'        => array( $svc, 'build_context_pack' ),
		) );

		self::register( 'document.validate_draft', array(
			'title'          => 'Validate document draft citations',
			'description'    => 'Kiểm tra citation trong draft/schema bằng bizcity_kg_validate_citations_in_json() canonical.',
			'input_schema'   => array(
				'type'       => 'object',
				'required'   => array( 'context_pack_id' ),
				'properties' => array(
					'context_pack_id' => array( 'type' => 'string' ),
					'draft_json'      => array( 'type' => array( 'object', 'array', 'string' ) ),
					'draft_content'   => array( 'type' => 'string' ),
					'draft_format'    => array( 'type' => 'string', 'enum' => array( 'markdown', 'text', 'plain' ), 'default' => 'markdown' ),
				),
			),
			'required_scope' => 'document.validate',
			'handler'        => array( $svc, 'validate_draft' ),
		) );

		$render_schema = array(
			'type'       => 'object',
			'required'   => array( 'context_pack_id' ),
			'properties' => array(
				'context_pack_id' => array( 'type' => 'string' ),
				'schema'          => array( 'type' => array( 'object', 'string' ) ),
				'draft_content'   => array( 'type' => 'string' ),
				'draft_format'    => array( 'type' => 'string', 'enum' => array( 'markdown', 'text', 'plain' ), 'default' => 'markdown' ),
				'validation_id'   => array( 'type' => 'string' ),
				'filename'        => array( 'type' => 'string' ),
			),
		);
		if ( defined( 'BIZCITY_MCP_RENDER_ENABLED' ) && ! BIZCITY_MCP_RENDER_ENABLED ) {
			return;
		}
		self::register( 'document.render_docx', array(
			'title'          => 'Prepare validated DOCX render package',
			'description'    => 'Validate DocumentSchema rồi trả browser handoff cho bizcity-doc buildDocxFromSchema().',
			'input_schema'   => $render_schema,
			'required_scope' => 'document.render.docx',
			'handler'        => array( $svc, 'render_docx' ),
		) );
		self::register( 'document.render_pptx', array(
			'title'          => 'Prepare validated PPTX render package',
			'description'    => 'Validate PresentationSchema rồi trả browser handoff cho bizcity-doc buildPptxFromSchema().',
			'input_schema'   => $render_schema,
			'required_scope' => 'document.render.pptx',
			'handler'        => array( $svc, 'render_pptx' ),
		) );
	}

	/**
	 * PHASE-0.54-MCP Wave K — opt-in landing-page Brain/Action tools.
	 */
	private static function register_page_tools() {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — keep PageBuilder writes disabled until explicitly enabled by wp-config.
		if ( ( defined( 'BIZCITY_MCP_PAGE_TOOLS_ENABLED' ) && ! BIZCITY_MCP_PAGE_TOOLS_ENABLED ) || ! class_exists( 'BizCity_Page_Action_MCP_Service' ) ) {
			return;
		}
		$svc = BizCity_Page_Action_MCP_Service::instance();

		self::register( 'page.get_schema', array(
			'title'          => 'Get landing page specification schema',
			'description'    => 'Trả SiteConfig schema tương thích BizCity PageBuilder để MCP client tạo Page Specification JSON.',
			'required_scope' => 'page.read',
			'handler'        => array( $svc, 'get_schema' ),
		) );
		self::register( 'page.get_project', array(
			'title'          => 'Get landing page draft',
			'description'    => 'Đọc một project landing page thuộc user hiện tại.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'draft_id' ), 'properties' => array( 'draft_id' => array( 'type' => 'integer', 'minimum' => 1 ) ) ),
			'required_scope' => 'page.read',
			'handler'        => array( $svc, 'get_project' ),
		) );
		self::register( 'page.preview', array(
			'title'          => 'Preview landing page draft',
			'description'    => 'Render preview HTML của draft; không tạo hoặc xuất bản WordPress page.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'draft_id' ), 'properties' => array( 'draft_id' => array( 'type' => 'integer', 'minimum' => 1 ) ) ),
			'required_scope' => 'page.read',
			'handler'        => array( $svc, 'preview' ),
		) );
		self::register( 'page.create_draft', array(
			'title'          => 'Create landing page draft',
			'description'    => 'Validate Page Specification JSON và tạo draft trong BizCity PageBuilder; không publish.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'site_config' ), 'properties' => array( 'title' => array( 'type' => 'string' ), 'site_config' => array( 'type' => 'object' ), 'citation_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'pattern' => '^src:\\d+#p\\d+$' ) ) ) ),
			'read_only'      => false,
			'required_scope' => 'page.write',
			'handler'        => array( $svc, 'create_draft' ),
		) );
		self::register( 'page.update_draft', array(
			'title'          => 'Update landing page draft',
			'description'    => 'Validate và cập nhật draft landing page thuộc user hiện tại; token publish được cấp lại.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'draft_id', 'site_config' ), 'properties' => array( 'draft_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'title' => array( 'type' => 'string' ), 'site_config' => array( 'type' => 'object' ), 'citation_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'pattern' => '^src:\\d+#p\\d+$' ) ) ) ),
			'read_only'      => false,
			'required_scope' => 'page.write',
			'handler'        => array( $svc, 'update_draft' ),
		) );
		self::register( 'page.publish', array(
			'title'          => 'Publish landing page',
			'description'    => 'Xuất bản draft thành WordPress page sau khi confirmation_token được user xác nhận.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'draft_id', 'confirmation_token' ), 'properties' => array( 'draft_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'confirmation_token' => array( 'type' => 'string', 'minLength' => 1 ) ) ),
			'read_only'      => false,
			'destructive'    => true,
			'idempotent'     => false,
			'required_scope' => 'page.publish',
			'handler'        => array( $svc, 'publish' ),
		) );
	}

	/**
	 * PHASE-0.54-MCP Wave L — read-only business metrics tools.
	 */
	private static function register_business_tools() {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — expose metrics only when explicitly enabled.
		if ( ( defined( 'BIZCITY_MCP_BUSINESS_TOOLS_ENABLED' ) && ! BIZCITY_MCP_BUSINESS_TOOLS_ENABLED ) || ! class_exists( 'BizCity_Business_MCP_Service' ) ) {
			return;
		}
		$svc = BizCity_Business_MCP_Service::instance();
		$range_schema = array(
			'type'       => 'object',
			'properties' => array(
				'from' => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$' ),
				'to'   => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$' ),
			),
		);
		// @mcp bizcity-mcp-standard@1 tool sales.summary
		// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-2 (SW-MCP-1) — canonical name of business.get_sales_metrics; same handler,
		// descriptor = fixture bridge.tools_list.owner.json.
		self::register( 'sales.summary', array(
			'title'          => 'Tổng kết doanh số',
			'description'    => 'Doanh thu, số đơn, hoàn tiền và giá trị đơn trung bình trong một khoảng ngày (mặc định 30 ngày gần nhất; from=to=hôm nay cho \'hôm nay\').',
			'input_schema'   => $range_schema,
			'output_schema'  => self::envelope_schema( array(
				'from'    => array( 'type' => 'string' ),
				'to'      => array( 'type' => 'string' ),
				'summary' => array( 'type' => 'object' ),
			) ),
			'required_scope' => 'business.read',
			'handler'        => array( $svc, 'get_sales_metrics' ),
			'mode'           => 'sales',
			'scopes'         => array( 'business.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'biz_sales',
			'fallback_pack'  => 'sales',
			'since'          => '0.88.1',
		) );
		self::register( 'business.get_sales_metrics', array(
			'title'          => 'Get sales metrics',
			'description'    => 'Đọc doanh thu, đơn hàng, hoàn tiền và giá trị đơn trung bình qua CRM Woo reports bridge.',
			'input_schema'   => $range_schema,
			'required_scope' => 'business.read',
			'handler'        => array( $svc, 'get_sales_metrics' ),
			'mode'           => 'sales',
			'alias_of'       => 'sales.summary', // [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-4 — deprecated alias, one version
		) );
		self::register( 'business.get_customer_metrics', array(
			'title'          => 'Get customer metrics',
			'description'    => 'Đọc KPI hội thoại, tin nhắn, xử lý, CSAT và SLA qua CRM Report Builder.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array_merge( $range_schema['properties'], array( 'metrics' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'group_by' => array( 'type' => 'string' ), 'inbox_id' => array( 'type' => 'integer' ), 'agent_id' => array( 'type' => 'integer' ) ) ) ),
			'required_scope' => 'business.read',
			'handler'        => array( $svc, 'get_customer_metrics' ),
		) );
		self::register( 'business.get_inventory_metrics', array(
			'title'          => 'Get inventory metrics',
			'description'    => 'Đọc tổng sản phẩm, tồn kho thấp, hết hàng và số đơn vị tồn qua WooCommerce CRUD API.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array( 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ), 'low_stock_threshold' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 5 ) ) ),
			'required_scope' => 'business.read',
			'handler'        => array( $svc, 'get_inventory_metrics' ),
			'mode'           => 'stock',
			'alias_of'       => 'inventory.check', // [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-4 (CL-B) — deprecated alias, one version; old shape kept
		) );
	}

	/**
	 * PHASE-0.54-MCP Wave M — Content Creator read-only tools.
	 */
	private static function register_content_brain_tools() {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — expose BZCC reads only when explicitly enabled.
		if ( ( defined( 'BIZCITY_MCP_CONTENT_TOOLS_ENABLED' ) && ! BIZCITY_MCP_CONTENT_TOOLS_ENABLED ) || ! class_exists( 'BizCity_Content_Brain_MCP_Service' ) ) {
			return;
		}
		$svc = BizCity_Content_Brain_MCP_Service::instance();
		self::register( 'content.list_posts', array(
			'title'          => 'List content drafts',
			'description'    => 'Liệt kê file nội dung thuộc user hiện tại qua Content Creator, có lọc status/search và phân trang.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array( 'status' => array( 'type' => 'string' ), 'search' => array( 'type' => 'string' ), 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ), 'offset' => array( 'type' => 'integer', 'minimum' => 0 ) ) ),
			'required_scope' => 'content.read',
			'handler'        => array( $svc, 'list_posts' ),
		) );
		self::register( 'content.get_post', array(
			'title'          => 'Get content draft',
			'description'    => 'Đọc một file nội dung và các chunk thuộc user hiện tại.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'file_id' ), 'properties' => array( 'file_id' => array( 'type' => 'integer', 'minimum' => 1 ) ) ),
			'required_scope' => 'content.read',
			'handler'        => array( $svc, 'get_post' ),
		) );
		self::register( 'content.get_templates', array(
			'title'          => 'List content templates',
			'description'    => 'Đọc các template Content Creator đang active để chọn trước khi tạo draft.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array( 'category' => array( 'type' => 'string' ), 'search' => array( 'type' => 'string' ), 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ) ) ),
			'required_scope' => 'content.read',
			'handler'        => array( $svc, 'get_templates' ),
		) );
	}

	/**
	 * PHASE-0.54-MCP Wave M — create-only Content Action tool.
	 */
	private static function register_content_action_tools() {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — keep content writes disabled until publish/update handlers pass DDV.
		if ( ( defined( 'BIZCITY_MCP_CONTENT_ACTION_TOOLS_ENABLED' ) && ! BIZCITY_MCP_CONTENT_ACTION_TOOLS_ENABLED ) || ! class_exists( 'BizCity_Content_Action_MCP_Service' ) ) {
			return;
		}
		$svc = BizCity_Content_Action_MCP_Service::instance();
		self::register( 'content.create_draft', array(
			'title'          => 'Create content draft',
			'description'    => 'Tạo file nội dung pending qua Content Creator; không publish và trả confirmation token cho publish wave sau.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'template_id' ), 'properties' => array( 'template_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'title' => array( 'type' => 'string' ), 'form_data' => array( 'type' => 'object' ), 'notebook_id' => array( 'type' => 'integer', 'minimum' => 0 ), 'notebook_context' => array( 'type' => 'object' ) ) ),
			'read_only'      => false,
			'required_scope' => 'content.write',
			'handler'        => array( $svc, 'create_draft' ),
		) );
		self::register( 'content.update_draft', array(
			'title'          => 'Update content draft',
			'description'    => 'Chỉnh sửa một chunk đã hoàn tất qua Content Creator; không publish và cấp confirmation token mới.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'file_id', 'chunk_id', 'content' ), 'properties' => array( 'file_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'chunk_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'content' => array( 'type' => 'string', 'minLength' => 1 ) ) ),
			'read_only'      => false,
			'idempotent'     => true,
			'required_scope' => 'content.write',
			'handler'        => array( $svc, 'update_draft' ),
		) );
	}

	/**
	 * PHASE-0.54-MCP Wave N — read-only report recipes and dataset builder.
	 */
	private static function register_report_brain_tools() {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — report tools are opt-in and do not persist drafts/files.
		if ( ( defined( 'BIZCITY_MCP_REPORT_TOOLS_ENABLED' ) && ! BIZCITY_MCP_REPORT_TOOLS_ENABLED ) || ! class_exists( 'BizCity_Report_Brain_MCP_Service' ) ) {
			return;
		}
		$svc = BizCity_Report_Brain_MCP_Service::instance();
		self::register( 'report.list_templates', array(
			'title'          => 'List report templates',
			'description'    => 'Liệt kê các recipe báo cáo read-only có sẵn cho MCP.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array() ),
			'required_scope' => 'report.read',
			'handler'        => array( $svc, 'list_templates' ),
		) );
		self::register( 'report.build_dataset', array(
			'title'          => 'Build report dataset',
			'description'    => 'Tổng hợp dataset doanh thu, CRM và tồn kho qua canonical services; chưa tạo báo cáo hay file.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'from' => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$' ),
				'to' => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$' ),
				'include_sales' => array( 'type' => 'boolean', 'default' => true ),
				'include_customer' => array( 'type' => 'boolean', 'default' => true ),
				'include_inventory' => array( 'type' => 'boolean', 'default' => false ),
				'customer_metrics' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'group_by' => array( 'type' => 'string', 'enum' => array( 'none', 'day', 'agent_id', 'inbox_id', 'label_id', 'responder_kind' ) ),
				'inventory_limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ),
				'low_stock_threshold' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 5 ),
			) ),
			'required_scope' => 'report.read',
			'handler'        => array( $svc, 'build_dataset' ),
		) );
	}

	/** PHASE-0.63A WP-8.4 — read-only pipeline lifecycle metrics bridge. */
	private static function register_pipeline_brain_tools() {
		if ( ! class_exists( 'BizCity_CRM_Pipeline_MCP_Bridge' ) ) { return; }
		$svc = BizCity_CRM_Pipeline_MCP_Bridge::instance();
		self::register( 'pipeline.get_metrics', array(
			'title' => 'Get pipeline metrics',
			'description' => 'Đọc metric lifecycle pipeline/SLA qua CRM reporting rollup canonical; không sửa run và không trả PII.',
			'input_schema' => array( 'type' => 'object', 'properties' => array(
				'from' => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$' ),
				'to' => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$' ),
				'metric' => array( 'type' => 'string' ),
				'dimension_type' => array( 'type' => 'string', 'enum' => array( 'tenant', 'channel', 'inbox', 'team', 'user' ) ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500 ),
			) ),
			'required_scope' => 'report.read',
			'handler' => array( $svc, 'get_metrics' ),
		) );
	}

	/**
	 * PHASE-0.54-MCP Wave R — read-only WooCommerce catalog/order/customer tools.
	 */
	private static function register_commerce_tools() {
		// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave R — commerce reads are opt-in and never write WooCommerce data.
		if ( ( defined( 'BIZCITY_MCP_COMMERCE_TOOLS_ENABLED' ) && ! BIZCITY_MCP_COMMERCE_TOOLS_ENABLED ) || ! class_exists( 'BizCity_Commerce_Brain_MCP_Service' ) ) {
			return;
		}
		$svc = BizCity_Commerce_Brain_MCP_Service::instance();
		$page_schema = array(
			'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
			'page'  => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
			'search'=> array( 'type' => 'string' ),
		);
		self::register( 'commerce.list_products', array(
			'title'          => 'List WooCommerce products',
			'description'    => 'Liệt kê sản phẩm WooCommerce (tên, SKU, giá, tồn kho) qua wc_get_products(), có lọc theo trạng thái/danh mục/tìm kiếm.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array_merge( $page_schema, array( 'status' => array( 'type' => 'string' ), 'category' => array( 'type' => 'string' ) ) ) ),
			'required_scope' => 'commerce.read',
			'handler'        => array( $svc, 'list_products' ),
		) );
		self::register( 'commerce.get_product', array(
			'title'          => 'Get WooCommerce product',
			'description'    => 'Đọc chi tiết một sản phẩm theo product_id hoặc sku qua wc_get_product().',
			'input_schema'   => array( 'type' => 'object', 'properties' => array( 'product_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'sku' => array( 'type' => 'string' ) ) ),
			'required_scope' => 'commerce.read',
			'handler'        => array( $svc, 'get_product' ),
		) );
		self::register( 'commerce.list_orders', array(
			'title'          => 'List WooCommerce orders',
			'description'    => 'Liệt kê đơn hàng WooCommerce qua wc_get_orders(), có lọc theo trạng thái/khách hàng/khoảng ngày.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array_merge( $page_schema, array(
				'status'      => array( 'type' => 'string' ),
				'customer_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'from'        => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$' ),
				'to'          => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$' ),
			) ) ),
			'required_scope' => 'commerce.read',
			'handler'        => array( $svc, 'list_orders' ),
		) );
		self::register( 'commerce.get_order', array(
			'title'          => 'Get WooCommerce order',
			'description'    => 'Đọc chi tiết một đơn hàng (dòng sản phẩm, địa chỉ, ghi chú) qua wc_get_order().',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'order_id' ), 'properties' => array( 'order_id' => array( 'type' => 'integer', 'minimum' => 1 ) ) ),
			'required_scope' => 'commerce.read',
			'handler'        => array( $svc, 'get_order' ),
		) );
		// @mcp bizcity-mcp-standard@1 tool order.status
		// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1 wave 2 — one order by id, or the latest orders; customer phone masked.
		self::register( 'order.status', array(
			'title'          => 'Trạng thái đơn hàng',
			'description'    => 'Xem trạng thái một đơn theo order_id (dòng sản phẩm, tổng tiền, trạng thái, SĐT khách che còn 3 số cuối), hoặc không có order_id thì xem các đơn mới nhất (tối đa 20).',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'order_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'limit'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 5 ),
				'status'   => array( 'type' => 'string' ),
			) ),
			'output_schema'  => self::envelope_schema( array(
				'order'  => array( 'type' => 'object' ),
				'orders' => array( 'type' => 'array' ),
				'total'  => array( 'type' => 'integer' ),
			) ),
			'required_scope' => 'order.read',
			'handler'        => array( $svc, 'order_status' ),
			'mode'           => 'orders',
			'scopes'         => array( 'order.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'biz_orders',
			'fallback_pack'  => 'orders',
			'since'          => '0.88.2',
		) );
		self::register( 'commerce.list_customers', array(
			'title'          => 'List WooCommerce customers',
			'description'    => 'Liệt kê khách hàng có tài khoản WordPress (role customer) kèm tổng số đơn và tổng chi tiêu qua WooCommerce customer helpers.',
			'input_schema'   => array( 'type' => 'object', 'properties' => $page_schema ),
			'required_scope' => 'commerce.read',
			'handler'        => array( $svc, 'list_customers' ),
		) );
		self::register( 'commerce.get_customer', array(
			'title'          => 'Get WooCommerce customer',
			'description'    => 'Đọc chi tiết một khách hàng kèm 5 đơn hàng gần nhất.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'customer_id' ), 'properties' => array( 'customer_id' => array( 'type' => 'integer', 'minimum' => 1 ) ) ),
			'required_scope' => 'commerce.read',
			'handler'        => array( $svc, 'get_customer' ),
		) );
	}
}
