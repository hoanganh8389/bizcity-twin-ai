<?php
/**
 * Shared, guarded WordPress stubs + collaborator fakes for the PHASE-0.60A/B/C/D unit tests.
 *
 * Every definition is guarded so whichever test file PHPUnit includes first "wins"
 * without a redeclaration fatal (the suite-wide convention: see BotConfigRepoTest.php).
 * Backing stores reuse the names other files already agreed on.
 *
 * // [2026-09-23 04:40 PM Claude Fable 5.1] PHASE-0.60A/B/C/D — test support, not shipped code.
 */

if ( ! isset( $GLOBALS['bizcity_reconciler_test_options'] ) ) { $GLOBALS['bizcity_reconciler_test_options'] = array(); }
if ( ! isset( $GLOBALS['bizcity_options_stub'] ) ) { $GLOBALS['bizcity_options_stub'] = array(); }
if ( ! isset( $GLOBALS['bzc_transfer_transients'] ) ) { $GLOBALS['bzc_transfer_transients'] = array(); }
if ( ! isset( $GLOBALS['bizcity_transients_stub'] ) ) { $GLOBALS['bizcity_transients_stub'] = array(); }
if ( ! isset( $GLOBALS['bizcity_transient_ttl_stub'] ) ) { $GLOBALS['bizcity_transient_ttl_stub'] = array(); }

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		foreach ( array( 'bizcity_reconciler_test_options', 'bizcity_options_stub' ) as $store ) {
			if ( isset( $GLOBALS[ $store ] ) && is_array( $GLOBALS[ $store ] ) && array_key_exists( $name, $GLOBALS[ $store ] ) ) {
				return $GLOBALS[ $store ][ $name ];
			}
		}
		return $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['bizcity_reconciler_test_options'][ $name ] = $value;
		$GLOBALS['bizcity_options_stub'][ $name ]            = $value;
		return true;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message; private $data;
		public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		foreach ( array( 'bzc_transfer_transients', 'bizcity_transients_stub' ) as $store ) {
			if ( array_key_exists( $key, $GLOBALS[ $store ] ?? array() ) ) {
				return $GLOBALS[ $store ][ $key ];
			}
		}
		return false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['bzc_transfer_transients']    = $GLOBALS['bzc_transfer_transients'] ?? array();
		$GLOBALS['bizcity_transients_stub']    = $GLOBALS['bizcity_transients_stub'] ?? array();
		$GLOBALS['bizcity_transient_ttl_stub'] = $GLOBALS['bizcity_transient_ttl_stub'] ?? array();
		$GLOBALS['bzc_transfer_transients'][ $key ]    = $value;
		$GLOBALS['bizcity_transients_stub'][ $key ]    = $value;
		$GLOBALS['bizcity_transient_ttl_stub'][ $key ] = (int) $ttl;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['bzc_transfer_transients'][ $key ], $GLOBALS['bizcity_transients_stub'][ $key ], $GLOBALS['bizcity_transient_ttl_stub'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql' ) { return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' ); }
}
// [2026-09-23 Claude Sonnet 5] PHASE-0.60A — this hardcoded-1258 version used to win the
// declaration race (Bot* files sort before FrameworkCliTest.php) and silently broke
// FrameworkCliTest::test_explicit_blog_switch_restores_original_context, which needs its
// OWN dynamic value via $GLOBALS['bizcity_framework_cli_test_blog_id']. Respect that global
// when the other test sets it; fall back to 1258 for Bot Studio's own fixtures otherwise —
// same value, now compatible regardless of which file's guard wins.
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() { return (int) ( $GLOBALS['bizcity_framework_cli_test_blog_id'] ?? 1258 ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) { return (string) $url; }
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $v ) { return filter_var( (string) $v, FILTER_VALIDATE_EMAIL ) ? (string) $v : ''; }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'Asia/Ho_Chi_Minh'; }
}

/** Fake Knowledge Database — same shape as BotConfigRepoTest.php / BotTurnClaimTest.php. */
if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
final class BizCity_Knowledge_Database {
	private static $instance;
	private $rows = array();
	public static function instance(): self { if ( ! self::$instance ) { self::$instance = new self(); } return self::$instance; }
	public function seed( int $id, $settings_raw ): void { $this->rows[ $id ] = (object) array( 'id' => $id, 'settings' => $settings_raw, 'system_prompt' => 'Persona.' ); }
	public function get_character( $id ) { return $this->rows[ (int) $id ] ?? null; }
	public function update_character( $id, $data ) { if ( isset( $this->rows[ (int) $id ] ) && isset( $data['settings'] ) ) { $this->rows[ (int) $id ]->settings = $data['settings']; } return true; }
}
}

/** Fake Channel Binding — same shape as BotTurnClaimTest.php. */
if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
final class BizCity_Channel_Binding {
	public static $next_binding = null;
	public static function resolve( string $platform, string $account_id ): ?array { return self::$next_binding; }
}
}

