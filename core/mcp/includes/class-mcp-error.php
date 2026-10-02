<?php
/**
 * BizCity_MCP_Error — canonical response envelope + error code catalog for
 * the Twin Client Brain MCP gateway (core/mcp).
 *
 * Every tool handler in core/mcp returns either a plain array (success) or a
 * WP_Error (failure). BizCity_MCP_Tool_Registry::call() converts both into
 * this envelope shape before the HTTP controller serializes it into the
 * MCP `tools/call` result. This mirrors R-ERROR-UX (code+message+hint via
 * `details`) adapted to the MCP protocol's own envelope contract instead of
 * BizCity_Error_Payload (which is the twinchat/webchat REST convention, not
 * MCP JSON-RPC).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-07-27 (PHASE-0.53-MCP Wave A)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-27 Johnny Chu] PHASE-0.53-MCP — new file, error envelope + code catalog.
final class BizCity_MCP_Error {

	// ── Auth / scope ────────────────────────────────────────────────────
	const AUTH_REQUIRED         = 'MCP_AUTH_REQUIRED';
	const AUTH_INVALID          = 'MCP_AUTH_INVALID';
	const SCOPE_DENIED          = 'MCP_SCOPE_DENIED';

	// ── Notebook / tenant ───────────────────────────────────────────────
	const TENANT_NOT_FOUND       = 'MCP_TENANT_NOT_FOUND';
	const NOTEBOOK_NOT_FOUND     = 'MCP_NOTEBOOK_NOT_FOUND';
	const NOTEBOOK_ACCESS_DENIED = 'MCP_NOTEBOOK_ACCESS_DENIED';

	// ── Retrieval / snapshot ────────────────────────────────────────────
	const QUERY_INVALID     = 'MCP_QUERY_INVALID';
	const RETRIEVAL_FAILED  = 'MCP_RETRIEVAL_FAILED';
	const SNAPSHOT_NOT_FOUND = 'MCP_SNAPSHOT_NOT_FOUND';
	const SNAPSHOT_EXPIRED   = 'MCP_SNAPSHOT_EXPIRED';
	const SNAPSHOT_STALE     = 'MCP_SNAPSHOT_STALE';

	// ── Passage / citation ──────────────────────────────────────────────
	const PASSAGE_NOT_FOUND     = 'MCP_PASSAGE_NOT_FOUND';
	const PASSAGE_ACCESS_DENIED = 'MCP_PASSAGE_ACCESS_DENIED';
	const CITATION_INVALID      = 'MCP_CITATION_INVALID';

	// ── Document tools (Wave E/F) ───────────────────────────────────────
	const CONTEXT_PACK_NOT_FOUND = 'MCP_CONTEXT_PACK_NOT_FOUND';
	const CONTEXT_PACK_EXPIRED   = 'MCP_CONTEXT_PACK_EXPIRED';
	const DRAFT_INVALID          = 'MCP_DRAFT_INVALID';
	const RENDER_BLOCKED         = 'MCP_RENDER_BLOCKED_BY_VALIDATION';
	const RENDERER_UNAVAILABLE   = 'MCP_RENDERER_UNAVAILABLE';
	const RENDER_FAILED          = 'MCP_RENDER_FAILED';
	const ACTION_CONFIRMATION_REQUIRED = 'MCP_ACTION_CONFIRMATION_REQUIRED';

	// ── Generic ──────────────────────────────────────────────────────────
	const RATE_LIMITED   = 'MCP_RATE_LIMITED';
	const INTERNAL_ERROR = 'MCP_INTERNAL_ERROR';
	const NOT_FOUND      = 'MCP_NOT_FOUND';

	// Extension beyond the source design doc's §17 catalog — needed at the
	// protocol dispatch layer (tools/call with an unknown tool name), not a
	// tool-level business error.
	const TOOL_NOT_FOUND = 'MCP_TOOL_NOT_FOUND';
	// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — admin per-tool allowlist gate (BizCity_MCP_Tool_Policy), distinct from TOOL_NOT_FOUND (registered but turned off vs. never existed).
	const TOOL_DISABLED  = 'MCP_TOOL_DISABLED';

	// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-7 / L3-1 — one MCP standard: delegated (cell) gate, confirm wrapper, CL-B write tools.
	const MODE_NOT_ALLOWED                = 'MCP_MODE_NOT_ALLOWED';
	const DELEGATION_PRINCIPAL_UNBOUND    = 'MCP_DELEGATION_PRINCIPAL_UNBOUND';
	const CONFIRM_ARGS_CHANGED            = 'MCP_CONFIRM_ARGS_CHANGED';
	const CONFIRM_INVALID                 = 'MCP_CONFIRM_INVALID';
	const INVENTORY_RESERVE_UNAVAILABLE   = 'MCP_INVENTORY_RESERVE_UNAVAILABLE';
	const INVENTORY_NOT_ENOUGH            = 'MCP_INVENTORY_NOT_ENOUGH';
	const BOOKING_SLOT_TAKEN              = 'MCP_BOOKING_SLOT_TAKEN';
	// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-8 — staff.notify: the staff member has no Zalo Bot binding (R-LM-8: no fallback channel).
	const STAFF_UNREACHABLE               = 'MCP_STAFF_UNREACHABLE';
	// [2026-10-01 Claude Opus 5.5] PHASE-0.88 D-MCP-2 — the same write (same idempotency_key) is still running; retry later.
	const WRITE_IN_PROGRESS               = 'MCP_WRITE_IN_PROGRESS';

	/**
	 * @return string e.g. "trc_5f2c..."
	 */
	public static function trace_id() {
		return 'trc_' . str_replace( '-', '', wp_generate_uuid4() );
	}

	private static function meta( $tool, array $ctx = array(), array $extra = array() ) {
		return array(
			'trace_id'       => isset( $extra['trace_id'] ) ? (string) $extra['trace_id'] : self::trace_id(),
			'client_id'      => isset( $ctx['client_id'] ) ? (string) $ctx['client_id'] : '',
			'blog_id'        => (int) get_current_blog_id(),
			'tool'           => (string) $tool,
			'tool_version'   => '1.0.0',
			'schema_version' => '1.0',
			'duration_ms'    => isset( $extra['duration_ms'] ) ? (int) $extra['duration_ms'] : 0,
			'generated_at'   => gmdate( 'c' ),
		);
	}

	/**
	 * @param string $tool
	 * @param mixed  $data
	 * @return array
	 */
	public static function success( $tool, $data, array $extra = array(), $message = '', array $ctx = array() ) {
		return array(
			'success'  => true,
			'complete' => true,
			'message'  => (string) $message,
			'data'     => $data,
			'meta'     => self::meta( $tool, $ctx, $extra ),
		);
	}

	/**
	 * @param string $tool
	 * @param string $code One of the MCP_* constants above.
	 * @return array
	 */
	public static function fail( $tool, $code, $message, $retryable = false, array $details = array(), array $extra = array(), array $ctx = array() ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP REFLECT — whitelist codes + R-ERROR-UX guidance and message bound.
		$code = self::is_known_code( $code ) ? (string) $code : self::INTERNAL_ERROR;
		$guidance = self::guidance( $code );
		$message = (string) $message;
		if ( function_exists( 'mb_substr' ) ) {
			$message = mb_substr( $message, 0, 120, 'UTF-8' );
		} else {
			$message = substr( $message, 0, 120 );
		}
		return array(
			'success'  => false,
			'complete' => true,
			'code'     => $code,
			'message'  => (string) $message,
			'hint'     => $guidance['hint'],
			'help_code'=> $guidance['help_code'],
			'error'    => array(
				'code'      => $code,
				'retryable' => (bool) $retryable,
				'details'   => $details,
				'hint'      => $guidance['hint'],
				'help_code' => $guidance['help_code'],
			),
			'meta'     => self::meta( $tool, $ctx, $extra ),
		);
	}

	/**
	 * Keep the MCP error catalog closed. Prefix checks are insufficient because
	 * a raw WP_Error code such as MCP_INTERNAL_CUSTOM would otherwise leak into
	 * the public protocol.
	 */
	public static function is_known_code( $code ) {
		return in_array( (string) $code, array(
			self::AUTH_REQUIRED, self::AUTH_INVALID, self::SCOPE_DENIED,
			self::TENANT_NOT_FOUND, self::NOTEBOOK_NOT_FOUND, self::NOTEBOOK_ACCESS_DENIED,
			self::QUERY_INVALID, self::RETRIEVAL_FAILED, self::SNAPSHOT_NOT_FOUND,
			self::SNAPSHOT_EXPIRED, self::SNAPSHOT_STALE, self::PASSAGE_NOT_FOUND,
			self::PASSAGE_ACCESS_DENIED, self::CITATION_INVALID, self::CONTEXT_PACK_NOT_FOUND,
			self::CONTEXT_PACK_EXPIRED, self::DRAFT_INVALID, self::RENDER_BLOCKED,
			self::RENDERER_UNAVAILABLE, self::RENDER_FAILED, self::RATE_LIMITED,
			self::ACTION_CONFIRMATION_REQUIRED, self::INTERNAL_ERROR, self::NOT_FOUND, self::TOOL_NOT_FOUND, // [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — whitelist admin/customer not_found envelope.
			self::TOOL_DISABLED, // [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — whitelist admin tool allowlist envelope.
			// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-7 / L3-1 — delegated gate, confirm wrapper and CL-B write-tool refusals.
			self::MODE_NOT_ALLOWED, self::DELEGATION_PRINCIPAL_UNBOUND, self::CONFIRM_ARGS_CHANGED, self::CONFIRM_INVALID,
			self::INVENTORY_RESERVE_UNAVAILABLE, self::INVENTORY_NOT_ENOUGH, self::BOOKING_SLOT_TAKEN, self::STAFF_UNREACHABLE,
			self::WRITE_IN_PROGRESS, // [2026-10-01 Claude Opus 5.5] PHASE-0.88 D-MCP-2 — write idempotency.
		), true );
	}

	/** @return array{hint:string,help_code:string} */
	private static function guidance( $code ) {
		// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-7 / L3-1 — R-ERROR-UX hints for the one-MCP-standard codes (Vietnamese, user-facing).
		$s88 = array(
			self::MODE_NOT_ALLOWED             => array( 'hint' => 'Người này chưa được cấp mục này cho Agent. Cấp quyền theo vai trò (WordPress hoặc CRM) rồi thử lại.', 'help_code' => 'mode_not_allowed' ),
			self::DELEGATION_PRINCIPAL_UNBOUND => array( 'hint' => 'Kiểm tra "UID chủ tài khoản" hoặc danh sách nhân sự của số ở Bot Studio, rồi đồng bộ lại cấu hình số.', 'help_code' => 'delegation_principal_unbound' ),
			self::CONFIRM_ARGS_CHANGED         => array( 'hint' => 'Tham số đã khác bản xem trước. Gọi lại tool không kèm confirm_token để lấy bản xem trước mới.', 'help_code' => 'confirm_args_changed' ),
			self::CONFIRM_INVALID              => array( 'hint' => 'Mã xác nhận đã dùng, đã hết hạn hoặc không thuộc người này. Gọi lại tool không kèm confirm_token để xem trước lại.', 'help_code' => 'confirm_invalid' ),
			self::INVENTORY_RESERVE_UNAVAILABLE => array( 'hint' => 'Bật WooCommerce (bản có giữ hàng ReserveStock) rồi thử lại.', 'help_code' => 'inventory_reserve_unavailable' ),
			self::INVENTORY_NOT_ENOUGH         => array( 'hint' => 'Giảm số lượng hoặc xem tồn kho khả dụng (inventory.check) trước khi giữ hàng.', 'help_code' => 'inventory_not_enough' ),
			self::BOOKING_SLOT_TAKEN           => array( 'hint' => 'Khung giờ này đã có người đặt. Xem giờ trống (booking.available_slots) rồi chọn giờ khác.', 'help_code' => 'booking_slot_taken' ),
			self::STAFF_UNREACHABLE            => array( 'hint' => 'Người nhận cần liên kết Zalo Bot của mình (Channel Gateway → Kênh của tôi) rồi thử lại.', 'help_code' => 'staff_unreachable' ),
			// [2026-10-01 Claude Opus 5.5] PHASE-0.88 D-MCP-2 — write idempotency: the first call has not finished yet.
			self::WRITE_IN_PROGRESS            => array( 'hint' => 'Thao tác này đang được xử lý. Đợi khoảng một phút rồi hỏi lại để xem kết quả, đừng gửi lại lệnh mới.', 'help_code' => 'write_in_progress' ),
		);
		if ( isset( $s88[ $code ] ) ) {
			return $s88[ $code ];
		}
		if ( in_array( $code, array( self::AUTH_REQUIRED, self::AUTH_INVALID ), true ) ) {
			return array( 'hint' => 'Kiểm tra MCP API key và gửi lại Authorization Bearer hợp lệ.', 'help_code' => 'auth_required' );
		}
		if ( in_array( $code, array( self::SCOPE_DENIED, self::NOTEBOOK_ACCESS_DENIED, self::PASSAGE_ACCESS_DENIED ), true ) ) {
			return array( 'hint' => 'Kiểm tra scope và quyền đọc notebook của MCP client.', 'help_code' => 'permission_denied' );
		}
		if ( $code === self::RATE_LIMITED ) {
			return array( 'hint' => 'Đợi hết thời gian giới hạn rồi thử lại với tần suất thấp hơn.', 'help_code' => 'rate_limited' );
		}
		if ( $code === self::ACTION_CONFIRMATION_REQUIRED ) {
			return array( 'hint' => 'Xem lại bản preview rồi xác nhận lại thao tác trước khi xuất bản.', 'help_code' => 'action_confirmation_required' );
		}
		if ( $code === self::TOOL_DISABLED ) {
			return array( 'hint' => 'Vào Channel Gateway → MCP Access → Cấu hình tools để admin bật lại tool này cho client.', 'help_code' => 'module_not_loaded' );
		}
		if ( in_array( $code, array( self::QUERY_INVALID, self::CITATION_INVALID, self::DRAFT_INVALID ), true ) ) {
			return array( 'hint' => 'Kiểm tra lại input, citation và schema rồi gửi lại.', 'help_code' => 'invalid_param_generic' );
		}
		if ( in_array( $code, array( self::SNAPSHOT_NOT_FOUND, self::SNAPSHOT_EXPIRED, self::SNAPSHOT_STALE, self::PASSAGE_NOT_FOUND, self::CONTEXT_PACK_NOT_FOUND, self::CONTEXT_PACK_EXPIRED, self::TOOL_NOT_FOUND ), true ) ) {
			return array( 'hint' => 'Kiểm tra handle hoặc tạo snapshot/context pack mới rồi thử lại.', 'help_code' => 'not_found' );
		}
		if ( in_array( $code, array( self::RENDER_BLOCKED, self::RENDERER_UNAVAILABLE, self::RENDER_FAILED ), true ) ) {
			return array( 'hint' => 'Kiểm tra validation và trạng thái renderer trước khi render lại.', 'help_code' => 'module_not_loaded' );
		}
		return array( 'hint' => 'Kiểm tra Diagnostics và thử lại sau.', 'help_code' => 'module_not_loaded' );
	}

	/**
	 * Convert a WP_Error thrown by a tool handler into the MCP envelope.
	 * WP_Error code is trusted verbatim only if it is already one of the
	 * MCP_* constants; anything else (e.g. a raw WP core error code) is
	 * mapped to MCP_INTERNAL_ERROR so internal WP error codes never leak
	 * to MCP clients (OWASP A05 — no stack traces / internal identifiers).
	 *
	 * @return array
	 */
	public static function from_wp_error( $tool, WP_Error $err, array $extra = array(), array $ctx = array() ) {
		$code = (string) $err->get_error_code();
		if ( ! self::is_known_code( $code ) ) {
			$code = self::INTERNAL_ERROR;
		}
		$data = $err->get_error_data();
		$details = ( $code === self::INTERNAL_ERROR ) ? array() : ( is_array( $data ) ? $data : array() );
		unset( $details['status'] ); // HTTP status is handled by the REST layer, not part of the MCP payload.
		$message = $code === self::INTERNAL_ERROR ? 'Lỗi nội bộ khi xử lý MCP request.' : $err->get_error_message();
		return self::fail( $tool, $code, $message, $code === self::INTERNAL_ERROR, $details, $extra, $ctx );
	}
}
