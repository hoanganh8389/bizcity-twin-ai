<?php
/**
 * BizCity Twin AI — Guru ↔ Skill (Tool) bridge (Phase B / F7.B2).
 *
 * Static PHP API. Used by `BizCity_CRM_Admin_Chat_Policy` to gate which
 * tools a given character/guru is allowed to call (R-MPRT-5 anti-jailbreak).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Persona
 * @since      1.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Guru_Skill_Bridge', false ) ) {
	return;
}

class BizCity_Guru_Skill_Bridge {

	const VALID_CLASSES = array( 'producer', 'distributor', 'retriever' );

	/**
	 * Return tool ids enabled for a given guru/character.
	 *
	 * Empty array = no binding row exists → caller (policy) treats as
	 * "no restriction" or "deny all" depending on its own contract.
	 * `BizCity_CRM_Admin_Chat_Policy` treats empty as "no restriction".
	 *
	 * @return string[]
	 */
	public static function tools_for_guru( int $guru_id ): array {
		if ( $guru_id <= 0 ) { return array(); }
		global $wpdb;
		$tbl = BizCity_Guru_Bridge_Installer::table_skills();
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT tool_id FROM {$tbl} WHERE guru_id = %d AND enabled = 1 ORDER BY priority ASC, id ASC",
				$guru_id
			)
		);
		return is_array( $rows ) ? array_values( array_unique( array_map( 'strval', $rows ) ) ) : array();
	}

	/** @return array<int,array<string,mixed>> */
	public static function list_for_guru( int $guru_id ): array {
		global $wpdb;
		$tbl = BizCity_Guru_Bridge_Installer::table_skills();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tbl} WHERE guru_id = %d ORDER BY priority ASC, id ASC",
				$guru_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Attach a tool to a guru (idempotent — UPSERT on uniq_guru_tool).
	 *
	 * @param int    $guru_id
	 * @param string $tool_id     Tool name as registered by provider.
	 * @param string $tool_class  producer|distributor|retriever (default producer).
	 * @param int    $priority    Lower = higher priority. Default 100.
	 * @return int|false Inserted/updated row id, or false on validation fail.
	 */
	public static function attach( int $guru_id, string $tool_id, string $tool_class = 'producer', int $priority = 100 ) {
		$tool_id = trim( $tool_id );
		if ( $guru_id <= 0 || $tool_id === '' ) { return false; }
		if ( ! in_array( $tool_class, self::VALID_CLASSES, true ) ) {
			$tool_class = 'producer';
		}
		global $wpdb;
		$tbl = BizCity_Guru_Bridge_Installer::table_skills();

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$tbl} WHERE guru_id = %d AND tool_id = %s",
				$guru_id, $tool_id
			)
		);
		if ( $existing ) {
			$wpdb->update(
				$tbl,
				array( 'tool_class' => $tool_class, 'priority' => $priority, 'enabled' => 1 ),
				array( 'id' => (int) $existing ),
				array( '%s', '%d', '%d' ),
				array( '%d' )
			);
			return (int) $existing;
		}
		$ok = $wpdb->insert(
			$tbl,
			array(
				'guru_id'    => $guru_id,
				'tool_id'    => $tool_id,
				'tool_class' => $tool_class,
				'priority'   => $priority,
				'enabled'    => 1,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : false;
	}

	public static function detach( int $guru_id, string $tool_id ): bool {
		if ( $guru_id <= 0 || $tool_id === '' ) { return false; }
		global $wpdb;
		$tbl = BizCity_Guru_Bridge_Installer::table_skills();
		$n   = $wpdb->delete( $tbl, array( 'guru_id' => $guru_id, 'tool_id' => $tool_id ), array( '%d', '%s' ) );
		return $n !== false && $n > 0;
	}

	public static function set_enabled( int $guru_id, string $tool_id, bool $enabled ): bool {
		if ( $guru_id <= 0 || $tool_id === '' ) { return false; }
		global $wpdb;
		$tbl = BizCity_Guru_Bridge_Installer::table_skills();
		$n   = $wpdb->update(
			$tbl,
			array( 'enabled' => $enabled ? 1 : 0 ),
			array( 'guru_id' => $guru_id, 'tool_id' => $tool_id ),
			array( '%d' ),
			array( '%d', '%s' )
		);
		return $n !== false;
	}

	/**
	 * Tools known to the runtime (Layer 2 + Layer 3 walk) but not yet bound to this guru.
	 *
	 * @return array<int,array{tool_id:string,tool_class:string,source:string}>
	 */
	public static function list_unbound_for_guru( int $guru_id ): array {
		$bound = array_flip( self::tools_for_guru( $guru_id ) );
		$known = self::all_known_tools();
		$out   = array();
		foreach ( $known as $t ) {
			if ( ! isset( $bound[ $t['tool_id'] ] ) ) { $out[] = $t; }
		}
		return $out;
	}

	/**
	 * Walk Layer 2 + Layer 3 to enumerate every tool currently registered.
	 *
	 * @return array<int,array{tool_id:string,tool_class:string,source:string}>
	 */
	public static function all_known_tools(): array {
		$out = array();
		$seen = array();

		// Layer 2 — agent tools.
		$agents = apply_filters( 'bizcity_register_agent', array() );
		if ( is_array( $agents ) ) {
			foreach ( $agents as $agent ) {
				$tools = is_array( $agent ) ? ( $agent['tools'] ?? null ) : ( is_object( $agent ) ? ( $agent->tools ?? null ) : null );
				if ( ! is_array( $tools ) ) { continue; }
				foreach ( $tools as $tool ) {
					$tid = self::probe_field( $tool, array( 'name', 'id' ) );
					$tc  = self::probe_field( $tool, array( 'tool_class' ) );
					if ( $tid === '' || isset( $seen[ $tid ] ) ) { continue; }
					$seen[ $tid ] = 1;
					$out[] = array(
						'tool_id'    => $tid,
						'tool_class' => in_array( $tc, self::VALID_CLASSES, true ) ? $tc : 'producer',
						'source'     => 'agent',
					);
				}
			}
		}

		// Layer 3 — persona providers.
		$providers = apply_filters( 'bizcity_persona_tool_providers', array() );
		if ( is_array( $providers ) ) {
			foreach ( $providers as $p ) {
				if ( ! is_object( $p ) || ! method_exists( $p, 'get_tool_definitions' ) ) { continue; }
				$defs = $p->get_tool_definitions();
				if ( ! is_array( $defs ) ) { continue; }
				foreach ( $defs as $def ) {
					$tid = self::probe_field( $def, array( 'name', 'id' ) );
					$tc  = self::probe_field( $def, array( 'tool_class' ) );
					if ( $tid === '' || isset( $seen[ $tid ] ) ) { continue; }
					$seen[ $tid ] = 1;
					$out[] = array(
						'tool_id'    => $tid,
						'tool_class' => in_array( $tc, self::VALID_CLASSES, true ) ? $tc : 'producer',
						'source'     => 'provider:' . get_class( $p ),
					);
				}
			}
		}

		return $out;
	}

	public static function count_total(): int {
		global $wpdb;
		$tbl = BizCity_Guru_Bridge_Installer::table_skills();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
	}

	public static function count_for_guru( int $guru_id ): int {
		global $wpdb;
		$tbl = BizCity_Guru_Bridge_Installer::table_skills();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE guru_id = %d", $guru_id ) );
	}

	/** @param mixed $thing */
	private static function probe_field( $thing, array $keys ): string {
		foreach ( $keys as $k ) {
			if ( is_array( $thing ) && array_key_exists( $k, $thing ) ) { return (string) $thing[ $k ]; }
			if ( is_object( $thing ) ) {
				if ( isset( $thing->{$k} ) ) { return (string) $thing->{$k}; }
				$g = 'get_' . $k;
				if ( method_exists( $thing, $g ) ) { return (string) $thing->{$g}(); }
			}
		}
		return '';
	}
}
