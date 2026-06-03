<?php
/**
 * WebChat Channel Binding Bootstrap (PHASE 0.36 W1)
 *
 * Ensures every blog has at least one row in `_bizcity_channel_bindings` for
 * `(blog_id, platform='WEBCHAT', account_id=<blog_id>)` so the Universal
 * Channel Listener can resolve a Guru when mirroring inbound webchat messages
 * into `_bizcity_channel_messages`.
 *
 * Convention:
 *   - account_id  = string(blog_id)  → one binding per site (webchat is local).
 *   - character_id default = option `bizcity_webchat_default_character_id`
 *                            (0 means "no Guru yet" — admin can set later via
 *                            the existing Channel Binding UI).
 *   - mode default = 'auto' → AI Guru replies inherit the binding.
 *
 * Idempotent: relies on `BizCity_Channel_Binding::upsert()` UNIQUE KEY
 * `(blog_id, platform, account_id)`.
 *
 * Scope: webchat is the FRONTEND visitor float widget. This bootstrap does
 * NOT touch admin TwinChat / Intent Engine / TwinShell — they live on
 * separate surfaces.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.6.0 (PHASE 0.36 W1)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_WebChat_Binding_Bootstrap {

	/**
	 * Per-request guard. ensure() is called from the inbound hot path
	 * (before every webchat trigger emission), so we avoid hitting DB
	 * more than once per request after the first successful resolve.
	 *
	 * @var array<int,bool> blog_id => true
	 */
	private static $checked = array();

	/**
	 * Ensure a WEBCHAT binding exists for the current (or given) blog.
	 *
	 * @param int|null $blog_id Override blog id; defaults to current blog.
	 * @return int Binding row id (0 if Channel_Binding class missing).
	 */
	public static function ensure( ?int $blog_id = null ): int {
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return 0;
		}

		$blog_id = $blog_id ?: (int) get_current_blog_id();
		if ( $blog_id <= 0 ) {
			return 0;
		}

		if ( isset( self::$checked[ $blog_id ] ) ) {
			return 0; // already verified this request
		}
		self::$checked[ $blog_id ] = true;

		$account_id = (string) $blog_id;

		// Fast path: binding already exists → nothing to do.
		$existing = BizCity_Channel_Binding::resolve( 'WEBCHAT', $account_id );
		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			return (int) $existing['id'];
		}

		// Pull default Guru from the webchat option (set in admin dashboard).
		$character_id = (int) get_option( 'bizcity_webchat_default_character_id', 0 );

		/**
		 * Allow other plugins / future admin UI to override the default
		 * binding payload before upsert.
		 *
		 * @param array $args  Default upsert args.
		 * @param int   $blog_id
		 */
		$args = apply_filters(
			'bizcity_webchat_default_binding_args',
			array(
				'platform'     => 'WEBCHAT',
				'account_id'   => $account_id,
				'character_id' => $character_id,
				'mode'         => 'auto',
				'status'       => 1,
				'meta'         => array(
					'source'      => 'webchat-bootstrap',
					'created_via' => 'auto',
					'blog_name'   => get_bloginfo( 'name' ),
				),
			),
			$blog_id
		);

		return (int) BizCity_Channel_Binding::upsert( $args );
	}
}
