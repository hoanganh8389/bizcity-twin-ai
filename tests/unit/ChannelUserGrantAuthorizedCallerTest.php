<?php
/**
 * PHASE-0.53 N2 (E4-03/G3, G4) — the two `_for_owner`-style grant methods added for the CRM staff
 * channel rail must refuse before touching WordPress/usermeta when the caller has not set
 * `$authorized_by_caller`. This is the one branch of `bind_primary_for_owner()` / `reassign_owner()`
 * that needs no WP state at all (`sanitize_key()` is the only WP call on this path, already stubbed
 * suite-wide in tests/bootstrap.php); the rest of both methods (usermeta read/write, quota, scope)
 * is exercised on a real site by the `core.channel.channel_user_grants` DDV probe.
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/class-channel-user-grant.php';

final class ChannelUserGrantAuthorizedCallerTest extends TestCase {

    public function test_bind_primary_for_owner_refuses_without_authorization(): void {
        $result = BizCity_Channel_User_Grant::bind_primary_for_owner( 'zalo_personal', 'acc1', 42, 10, false, array() );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'bind_not_authorized', $result['reason'] );
    }

    public function test_bind_primary_for_owner_authorization_defaults_to_false(): void {
        // A future call site that forgets the flag must fail closed, not silently bind.
        $result = BizCity_Channel_User_Grant::bind_primary_for_owner( 'zalo_personal', 'acc1', 42, 10 );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'bind_not_authorized', $result['reason'] );
    }

    public function test_reassign_owner_refuses_without_authorization(): void {
        $result = BizCity_Channel_User_Grant::reassign_owner( 'zalo_personal', 'acc1', 42, 10, false, array() );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'reassign_not_authorized', $result['reason'] );
    }

    public function test_reassign_owner_authorization_defaults_to_false(): void {
        $result = BizCity_Channel_User_Grant::reassign_owner( 'zalo_personal', 'acc1', 42 );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'reassign_not_authorized', $result['reason'] );
    }
}
