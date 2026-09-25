<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent\Shell
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.16 / Vòng 4 — Task 4.16.3
 * Collects WP-side context layers into a flat ctx[] array consumable by
 * `BizCity_Twin_Runner` and the agents it spawns.
 *
 * Sprint 1 — minimal viable collector:
 *   • user_id, channel, conversation_id, character_id
 *   • locale (WP locale)
 *   • blog_id (multisite)
 *   • is_admin, capabilities
 *
 * Sprint 2 will fold in:
 *   • Rolling memory snapshot
 *   • KG hub recent facts
 *   • Skill / persona overrides
 *   • Provider hint resolution
 *
 * @since 4.0.0 (Vòng 4)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Intent_Context_Collector {

	/**
	 * @param array $params Intent_Engine::process() params.
	 * @return array<string,mixed>
	 */
	public function collect( array $params ): array {
		$user_id = (int) ( $params['user_id'] ?? 0 );

		$ctx = [
			'user_id'         => $user_id,
			'channel'         => (string) ( $params['channel'] ?? 'webchat' ),
			'conversation_id' => (string) ( $params['conversation_id'] ?? $params['session_id'] ?? '' ),
			'character_id'    => (int) ( $params['character_id'] ?? 0 ),
			'message_id'      => (string) ( $params['message_id'] ?? '' ),
			'provider_hint'   => (string) ( $params['provider_hint'] ?? '' ),
			'selected_skill'  => (string) ( $params['selected_skill'] ?? '' ),
			'skill_path'      => (string) ( $params['skill_path'] ?? '' ),
			'slash_command'   => (string) ( $params['slash_command'] ?? '' ),
			'has_images'      => is_array( $params['images'] ?? null ) ? count( $params['images'] ) : 0,
			'locale'          => function_exists( 'get_locale' ) ? get_locale() : 'vi',
			'blog_id'         => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'is_admin'        => is_admin(),
		];

		// User capability snapshot (avoid passing the full WP_User across runner).
		if ( $user_id > 0 && function_exists( 'user_can' ) ) {
			$ctx['can_publish_posts'] = user_can( $user_id, 'publish_posts' );
			$ctx['can_manage']        = user_can( $user_id, 'manage_options' );
		} else {
			$ctx['can_publish_posts'] = false;
			$ctx['can_manage']        = false;
		}

		// Pass-through any caller-supplied context_overrides last so they win.
		if ( isset( $params['context_overrides'] ) && is_array( $params['context_overrides'] ) ) {
			$ctx = array_merge( $ctx, $params['context_overrides'] );
		}

		return $ctx;
	}
}
