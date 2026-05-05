<?php
/**
 * Twin Shell — Learning Hub WP-CLI commands (Wave D).
 *
 * Usage:
 *   wp twin-shell learning list-cortex
 *   wp twin-shell learning summary [--user=<id>] [--scope=user|site] [--cortex=<id>]
 *   wp twin-shell learning analytics [--user=<id>] [--range=24h|7d|30d] [--scope=…] [--cortex=…]
 *   wp twin-shell learning sweep
 *   wp twin-shell learning cleanup-run
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinShell\Learning
 * @since 0.13.38
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class BizCity_Twin_Shell_Learning_CLI {

	public static function register() {
		WP_CLI::add_command( 'twin-shell learning', __CLASS__ );
	}

	/**
	 * List registered cortex contributors.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<id>]
	 * : User ID for capability resolution. Default: current admin (1).
	 *
	 * @when after_wp_load
	 */
	public function list_cortex( $args, $assoc_args ) {
		unset( $args );
		$uid = (int) ( $assoc_args['user'] ?? 1 );
		$cortexes = BizCity_Twin_Shell_Learning_SDK::instance()->cortexes( $uid );
		if ( empty( $cortexes ) ) {
			WP_CLI::warning( 'No cortex registered (or none visible to user ' . $uid . ').' );
			return;
		}
		$rows = [];
		foreach ( $cortexes as $id => $c ) {
			$rows[] = [
				'id'             => $id,
				'label'          => $c['label'],
				'capability'     => $c['capability'],
				'has_analytics'  => $c['analytics'] ? 'yes' : 'no',
			];
		}
		WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'label', 'capability', 'has_analytics' ] );
	}

	/**
	 * Print aggregator summary for a user.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<id>]
	 * [--scope=<user|site>]
	 * [--cortex=<id>]
	 *
	 * @when after_wp_load
	 */
	public function summary( $args, $assoc_args ) {
		unset( $args );
		$uid    = (int) ( $assoc_args['user']   ?? 1 );
		$scope  = ( ( $assoc_args['scope'] ?? 'user' ) === 'site' );
		$cortex = isset( $assoc_args['cortex'] ) ? sanitize_key( (string) $assoc_args['cortex'] ) : '';
		$sdk    = BizCity_Twin_Shell_Learning_SDK::instance();
		$list   = $cortex ? array_filter( $sdk->cortexes( $uid ), static fn ( $id ) => $id === $cortex, ARRAY_FILTER_USE_KEY ) : $sdk->cortexes( $uid );
		if ( empty( $list ) ) {
			WP_CLI::error( 'No cortex matched.' );
			return;
		}
		foreach ( $list as $id => $c ) {
			WP_CLI::log( '── ' . $id . ' ──' );
			WP_CLI::log( wp_json_encode( call_user_func( $c['aggregator'], $uid, $scope ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		}
	}

	/**
	 * Print analytics for a user.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<id>]
	 * [--range=<24h|7d|30d>]
	 * [--scope=<user|site>]
	 * [--cortex=<id>]
	 *
	 * @when after_wp_load
	 */
	public function analytics( $args, $assoc_args ) {
		unset( $args );
		$uid    = (int) ( $assoc_args['user']  ?? 1 );
		$range  = (string) ( $assoc_args['range'] ?? '24h' );
		$scope  = ( ( $assoc_args['scope'] ?? 'user' ) === 'site' );
		$cortex = isset( $assoc_args['cortex'] ) ? sanitize_key( (string) $assoc_args['cortex'] ) : '';
		$sdk    = BizCity_Twin_Shell_Learning_SDK::instance();
		$list   = $sdk->cortexes( $uid );
		if ( $cortex ) {
			$list = isset( $list[ $cortex ] ) ? [ $cortex => $list[ $cortex ] ] : [];
		}
		if ( empty( $list ) ) {
			WP_CLI::error( 'No cortex matched.' );
			return;
		}
		foreach ( $list as $id => $c ) {
			if ( empty( $c['analytics'] ) ) {
				WP_CLI::warning( $id . ' has no analytics callable; skipping.' );
				continue;
			}
			WP_CLI::log( '── ' . $id . ' (' . $range . ') ──' );
			WP_CLI::log( wp_json_encode( call_user_func( $c['analytics'], $uid, $range, $scope ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		}
	}

	/**
	 * Trigger the per-blog learning sweep cron synchronously.
	 *
	 * @when after_wp_load
	 */
	public function sweep( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		do_action( 'bizcity_kg_learning_sweep' );
		$count = (int) get_option( 'bizcity_twinchat_learning_last_sweep_count', 0 );
		WP_CLI::success( sprintf( 'Sweep done. Last enqueue count: %d', $count ) );
	}

	/**
	 * Trigger the cleanup engine (detect + reap) synchronously.
	 *
	 * @when after_wp_load
	 */
	public function cleanup_run( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		if ( ! class_exists( 'BizCity_KG_Cleanup_Service' ) ) {
			WP_CLI::error( 'BizCity_KG_Cleanup_Service not loaded.' );
			return;
		}
		$res = BizCity_KG_Cleanup_Service::instance()->run( [ 'trigger_kind' => 'cli' ] );
		WP_CLI::log( wp_json_encode( $res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		WP_CLI::success( 'Cleanup run finished.' );
	}
}

BizCity_Twin_Shell_Learning_CLI::register();
