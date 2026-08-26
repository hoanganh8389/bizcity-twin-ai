<?php
/**
 * Diagnostics Installer Resolver — maps a registered table row to the
 * `BizCity_Site_Provisioner` installer id responsible for creating/upgrading it.
 *
 * The mapping is best-effort and ALWAYS prefers explicit declarations:
 *   1. The table registry row may carry `'installer' => 'research'` (explicit).
 *   2. Else: match registry row `class` to the static class of an installer
 *      callback (e.g. `BizCity_KG_Database` → installer id `kg_hub`).
 *   3. Else: match registry row `owner` (e.g. `core/research`) to a manual
 *      heuristic table of `owner-prefix → installer id`.
 *   4. Else: return null (no Fix/Repair button rendered).
 *
 * The resolver is read-only and stateless; it does NOT execute installers.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      PHASE-0.41 L8 (2026-05-21)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Diagnostics_Installer_Resolver {

	/** @var array<string,string>|null `class => id` */
	private static $class_to_id = null;

	/** Manual owner → installer-id heuristics (substring match). */
	private static $owner_map = [
		'core/knowledge/kg-hub'      => 'kg_hub',
		'core/knowledge'             => 'knowledge',
		'core/intent'                => 'intent',
		'core/twin-core'             => 'twin_state',
		'core/memory'                => 'memory',
		'core/skills'                => 'skills',
		'core/runtime'               => 'runtime',
		'core/research'              => 'research',
		'core/scheduler'             => 'scheduler',
		'core/channel-gateway'       => 'channel_messages',
		'core/bizcity-llm'           => 'llm_usage_clients',
		// [2026-06-10 Johnny Chu] HOTFIX — core/bizcity-market disabled: removed from installer resolver.
		// 'core/bizcity-market'     => 'market',
		'modules/twinchat/learning'  => 'kg_hub',
		'modules/twinchat/studio'    => 'studio_job',
		'modules/webchat'            => 'webchat',
		'plugins/bizcity-twin-crm'   => 'crm',
		'plugins/bizgpt-tool-google' => 'tool_google',
	];

	/**
	 * Resolve installer id for one registry row.
	 *
	 * @param array $row Registry row (with 'class','owner', optional 'installer').
	 * @return string|null Installer id, or null when no match.
	 */
	public static function for_row( array $row ): ?string {
		// 1. Explicit declaration on the registry row wins.
		if ( ! empty( $row['installer'] ) ) {
			$id = (string) $row['installer'];
			if ( self::id_exists( $id ) ) {
				return $id;
			}
		}

		// 2. Class match.
		$class = (string) ( $row['class'] ?? '' );
		if ( $class !== '' ) {
			$map = self::load_class_map();
			$key = strtolower( $class );
			if ( isset( $map[ $key ] ) ) {
				return $map[ $key ];
			}
		}

		// 3. Owner heuristic (longest prefix match).
		$owner = (string) ( $row['owner'] ?? '' );
		if ( $owner !== '' ) {
			$best_key = '';
			foreach ( self::$owner_map as $needle => $id ) {
				if ( stripos( $owner, $needle ) === 0 && strlen( $needle ) > strlen( $best_key ) ) {
					$best_key = $needle;
				}
			}
			if ( $best_key !== '' ) {
				$id = self::$owner_map[ $best_key ];
				if ( self::id_exists( $id ) ) {
					return $id;
				}
			}
		}

		return null;
	}

	/** Reset memo. */
	public static function flush(): void {
		self::$class_to_id = null;
	}

	/* ────────────────────────────────────────────────────────────── */

	private static function load_class_map(): array {
		if ( null !== self::$class_to_id ) {
			return self::$class_to_id;
		}
		$map = [];
		if ( class_exists( 'BizCity_Site_Provisioner' ) ) {
			foreach ( BizCity_Site_Provisioner::get_installers() as $i ) {
				$cb = $i['callback'] ?? null;
				if ( is_array( $cb ) && isset( $cb[0] ) && is_string( $cb[0] ) ) {
					$map[ strtolower( $cb[0] ) ] = (string) $i['id'];
				}
			}
		}
		return self::$class_to_id = $map;
	}

	private static function id_exists( string $id ): bool {
		if ( ! class_exists( 'BizCity_Site_Provisioner' ) ) {
			return false;
		}
		foreach ( BizCity_Site_Provisioner::get_installers() as $i ) {
			if ( ( $i['id'] ?? '' ) === $id ) {
				return true;
			}
		}
		return false;
	}
}
