<?php
/**
 * BizCity_Skill_Slash_Matcher — dual-tier slash command resolver (GURU W2/W3).
 *
 * Tier 1 (skill path): tra cứu `/cmd` qua
 *   `BizCity_Skill_Database::get_by_slash_command()` (FIND_IN_SET trên CSV
 *   `slash_commands` + fallback `skill_key`).
 *   → hit → fire `bizcity_skill_trigger_pipeline($skill, $args)` (archetype
 *     C/D) HOẶC inject A/B vào prompt next-turn (tại đây chỉ fire pipeline
 *     hook — A/B injection vẫn do `BizCity_Skill_Context::inject_skill_context`
 *     làm trên `bizcity_chat_system_prompt`).
 *
 * Tier 2 (workflow path): nếu skill miss → tìm workflow có
 *   `trigger_type='slash_command'` + `trigger_config.slash_command === '/cmd'`.
 *   → hit → enqueue qua `BizCity_Automation_Repo_Runs::enqueue()` (chính
 *     `BizCity_Automation_Trigger_Matcher::on_channel_message()` consume
 *     return value để biết match).
 *
 * G2 collision guardrail: nếu CÙNG `/cmd` xuất hiện ở CẢ skill row VÀ
 *   workflow row → REST CRUD admin trả 409 Conflict (xem
 *   `class-skill-rest-api.php::update_skill_db()` và
 *   `class-automation-rest.php::create_workflow|update_workflow()`).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 * @since      WF-AUTO GURU W2 (2026-06-03)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Skill_Slash_Matcher {

	private static $instance = null;

	public static function instance(): self {
		// [2026-06-03 Johnny Chu] GURU W2 — singleton init.
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Extract `/cmd` từ đầu text.
	 *
	 * Accepts:
	 *   "/cmd"            → ['cmd' => 'cmd', 'args' => '']
	 *   "/cmd args text"  → ['cmd' => 'cmd', 'args' => 'args text']
	 *   "  /cmd  rest "   → ['cmd' => 'cmd', 'args' => 'rest']
	 *   "no slash"        → null
	 *
	 * @param string $text Raw user text.
	 * @return array|null  { cmd, args } or null.
	 */
	public static function extract_command( string $text ) {
		// [2026-06-03 Johnny Chu] GURU W2 — extract /cmd + args from raw text.
		$text = ltrim( $text );
		if ( $text === '' || $text[0] !== '/' ) { return null; }
		// [2026-06-03 Johnny Chu] WF-AUTO W5 — reject /cmd longer than 64 chars (hardening).
		if ( ! preg_match( '/^\/([a-zA-Z0-9_\-]+)\s*(.*)$/s', $text, $m ) ) {
			return null;
		}
		if ( strlen( $m[1] ) > 64 ) { return null; }
		return array(
			'cmd'  => strtolower( $m[1] ),
			'args' => isset( $m[2] ) ? trim( (string) $m[2] ) : '',
		);
	}

	/**
	 * Lookup `/cmd` in dual-tier order: skill first, workflow fallback.
	 *
	 * @param string $cmd Slug WITHOUT leading '/'.
	 * @return array { source: 'skill'|'workflow'|null, skill?: array, workflow?: array }
	 */
	public function lookup( string $cmd ): array {
		// [2026-06-03 Johnny Chu] GURU W2 — dual-tier lookup: skill first, workflow fallback.
		$cmd = ltrim( trim( $cmd ), '/' );
		if ( $cmd === '' ) {
			return array( 'source' => null );
		}

		// ── Tier 1: skill ────────────────────────────────────────────────
		if ( class_exists( 'BizCity_Skill_Database' ) ) {
			$skill = BizCity_Skill_Database::instance()->get_by_slash_command( $cmd );
			if ( $skill && ! empty( $skill['id'] ) ) {
				return array( 'source' => 'skill', 'skill' => $skill );
			}
		}

		// ── Tier 2: workflow ─────────────────────────────────────────────
		$wf = self::find_workflow_for_slash( $cmd );
		if ( $wf ) {
			return array( 'source' => 'workflow', 'workflow' => $wf );
		}

		return array( 'source' => null );
	}

	/**
	 * Try to dispatch `/cmd` from a channel payload. Returns match result.
	 *
	 * Caller (typically `BizCity_Automation_Trigger_Matcher::on_channel_message`)
	 * SHOULD `return` early when `matched=true` to suppress keyword/fallback.
	 *
	 * @param array  $payload Run payload (chat_id, platform, character_id, …).
	 * @param string $text    Raw user text (may already be in payload['text']).
	 * @return array { matched: bool, source?: 'skill'|'workflow', detail?: string,
	 *                 skill_id?, workflow_id?, run_id? }
	 */
	public function try_dispatch( array $payload, string $text ): array {
		// [2026-06-03 Johnny Chu] GURU W2 — parse, lookup, dispatch — called from automation matcher.
		// [2026-06-03 Johnny Chu] WF-AUTO W5 — request-scoped dedup: ignore duplicate /cmd within same PHP request.
		static $dispatched = array();
		$parsed = self::extract_command( $text );
		if ( ! $parsed ) {
			return array( 'matched' => false, 'detail' => 'no_slash_prefix' );
		}
		$cmd  = $parsed['cmd'];
		if ( isset( $dispatched[ $cmd ] ) ) {
			return array( 'matched' => false, 'detail' => 'dedup_skip:already_dispatched_this_request' );
		}
		$args = $parsed['args'];
		$hit  = $this->lookup( $cmd );

		if ( $hit['source'] === 'skill' ) {
			$skill = $hit['skill'];
			$this->dispatch_skill( $skill, $args, $payload );
			$dispatched[ $cmd ] = true;
			return array(
				'matched'  => true,
				'source'   => 'skill',
				'skill_id' => (int) ( $skill['id'] ?? 0 ),
				'detail'   => 'skill_key=' . (string) ( $skill['skill_key'] ?? '' ),
			);
		}

		if ( $hit['source'] === 'workflow' ) {
			$wf     = $hit['workflow'];
			$run_id = $this->dispatch_workflow( $wf, $cmd, $args, $payload );
			$dispatched[ $cmd ] = true;
			return array(
				'matched'     => true,
				'source'      => 'workflow',
				'workflow_id' => (int) ( $wf['id'] ?? 0 ),
				'run_id'      => $run_id,
				'detail'      => 'wf_id=' . (int) ( $wf['id'] ?? 0 ),
			);
		}

		return array( 'matched' => false, 'detail' => 'no_skill_no_workflow_for_/' . $cmd );
	}

	/**
	 * Fire skill pipeline hook with DB row reshaped to match
	 * `BizCity_Skill_Context` skill array contract.
	 */
	private function dispatch_skill( array $skill_row, string $args_text, array $payload ): void {
		// [2026-06-03 Johnny Chu] GURU W2 — reshape DB row → skill contract, fire pipeline + skill_invoked hooks.
		$frontmatter = array(
			'title'          => (string) ( $skill_row['title'] ?? '' ),
			'category'       => (string) ( $skill_row['category'] ?? 'general' ),
			'triggers'       => json_decode( (string) ( $skill_row['triggers_json'] ?? '[]' ), true ) ?: array(),
			'tools'           => json_decode( (string) ( $skill_row['tools_json']    ?? '[]' ), true ) ?: array(),
			'slash_commands' => array_filter( array_map( 'trim', explode( ',', (string) ( $skill_row['slash_commands'] ?? '' ) ) ) ),
			'modes'          => array_filter( array_map( 'trim', explode( ',', (string) ( $skill_row['modes'] ?? '' ) ) ) ),
			'priority'       => (int) ( $skill_row['priority'] ?? 50 ),
			'output_format'  => (string) ( $skill_row['output_format'] ?? '' ),
			'archetype'      => (string) ( $skill_row['archetype'] ?? '' ),
		);
		$archetype = class_exists( 'BizCity_Skill_Context' )
			? BizCity_Skill_Context::detect_archetype( $frontmatter )
			: ( strtoupper( (string) $frontmatter['archetype'] ) ?: 'A' );

		$skill = array(
			'id'           => (int) ( $skill_row['id'] ?? 0 ),
			'skill_id'     => (int) ( $skill_row['id'] ?? 0 ),
			'skill_key'    => (string) ( $skill_row['skill_key'] ?? '' ),
			'frontmatter'  => $frontmatter,
			'content'      => (string) ( $skill_row['content'] ?? '' ),
			'archetype'    => $archetype,
			'score'        => 100, // explicit slash = max confidence.
			'reasons'      => array( 'slash_command' ),
			'path'         => 'db://skill/' . (int) ( $skill_row['id'] ?? 0 ),
		);

		$args = array(
			'mode'         => 'slash',
			'message'      => (string) ( $payload['text'] ?? $payload['message'] ?? '' ),
			'slash_args'   => $args_text,
			'character_id' => (int) ( $payload['character_id'] ?? 0 ),
			'user_id'      => (int) ( $payload['wp_user_id'] ?? 0 ),
			'context'      => $payload,
			'source'       => 'slash_matcher',
		);

		// Canonical hook (archetype C/D consumers in BizCity_Skill_Pipeline_Bridge).
		do_action( 'bizcity_skill_trigger_pipeline', $skill, $args );

		// Mirror to BRIDGE W1's hook so `trigger.skill_intent` workflows can
		// chain off direct slash invocations too.
		do_action( 'bizcity_skill_invoked', $skill['skill_key'], array(
			'archetype'    => $archetype,
			'skill_id'     => $skill['id'],
			'character_id' => $args['character_id'],
			'workflow_id'  => 0,
			'prompt'       => $args['message'],
			'output'       => '',
			'source'       => 'slash_matcher',
		) );
	}

	/**
	 * Enqueue workflow run for slash dispatch.
	 *
	 * @param array  $wf        Workflow row.
	 * @param string $cmd       Slash command slug WITHOUT leading '/'.
	 * @param string $args_text Text after the command.
	 * @param array  $payload   Run payload.
	 * @return string|int|WP_Error run_id (xem Repo_Runs::enqueue contract).
	 */
	private function dispatch_workflow( array $wf, string $cmd, string $args_text, array $payload ) {
		// [2026-06-03 Johnny Chu] GURU W2 — enqueue workflow run; _slash stores /cmd for trace.
		$run_payload = array_merge( $payload, array(
			'_trigger'   => 'slash_command',
			'slash_args' => $args_text,
			'_slash'     => '/' . ltrim( $cmd, '/' ),
		) );

		if ( ! class_exists( 'BizCity_Automation_Repo_Runs' ) ) {
			return new WP_Error( 'runs_repo_missing', 'Automation runs repo missing.' );
		}
		$run_id = BizCity_Automation_Repo_Runs::enqueue( (int) $wf['id'], $run_payload );
		if ( ! is_wp_error( $run_id ) ) {
			do_action( 'bizcity_automation_run_enqueued', $run_id, (int) $wf['id'], $run_payload );
		}
		return $run_id;
	}

	/* ─── G2 collision helpers ─────────────────────────────────────────── */

	/**
	 * Find workflow that owns slash `/cmd` via trigger_type=slash_command +
	 * trigger_config.slash_command match. Optional `$exclude_id` skips a row
	 * (when checking own update).
	 *
	 * @return array|null Workflow row or null.
	 */
	public static function find_workflow_for_slash( string $cmd, int $exclude_id = 0 ) {
		// [2026-06-03 Johnny Chu] GURU W3 — collision lookup: find workflow that owns /cmd.
		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) { return null; }
		$cmd_norm = '/' . ltrim( strtolower( trim( $cmd ) ), '/' );
		if ( $cmd_norm === '/' ) { return null; }
		$out = BizCity_Automation_Repo_Workflows::query( array(
			'trigger_type' => 'slash_command',
			'enabled'      => 1,
			'limit'        => 200,
		) );
		foreach ( ( $out['rows'] ?? array() ) as $wf ) {
			if ( $exclude_id > 0 && (int) $wf['id'] === $exclude_id ) { continue; }
			$cfg = self::decode_trigger_config( $wf );
			$claim = strtolower( trim( (string) ( $cfg['slash_command'] ?? '' ) ) );
			if ( $claim === '' ) { continue; }
			if ( $claim[0] !== '/' ) { $claim = '/' . $claim; }
			if ( $claim === $cmd_norm ) { return $wf; }
		}
		return null;
	}

	/**
	 * Find skill row that owns slash `/cmd` (DB lookup).
	 *
	 * @return array|null Skill row or null.
	 */
	public static function find_skill_for_slash( string $cmd, int $exclude_id = 0 ) {
		// [2026-06-03 Johnny Chu] GURU W3 — collision lookup: find skill that owns /cmd.
		if ( ! class_exists( 'BizCity_Skill_Database' ) ) { return null; }
		$row = BizCity_Skill_Database::instance()->get_by_slash_command( $cmd );
		if ( ! $row ) { return null; }
		if ( $exclude_id > 0 && (int) ( $row['id'] ?? 0 ) === $exclude_id ) { return null; }
		return $row;
	}

	/**
	 * Detect collisions for a list of slash commands. Returns first conflict
	 * encountered (or null) so REST can return 409 with machine-readable code.
	 *
	 * @param array  $slash_list Array of "/cmd" strings (or bare "cmd").
	 * @param string $self_kind  'skill' | 'workflow' (denotes WHO is saving — opposite tier is checked).
	 * @param int    $self_id    Row id being saved (excluded from check).
	 * @return array|null { cmd, conflicts_with: 'skill'|'workflow', conflict_id, conflict_label }
	 */
	public static function detect_collision( array $slash_list, string $self_kind, int $self_id = 0 ) {
		// [2026-06-03 Johnny Chu] GURU W3 — cross-tier collision scan, returns first conflict or null.
		foreach ( $slash_list as $raw ) {
			$cmd = ltrim( strtolower( trim( (string) $raw ) ), '/' );
			if ( $cmd === '' ) { continue; }
			if ( $self_kind === 'skill' ) {
				$wf = self::find_workflow_for_slash( $cmd, 0 );
				if ( $wf ) {
					return array(
						'cmd'             => '/' . $cmd,
						'conflicts_with'  => 'workflow',
						'conflict_id'     => (int) $wf['id'],
						'conflict_label'  => (string) ( $wf['title'] ?? $wf['slug'] ?? ( 'wf#' . (int) $wf['id'] ) ),
					);
				}
			} else { // self_kind = workflow
				$skill = self::find_skill_for_slash( $cmd, 0 );
				if ( $skill ) {
					return array(
						'cmd'             => '/' . $cmd,
						'conflicts_with'  => 'skill',
						'conflict_id'     => (int) ( $skill['id'] ?? 0 ),
						'conflict_label'  => (string) ( $skill['title'] ?? $skill['skill_key'] ?? ( 'skill#' . (int) ( $skill['id'] ?? 0 ) ) ),
					);
				}
			}
		}
		return null;
	}

	/* ─── Internals ─────────────────────────────────────────────────────── */

	private static function decode_trigger_config( array $wf ): array {
		// [2026-06-03 Johnny Chu] GURU W3 — safe decode trigger_config_json from workflow row.
		$raw = $wf['trigger_config'] ?? null;
		if ( is_array( $raw ) ) { return $raw; }
		$json = is_string( $raw ) && $raw !== ''
			? $raw
			: ( is_string( $wf['trigger_config_json'] ?? null ) ? $wf['trigger_config_json'] : '' );
		if ( $json === '' ) { return array(); }
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
