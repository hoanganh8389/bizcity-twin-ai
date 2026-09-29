<?php
/** Remote Zalo Hub WP-CLI commands — B7. */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_CLI', false ) ) { return; }

// [2026-09-29 12:45 PM GitHub Copilot] PHASE-0.82-B7 — expose system-cron tick/status without a public REST tick route.
final class BizCity_Remote_Zalo_CLI {
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) { WP_CLI::add_command( 'bizcity zalo-remote', __CLASS__ ); }
	}
	/** @param array $args @param array $assoc_args */
	public function tick( array $args, array $assoc_args ): void {
		$summary = class_exists( 'BizCity_Remote_Zalo_Poller' ) ? BizCity_Remote_Zalo_Poller::tick( array( 'max_pages' => (int) ( $assoc_args['max-pages'] ?? 5 ) ) ) : array( 'state' => 'unavailable' );
		WP_CLI::line( (string) wp_json_encode( $summary ) );
	}
	/** @param array $args @param array $assoc_args */
	public function status( array $args, array $assoc_args ): void {
		$state = class_exists( 'BizCity_Remote_Zalo_Cursor_Store' ) ? BizCity_Remote_Zalo_Cursor_Store::get() : array( 'state' => 'unavailable' );
		unset( $state['base_url'], $state['key'], $state['key_enc'] );
		WP_CLI::line( (string) wp_json_encode( $state ) );
	}
}

BizCity_Remote_Zalo_CLI::register();
