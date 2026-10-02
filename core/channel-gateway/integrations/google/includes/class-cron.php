<?php
/**
 * Cron handler — refresh expiring Google tokens.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BZGoogle_Cron {

    const HOOK = 'bzgoogle_refresh_tokens';

    /**
     * Schedule the cron event if not already scheduled.
     */
    public static function schedule() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::HOOK );
        }
    }

    /**
     * Remove cron on deactivation.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( self::HOOK );
    }

    /**
     * Refresh all tokens expiring within the next 10 minutes.
     */
    public static function refresh_expiring_tokens() {
        $accounts = BZGoogle_Token_Store::get_expiring_accounts( 600 );
        if ( empty( $accounts ) ) return;

        foreach ( $accounts as $row ) {
            BZGoogle_Token_Store::refresh( $row );
        }
    }
}
