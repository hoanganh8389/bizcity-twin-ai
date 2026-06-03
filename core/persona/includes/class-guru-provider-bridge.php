<?php
/**
 * BizCity Twin AI — Guru ↔ Provider bridge (Phase B / F7.B3).
 *
 * Counterpart to BizCity_Guru_Skill_Bridge — binds whole Persona Tool
 * Provider classes (and optional scope JSON) to a Guru. Useful when an
 * admin wants to attach an entire plugin's tool surface in one call.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Persona
 * @since      1.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Guru_Provider_Bridge', false ) ) {
	return;
}

class BizCity_Guru_Provider_Bridge {

	/** @return string[] */
	public static function providers_for_guru( int $guru_id ): array {
		if ( $guru_id <= 0 ) { return array(); }
		global $wpdb;
		$tbl = BizCity_Guru_Bridge_Installer::table_providers();
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT provider_class FROM {$tbl} WHERE guru_id = %d AND enabled = 1 ORDER BY id ASC",
				$guru_id
			)
		);
		return is_array( $rows ) ? array_values( array_unique( array_map( 'strval', $rows ) ) ) : array();
	}

	/** @return array<int,array<string,mixed>> */
	public static function list_for_guru( int $guru_id ): array {
		global $wpdb;
		$tbl = BizCity_Guru_Bridge_Installer::table_providers();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$tbl} WHERE guru_id = %d ORDER BY id ASC", $guru_id ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array $scope Optional scope hints (e.g. allowed source kinds).
	 * @return int|false
	 */
	public static function attach( int $guru_id, string $provider_class, array $scope = array() ) {
		$provider_class = trim( $provider_class );
		if ( $guru_id <= 0 || $provider_class === '' ) { return false; }
		global $wpdb;
		$tbl = BizCity_Guru_Bridge_Installer::table_providers();

		$scope_json = empty( $scope ) ? null : wp_json_encode( $scope );

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$tbl} WHERE guru_id = %d AND provider_class = %s",
				$guru_id, $provider_class
			)
		);
		if ( $existing ) {
			$wpdb->update(
				$tbl,
				array( 'scope_json' => $scope_json, 'enabled' => 1 ),
				array( 'id' => (int) $existing ),
				array( '%s', '%d' ),
				array( '%d' )
			);
			return (int) $existing;
		}
		$ok = $wpdb->insert(
			$tbl,
			array(
				'guru_id'        => $guru_id,
				'provider_class' => $provider_class,
				'scope_json'     => $scope_json,
				'enabled'        => 1,
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : false;
	}

	public static function detach( int $guru_id, string $provider_class ): bool {
		if ( $guru_id <= 0 || $provider_class === '' ) { return false; }
		global $wpdb;
		$tbl = BizCity_Guru_Bridge_Installer::table_providers();
		$n   = $wpdb->delete( $tbl, array( 'guru_id' => $guru_id, 'provider_class' => $provider_class ), array( '%d', '%s' ) );
		return $n !== false && $n > 0;
	}

	public static function set_enabled( int $guru_id, string $provider_class, bool $enabled ): bool {
		if ( $guru_id <= 0 || $provider_class === '' ) { return false; }
		global $wpdb;
		$tbl = BizCity_Guru_Bridge_Installer::table_providers();
		$n   = $wpdb->update(
			$tbl,
			array( 'enabled' => $enabled ? 1 : 0 ),
			array( 'guru_id' => $guru_id, 'provider_class' => $provider_class ),
			array( '%d' ),
			array( '%d', '%s' )
		);
		return $n !== false;
	}

	/**
	 * @return array<int,array{class:string,id:string,kinds:array}>
	 */
	public static function all_known_providers(): array {
		$out = array();
		$providers = apply_filters( 'bizcity_persona_tool_providers', array() );
		if ( ! is_array( $providers ) ) { return $out; }
		foreach ( $providers as $p ) {
			if ( ! is_object( $p ) ) { continue; }
			$cls   = get_class( $p );
			$id    = method_exists( $p, 'id' ) ? (string) $p->id() : $cls;
			$kinds = method_exists( $p, 'source_kinds' ) ? (array) $p->source_kinds() : array();
			$out[] = array( 'class' => $cls, 'id' => $id, 'kinds' => $kinds );
		}
		return $out;
	}

	public static function list_unbound_for_guru( int $guru_id ): array {
		$bound = array_flip( self::providers_for_guru( $guru_id ) );
		$out   = array();
		foreach ( self::all_known_providers() as $p ) {
			if ( ! isset( $bound[ $p['class'] ] ) ) { $out[] = $p; }
		}
		return $out;
	}

	public static function count_total(): int {
		global $wpdb;
		$tbl = BizCity_Guru_Bridge_Installer::table_providers();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
	}

	public static function count_for_guru( int $guru_id ): int {
		global $wpdb;
		$tbl = BizCity_Guru_Bridge_Installer::table_providers();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE guru_id = %d", $guru_id ) );
	}
}
