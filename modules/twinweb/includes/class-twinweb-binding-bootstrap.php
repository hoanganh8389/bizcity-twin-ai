<?php
/**
 * TwinWeb Binding Bootstrap
 *
 * Ensures one canonical channel-binding row for platform TWINWEB per blog.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since 2026-07-14
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_Binding_Bootstrap' ) ) {
	return;
}

class BizCity_TwinWeb_Binding_Bootstrap {

	private static $checked = array();

	/**
	 * Ensure TWINWEB binding row exists for the current blog.
	 *
	 * @param int|null $blog_id Optional site blog_id.
	 * @return int Binding ID or 0.
	 */
	public static function ensure( $blog_id = null ) {
		// [2026-07-14 Johnny Chu] PHASE-TWINWEB-SEARCH W1 — fail-open when channel gateway binding class is not loaded.
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return 0;
		}

		$resolved_blog_id = null === $blog_id ? (int) get_current_blog_id() : (int) $blog_id;
		if ( $resolved_blog_id <= 0 ) {
			return 0;
		}
		if ( isset( self::$checked[ $resolved_blog_id ] ) ) {
			return 0;
		}
		self::$checked[ $resolved_blog_id ] = true;

		$account_id = (string) $resolved_blog_id;
		$existing   = BizCity_Channel_Binding::resolve( 'TWINWEB', $account_id );
		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			return (int) $existing['id'];
		}

		// [2026-07-19 Johnny Chu] PHASE-TWINWEB-W0.18 — explicit Twin Brain auto mode must not recreate legacy Guru binding.
		$grounding_policy = get_option( 'bizcity_twinweb_grounding_policy_' . $resolved_blog_id, array() );
		if ( is_array( $grounding_policy ) && ! empty( $grounding_policy['brain_auto_mode'] ) ) {
			return 0;
		}

		$character_id = (int) get_option( 'bizcity_twinweb_default_character_id', 0 );
		if ( $character_id <= 0 ) {
			return 0;
		}

		return (int) BizCity_Channel_Binding::upsert( array(
			'platform'     => 'TWINWEB',
			'account_id'   => $account_id,
			'character_id' => $character_id,
			'mode'         => 'auto',
			'status'       => 1,
			'meta'         => array( 'source' => 'twinweb-bootstrap' ),
		) );
	}

	/**
	 * Resolve configured Guru (character_id) for current blog's TWINWEB binding.
	 *
	 * @return int Character ID, or 0 when not configured.
	 */
	public static function resolve_character_id() {
		// [2026-07-14 Johnny Chu] PHASE-TWINWEB-SEARCH W1 — ensure row lazily then resolve bound guru id.
		self::ensure();
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return 0;
		}

		$binding = BizCity_Channel_Binding::resolve( 'TWINWEB', (string) get_current_blog_id() );
		return (int) ( is_array( $binding ) ? ( $binding['character_id'] ?? 0 ) : 0 );
	}
}
