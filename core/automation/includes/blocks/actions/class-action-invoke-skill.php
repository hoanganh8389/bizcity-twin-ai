<?php
/**
 * Action: Invoke a registered Skill (Archetype A/B/C) through Twin Runner.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Wave B BRIDGE W1 — cross-tier bridge block.
 *
 * Workflow gọi skill thay vì gọi LLM tool-loop trực tiếp. Block resolve
 * `skill_slug` qua `BizCity_Skill_Database::get_by_slash_command()` →
 * dispatch qua `BizCity_TwinBrain_Runtime::start_turn()` với prompt được
 * compose từ `prompt_template` + `vars` (mặc định forward `trigger.text`).
 *
 * Fail-OPEN (R-SKILL §3.4): skill không tồn tại / runtime missing →
 * note_event('invoke_skill_skipped') + return `_degraded` flag, KHÔNG
 * break workflow. Runner downstream xử lý tiếp với `ctx.skill_output = ''`.
 *
 * @since 2026-06-03
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Invoke_Skill extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'action.invoke_skill'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Gọi Skill (Twin Runner)',
			'short'    => 'invoke_skill',
			'category' => 'bridge',
			'color'    => '#0ea5e9',
			'icon'     => 'sparkles',
			'defaults' => array(
				'label'           => 'invoke_skill',
				'skill_slug'      => '',
				'prompt_template' => '{{trigger.text}}',
				'vars_json'       => '',
				'character_id'    => 0,
				'timeout_seconds' => 30,
			),
			'fields'   => array(
				array( 'name' => 'label',           'label' => 'Tên hiển thị',      'type' => 'text' ),
				array( 'name' => 'skill_slug',      'label' => 'Skill slug (slash hoặc skill_key)', 'type' => 'text', 'hint' => 'vd: /sales_post hoặc sales_post_with_image' ),
				array( 'name' => 'prompt_template', 'label' => 'Prompt template',   'type' => 'textarea', 'hint' => 'Hỗ trợ {{trigger.text}}, {{n_xxx.output}}, {{vars.foo}}' ),
				array( 'name' => 'vars_json',       'label' => 'Vars (JSON)',        'type' => 'textarea', 'hint' => '{"product":"abc","tone":"friendly"}' ),
				array( 'name' => 'character_id',    'label' => 'Pin Guru (optional)', 'type' => 'number' ),
				array( 'name' => 'timeout_seconds', 'label' => 'Timeout (giây)',     'type' => 'number' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-06-03 Johnny Chu] WF-AUTO BRIDGE W1 — invoke skill via Twin Runner.
		$skill_slug = trim( (string) $this->resolve( $data['skill_slug'] ?? '', $ctx ) );
		if ( $skill_slug === '' ) {
			$this->note_event( 'invoke_skill_skipped', array( 'reason' => 'invalid_param', 'detail' => 'skill_slug empty' ) );
			return array( 'ok' => false, '_degraded' => true, 'skill_output' => '', 'reason' => 'invalid_param' );
		}

		// (1) Resolve skill row (fail-OPEN per R-SKILL §3.4).
		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			$this->note_event( 'invoke_skill_skipped', array( 'reason' => 'skill_db_missing', 'skill_slug' => $skill_slug ) );
			return array( 'ok' => false, '_degraded' => true, 'skill_output' => '', 'reason' => 'skill_db_missing' );
		}
		$db   = BizCity_Skill_Database::instance();
		$skill = $db->get_by_slash_command( $skill_slug );
		if ( ! $skill ) {
			$this->note_event( 'invoke_skill_skipped', array(
				'reason'     => 'skill_not_found',
				'skill_slug' => $skill_slug,
			) );
			$this->debug( 'skill_not_found slug=' . $skill_slug );
			return array( 'ok' => false, '_degraded' => true, 'skill_output' => '', 'reason' => 'skill_not_found' );
		}

		// (2) Build prompt + vars.
		$vars_json = (string) $this->resolve( $data['vars_json'] ?? '', $ctx );
		$vars      = array();
		if ( $vars_json !== '' ) {
			$decoded = json_decode( $vars_json, true );
			if ( is_array( $decoded ) ) {
				$vars = $decoded;
			}
		}
		// Merge vars into ctx under `vars.*` namespace for template resolve.
		$ctx_with_vars = $ctx;
		$ctx_with_vars['vars'] = $vars;

		$prompt = (string) $this->resolve( $data['prompt_template'] ?? '{{trigger.text}}', $ctx_with_vars );
		if ( $prompt === '' ) {
			$prompt = '/' . ltrim( $skill_slug, '/' );
		}

		// (3) Pin guru — prefer explicit data.character_id, else inherit from
		// payload (R-GCB-3 propagation), else from skill row.
		$character_id = (int) ( $data['character_id'] ?? 0 );
		if ( $character_id <= 0 ) {
			$character_id = (int) ( $ctx['_payload']['character_id'] ?? $ctx['payload']['character_id'] ?? 0 );
		}
		if ( $character_id <= 0 ) {
			$character_id = (int) ( $skill['character_id'] ?? 0 );
		}

		// (4) Dispatch via Twin Runner.
		if ( ! class_exists( 'BizCity_TwinBrain_Runtime' ) ) {
			$this->note_event( 'invoke_skill_skipped', array( 'reason' => 'runtime_missing', 'skill_slug' => $skill_slug ) );
			return array( 'ok' => false, '_degraded' => true, 'skill_output' => '', 'reason' => 'runtime_missing' );
		}

		$opts = array(
			'user_id'    => (int) ( $ctx['_user_id'] ?? get_current_user_id() ),
			'guru_id'    => $character_id,
			'tool_force' => '',
			'source'     => 'automation',
			'source_ref' => isset( $ctx['_workflow_id'] ) ? ( 'wf:' . $ctx['_workflow_id'] ) : '',
		);

		$started = microtime( true );
		try {
			$start = BizCity_TwinBrain_Runtime::instance()->start_turn( $prompt, $opts );
		} catch ( \Throwable $e ) {
			$this->note_event( 'invoke_skill_failed', array(
				'reason'     => 'runtime_error',
				'skill_slug' => $skill_slug,
				'error'      => $e->getMessage(),
			) );
			return array( 'ok' => false, '_degraded' => true, 'skill_output' => '', 'reason' => 'runtime_error', 'error' => $e->getMessage() );
		}
		$duration_ms = (int) ( ( microtime( true ) - $started ) * 1000 );

		$trace_id     = is_array( $start ) && isset( $start['trace_id'] )    ? (string) $start['trace_id']    : '';
		$skill_output = is_array( $start ) && isset( $start['response'] )    ? (string) $start['response']
			: ( is_array( $start ) && isset( $start['final_text'] ) ? (string) $start['final_text'] : '' );

		$this->note_event( 'invoke_skill_ok', array(
			'skill_slug'  => $skill_slug,
			'skill_id'    => (int) ( $skill['id'] ?? 0 ),
			'character_id'=> $character_id,
			'trace_id'    => $trace_id,
			'duration_ms' => $duration_ms,
		) );

		// [2026-06-03 Johnny Chu] WF-AUTO BRIDGE W2 — emit canonical hook so
		// downstream workflows with trigger_type=skill_intent can chain.
		do_action( 'bizcity_skill_invoked', $skill_slug, array(
			'archetype'    => (string) ( $skill['archetype'] ?? '' ),
			'skill_id'     => (int) ( $skill['id'] ?? 0 ),
			'trace_id'     => $trace_id,
			'character_id' => $character_id,
			'workflow_id'  => (int) ( $ctx['_workflow_id'] ?? 0 ),
			'prompt'       => $prompt,
			'output'       => $skill_output,
			'source'       => 'invoke_skill',
		) );

		return array(
			'ok'           => true,
			'skill_slug'   => $skill_slug,
			'skill_id'     => (int) ( $skill['id'] ?? 0 ),
			'trace_id'     => $trace_id,
			'skill_output' => $skill_output,
			'duration_ms'  => $duration_ms,
		);
	}
}
