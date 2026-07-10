<?php
/**
 * BizCity_Error_Payload — Canonical error response builder.
 *
 * [2026-06-05 Johnny Chu] R-ERROR-UX — helper bắt buộc cho mọi REST/AJAX
 * endpoint cần trả payload lỗi chuẩn 4 trường:
 *   code · message · hint · help_code
 *
 * @package    BizCity_Twin_AI
 * @subpackage Core\Helper
 * @since      3.1.0
 * @see        core/helper/docs/ERROR-UX-SPEC.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'BizCity_Error_Payload' ) ) {
	return;
}

/**
 * Builds standardised fail-OPEN error arrays consumed by the FE ErrorPayload
 * type. HTTP status is always 200 (fail-OPEN) — caller uses wp_send_json_success().
 *
 * Usage:
 *   wp_send_json_success( BizCity_Error_Payload::make(
 *       'token_invalid',
 *       'Facebook Page Token hết hạn.',
 *       'Vào Cài đặt → Facebook → Cấp quyền lại.',
 *       'fb_token_expired'
 *   ) );
 *
 * REST:
 *   return new WP_REST_Response( BizCity_Error_Payload::make( ... ), 200 );
 */
class BizCity_Error_Payload {

	/**
	 * Build a standardised error payload array.
	 *
	 * @param string      $code      Error code from the catalog (e.g. 'token_invalid').
	 *                               MUST be a key from the catalog in ERROR-UX-SPEC.md §4.
	 * @param string      $message   Human-readable Vietnamese sentence describing WHAT happened.
	 *                               Max 120 characters. NO stack traces, SQL, file paths, PII.
	 * @param string|null $hint      Action-oriented instruction. Must start with a verb:
	 *                               "Vào...", "Kiểm tra...", "Đợi...", "Liên hệ...".
	 * @param string|null $help_code Key that exists in FE HELP_CATALOG to open ErrorHelpDialog.
	 * @param array       $context   Optional debug key/value pairs. NEVER include PII.
	 * @return array
	 */
	public static function make( $code, $message, $hint = null, $help_code = null, $context = array() ) {
		// [2026-06-05 Johnny Chu] R-ERROR-UX — bridge to Error_Reporter auto-record.
		// Auto-record only when BizCity_Error_Reporter is available (diagnostics loaded).
		if ( class_exists( 'BizCity_Error_Reporter' ) ) {
			BizCity_Error_Reporter::record( array(
				'code'    => $code,
				'module'  => self::caller_module(),
				'title'   => $message,
				'detail'  => $hint ?? '',
				'context' => (array) $context,
				'source'  => 'be',
			) );
		}

		$payload = array(
			'success'    => false,
			'_degraded'  => true,
			'code'       => (string) $code,
			'message'    => (string) $message,
			'hint'       => null !== $hint ? (string) $hint : null,
			'help_code'  => null !== $help_code ? (string) $help_code : null,
			'context'    => (array) $context,
		);

		// Attach repair-hint URL when available.
		if ( class_exists( 'BizCity_Error_Reporter' ) ) {
			$fix = BizCity_Error_Reporter::suggest_fix( (string) $code );
			if ( ! empty( $fix ) ) {
				$payload['fix'] = $fix;
			}
		}

		return $payload;
	}

	/**
	 * Build from a WP_Error object.
	 *
	 * @param WP_Error    $error
	 * @param string|null $hint
	 * @param string|null $help_code
	 * @return array
	 */
	public static function from_wp_error( $error, $hint = null, $help_code = null ) {
		$code    = $error->get_error_code();
		$message = $error->get_error_message();
		$data    = $error->get_error_data( $code );

		return self::make(
			$code,
			$message,
			$hint,
			$help_code,
			is_array( $data ) ? $data : array()
		);
	}

	/**
	 * Build a "module not loaded" degraded response.
	 * Shorthand for the most common gateway-guard pattern.
	 *
	 * @param string $module_label Human-readable module name for the message.
	 * @return array
	 */
	public static function module_not_loaded( $module_label ) {
		return self::make(
			'module_not_loaded',
			/* translators: %s = module display name */
			sprintf( 'Module %s chưa được load.', $module_label ),
			'Kiểm tra plugin bizcity-twin-ai đã activate và không có PHP fatal error.',
			'module_not_loaded'
		);
	}

	/**
	 * Build a "gateway degraded" response for LLM client failures.
	 *
	 * @param string|null $detail Optional short technical detail (NOT shown to user).
	 * @return array
	 */
	public static function gateway_degraded( $detail = null ) {
		return self::make(
			'gateway_degraded',
			'Dịch vụ AI tạm thời không khả dụng.',
			'Thử lại sau vài phút. Nếu lỗi kéo dài, liên hệ support@bizcity.vn.',
			'gateway_degraded',
			null !== $detail ? array( 'detail' => (string) $detail ) : array()
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// Internal helpers
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Infer a short module identifier from the call stack.
	 * Returns e.g. "channel-gateway", "automation", "agents" — used in
	 * the `module` field of the auto-recorded error report.
	 *
	 * @return string
	 */
	private static function caller_module() {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 );
		foreach ( $trace as $frame ) {
			$file = isset( $frame['file'] ) ? str_replace( '\\', '/', (string) $frame['file'] ) : '';
			if ( $file === '' ) {
				continue;
			}
			// Skip the helper itself.
			if ( strpos( $file, 'core/helper' ) !== false ) {
				continue;
			}
			// Extract "core/<module>" or "modules/<module>".
			if ( preg_match( '#/(core|modules)/([^/]+)#', $file, $m ) ) {
				return $m[2];
			}
		}
		return 'unknown';
	}
}
