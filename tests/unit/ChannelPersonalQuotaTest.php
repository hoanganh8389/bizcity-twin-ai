<?php
/**
 * PHASE-0.50 UID-02 — site-level Zalo Personal quota read by the grant layer.
 *
 * Only the option → quota mapping is unit-tested; binding, the "already owned"
 * bypass and the pre-QR refusal are covered by the DDV probe
 * `core.channel.channel_user_grants` on a real site.
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_option' ) ) {
    // Suite-wide stub: this file loads before ContextBankReconcilerTest, whose update_option() writes to
    // `bizcity_reconciler_test_options`, so read that store too or its checkpoint round-trip breaks.
    function get_option( $key, $default = false ) {
        foreach ( array( 'bizcity_reconciler_test_options', 'bizcity_options_stub' ) as $store ) {
            if ( isset( $GLOBALS[ $store ] ) && is_array( $GLOBALS[ $store ] ) && array_key_exists( $key, $GLOBALS[ $store ] ) ) {
                return $GLOBALS[ $store ][ $key ];
            }
        }
        return $default;
    }
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
    function get_current_blog_id() { return 1258; }
}

require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/class-channel-user-grant.php';

final class ChannelPersonalQuotaTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['bizcity_options_stub'][ BizCity_Channel_User_Grant::OPTION_PERSONAL_QUOTA ] );
        unset( $GLOBALS['bizcity_reconciler_test_options'][ BizCity_Channel_User_Grant::OPTION_PERSONAL_QUOTA ] );
    }

    /** The suite shares one process; seed every option store a get_option() stub may read. */
    private function set_site_quota( $value ): void {
        $GLOBALS['bizcity_options_stub'][ BizCity_Channel_User_Grant::OPTION_PERSONAL_QUOTA ] = $value;
        $GLOBALS['bizcity_reconciler_test_options'][ BizCity_Channel_User_Grant::OPTION_PERSONAL_QUOTA ] = $value;
    }

    /**
     * @return array<string,array{0:mixed,1:int}>
     */
    public function quotas(): array {
        return array(
            'unset option is unlimited'  => array( null, 0 ),
            'zero is unlimited'          => array( 0, 0 ),
            'site cap applies'           => array( 3, 3 ),
            'numeric string is accepted' => array( '2', 2 ),
            'negative clamps to 0'       => array( -5, 0 ),
            'above max clamps to max'    => array( 999, BizCity_Channel_User_Grant::MAX_PERSONAL_QUOTA ),
        );
    }

    /**
     * @dataProvider quotas
     */
    public function test_site_option_maps_to_quota( $option, int $expected ): void {
        if ( null !== $option ) {
            $this->set_site_quota( $option );
        }

        $this->assertSame( $expected, BizCity_Channel_User_Grant::personal_account_quota( 42 ) );
    }

    public function test_option_name_matches_the_crm_setting_route(): void {
        // CRM → Nhân sự writes this exact option through /crm-settings/personal-phone-quota.
        $this->assertSame( 'bizcity_channel_personal_accounts_per_user', BizCity_Channel_User_Grant::OPTION_PERSONAL_QUOTA );
        $this->assertSame( 50, BizCity_Channel_User_Grant::MAX_PERSONAL_QUOTA );
    }
}
