<?php
/**
 * PHASE-0.60B — pure parts of the enrichment owner: profile normalisation, the source-labelled
 * context block (C5.1/C5.2), birthday next-occurrence edge cases (§5.2), rule 2 (never guess).
 * // [2026-09-23 04:45 PM Claude Fable 5.1] PHASE-0.60B-test
 */

require_once __DIR__ . '/support/bot-studio-stubs.php';
require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/class-contact-enrichment.php';

use PHPUnit\Framework\TestCase;

final class CrmContactEnrichmentTest extends TestCase {

	public function test_normalize_profile_accepts_bridge_key_spellings_and_vn_dob(): void {
		$n = BizCity_CRM_Contact_Enrichment::normalize_profile( array( 'success' => true, 'profile' => array( 'displayName' => ' Nguyễn Văn A ', 'avatar' => 'https://x/a.jpg', 'gender' => 0, 'sdob' => '12/03/1990' ) ) );
		$this->assertSame( 'Nguyễn Văn A', $n['name'] );
		$this->assertSame( 'male', $n['gender'] );
		$this->assertSame( '1990-03-12', $n['birthday'], 'Zalo sdob is d/m/Y' );
		$this->assertSame( '03-12', $n['birthday_md'] );
		$this->assertContains( 'sdob', $n['keys'], 'raw key list is recorded so the first real call documents Q-B2' );
	}

	public function test_normalize_profile_never_guesses_a_year(): void {
		$n = BizCity_CRM_Contact_Enrichment::normalize_profile( array( 'name' => 'B', 'dob' => '12/03' ) );
		$this->assertSame( '', $n['birthday'] );
		$this->assertSame( '03-12', $n['birthday_md'] );
		$n = BizCity_CRM_Contact_Enrichment::normalize_profile( array( 'name' => 'C', 'dob' => 'không rõ' ) );
		$this->assertSame( '', $n['birthday'] );
		$this->assertSame( '', $n['birthday_md'] );
		$this->assertSame( '', BizCity_CRM_Contact_Enrichment::normalize_profile( array( 'gender' => 'unknown' ) )['gender'] );
	}

	public function test_context_block_labels_sources_and_states_empty_slots(): void {
		$block = BizCity_CRM_Contact_Enrichment::render_context_block( array(
			'name' => 'Nguyễn Văn A',
			'birthday' => null, 'birthday_md' => '03-12',
			'additional_attributes' => wp_json_encode( array( 'zalo_profile' => array( 'display_name' => 'Nguyễn Văn A', 'gender' => 'male' ), 'birthday_meta' => array( 'source' => 'customer_stated' ) ) ),
		) );
		$this->assertStringContainsString( 'Tên: Nguyễn Văn A (nguồn: hồ sơ Zalo)', $block );
		$this->assertStringContainsString( 'Giới tính: nam (nguồn: hồ sơ Zalo)', $block );
		$this->assertStringContainsString( 'Sinh nhật: 12/03 (nguồn: khách nói trong chat; chưa có năm sinh)', $block, 'C5.2 — the missing year is information' );
		$this->assertStringContainsString( 'không phải khách tự khai', $block );

		$block = BizCity_CRM_Contact_Enrichment::render_context_block( array( 'name' => 'Chị Hoa', 'additional_attributes' => '' ) );
		$this->assertStringContainsString( 'Tên: Chị Hoa (nguồn: nhân viên nhập)', $block );
		$this->assertStringContainsString( 'Sinh nhật: chưa có', $block );
		$this->assertLessThanOrEqual( BizCity_CRM_Contact_Enrichment::CONTEXT_MAX_CHARS, mb_strlen( $block ) );
	}

	public function test_context_block_is_built_from_one_contact_row_only(): void {
		// C5.4 / E-B6 — the renderer receives exactly one row; nothing about another contact can leak in.
		$a = BizCity_CRM_Contact_Enrichment::render_context_block( array( 'name' => 'A', 'birthday' => '1990-01-01', 'additional_attributes' => '' ) );
		$b = BizCity_CRM_Contact_Enrichment::render_context_block( array( 'name' => 'B', 'additional_attributes' => '' ) );
		$this->assertStringNotContainsString( '01/01/1990', $b );
		$this->assertStringContainsString( '01/01/1990', $a );
	}

	public function test_next_occurrence_handles_past_dates_and_leap_day(): void {
		$now = gmmktime( 3, 0, 0, 9, 23, 2026 ); // 2026-09-23 10:00 in Asia/Ho_Chi_Minh
		$this->assertSame( '2026-12-03', BizCity_CRM_Contact_Enrichment::next_occurrence( '12-03', $now, 'Asia/Ho_Chi_Minh' ) );
		$this->assertSame( '2027-03-12', BizCity_CRM_Contact_Enrichment::next_occurrence( '03-12', $now, 'Asia/Ho_Chi_Minh' ), 'already passed this year → next year' );
		$this->assertSame( '2026-09-23', BizCity_CRM_Contact_Enrichment::next_occurrence( '09-23', $now, 'Asia/Ho_Chi_Minh' ), 'today counts as today' );
		$this->assertSame( '2027-02-28', BizCity_CRM_Contact_Enrichment::next_occurrence( '02-29', $now, 'Asia/Ho_Chi_Minh' ), '29/02 → 28/02 on a non-leap year' );
		$leap_now = gmmktime( 3, 0, 0, 1, 10, 2028 );
		$this->assertSame( '2028-02-29', BizCity_CRM_Contact_Enrichment::next_occurrence( '02-29', $leap_now, 'Asia/Ho_Chi_Minh' ) );
		$this->assertSame( '', BizCity_CRM_Contact_Enrichment::next_occurrence( 'bad', $now ) );
	}

	public function test_timezone_decides_today_not_utc(): void {
		// 2026-09-23 23:30 UTC is already 2026-09-24 06:30 in Asia/Ho_Chi_Minh → a 09-23 birthday rolls to next year.
		$now = gmmktime( 23, 30, 0, 9, 23, 2026 );
		$this->assertSame( '2027-09-23', BizCity_CRM_Contact_Enrichment::next_occurrence( '09-23', $now, 'Asia/Ho_Chi_Minh' ) );
		$this->assertSame( '2026-09-23', BizCity_CRM_Contact_Enrichment::next_occurrence( '09-23', $now, 'UTC' ) );
	}
}
