<?php
/**
 * TwinSearch — Input Gate (Wave 0.18.1.6)
 *
 * Reads / writes the `input_gate` sub-object inside `wp_bizcity_characters.settings`
 * (existing JSON column — no schema migration needed).
 *
 * Settings shape:
 *   {
 *     "input_gate": {
 *       "required":  true|false,
 *       "providers": ["twinsearch","bizcoach-map", ...],
 *       "min_sources": 1,
 *       "block_message": "..."
 *     }
 *   }
 *
 * Public API:
 *   BizCity_Input_Gate::get_config( int $character_id ): array
 *   BizCity_Input_Gate::set_config( int $character_id, array $cfg ): bool
 *   BizCity_Input_Gate::is_required( int $character_id ): bool
 *   BizCity_Input_Gate::count_scope_sources( string $scope_type, int $scope_id ): int
 *   BizCity_Input_Gate::should_block( int $character_id, string $scope_type, int $scope_id ): array  // {blocked, reason, message}
 *
 * Filters exposed (opt-in wiring — DOES NOT auto-modify chat path):
 *   apply_filters( 'bizcity_chat_should_block_response', $result, $args )
 *   do_action(  'bizcity_input_gate_updated', $character_id, $cfg )
 *   do_action(  'bizcity_input_gate_blocked', $character_id, $scope_type, $scope_id )
 *
 * To enforce in chat completion, host code can:
 *   add_filter( 'bizcity_chat_should_block_response', function ( $r, $args ) {
 *       return BizCity_Input_Gate::should_block( $args['character_id'], $args['scope_type'], $args['scope_id'] );
 *   }, 10, 2 );
 *
 * @package Bizcity_Twin_AI\Modules\TwinSearch
 * @since 0.18.1.6
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Input_Gate' ) ) {
	return;
}

class BizCity_Input_Gate {

	const META_KEY = 'input_gate';
	const DEFAULTS = [
		'required'      => false,
		'providers'     => [ 'twinsearch' ],
		'min_sources'   => 1,
		'block_message' => '',
	];

	/* ─────────────── Read ─────────────── */

	public static function get_config( int $character_id ): array {
		if ( $character_id <= 0 ) {
			return self::DEFAULTS;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_characters';
		$json  = $wpdb->get_var( $wpdb->prepare( "SELECT settings FROM {$table} WHERE id=%d", $character_id ) );
		if ( ! $json ) {
			return self::DEFAULTS;
		}
		$arr = json_decode( (string) $json, true );
		if ( ! is_array( $arr ) || empty( $arr[ self::META_KEY ] ) || ! is_array( $arr[ self::META_KEY ] ) ) {
			return self::DEFAULTS;
		}
		return self::sanitize( array_merge( self::DEFAULTS, $arr[ self::META_KEY ] ) );
	}

	public static function is_required( int $character_id ): bool {
		$cfg = self::get_config( $character_id );
		return ! empty( $cfg['required'] );
	}

	/* ─────────────── Write ─────────────── */

	public static function set_config( int $character_id, array $cfg ): bool {
		if ( $character_id <= 0 ) {
			return false;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_characters';

		$json     = $wpdb->get_var( $wpdb->prepare( "SELECT settings FROM {$table} WHERE id=%d", $character_id ) );
		$settings = is_string( $json ) && $json !== '' ? json_decode( $json, true ) : [];
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}
		$settings[ self::META_KEY ] = self::sanitize( array_merge( self::DEFAULTS, $cfg ) );

		$ok = $wpdb->update(
			$table,
			[ 'settings' => wp_json_encode( $settings ) ],
			[ 'id' => $character_id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( false !== $ok ) {
			do_action( 'bizcity_input_gate_updated', $character_id, $settings[ self::META_KEY ] );
			wp_cache_delete( $character_id, 'bizcity_characters' );
			return true;
		}
		return false;
	}

	/* ─────────────── Sanitize ─────────────── */

	public static function sanitize( array $cfg ): array {
		$allowed_providers = [ 'twinsearch', 'bizcoach-map', 'twinsource' ];
		$providers = array_values( array_intersect(
			$allowed_providers,
			array_map( 'strval', (array) ( $cfg['providers'] ?? [] ) )
		) );
		return [
			'required'      => ! empty( $cfg['required'] ),
			'providers'     => empty( $providers ) ? [ 'twinsearch' ] : $providers,
			'min_sources'   => max( 1, min( 100, (int) ( $cfg['min_sources'] ?? 1 ) ) ),
			'block_message' => sanitize_text_field( (string) ( $cfg['block_message'] ?? '' ) ),
		];
	}

	/* ─────────────── Source count (per scope) ─────────────── */

	/**
	 * Count attached sources via Research Ingest table (status != detached).
	 * Falls back to 0 when ingest table missing.
	 */
	public static function count_scope_sources( string $scope_type, int $scope_id ): int {
		if ( ! class_exists( 'BizCity_Research_DB' ) ) {
			return 0;
		}
		global $wpdb;
		$tbl = BizCity_Research_DB::table_ingests();
		$n   = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl} WHERE scope_type=%s AND scope_id=%d AND ingest_status NOT IN ('detached','invalid')",
			$scope_type, $scope_id
		) );
		return $n;
	}

	/* ─────────────── Enforcement ─────────────── */

	/**
	 * @return array { blocked: bool, reason?: string, message?: string, required_min?: int, current?: int }
	 */
	public static function should_block( int $character_id, string $scope_type = 'character', int $scope_id = 0 ): array {
		$cfg = self::get_config( $character_id );
		if ( empty( $cfg['required'] ) ) {
			return [ 'blocked' => false ];
		}
		$scope_id = $scope_id > 0 ? $scope_id : $character_id;
		$n = self::count_scope_sources( $scope_type, $scope_id );
		if ( $n >= (int) $cfg['min_sources'] ) {
			return [ 'blocked' => false, 'current' => $n ];
		}
		do_action( 'bizcity_input_gate_blocked', $character_id, $scope_type, $scope_id );
		$msg = $cfg['block_message'] !== ''
			? $cfg['block_message']
			: sprintf(
				/* translators: 1 = current attached, 2 = required min */
				__( 'Twin Guru chua co du nguon kien thuc (%1$d/%2$d). Vui long bo sung qua nut Nghien cuu sau truoc khi tro chuyen.', 'bizcity-twinsearch' ),
				$n,
				(int) $cfg['min_sources']
			);
		return [
			'blocked'      => true,
			'reason'       => 'input_gate_required',
			'message'      => $msg,
			'required_min' => (int) $cfg['min_sources'],
			'current'      => $n,
		];
	}
}
