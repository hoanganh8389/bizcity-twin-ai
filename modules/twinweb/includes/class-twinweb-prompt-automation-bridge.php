<?php
/**
 * Twin GPT prompt automation bridge.
 *
 * Reuses ZaloBot keyword workflow semantics from the main prompt input without
 * sending real Zalo messages.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_Prompt_Automation_Bridge' ) ) { return; }

final class BizCity_TwinWeb_Prompt_Automation_Bridge {
	public static function hints( array $identity ): array {
		// [2026-07-22 Johnny Chu] PHASE-3-TWIN-GPT — expose prompt-triggerable workflow keywords for /gpt composer hints.
		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			return array( 'items' => array(), '_degraded' => true, 'message' => 'Automation runtime chưa tải.' );
		}
		$out = BizCity_Automation_Repo_Workflows::query( array(
			'trigger_type' => 'zalo_inbound',
			'enabled'      => 1,
			'limit'        => 100,
			'zone'         => 'admin',
		) );
		$items = array();
		foreach ( (array) ( $out['rows'] ?? array() ) as $wf ) {
			$cfg = is_array( $wf['trigger_config'] ?? null ) ? $wf['trigger_config'] : array();
			$visibility = (string) ( $cfg['visibility'] ?? 'private' );
			$selector = (string) ( $cfg['selector_mode'] ?? '' );
			$owner_required = ! empty( $cfg['owner_required'] );
			if ( $owner_required && (int) ( $identity['user_id'] ?? 0 ) <= 0 ) { continue; }
			if ( $visibility !== 'global' && $selector !== 'global' && (int) ( $wf['created_by'] ?? 0 ) !== (int) ( $identity['user_id'] ?? 0 ) ) { continue; }
			$keywords = array();
			if ( isset( $cfg['keywords'] ) && is_array( $cfg['keywords'] ) ) {
				foreach ( $cfg['keywords'] as $kw ) {
					if ( is_array( $kw ) ) {
						$kw = $kw['text'] ?? $kw['keyword'] ?? '';
					}
					$kw = trim( (string) $kw );
					if ( $kw !== '' ) { $keywords[] = $kw; }
				}
			}
			if ( empty( $keywords ) && ! empty( $cfg['filter'] ) ) {
				$keywords = array_values( array_filter( array_map( 'trim', preg_split( '/[|,;]+/', (string) $cfg['filter'] ) ?: array() ) ) );
			}
			if ( empty( $keywords ) ) { continue; }
			$items[] = array(
				'id'       => (int) $wf['id'],
				'slug'     => (string) ( $wf['slug'] ?? '' ),
				'name'     => (string) ( $wf['name'] ?? '' ),
				'keywords' => array_slice( array_values( array_unique( $keywords ) ), 0, 12 ),
				'mode'     => (string) ( $cfg['mode'] ?? 'keyword_contains' ),
				'global'   => ( $visibility === 'global' || $selector === 'global' ),
			);
		}
		return array( 'items' => $items );
	}

	public static function maybe_dispatch( string $message, array $identity, array $context = array() ): bool {
		// [2026-07-22 Johnny Chu] PHASE-3-TWIN-GPT — run matching ZaloBot keyword workflow from Twin GPT prompt using dry-run output.
		if ( ! class_exists( 'BizCity_Automation_Trigger_Matcher' ) || ! class_exists( 'BizCity_Automation_Runner' ) ) {
			return false;
		}
		$message = trim( wp_strip_all_tags( $message ) );
		if ( $message === '' ) { return false; }

		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$thread_id = sanitize_key( (string) ( $context['thread_id'] ?? '' ) );
		$chat_id = 'twinweb_prompt_' . ( $user_id > 0 ? $user_id : 'guest_' . sanitize_key( (string) ( $identity['guest_sid'] ?? '' ) ) ) . '_' . ( $thread_id !== '' ? $thread_id : 'default' );
		$payload = array(
			'platform'      => 'ZALO_BOT',
			'event_subtype' => 'twin_gpt_prompt',
			'origin_surface'=> 'twin_gpt_prompt',
			'text'          => $message,
			'raw_text'      => $message,
			'message_text_clean' => $message,
			'instance_id'   => '',
			'account_id'    => '',
			'sender_id'     => $user_id > 0 ? (string) $user_id : (string) ( $identity['guest_sid'] ?? 'guest' ),
			'user_id'       => $user_id > 0 ? (string) $user_id : (string) ( $identity['guest_sid'] ?? 'guest' ),
			'wp_user_id'    => $user_id,
			'_owner_user_id'=> $user_id,
			'character_id'  => (int) ( $context['character_id'] ?? 0 ),
			'chat_id'       => $chat_id,
			'conversation_chat_id' => $chat_id,
			'provider_chat_id' => $chat_id,
			'provider_chat_type' => 'private',
			'chat_kind'     => 'private',
			'mention_detected' => true,
			'_dry_run'      => true,
		);

		// [2026-08-16 Johnny Chu] CCG-1 — exact #workflow_slug wins over legacy keyword matching.
		$command_result = class_exists( 'BizCity_Automation_Command_Resolver' )
			? BizCity_Automation_Command_Resolver::resolve(
				$message,
				array( 'user_id' => $user_id, 'is_admin' => current_user_can( 'manage_options' ), 'zone' => 'admin' ),
				array( 'zone' => 'admin' )
			)
			: array( 'matched' => false, 'reason' => 'command_resolver_unavailable' );
		$parsed_command = class_exists( 'BizCity_Automation_Command_Resolver' )
			? BizCity_Automation_Command_Resolver::extract( $message )
			: null;
		if ( $parsed_command && empty( $command_result['matched'] ) ) {
			BizCity_TwinWeb_REST::sse_error( array(
				'code'      => (string) ( $command_result['reason'] ?? 'workflow_command_denied' ),
				'message'   => 'Workflow command không được phép chạy.',
				'hint'      => 'Chọn workflow được cấp quyền trong danh sách # rồi thử lại.',
				'help_code' => 'automation_run_failed',
			) );
			BizCity_TwinWeb_REST::sse_complete( array( 'success' => false, 'surface' => 'prompt_automation' ) );
			return true;
		}

		$wf = is_array( $command_result['workflow'] ?? null ) ? $command_result['workflow'] : array();
		if ( empty( $command_result['matched'] ) ) {
			$preview = BizCity_Automation_Trigger_Matcher::instance()->find_matching_workflows_for_payload( 'zalo_inbound', $payload, array( 'zone' => 'admin' ) );
			$matched = (array) ( $preview['matched'] ?? array() );
			if ( empty( $matched ) ) { return false; }
			$row = $matched[0];
			$wf = is_array( $row['wf'] ?? null ) ? $row['wf'] : array();
		} else {
			$payload['_trigger']      = 'prompt_command';
			$payload['command_slug']  = (string) ( $command_result['slug'] ?? '' );
			$payload['command_args']  = (string) ( $command_result['args'] ?? '' );
			// [2026-08-16 Johnny Chu] CCG-5 — explicit # commands execute real workflow side effects; legacy keyword prompts stay dry-run.
			unset( $payload['_dry_run'] );
		}
		$workflow_id = (int) ( $wf['id'] ?? 0 );
		if ( $workflow_id <= 0 ) { return false; }

		BizCity_TwinWeb_REST::sse_emit_public( 'started', array(
			'trace_id'   => 'tw_auto_' . wp_generate_uuid4(),
			'session_id' => (string) ( $context['thread_id'] ?? '' ),
			'surface'    => 'prompt_automation',
		) );
		BizCity_TwinWeb_REST::sse_emit_public( 'twin_event', array(
			'type'        => 'automation_match',
			'workflow_id' => $workflow_id,
			'workflow'    => (string) ( $wf['name'] ?? $wf['slug'] ?? '' ),
		) );

		$result = BizCity_Automation_Runner::instance()->run_now( $workflow_id, $payload );
		if ( is_wp_error( $result ) ) {
			BizCity_TwinWeb_REST::sse_error( array(
				'code' => $result->get_error_code(),
				'message' => 'Workflow không chạy được.',
				'hint' => 'Kiểm tra lại kịch bản Automation và thử lại.',
				'help_code' => 'automation_run_failed',
			) );
			BizCity_TwinWeb_REST::sse_complete( array( 'success' => false, 'surface' => 'prompt_automation' ) );
			return true;
		}

		$text = self::extract_reply_text( is_array( $result ) ? $result : array() );
		if ( $text === '' ) {
			$text = 'Workflow đã chạy xong nhưng chưa có nội dung trả lời.';
		}
		BizCity_TwinWeb_REST::sse_token( array( 'text' => $text ) );
		BizCity_TwinWeb_REST::sse_complete( array(
			'success' => true,
			'surface' => 'prompt_automation',
			'workflow_id' => $workflow_id,
		) );
		return true;
	}

	private static function extract_reply_text( array $result ): string {
		// [2026-07-22 Johnny Chu] PHASE-3-TWIN-GPT — prefer dry reply text, then LLM outputs, then final_answer fields.
		$ctx = is_array( $result['ctx'] ?? null ) ? $result['ctx'] : array();
		$candidates = array();
		foreach ( $ctx as $key => $value ) {
			if ( ! is_array( $value ) ) { continue; }
			if ( isset( $value['dry'], $value['text'] ) ) { $candidates[] = (string) $value['text']; }
			foreach ( array( 'final_answer_md', 'output', 'message', 'text' ) as $field ) {
				if ( isset( $value[ $field ] ) && is_scalar( $value[ $field ] ) ) {
					$candidates[] = (string) $value[ $field ];
				}
			}
		}
		for ( $i = count( $candidates ) - 1; $i >= 0; $i-- ) {
			$text = trim( $candidates[ $i ] );
			if ( $text !== '' ) { return $text; }
		}
		return '';
	}
}
