<?php
/**
 * Action: Set pending_intent for next-turn resume.
 *
 * Khi block này chạy, lượt tin nhắn TIẾP THEO của cùng chat_id sẽ được matcher
 * route THẲNG vào `workflow_id` chỉ định (preempts keyword + fallback).
 * Pending TTL mặc định 15' — nếu user không reply trong window, slot tự hết.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      AUTOMATION BE-7.C (2026-05-30)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Set_Pending_Intent extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'action.set_pending_intent'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Đặt slot chờ lượt sau',
			'short'    => 'set_pending_intent',
			'category' => 'state',
			'color'    => '#0891b2',
			'icon'     => 'clock',
			'defaults' => array(
				'label'        => 'set_pending_intent',
				'intent'       => 'awaiting_image_purpose',
				'workflow_id'  => 0,
				'workflow_slug'=> '',
				'ttl_min'      => 15,
				'slots_json'   => '{}',
			),
			'fields' => array(
				array( 'name' => 'label',         'label' => 'Tên hiển thị',           'type' => 'text' ),
				array( 'name' => 'intent',        'label' => 'Intent ID',              'type' => 'text' ),
				array( 'name' => 'workflow_id',   'label' => 'Workflow resume (id)',   'type' => 'number',
					'hint' => '0 = self (workflow hiện tại). >0 = id workflow khác.' ),
				array( 'name' => 'workflow_slug', 'label' => 'Workflow resume (slug)', 'type' => 'text',
					'hint' => 'Ưu tiên hơn id nếu khác rỗng.' ),
				array( 'name' => 'ttl_min',       'label' => 'TTL (phút)',             'type' => 'number' ),
				array( 'name' => 'slots_json',    'label' => 'Slots (JSON)',           'type' => 'textarea',
					'hint' => 'JSON object — value sẽ resolve {{token}}.' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		$chat_id = (string) ( $ctx['trigger']['chat_id'] ?? '' );
		if ( $chat_id === '' ) {
			return new WP_Error( 'no_chat_id', 'set_pending_intent: trigger.chat_id rỗng.' );
		}

		// Resolve workflow target.
		$wf_id = (int) ( $data['workflow_id'] ?? 0 );
		$slug  = trim( (string) ( $data['workflow_slug'] ?? '' ) );
		if ( $slug !== '' && class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			$wf = BizCity_Automation_Repo_Workflows::find_by_slug( $slug );
			if ( $wf ) { $wf_id = (int) $wf['id']; }
			// [2026-07-21 Johnny Chu] PHASE-SEEDREAM-45-FIX — template slug must resolve to current user's deterministic /gpt workflow copy.
			if ( ! $wf && class_exists( 'BizCity_Automation_Repo_Templates' ) ) {
				$tpl = BizCity_Automation_Repo_Templates::find_by_slug( $slug );
				if ( $tpl ) {
					$user_id = (int) ( $ctx['_owner_user_id'] ?? $ctx['trigger']['_owner_user_id'] ?? $ctx['trigger']['wp_user_id'] ?? get_current_user_id() );
					// [2026-07-21 Johnny Chu] PHASE-SEEDREAM-45-FIX — capture workflow owner is the safest fallback for customer template copies.
					if ( $user_id <= 0 && ! empty( $ctx['_workflow_id'] ) ) {
						$current_wf = BizCity_Automation_Repo_Workflows::find( (int) $ctx['_workflow_id'] );
						$user_id = (int) ( $current_wf['created_by'] ?? 0 );
					}
					$seed = (string) ( $tpl['template_uuid'] ?? '' );
					if ( $seed === '' ) { $seed = (string) ( $tpl['slug'] ?? ( $tpl['id'] ?? '' ) ); }
					$copy_slug = 'twg-u' . $user_id . '-' . substr( md5( $seed ), 0, 12 );
					$copy = BizCity_Automation_Repo_Workflows::find_by_slug( $copy_slug );
					if ( $copy && (int) ( $copy['created_by'] ?? 0 ) === $user_id ) {
						$wf_id = (int) $copy['id'];
					}
				}
			}
		}
		if ( $wf_id === 0 ) {
			$wf_id = (int) ( $ctx['_workflow_id'] ?? 0 ); // self.
		}
		if ( $wf_id === 0 ) {
			return new WP_Error( 'no_workflow', 'set_pending_intent: không xác định được workflow_id resume.' );
		}

		// Slots JSON parse + resolve tokens.
		$slots = array();
		$raw   = trim( (string) ( $data['slots_json'] ?? '{}' ) );
		if ( $raw !== '' && $raw !== '{}' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $k => $v ) {
					$slots[ $k ] = is_string( $v ) ? $this->resolve( $v, $ctx ) : $v;
				}
			}
		}

		$intent = (string) $this->resolve( $data['intent'] ?? '', $ctx );
		$ttl    = max( 1, (int) ( $data['ttl_min'] ?? 15 ) ) * MINUTE_IN_SECONDS;

		BizCity_Automation_Pending_State::patch( $chat_id, array(
			'intent'      => $intent,
			'workflow_id' => $wf_id,
			'slots'       => $slots,
		), $ttl );

		return array( 'intent' => $intent, 'workflow_id' => $wf_id, 'ttl' => $ttl );
	}
}
