<?php
/**
 * PHASE-0.60A W1 — BizCity_Bot_Config_Repo tests.
 *
 * Covers B1.7 (safe defaults when settings.bot was never configured) and B1.8
 * (read-merge-write must not clobber sibling keys already living in the same
 * `settings` JSON column, e.g. `fanpage_id` from an unrelated feature).
 *
 * // [2026-09-23 Claude Sonnet 5] PHASE-0.60A W1-test
 */

// Minimal WP option store + WP_Error, ahead of requiring the class under test.
// [2026-09-23 Claude Sonnet 5] PHASE-0.60A W1-test — this suite has no single canonical
// get_option/update_option stub; several files each define their own guarded copy, and
// whichever file's testsuite-discovery order loads first "wins" for the whole process
// (see ChannelPersonalQuotaTest.php's own comment about this). Reusing the exact same
// backing store names (bizcity_reconciler_test_options / bizcity_options_stub) that
// ChannelPersonalQuotaTest.php / ContextBankReconcilerTest.php already agreed on — rather
// than inventing a third name — keeps this file compatible regardless of load order.
if ( ! isset( $GLOBALS['bizcity_reconciler_test_options'] ) ) {
	$GLOBALS['bizcity_reconciler_test_options'] = array();
}
if ( ! isset( $GLOBALS['bizcity_options_stub'] ) ) {
	$GLOBALS['bizcity_options_stub'] = array();
}
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
		private $code;
		private $message;
		private $data;
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

// Fake Knowledge Database — a tiny in-memory character store standing in for the
// real DB-backed class-database.php, so this stays a hermetic unit test (no $wpdb).
if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
final class BizCity_Knowledge_Database {
	private static $instance;
	private $rows = array();

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function seed( int $id, $settings_raw ): void {
		$this->rows[ $id ] = (object) array( 'id' => $id, 'settings' => $settings_raw, 'system_prompt' => 'Persona.' );
	}

	public function get_character( $id ) {
		return $this->rows[ (int) $id ] ?? null;
	}

	public function update_character( $id, $data ) {
		if ( isset( $this->rows[ (int) $id ] ) && isset( $data['settings'] ) ) {
			$this->rows[ (int) $id ]->settings = $data['settings'];
		}
		return true;
	}
}
}

require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-config-repo.php';

use PHPUnit\Framework\TestCase;

final class BotConfigRepoTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['bizcity_reconciler_test_options'] = array();
		$GLOBALS['bizcity_options_stub']            = array();
		// Reset the fake DB's rows for isolation between tests.
		$ref = new ReflectionProperty( BizCity_Knowledge_Database::class, 'rows' );
		$ref->setAccessible( true );
		$ref->setValue( BizCity_Knowledge_Database::instance(), array() );
	}

	public function test_get_returns_safe_defaults_when_never_configured(): void {
		BizCity_Knowledge_Database::instance()->seed( 1, '' );
		$bot = BizCity_Bot_Config_Repo::get( 1 );
		$this->assertTrue( $bot['bypass_notebook'] );
		$this->assertSame( 20, $bot['history_limit'] );
	}

	public function test_get_returns_defaults_for_unknown_character(): void {
		$bot = BizCity_Bot_Config_Repo::get( 999 );
		$this->assertSame( BizCity_Bot_Config_Repo::defaults(), $bot );
	}

	public function test_save_does_not_clobber_sibling_settings_keys(): void {
		BizCity_Knowledge_Database::instance()->seed( 2, wp_json_encode( array(
			'fanpage_id' => 'fp-123',
			'temperature' => 0.4,
		) ) );

		$result = BizCity_Bot_Config_Repo::save( 2, array( 'history_limit' => 40 ) );
		$this->assertIsArray( $result );
		$this->assertSame( 40, $result['history_limit'] );

		$raw = BizCity_Knowledge_Database::instance()->get_character( 2 )->settings;
		$decoded = json_decode( $raw, true );
		$this->assertSame( 'fp-123', $decoded['fanpage_id'], 'sibling feature key must survive a bot-settings save' );
		$this->assertSame( 0.4, $decoded['temperature'], 'sibling feature key must survive a bot-settings save' );
		$this->assertSame( 40, $decoded['bot']['history_limit'] );
	}

	public function test_save_rejects_out_of_range_history_limit(): void {
		BizCity_Knowledge_Database::instance()->seed( 3, '' );
		$result = BizCity_Bot_Config_Repo::save( 3, array( 'history_limit' => 500 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_get_tuning_defaults_when_option_never_set(): void {
		$tuning = BizCity_Bot_Config_Repo::get_tuning();
		$this->assertSame( 30, $tuning['pause_window_minutes'] );
		$this->assertSame( 40, $tuning['daily_message_cap'] );
		$this->assertSame( 8, $tuning['debounce_seconds'] );
	}

	public function test_save_tuning_validates_bounds(): void {
		$result = BizCity_Bot_Config_Repo::save_tuning( array( 'daily_message_cap' => 0 ) );
		$this->assertInstanceOf( WP_Error::class, $result );

		$ok = BizCity_Bot_Config_Repo::save_tuning( array( 'daily_message_cap' => 60 ) );
		$this->assertIsArray( $ok );
		$this->assertSame( 60, $ok['daily_message_cap'] );
		// Sibling tuning fields survive a partial save.
		$this->assertSame( 30, $ok['pause_window_minutes'] );
	}
}
