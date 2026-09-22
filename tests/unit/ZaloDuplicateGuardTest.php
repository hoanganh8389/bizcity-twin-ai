<?php
/**
 * PHASE-0.48F U10 — R-ZP-DUP: one phone / one Zalo login = one Personal account per site.
 *
 * Pure unit test of BizCity_Zalo_Duplicate_Guard (no WordPress, no DB, no bridge).
 * Live incident this pins: "Zalo Cá nhân — 0931576886" existed twice on one site; the newer one
 * connected, the sidecar superseded the older one ("Đã đăng xuất"), and re-login on the older one
 * only said "Chưa tạo được mã QR".
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
}

require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-zalo-personal/includes/shared/class-zalo-duplicate-guard.php';

class ZaloDuplicateGuardTest extends TestCase {

    public function test_phone_key_normalizes_common_vn_formats(): void {
        $this->assertSame( '0931576886', BizCity_Zalo_Duplicate_Guard::phone_key( '0931576886' ) );
        $this->assertSame( '0931576886', BizCity_Zalo_Duplicate_Guard::phone_key( 'Sale — 0931 576 886' ) );
        $this->assertSame( '0931576886', BizCity_Zalo_Duplicate_Guard::phone_key( '+84 931.576.886' ) );
        $this->assertSame( '0931576886', BizCity_Zalo_Duplicate_Guard::phone_key( '84931576886' ) );
    }

    public function test_phone_key_ignores_labels_without_a_phone(): void {
        $this->assertSame( '', BizCity_Zalo_Duplicate_Guard::phone_key( 'Zalo Cá nhân' ) );
        $this->assertSame( '', BizCity_Zalo_Duplicate_Guard::phone_key( 'Kho 2' ) );
        $this->assertSame( '', BizCity_Zalo_Duplicate_Guard::phone_key( '' ) );
    }

    public function test_create_is_blocked_when_the_phone_already_has_a_live_account(): void {
        $rows = array(
            array( 'bridge_account_id' => '7', 'label' => 'Kho', 'status' => 'connected' ),
            array( 'bridge_account_id' => '9', 'label' => '0931 576 886', 'status' => 'logged_out', 'crm_inbox_id' => 13 ),
        );
        $dup = BizCity_Zalo_Duplicate_Guard::find_phone_duplicate( '+84931576886', $rows );
        $this->assertNotNull( $dup );
        $this->assertSame( '9', $dup['bridge_account_id'] );

        $payload = BizCity_Zalo_Duplicate_Guard::create_blocked_payload( $dup );
        $this->assertFalse( $payload['ok'] );
        $this->assertSame( 'duplicate_phone', $payload['code'] );
        $this->assertStringContainsString( '#9', $payload['message'] );
        $this->assertStringContainsString( 'Đăng nhập lại', $payload['hint'], 'A logged-out twin must point to re-login, not to creating another account.' );
        $this->assertSame( 13, $payload['duplicate_of']['crm_inbox_id'] );
    }

    public function test_dead_rows_and_other_phones_do_not_block_create(): void {
        $rows = array(
            array( 'bridge_account_id' => '9', 'label' => '0931576886', 'status' => 'orphaned' ),
            array( 'bridge_account_id' => '10', 'label' => '0987654321', 'status' => 'connected' ),
        );
        $this->assertNull( BizCity_Zalo_Duplicate_Guard::find_phone_duplicate( '0931576886', $rows ) );
        $this->assertNull( BizCity_Zalo_Duplicate_Guard::find_phone_duplicate( 'Zalo Cá nhân', $rows ), 'No phone in label — nothing to compare, never block.' );
    }

    public function test_qr_is_blocked_when_same_zalo_login_is_connected_on_a_sibling(): void {
        $bridge = array(
            array( 'id' => 9, 'label' => '0931576886', 'status' => 'logged_out', 'zaloUid' => 'uid-1' ),
            array( 'id' => 12, 'label' => '0931576886 (mới)', 'status' => 'connected', 'zaloUid' => 'uid-1' ),
            array( 'id' => 15, 'label' => 'Kho', 'status' => 'connected', 'zaloUid' => 'uid-2' ),
        );
        $sibling = BizCity_Zalo_Duplicate_Guard::find_connected_sibling( '9', $bridge );
        $this->assertNotNull( $sibling );
        $this->assertSame( 12, $sibling['id'] );

        $payload = BizCity_Zalo_Duplicate_Guard::qr_blocked_payload( $sibling, 'op1', 'rq1' );
        $this->assertSame( 'blocked', $payload['operation_status'] );
        $this->assertSame( 'duplicate_zalo_login', $payload['reason_bucket'] );
        $this->assertStringContainsString( '#12', $payload['message'] );
        $this->assertSame( 'op1', $payload['operation_id'] );
    }

    public function test_find_twins_marks_logged_out_row_whose_login_is_live_elsewhere(): void {
        $rows = array(
            // Live incident shape: uid not yet synced locally → matched by phone in label.
            array( 'bridge_account_id' => '9', 'label' => '0931576886', 'status' => 'logged_out', 'zalo_uid' => '', 'crm_inbox_id' => 13 ),
            array( 'bridge_account_id' => '12', 'label' => '0931576886', 'status' => 'connected', 'zalo_uid' => 'uid-1', 'crm_inbox_id' => 21 ),
            // Synced uids: match by uid even though labels differ.
            array( 'bridge_account_id' => '30', 'label' => 'Kho cũ', 'status' => 'expired', 'zalo_uid' => 'uid-7', 'crm_inbox_id' => 40 ),
            array( 'bridge_account_id' => '31', 'label' => 'Kho', 'status' => 'connected', 'zalo_uid' => 'uid-7', 'crm_inbox_id' => 41 ),
            // Plain logged-out phone with no live twin stays a normal re-login case.
            array( 'bridge_account_id' => '50', 'label' => '0987654321', 'status' => 'logged_out', 'zalo_uid' => 'uid-9', 'crm_inbox_id' => 60 ),
        );
        $twins = BizCity_Zalo_Duplicate_Guard::find_twins( $rows );
        $this->assertSame( array( 13, 40 ), array_keys( $twins ) );
        $this->assertSame( 21, $twins[13]['crm_inbox_id'] );
        $this->assertSame( 'phone', $twins[13]['match'] );
        $this->assertSame( 41, $twins[40]['crm_inbox_id'] );
        $this->assertSame( 'zalo_uid', $twins[40]['match'] );
    }

    public function test_find_twins_trusts_known_uids_over_labels(): void {
        // Same phone typed in both labels, but the bridge says they are different Zalo logins → not twins.
        $rows = array(
            array( 'bridge_account_id' => '1', 'label' => '0931576886', 'status' => 'logged_out', 'zalo_uid' => 'uid-a', 'crm_inbox_id' => 5 ),
            array( 'bridge_account_id' => '2', 'label' => '0931576886', 'status' => 'connected', 'zalo_uid' => 'uid-b', 'crm_inbox_id' => 6 ),
        );
        $this->assertSame( array(), BizCity_Zalo_Duplicate_Guard::find_twins( $rows ) );
    }

    public function test_qr_is_not_blocked_without_uid_or_connected_sibling(): void {
        $never_logged_in = array(
            array( 'id' => 20, 'label' => 'Mới', 'status' => 'pending_qr', 'zaloUid' => '' ),
            array( 'id' => 12, 'label' => 'Khác', 'status' => 'connected', 'zaloUid' => 'uid-1' ),
        );
        $this->assertNull( BizCity_Zalo_Duplicate_Guard::find_connected_sibling( '20', $never_logged_in ) );

        $sibling_logged_out = array(
            array( 'id' => 9, 'status' => 'logged_out', 'zaloUid' => 'uid-1' ),
            array( 'id' => 12, 'status' => 'logged_out', 'zaloUid' => 'uid-1' ),
        );
        $this->assertNull( BizCity_Zalo_Duplicate_Guard::find_connected_sibling( '9', $sibling_logged_out ) );
        $this->assertNull( BizCity_Zalo_Duplicate_Guard::find_connected_sibling( '404', $sibling_logged_out ) );
    }
}
