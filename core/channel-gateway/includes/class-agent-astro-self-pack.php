<?php
/**
 * `astro_self` projection pack (PHASE-0.87 CL-2, projection-pack@1.1 §5): the principal's OWN `is_self` chart.
 *
 * The data belongs to the astrology plugin (bizcoach `bccm_*`, R-COACHEE.4); this file only reads it through the filter
 * `bizcity_agent_astro_self_profile( null, $user_id )` ⇒ `{profile_ref, natal_summary, periods:[{from,to,highlights}]}`
 * (R-BA-10: verticals plug in by filter). No provider ⇒ the pack is listed `source_plugin_missing`, never invented.
 * No FreeAstroAPI / LLM call from PHP (R-PF-2, R-TAA-6).
 *
 * // @axis twin-agent-axis@1 pack astro_self
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Agent_Astro_Self_Pack', false ) ) {
	return;
}

final class BizCity_Agent_Astro_Self_Pack {

	const FILTER  = 'bizcity_agent_astro_self_profile';
	const PERIODS = 12;

	public static function register(): void {
		add_filter( 'bizcity_twin_agent_pack_exporters', array( __CLASS__, 'exporters' ) );
	}

	public static function exporters( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();
		$exporters['astro_self'] = array(
			'mode'      => 'astro_self',
			'audience'  => 'owner_agent',
			'available' => array( __CLASS__, 'available' ),
			'stats'     => array( __CLASS__, 'stats' ),
			'page'      => array( __CLASS__, 'page' ),
		);
		return $exporters;
	}

	public static function available(): bool {
		return function_exists( 'has_filter' ) && false !== has_filter( self::FILTER );
	}

	/** @param array $ctx {owner_user_id} */
	public static function items( array $ctx ): array {
		$user = (int) ( $ctx['owner_user_id'] ?? 0 );
		$p    = $user > 0 ? apply_filters( self::FILTER, null, $user ) : null;
		if ( ! is_array( $p ) || '' === trim( (string) ( $p['profile_ref'] ?? '' ) ) ) {
			return array();
		}
		$periods = array();
		foreach ( array_slice( (array) ( $p['periods'] ?? array() ), 0, self::PERIODS ) as $pe ) {
			if ( is_array( $pe ) ) {
				$periods[] = array( 'from' => substr( (string) ( $pe['from'] ?? '' ), 0, 10 ), 'to' => substr( (string) ( $pe['to'] ?? '' ), 0, 10 ), 'highlights' => self::clip( (string) ( $pe['highlights'] ?? '' ), 600 ) );
			}
		}
		return array( array( 'profile_ref' => self::clip( (string) $p['profile_ref'], 40 ), 'natal_summary' => self::clip( (string) ( $p['natal_summary'] ?? '' ), 2000 ), 'periods' => $periods ) );
	}

	public static function stats( array $ctx ): array {
		$json = (string) wp_json_encode( self::items( $ctx ) );
		return array( 'version' => 'v-' . substr( sha1( 'astro_self|' . $json ), 0, 8 ), 'as_of' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'bytes' => strlen( $json ), 'items' => '[]' === $json ? 0 : 1 );
	}

	public static function page( array $ctx, int $after, int $limit ): array {
		$items = $after > 0 ? array() : self::items( $ctx );
		return array( 'items' => $items, 'last_id' => count( $items ), 'more' => false );
	}

	private static function clip( string $s, int $max ): string {
		$s = trim( wp_strip_all_tags( $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}
}
