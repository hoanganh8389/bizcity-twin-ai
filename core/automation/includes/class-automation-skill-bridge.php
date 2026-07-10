<?php
/**
 * BizCity_Automation_Skill_Bridge — Bridge skill A/B/C invocations to automation.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @license    GPL-2.0-or-later
 *
 * Wave B BRIDGE W2 (2026-06-03).
 *
 * Subscribes to skill-domain hooks và fan-out vào workflow engine với
 * trigger_type=`skill_intent`. Lắng nghe:
 *
 *   - `bizcity_skill_trigger_pipeline( $skill, $args )` → archetype C
 *     pipeline dispatch từ matcher.
 *   - `bizcity_skill_invoked( $slug, $payload )` → emit từ
 *     `action.invoke_skill` block (wave B W1) hoặc REST `/run` adapter.
 *
 * R-EVT-2: KHÔNG tạo bảng log mới. Mọi event chỉ enqueue workflow run
 * thông qua `BizCity_Automation_Repo_Runs::enqueue()`.
 *
 * @since 2026-06-03
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Skill_Bridge {

	/** Request-scoped dedup so back-to-back identical fires don't double-trigger. */
	private static $seen = array();

	public static function init(): void {
		// [2026-06-03 Johnny Chu] WF-AUTO BRIDGE W2 — subscribe canonical hooks.
		add_action( 'bizcity_skill_trigger_pipeline', array( __CLASS__, 'on_skill_pipeline' ), 30, 2 );
		add_action( 'bizcity_skill_invoked',          array( __CLASS__, 'on_skill_invoked' ),  30, 2 );
	}

	/**
	 * Hook: `bizcity_skill_trigger_pipeline` (archetype C dispatch from matcher).
	 *
	 * @param array $skill { path, frontmatter, content, score, archetype }
	 * @param array $args  Original matcher args.
	 */
	public static function on_skill_pipeline( $skill, $args = array() ): void {
		if ( ! is_array( $skill ) ) { return; }
		$fm        = is_array( $skill['frontmatter'] ?? null ) ? $skill['frontmatter'] : array();
		$slug      = (string) ( $fm['slug'] ?? $fm['skill_key'] ?? '' );
		$archetype = (string) ( $fm['archetype'] ?? $skill['archetype'] ?? 'C' );

		self::dispatch( $slug, $archetype, 'pipeline', is_array( $args ) ? $args : array() );
	}

	/**
	 * Hook: `bizcity_skill_invoked` (workflow → skill bridge).
	 *
	 * @param string $slug
	 * @param array  $payload
	 */
	public static function on_skill_invoked( $slug, $payload = array() ): void {
		$slug = (string) $slug;
		if ( $slug === '' ) { return; }
		$payload   = is_array( $payload ) ? $payload : array( '_raw' => $payload );
		$archetype = (string) ( $payload['archetype'] ?? '' );
		self::dispatch( $slug, $archetype, 'invoke_skill', $payload );
	}

	/**
	 * Internal: query enabled skill_intent workflows + filter + enqueue.
	 *
	 * @param string $slug
	 * @param string $archetype
	 * @param string $source     'pipeline' | 'invoke_skill'
	 * @param array  $args_raw   Forwarded payload.
	 */
	private static function dispatch( string $slug, string $archetype, string $source, array $args_raw ): void {
		if ( $slug === '' ) { return; }
		$dedup_key = $source . '|' . $slug . '|' . md5( wp_json_encode( $args_raw ) );
		if ( isset( self::$seen[ $dedup_key ] ) ) { return; }
		self::$seen[ $dedup_key ] = true;

		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) { return; }

		$wfs = BizCity_Automation_Repo_Workflows::query( array(
			'trigger_type' => 'skill_intent',
			'enabled'      => 1,
			'limit'        => 50,
		) );
		if ( empty( $wfs['rows'] ) ) { return; }

		foreach ( $wfs['rows'] as $wf ) {
			$cfg = is_string( $wf['trigger_config_json'] ?? null )
				? json_decode( $wf['trigger_config_json'], true )
				: ( $wf['trigger_config'] ?? array() );
			if ( ! is_array( $cfg ) ) { $cfg = array(); }

			// Filter 1 — skill_slug (empty = match any).
			$want_slug = trim( (string) ( $cfg['skill_slug'] ?? '' ) );
			if ( $want_slug !== '' && $want_slug !== $slug ) { continue; }

			// Filter 2 — archetype (any | A | B | C).
			$want_arch = strtoupper( trim( (string) ( $cfg['archetype'] ?? 'any' ) ) );
			if ( $want_arch !== '' && $want_arch !== 'ANY' && strtoupper( $archetype ) !== $want_arch ) { continue; }

			$enriched = array(
				'_trigger'   => 'skill_intent',
				'skill_slug' => $slug,
				'archetype'  => $archetype,
				'source'     => $source,
				'args'       => $args_raw,
				'text'       => isset( $args_raw['message'] ) ? (string) $args_raw['message']
					: ( isset( $args_raw['prompt'] ) ? (string) $args_raw['prompt'] : '' ),
			);

			if ( class_exists( 'BizCity_Automation_Listener' ) ) {
				BizCity_Automation_Listener::inject( 'skill_intent', $enriched );
			}

			if ( class_exists( 'BizCity_Automation_Repo_Runs' ) ) {
				$run = BizCity_Automation_Repo_Runs::enqueue( (int) $wf['id'], $enriched );
				if ( ! is_wp_error( $run ) ) {
					do_action( 'bizcity_automation_run_enqueued', $run, (int) $wf['id'], $enriched );
				}
			}
		}
	}
}