/**
 * Fake Zalo Personal bridge client — PHASE-0.60E EA-7. Same two method signatures as the real
 * `BizCity_Zalo_Bridge_Client` (get_group_candidates/get_group_history); tests set the canned
 * response before calling BizCity_Bot_Tools::run() and read back which args this fake was
 * called with.
 */
if ( ! class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
final class BizCity_Zalo_Bridge_Client {
	private static $instance;
	public static $next_candidates_response = array( 'success' => true, 'groups' => array() );
	public static $next_history_response    = array( 'success' => true, 'messages' => array() );
	public static $last_history_call        = null; // [account_id, thread_ref, count]
	public static function instance(): self { if ( ! self::$instance ) { self::$instance = new self(); } return self::$instance; }
	public function get_group_candidates( string $account_id ): array { return self::$next_candidates_response; }
	public function get_group_history( string $account_id, string $thread_ref, int $count = 20 ): array {
		self::$last_history_call = array( $account_id, $thread_ref, $count );
		return self::$next_history_response;
	}
}
}

/** Fake TwinBrain vertical registry — the canonical 13-row catalog reduced to what the tests need. */
if ( ! class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' ) ) {
final class BizCity_TwinBrain_Vertical_Bridge_Registry {
	public static function all(): array {
		return array(
			array( 'id' => 'astro', 'label' => 'Astro', 'role' => 'Hồ sơ cá nhân và transit.', 'output_shape' => 'narrative', 'guest_allowed' => false, 'min_plan' => 'free' ),
			array( 'id' => 'quick', 'label' => 'Quick', 'role' => 'Tra cứu nhanh.', 'output_shape' => 'narrative', 'guest_allowed' => true, 'min_plan' => 'free' ),
			array( 'id' => 'med', 'label' => 'Med', 'role' => 'Y khoa tham khảo.', 'output_shape' => 'narrative', 'guest_allowed' => true, 'min_plan' => 'free' ),
			array( 'id' => 'law', 'label' => 'Law', 'role' => 'Pháp luật tham khảo.', 'output_shape' => 'narrative', 'guest_allowed' => true, 'min_plan' => 'plus' ),
			array( 'id' => 'woo_bizops', 'label' => 'Woo BizOps', 'role' => 'Doanh thu, đơn hàng.', 'output_shape' => 'table_and_narrative', 'guest_allowed' => false, 'min_plan' => 'free', 'sensitive' => true, 'context_bank' => array( 'requires_grant' => true ) ),
		);
	}
	public static function get( string $id ): ?array {
		foreach ( self::all() as $row ) { if ( $row['id'] === $id ) { return $row; } }
		return null;
	}
}
}

/**
 * Fake CRM repository — in-memory conversations/messages, enough for the runner + draft path.
 *
 * // [2026-09-23 Claude Sonnet 5] PHASE-0.60A — extended with $assigned/$reasons/
 * set_conversation_assignee() so this stays a superset of CrmContactTransferTest.php's own
 * guarded fake (tests/unit/CrmContactTransferTest.php:108-119): this file's Bot* consumers
 * load first alphabetically, so its fake wins the declaration race and CrmContactTransferTest's
 * own definition never runs — it was fataling on "Access to undeclared static property"
 * before this file also declared what it needs.
 */
if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
final class BizCity_CRM_Repository {
	public static $conversations = array();
	public static $messages      = array();
	public static $contacts      = array();
	public static $inserted      = array();
	/** conversation_id => assignee_id after the call (CrmContactTransferTest.php's shape). */
	public static $assigned = array();
	public static $reasons  = array();
	public static function reset(): void { self::$conversations = array(); self::$messages = array(); self::$contacts = array(); self::$inserted = array(); self::$assigned = array(); self::$reasons = array(); }
	public static function get_conversation( int $id ): ?array { return self::$conversations[ $id ] ?? null; }
	public static function get_contact( int $id ): ?array { return self::$contacts[ $id ] ?? null; }
	public static function list_messages( int $conversation_id, int $limit = 100, int $after_id = 0 ): array {
		$rows = array_values( array_filter( self::$messages, static function ( $m ) use ( $conversation_id ) { return (int) $m['conversation_id'] === $conversation_id; } ) );
		return array_slice( $rows, -$limit );
	}
	public static function insert_message( array $data ): int { $id = count( self::$inserted ) + 1000; $data['id'] = $id; self::$inserted[] = $data; return $id; }
	public static function get_inbox( int $id ): ?array { return array( 'id' => $id, 'channel_type' => 'zalo_personal', 'channel_ref_id' => '3' ); }
	public static function list_conversations_for_contact( int $contact_id, int $limit = 10 ): array { return array(); }
	public static function set_conversation_assignee( int $conv_id, ?int $assignee_id, int $by_user_id = 0, array $ctx = array(), bool $emit = true ): bool {
		self::$assigned[ $conv_id ] = (int) $assignee_id;
		self::$reasons[ $conv_id ] = (string) ( $ctx['reason'] ?? '' );
		return true;
	}
}
}
