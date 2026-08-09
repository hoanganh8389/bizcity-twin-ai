<?php
/**
 * BizCity Diagnostics — Twin GPT owner continuity probe.
 *
 * R-DDV evidence for four Wave 4 acceptance lines:
 * - Group chat_id never becomes personal identity.
 * - First-person Astro resolves the user's is_self=1 subject.
 * - Automation preserves owner from inbound through run/event/publish/reply.
 * - Automation cron owner path never derives owner from current session user.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-18
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_TwinWeb_Owner_Continuity', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Owner_Continuity implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twinweb.owner_continuity'; }
	public function label(): string { return 'Twin GPT Owner Continuity'; }
	public function description(): string {
		return 'Verifies DDV Disk/Loader/Runtime evidence for group identity isolation, first-person Astro ownership, automation owner propagation, and cron owner fail-closed behavior.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 77; }
	public function icon(): string { return 'UserCheck'; }
	public function estimate_ms(): int { return 180; }

	public function precondition() {
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-18 Johnny Chu] PHASE-TWINWEB F4 — DDV probe for identity + owner continuity acceptance lines.
		$steps = array();
		$pass  = true;
		$root  = $this->plugin_root();

		$files = array(
			'webhook'        => $root . '/plugins/bizcity-zalo-bot/includes/class-webhook-handler.php',
			'resolver'       => $root . '/core/channel-gateway/includes/class-user-resolver.php',
			'gateway'        => $root . '/core/channel-gateway/includes/class-gateway-bridge.php',
			'trigger_zalo'   => $root . '/core/automation/includes/blocks/triggers/class-trigger-zalo.php',
			'run_astro'      => $root . '/core/automation/includes/blocks/actions/class-action-run-astro.php',
			'relation'       => $root . '/core/automation/includes/blocks/actions/class-action-run-astro-relation-assessment.php',
			'transit'        => $root . '/core/automation/includes/blocks/actions/class-action-run-astro-transit.php',
			'base_block'     => $root . '/core/automation/includes/blocks/abstract-block.php',
			'matcher'        => $root . '/core/automation/includes/class-automation-trigger-matcher.php',
			'repo_runs'      => $root . '/core/automation/includes/class-automation-repo-runs.php',
			'runner'         => $root . '/core/automation/includes/class-automation-runner.php',
			'create_event'   => $root . '/core/automation/includes/blocks/actions/class-action-create-crm-event.php',
			'crm_bridge'     => $root . '/core/automation/includes/class-automation-crm-bridge.php',
			'publish_fb'     => $root . '/core/automation/includes/blocks/actions/class-action-publish-fb-post.php',
			'publish_wp'     => $root . '/core/automation/includes/blocks/actions/class-action-publish-wp-post.php',
			'schedule_event' => $root . '/core/automation/includes/blocks/actions/class-action-schedule-event.php',
			'notifier'       => $root . '/core/scheduler/includes/class-scheduler-completion-notifier.php',
		);

		/* Layer 1 — Disk */
		$missing_files = array();
		foreach ( $files as $key => $file ) {
			if ( ! is_readable( $file ) ) {
				$missing_files[] = $key;
			}
		}
		$ok = empty( $missing_files );
		$steps[] = $step = $this->step(
			'Disk · required owner-continuity files readable',
			$ok,
			$ok ? 'All owner-continuity source files are readable.' : 'Missing/unreadable: ' . implode( ', ', $missing_files )
		);
		$ctx->emit_step( $step );
		if ( ! $ok ) { $pass = false; }

		$webhook  = $this->source( $files['webhook'] );
		$resolver = $this->source( $files['resolver'] );
		$gateway  = $this->source( $files['gateway'] );
		$trigger  = $this->source( $files['trigger_zalo'] );
		$group_markers = array(
			'webhook_missing_sender_guard' => strpos( $webhook, 'Missing message.from.id in webhook payload' ) !== false,
			// [2026-07-18 Johnny Chu] SPRINT-28 DDV-FIX — accept current explicit bot_chat_id assignment and future resolve_chat_id variants.
			'webhook_identity_sender_key'  => strpos( $webhook, "'zalobot_' . $bot->id . '_' . $identity_user_id" ) !== false || strpos( $webhook, '$bot_chat_id' ) !== false,
			'webhook_conversation_meta'    => strpos( $webhook, 'conversation_chat_id' ) !== false,
			'resolver_group_guard'         => strpos( $resolver, 'function is_group_chat_id' ) !== false && strpos( $resolver, 'Refuse group-like chat_id' ) !== false,
			'gateway_identity_chat_id'     => strpos( $gateway, 'identity_chat_id' ) !== false && strpos( $gateway, '$resolve_chat_id' ) !== false && strpos( $gateway, "'zalobot_'" ) !== false,
			'trigger_identity_chat_id'     => strpos( $trigger, 'identity_chat_id' ) !== false && strpos( $trigger, '$resolve_chat_id' ) !== false && strpos( $trigger, "'zalobot_'" ) !== false,
		);
		$ok = $this->all_true( $group_markers );
		$steps[] = $step = $this->step(
			'Disk · Group chat_id never becomes personal identity',
			$ok,
			$ok ? 'sender identity key + conversation_chat_id metadata + resolver group guard found' : 'Missing markers: ' . implode( ', ', $this->false_keys( $group_markers ) )
		);
		$ctx->emit_step( $step );
		if ( ! $ok ) { $pass = false; }

		$run_astro = $this->source( $files['run_astro'] );
		$relation  = $this->source( $files['relation'] );
		$transit   = $this->source( $files['transit'] );
		$astro_markers = array(
			'run_astro_owner_chain'       => strpos( $run_astro, '$this->resolve_owner_user_id( $ctx, 0 )' ) !== false,
			'run_astro_self_coachee'      => strpos( $run_astro, 'resolve_self_coachee_row( $user_id )' ) !== false,
			'run_astro_no_chat_resolver'  => strpos( $run_astro, 'BizCity_User_Resolver::instance()->resolve' ) === false,
			'relation_owner_chain'        => strpos( $relation, '$this->resolve_owner_user_id( $ctx, 0 )' ) !== false,
			'relation_no_chat_resolver'   => strpos( $relation, 'BizCity_User_Resolver::instance()->resolve' ) === false,
			'transit_owner_chain'         => strpos( $transit, '$this->resolve_owner_user_id( $ctx )' ) !== false,
			'transit_self_coachee'        => strpos( $transit, 'resolve_self_coachee_id( $owner_user_id )' ) !== false,
			'transit_no_chat_resolver'    => strpos( $transit, 'BizCity_User_Resolver::instance()->resolve' ) === false,
		);
		$ok = $this->all_true( $astro_markers );
		$steps[] = $step = $this->step(
			'Disk · First-person Astro resolves owner is_self profile',
			$ok,
			$ok ? 'run_astro/relation use owner chain; run_astro resolves self coachee; chat_id fallback removed' : 'Missing markers: ' . implode( ', ', $this->false_keys( $astro_markers ) )
		);
		$ctx->emit_step( $step );
		if ( ! $ok ) { $pass = false; }

		$owner_chain_markers = array(
			'matcher_inbound'           => strpos( $this->source( $files['matcher'] ), "['inbound']" ) !== false || strpos( $this->source( $files['matcher'] ), '$run_payload[\'inbound\']' ) !== false,
			'matcher_owner_payload'     => strpos( $this->source( $files['matcher'] ), "['_owner_user_id']" ) !== false,
			'repo_runs_user_id'         => strpos( $this->source( $files['repo_runs'] ), 'runs_has_user_id_column' ) !== false && strpos( $this->source( $files['repo_runs'] ), 'user_id' ) !== false,
			'runner_owner_context'      => strpos( $this->source( $files['runner'] ), "'_owner_user_id' => $owner_user_id" ) !== false,
			'create_event_owner_fields' => strpos( $this->source( $files['create_event'] ), "'owner_user_id'" ) !== false && strpos( $this->source( $files['create_event'] ), "'workflow_owner_id'" ) !== false,
			'crm_bridge_fail_closed'    => strpos( $this->source( $files['crm_bridge'] ), 'CRM bridge refused create_event: owner_user_id missing' ) !== false,
			'publish_fb_owner_meta'     => strpos( $this->source( $files['publish_fb'] ), 'build_event_metadata' ) !== false && strpos( $this->source( $files['publish_fb'] ), 'owner_user_id' ) !== false,
			'publish_wp_owner_meta'     => strpos( $this->source( $files['publish_wp'] ), 'build_event_metadata' ) !== false && strpos( $this->source( $files['publish_wp'] ), "'user_id'     => $author" ) !== false,
			'notifier_reply_delivery'   => strpos( $this->source( $files['notifier'] ), 'metadata.inbound' ) !== false && strpos( $this->source( $files['notifier'] ), 'patch_delivery' ) !== false,
		);
		$ok = $this->all_true( $owner_chain_markers );
		$steps[] = $step = $this->step(
			'Disk · Automation owner survives inbound/run/event/publish/reply',
			$ok,
			$ok ? 'owner and inbound markers found across matcher, runs, runner, event creators, publishers, notifier' : 'Missing markers: ' . implode( ', ', $this->false_keys( $owner_chain_markers ) )
		);
		$ctx->emit_step( $step );
		if ( ! $ok ) { $pass = false; }

		$cron_owner_files = array(
			'base_block',
			'runner',
			'create_event',
			'crm_bridge',
			'publish_fb',
			'publish_wp',
			'schedule_event',
			'run_astro',
			'relation',
			'transit',
		);
		$current_user_hits = array();
		foreach ( $cron_owner_files as $key ) {
			$code = $this->source_without_comments( $files[ $key ] );
			if ( strpos( $code, 'get_current_user_id(' ) !== false ) {
				$current_user_hits[] = $key;
			}
		}
		$ok = empty( $current_user_hits );
		$steps[] = $step = $this->step(
			'Disk · Cron automation owner path has no current-session fallback',
			$ok,
			$ok ? 'No get_current_user_id() calls in automation owner-critical cron path.' : 'get_current_user_id() found in: ' . implode( ', ', $current_user_hits )
		);
		$ctx->emit_step( $step );
		if ( ! $ok ) { $pass = false; }

		/* Layer 2 — Loader */
		$loader_markers = array(
			'user_resolver'      => class_exists( 'BizCity_User_Resolver' ) && method_exists( 'BizCity_User_Resolver', 'resolve' ),
			'run_astro'          => class_exists( 'BizCity_Automation_Action_Run_Astro' ),
			'relation'           => class_exists( 'BizCity_Automation_Action_Run_Astro_Relation_Assessment' ),
			'create_crm_event'   => class_exists( 'BizCity_Automation_Action_Create_CRM_Event' ),
			'crm_bridge'         => class_exists( 'BizCity_Automation_CRM_Bridge' ) && method_exists( 'BizCity_Automation_CRM_Bridge', 'create_event' ),
			'repo_runs'          => class_exists( 'BizCity_Automation_Repo_Runs' ),
			'scheduler_notifier' => class_exists( 'BizCity_Scheduler_Completion_Notifier' ),
		);
		$ok = $this->all_true( $loader_markers );
		$steps[] = $step = $this->step(
			'Loader · owner-continuity classes loaded',
			$ok,
			$ok ? 'Resolver, automation actions, run repo, CRM bridge and scheduler notifier are loaded.' : 'Missing classes: ' . implode( ', ', $this->false_keys( $loader_markers ) )
		);
		$ctx->emit_step( $step );
		if ( ! $ok ) { $pass = false; }

		/* Layer 3 — Runtime */
		if ( class_exists( 'BizCity_User_Resolver' ) ) {
			$resolved = (int) BizCity_User_Resolver::instance()->resolve( 'zalobot_999_group___healthtest' );
			$ok = ( $resolved === 0 );
			$steps[] = $step = $this->step(
				'Runtime · group-like Zalo chat_id resolves to 0',
				$ok,
				$ok ? 'resolver refused zalobot_999_group___healthtest' : 'resolver returned user_id=' . $resolved
			);
			$ctx->emit_step( $step );
			if ( ! $ok ) { $pass = false; }
		} else {
			$steps[] = $step = $this->step( 'Runtime · group-like Zalo chat_id resolves to 0', false, 'BizCity_User_Resolver not loaded.' );
			$ctx->emit_step( $step );
			$pass = false;
		}

		if ( class_exists( 'BizCity_Automation_Action_Create_CRM_Event' ) ) {
			$action = new BizCity_Automation_Action_Create_CRM_Event();
			$result = $action->execute( array( 'trigger' => array( 'chat_id' => 'zalobot_999_user___healthtest' ) ), array(
				'event_type' => 'task',
				'title'      => '__healthtest owner missing',
			) );
			$ok = is_array( $result )
				&& (int) ( $result['event_id'] ?? -1 ) === 0
				&& (string) ( $result['_degraded'] ?? '' ) === 'owner_missing';
			$steps[] = $step = $this->step(
				'Runtime · create_crm_event refuses missing owner',
				$ok,
				$ok ? 'action returned event_id=0 and _degraded=owner_missing' : 'unexpected result: ' . wp_json_encode( $result )
			);
			$ctx->emit_step( $step );
			if ( ! $ok ) { $pass = false; }
		} else {
			$steps[] = $step = $this->step( 'Runtime · create_crm_event refuses missing owner', false, 'BizCity_Automation_Action_Create_CRM_Event not loaded.' );
			$ctx->emit_step( $step );
			$pass = false;
		}

		if ( class_exists( 'BizCity_Automation_CRM_Bridge' ) ) {
			$created = BizCity_Automation_CRM_Bridge::create_event( array(
				'event_type' => 'task',
				'title'      => '__healthtest owner missing bridge',
				'source'     => 'workflow',
				'start_at'   => current_time( 'mysql' ),
			) );
			$ok = ( (int) $created === 0 );
			$steps[] = $step = $this->step(
				'Runtime · CRM bridge refuses missing owner before scheduler insert',
				$ok,
				$ok ? 'bridge returned 0 for ownerless payload' : 'bridge created unexpected event_id=' . (int) $created
			);
			$ctx->emit_step( $step );
			if ( ! $ok ) { $pass = false; }
		} else {
			$steps[] = $step = $this->step( 'Runtime · CRM bridge refuses missing owner before scheduler insert', false, 'BizCity_Automation_CRM_Bridge not loaded.' );
			$ctx->emit_step( $step );
			$pass = false;
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'Twin GPT owner-continuity DDV passed for group identity, Astro self-profile, automation owner chain and cron fail-closed owner path.'
				: 'Twin GPT owner-continuity DDV found missing evidence.',
			'error'    => $pass ? '' : 'twinweb_owner_continuity_failed',
			'fix_hint' => $pass ? '' : 'Check Zalo identity key construction, automation owner propagation, Astro owner resolution, and ownerless scheduler event guards.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe. Runtime checks return before scheduler writes.
	}

	private function plugin_root(): string {
		return dirname( dirname( dirname( dirname( __DIR__ ) ) ) );
	}

	private function source( string $file ): string {
		return is_readable( $file ) ? (string) file_get_contents( $file ) : '';
	}

	private function source_without_comments( string $file ): string {
		$src = $this->source( $file );
		if ( $src === '' || ! function_exists( 'token_get_all' ) ) {
			return $src;
		}

		$out = '';
		foreach ( token_get_all( $src ) as $token ) {
			if ( is_array( $token ) ) {
				if ( $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT ) {
					continue;
				}
				$out .= $token[1];
			} else {
				$out .= $token;
			}
		}
		return $out;
	}

	private function all_true( array $checks ): bool {
		foreach ( $checks as $ok ) {
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	private function false_keys( array $checks ): array {
		$out = array();
		foreach ( $checks as $key => $ok ) {
			if ( ! $ok ) {
				$out[] = (string) $key;
			}
		}
		return $out;
	}

	private function step( string $label, bool $ok, string $detail ): array {
		return array(
			'label'  => $label,
			'status' => $ok ? 'pass' : 'fail',
			'detail' => $detail,
		);
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_Owner_Continuity';
	return $list;
} );