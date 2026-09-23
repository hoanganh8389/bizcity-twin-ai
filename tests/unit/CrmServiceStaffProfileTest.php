<?php
/**
 * PHASE-0.69 WP-S — `BizCity_CRM_Staff_Profile`: sanitize-on-write (never trust stored JSON blindly) and
 * `on_roster_at()`'s exception-before-weekly-grid precedence (0.69 §5.1).
 *
 * Same note as `CrmServiceLocationTest.php`: written against a standalone harness first because this
 * sandbox's local `vendor/bin/phpunit` does not execute (pre-existing environment issue, unrelated to
 * this phase). `get_user_meta`/`update_user_meta`/`get_userdata` are stubbed with an in-memory array so
 * `save()`/`get()` round-trip without a database.
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( (string) $s ); }
}

// [2026-09-23] The PHPUnit suite loads every file under `tests/unit/` to discover test classes (the
// testsuite XML points at the whole directory), so a `function_exists()`-guarded stub here can lose the
// definition race to whichever file sorts first alphabetically and ALSO defines the same WP function name.
// Two collisions exist today: `CrmContactTransferTest.php` defines `get_userdata()` reading
// `$GLOBALS['bzc_transfer_users']` as its allow-list, and `ChannelUserLinkerZaloBotTargetTest.php` defines
// `get_user_meta()` reading `$GLOBALS['bzc_linker_user_meta'][user_id][key]`. Both sort before this file
// (Contact/Channel < Service) and so both win their races unconditionally. Rather than fight PHP's
// can't-redeclare-a-function rule, this file cooperates: it seeds those exact globals in `setUp()` and
// points its OWN `update_user_meta()` at the same storage shape `get_user_meta()` will read from — so the
// round trip works whichever stub actually ends up active, in the full suite or run alone.
$GLOBALS['__crm_test_user_meta'] = array();
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) { return $user_id > 0 ? (object) array( 'ID' => $user_id ) : false; }
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) { return $GLOBALS['bzc_linker_user_meta'][ (int) $user_id ][ $key ] ?? ''; }
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value ) { $GLOBALS['bzc_linker_user_meta'][ (int) $user_id ][ $key ] = $value; return true; }
}
if ( ! class_exists( 'WP_Error' ) ) {
	// [2026-09-23] Minimal stand-in — no test in this suite has needed the real WP_Error before now.
	class WP_Error {
		private $code; private $message; private $data;
		public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/service/class-crm-staff-profile.php';

final class CrmServiceStaffProfileTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['bzc_linker_user_meta'] = array();
		// See the note above the stub definitions at the top of this file.
		$GLOBALS['bzc_transfer_users'] = array( 5, 7, 9, 11 );
	}

	public function test_get_on_a_user_with_no_profile_returns_safe_empty_defaults(): void {
		$profile = BizCity_CRM_Staff_Profile::get( 999 );
		$this->assertSame( array(), $profile['skills'] );
		$this->assertSame( array(), $profile['areas'] );
		$this->assertArrayHasKey( '0', $profile['roster'] );
		$this->assertArrayHasKey( '6', $profile['roster'] );
	}

	public function test_save_sanitizes_skills_and_areas_to_slugs_and_dedupes(): void {
		$result = BizCity_CRM_Staff_Profile::save( 5, array(
			'skills' => array( 'ho_ta', 'HO_TA', 'Không hợp lệ!', 'ho_ta' ),
			'areas'  => array( 'cau_giay' ),
		) );
		$this->assertTrue( $result );
		$saved = BizCity_CRM_Staff_Profile::get( 5 );
		// 'HO_TA' lowercases to a duplicate of 'ho_ta'; the invalid string is dropped, not stored raw.
		$this->assertSame( array( 'ho_ta' ), $saved['skills'] );
		$this->assertSame( array( 'cau_giay' ), $saved['areas'] );
	}

	public function test_save_rejects_unknown_user(): void {
		$result = BizCity_CRM_Staff_Profile::save( 0, array( 'skills' => array( 'ho_ta' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_save_drops_malformed_roster_windows_but_keeps_valid_ones(): void {
		BizCity_CRM_Staff_Profile::save( 7, array(
			'roster' => array(
				'1' => array( array( '08:00', '18:00' ), array( '25:00', '30:00' ), 'not-an-array' ),
			),
		) );
		$saved = BizCity_CRM_Staff_Profile::get( 7 );
		$this->assertSame( array( array( '08:00', '18:00' ) ), $saved['roster']['1'] );
	}

	public function test_on_roster_at_true_inside_a_declared_window(): void {
		BizCity_CRM_Staff_Profile::save( 9, array( 'roster' => array( '1' => array( array( '08:00', '18:00' ) ) ) ) );
		// 2026-09-21 is a Monday (dow=1 in UTC); 10:00 UTC falls inside 08:00-18:00.
		$this->assertTrue( BizCity_CRM_Staff_Profile::on_roster_at( 9, strtotime( '2026-09-21 10:00:00 UTC' ) ) );
	}

	public function test_on_roster_at_false_outside_the_declared_window(): void {
		BizCity_CRM_Staff_Profile::save( 9, array( 'roster' => array( '1' => array( array( '08:00', '18:00' ) ) ) ) );
		$this->assertFalse( BizCity_CRM_Staff_Profile::on_roster_at( 9, strtotime( '2026-09-21 20:00:00 UTC' ) ) );
	}

	public function test_on_roster_at_exception_wins_over_the_weekly_grid_even_when_grid_would_say_yes(): void {
		BizCity_CRM_Staff_Profile::save( 11, array(
			'roster'            => array( '1' => array( array( '08:00', '18:00' ) ) ),
			'roster_exceptions' => array( array( 'date' => '2026-09-21', 'off' => true, 'reason' => 'nghỉ phép' ) ),
		) );
		$this->assertFalse( BizCity_CRM_Staff_Profile::on_roster_at( 11, strtotime( '2026-09-21 10:00:00 UTC' ) ) );
	}
}
