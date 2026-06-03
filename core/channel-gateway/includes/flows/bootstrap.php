<?php
/**
 * BizCity Channel Gateway — Flows sub-module bootstrap.
 *
 * Loads the ported `bizgpt-custom-flows` codepath under
 * `core/channel-gateway/includes/flows/` and registers backward-compat
 * function aliases so legacy callers keep working until the standalone
 * plugin is archived.
 *
 * Schema source of truth: core/diagnostics/changelog/modules.flows.json
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway\Flows
 * @since      PHASE-N (2026-05-25)
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-flow-installer.php';
require_once __DIR__ . '/class-flow-ref-codec.php';
require_once __DIR__ . '/class-flow-handler.php';
require_once __DIR__ . '/class-flow-rest.php';

BizCity_CG_Flow_REST::init();

if ( is_admin() ) {
	require_once __DIR__ . '/class-flow-admin-page.php';
	BizCity_CG_Flow_Admin_Page::init();
}

/* ============================================================
 * Backward-compat function wrappers — DISABLED 2026-05-25.
 *
 * Lý do: plugin gốc `bizgpt-custom-flows` declare các function
 * `bizgpt_flow_remove_vietnamese_accents` / `bizgpt_match_custom_flow`
 * / `bizgpt_handle_guest_flow` / `bizgpt_run_flow_steps` ở file-scope
 * KHÔNG kèm `function_exists` guard. `bizcity-twin-ai` load alphabetically
 * TRƯỚC nên nếu ta define wrapper ở đây, plugin cũ re-declare → PHP Fatal
 * (Cannot redeclare bizgpt_flow_rem...).
 *
 * Trong khi plugin cũ còn active, mới code KHÔNG cần wrapper — gọi thẳng
 * `BizCity_CG_Flow_Handler::strip_accents()` / `::match()` / `::handle_guest_flow()`.
 * Sau Phase D (archive plugin cũ), nếu cần backward-compat cho code 3rd-party
 * sẽ re-enable block này (lúc đó không còn declarer khác).
 *
 * Re-enable checklist (Phase D):
 *   1. Verify `WP_PLUGIN_DIR . '/bizgpt-custom-flows/bizgpt-custom-flows.php'` không tồn tại.
 *   2. Uncomment block bên dưới.
 *   3. Update probe `class-probe-cg-flows.php` để check compat wrapper trở lại.
 * ============================================================ */
// if ( ! function_exists( 'bizgpt_flow_remove_vietnamese_accents' ) ) {
// 	function bizgpt_flow_remove_vietnamese_accents( string $s ): string {
// 		return BizCity_CG_Flow_Handler::strip_accents( $s );
// 	}
// }
// if ( ! function_exists( 'bizgpt_match_custom_flow' ) ) {
// 	function bizgpt_match_custom_flow( string $question ): array {
// 		return BizCity_CG_Flow_Handler::match( $question );
// 	}
// }
// if ( ! function_exists( 'bizgpt_handle_guest_flow' ) ) {
// 	function bizgpt_handle_guest_flow( string $question ): array {
// 		return BizCity_CG_Flow_Handler::handle_guest_flow( $question );
// 	}
// }
// if ( ! function_exists( 'bizgpt_run_flow_steps' ) ) {
// 	function bizgpt_run_flow_steps( int $flow_id, array $ctx ): array {
// 		return BizCity_CG_Flow_Handler::run_steps( $flow_id, $ctx );
// 	}
// }
