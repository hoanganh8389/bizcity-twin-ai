<?php
/**
 * Bizcity Twin AI — core/conversation bootstrap (shared message store owner).
 *
 * [2026-09-24 Claude Opus 5.5] CORE-REDUCTION WP-11 C0a / R-INTENT-MIN R-IM-6 (owner decision D-24).
 * Owns the `bizcity_webchat_*` message-store classes that TwinChat, Twin GPT, Channel Gateway, CRM and
 * profile share. No DB work at file scope (R-PERF).
 *
 * [2026-09-24 Claude Opus 5.5] WP-11 C1a — modules/webchat is archived, so this folder is now loaded by
 * bizcity-twin-ai.php on its own and installs its tables itself: one `init` schema check, gated to
 * maintenance contexts, idempotent (version option). The site provisioner calls the same method for new
 * blogs through the installer registry (`[ 'BizCity_WebChat_Database', 'ensure_tables_exist' ]`).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Conversation
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! defined( 'BIZCITY_CONVERSATION_DIR' ) ) {
	define( 'BIZCITY_CONVERSATION_DIR', __DIR__ . '/' );
}

// R-SAFE-LOADER: every artifact goes through the guarded loader. Without it the store stays absent and
// callers, which all check class_exists( 'BizCity_WebChat_Database' ), degrade instead of fataling.
if ( class_exists( 'BizCity_Safe_Loader', false ) ) {
	BizCity_Safe_Loader::require_file( BIZCITY_CONVERSATION_DIR . 'includes/class-conversation-session-state.php', 'conversation.session_state' );
	BizCity_Safe_Loader::require_file( BIZCITY_CONVERSATION_DIR . 'includes/class-conversation-store.php', 'conversation.store' );
	// [2026-09-25 Claude Opus 5.5] WP-11 FATAL-SWEEP — chat image helpers (Admin Chat, Chat Send Service).
	BizCity_Safe_Loader::require_file( BIZCITY_CONVERSATION_DIR . 'includes/functions-conversation-media.php', 'conversation.media' );
} else {
	error_log( '[bizcity] conversation_store_skipped: BizCity_Safe_Loader unavailable' );
}

if ( ! function_exists( 'bizcity_conversation_maybe_install' ) ) {
	/**
	 * Create / upgrade the message-store tables on maintenance requests only (admin, REST, cron, CLI,
	 * channel webhooks) — never on a plain frontend page render. Runs at most once per request.
	 */
	function bizcity_conversation_maybe_install() {
		static $done = false;
		if ( $done || ! class_exists( 'BizCity_WebChat_Database', false ) || ! method_exists( 'BizCity_WebChat_Database', 'ensure_tables_exist' ) ) {
			return;
		}
		$done = true;
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$qs   = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '';
		$maintenance = is_admin()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| false !== strpos( $uri, '/wp-json/' )
			|| false !== strpos( $uri, '/bizhook/' )
			|| false !== strpos( $uri, '/zalohook/' )
			|| false !== strpos( $uri, '/facehook/' )
			|| false !== strpos( $uri, '/bizfbhook' )
			|| false !== strpos( $qs, 'fbhook=1' );
		if ( ! $maintenance ) {
			return;
		}
		BizCity_WebChat_Database::ensure_tables_exist();
	}
	add_action( 'init', 'bizcity_conversation_maybe_install', 0 );
}
