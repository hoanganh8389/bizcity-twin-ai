<?php
/**
 * Diagnostics probe for PHASE-0.45 Zalo Bot group automation contracts.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_Zalobot_Group_Automation' ) ) {
	return;
}

final class BizCity_Probe_Zalobot_Group_Automation implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.channel.zalobot_group_automation'; }
	public function label(): string { return 'Zalo Bot group automation: login branch + AskBrain parity'; }
	public function description(): string { return 'Read-only contract checks for unlinked-user routing, group-safe login guidance, TwinBrain deep research and citation pack propagation.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 44; }
	public function icon(): string { return 'workflow'; }
	public function estimate_ms(): int { return 200; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;
		$root  = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/bizcity-twin-ai' : '';
		$files = array(
			'login_action'  => $root . '/core/automation/includes/blocks/actions/class-action-ensure-linked-user.php',
			'deep_action'   => $root . '/core/automation/includes/blocks/actions/class-action-twinbrain-deep-research.php',
			'template_file' => $root . '/core/automation/templates/builtin-zalobot-phase045.json',
			'matcher'       => $root . '/core/automation/includes/class-automation-trigger-matcher.php',
			'runtime'       => $root . '/core/twinbrain/includes/class-twinbrain-runtime.php',
		);
		$disk_ok = true;
		foreach ( $files as $key => $file ) {
			$exists = is_file( $file ) && is_readable( $file );
			$steps[] = array( 'label' => 'Disk: ' . $key, 'status' => $exists ? 'pass' : 'fail', 'detail' => $exists ? 'Readable artifact exists.' : 'Artifact missing or unreadable.' );
			if ( ! $exists ) { $disk_ok = false; }
		}
		if ( ! $disk_ok ) { $pass = false; }

		$action_ok = class_exists( 'BizCity_Automation_Action_Ensure_Linked_User', false )
			&& class_exists( 'BizCity_Automation_Action_Issue_Login_Link', false )
			&& class_exists( 'BizCity_Automation_Action_TwinBrain_Deep_Research', false );
		$steps[] = array( 'label' => 'Loader: PHASE-0.45 action classes', 'status' => $action_ok ? 'pass' : 'skip', 'detail' => $action_ok ? 'Identity guard and deep-research actions are loaded.' : 'Automation bootstrap is not loaded in this context.' );
		if ( ! $action_ok && defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) { $pass = false; }

		$identity_runtime_ok = false;
		if ( class_exists( 'BizCity_Automation_Action_Ensure_Linked_User', false ) ) {
			// [2026-09-01 Johnny Chu] PHASE-0.45-DDV — execute identity branches without sending messages or mutating storage.
			$identity_action = new BizCity_Automation_Action_Ensure_Linked_User();
			$linked_result = $identity_action->execute( array( 'trigger' => array( 'wp_user_id' => 1513, 'chat_kind' => 'private' ) ), array() );
			$group_result  = $identity_action->execute( array( 'trigger' => array( 'wp_user_id' => 0, 'chat_kind' => 'group' ) ), array() );
			$identity_runtime_ok = ! empty( $linked_result['linked'] )
				&& (int) ( $linked_result['wp_user_id'] ?? 0 ) === 1513
				&& empty( $group_result['linked'] )
				&& (string) ( $group_result['status'] ?? '' ) === 'login_required'
				&& strpos( (string) ( $group_result['public_hint'] ?? '' ), 'nhắn riêng' ) !== false;
		}
		$steps[] = array( 'label' => 'Runtime: linked/unlinked identity branch is group-safe', 'status' => $identity_runtime_ok ? 'pass' : 'fail', 'detail' => $identity_runtime_ok ? 'Linked owner is preserved; unlinked group receives login-required public guidance without side effects.' : 'Identity action branch did not return the expected owner/public-guidance contract.' );
		if ( ! $identity_runtime_ok ) { $pass = false; }

		$template_ok = false;
		if ( is_readable( $files['template_file'] ) ) {
			$decoded = json_decode( (string) file_get_contents( $files['template_file'] ), true );
			if ( is_array( $decoded ) ) {
				$slugs = array();
				foreach ( $decoded as $template ) {
					if ( ! is_array( $template ) ) { continue; }
					$slugs[] = (string) ( $template['slug'] ?? '' );
				}
				$template_ok = in_array( 'tpl_zalobot_login_required_v1', $slugs, true ) && in_array( 'tpl_zalobot_group_deep_research_v1', $slugs, true );
			}
		}
		$steps[] = array( 'label' => 'Runtime contract: PHASE-0.45 blueprints', 'status' => $template_ok ? 'pass' : 'fail', 'detail' => $template_ok ? 'Login-required and group deep-research blueprints are registered in JSON.' : 'Required blueprint slug is missing or invalid JSON.' );
		if ( ! $template_ok ) { $pass = false; }

		$matcher_src = is_readable( $files['matcher'] ) ? (string) file_get_contents( $files['matcher'] ) : '';
		$matcher_ok = strpos( $matcher_src, 'dispatch_unlinked_zalobot_workflow' ) !== false && strpos( $matcher_src, 'identity_link_required' ) !== false;
		$steps[] = array( 'label' => 'Runtime contract: unlinked Zalo Bot matcher branch', 'status' => $matcher_ok ? 'pass' : 'fail', 'detail' => $matcher_ok ? 'Unlinked Bot messages have a canonical template route.' : 'Matcher branch is missing.' );
		if ( ! $matcher_ok ) { $pass = false; }

		$runtime_src = is_readable( $files['runtime'] ) ? (string) file_get_contents( $files['runtime'] ) : '';
		$pack_ok = strpos( $runtime_src, "'graph_vector_rerank_pack'" ) !== false && strpos( $runtime_src, "'final_context_chunks'" ) !== false;
		$steps[] = array( 'label' => 'Runtime contract: complete_turn exposes AskBrain parity pack', 'status' => $pack_ok ? 'pass' : 'fail', 'detail' => $pack_ok ? 'Graph, retrieval and final context fields are exposed to automation.' : 'Parity pack fields are not exposed.' );
		if ( ! $pack_ok ) { $pass = false; }

		return array( 'status' => $pass ? 'pass' : 'fail', 'steps' => $steps );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = new BizCity_Probe_Zalobot_Group_Automation();
	return $list;
} );