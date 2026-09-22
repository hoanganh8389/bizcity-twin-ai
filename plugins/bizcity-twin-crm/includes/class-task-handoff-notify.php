<?php
/**
 * PHASE-0.50 C-05 — optional Zone 2 Zalo Bot ping when a leader assigns work.
 *
 * The badge inside `/gpt/crm/` is the real notification (contract §1.5); this is
 * only an internal nudge on the admin branch. It therefore carries **no customer
 * data at all** — no name, phone, contact id or conversation — just how many new
 * tasks, who assigned them and the due date, plus a link to open the workspace.
 *
 * Off by default. Enable per site with the `bizcity_crm_task_handoff_zalo_bot`
 * option, or per assignee with the filter of the same name.
 *
 * Throttle: at most one message per assignee per 10 minutes (contract §1.5);
 * a throttled ping is dropped, never queued, because the badge already carries
 * the state.
 *
 * [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0.50 C-05 N-06 — a leader can point this at their own
 * automation scenario instead of the plain template: configure `bizcity_crm_task_handoff_zalo_bot_workflow`
 * (`['slug' => …, 'secret' => …]`, e.g. via the CRM Settings screen) for a workflow with a **webhook**
 * trigger. When set, this dispatches through `BizCity_Automation_Trigger_Matcher::dispatch_webhook()`
 * (deferred to cron, same idempotent enqueue every other webhook-triggered workflow uses) with a payload
 * of `chat_id, recipient_user_id, count, kind, leader_name, due_date, link` — still no customer data. A
 * plain `webhook → action.reply_zalo` workflow needs zero extra configuration: `reply_zalo` already reads
 * `chat_id` straight from the trigger payload before falling back to its own `override_chat_id` field.
 * Left unset (the default), this class keeps sending the built-in template directly, unchanged from before.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.50 2026-09-18
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Task_Handoff_Notify', false ) ) {
	return;
}

final class BizCity_CRM_Task_Handoff_Notify {

	const OPTION_ENABLED  = 'bizcity_crm_task_handoff_zalo_bot';
	const OPTION_WORKFLOW = 'bizcity_crm_task_handoff_zalo_bot_workflow';
	const THROTTLE_PREFIX = 'bzc_handoff_notify_';
	const THROTTLE_TTL    = 600; // 10 minutes, contract §1.5.

	public static function register(): void {
		add_action( 'bizcity_crm_task_handoff_assigned', array( __CLASS__, 'on_assigned' ), 10, 4 );
	}

	/**
	 * @param int         $assignee_id Member who received the work.
	 * @param int         $count       How many tasks were created in this handoff.
	 * @param int         $actor_id    Leader who assigned it.
	 * @param string|null $due_date    `Y-m-d` or null.
	 */
	public static function on_assigned( $assignee_id, $count, $actor_id, $due_date = null ): void {
		$assignee_id = (int) $assignee_id;
		$count       = max( 1, (int) $count );
		if ( $assignee_id <= 0 || $assignee_id === (int) $actor_id ) {
			return; // Self-assigned work never pings.
		}
		if ( ! self::enabled( $assignee_id ) || ! self::claim_throttle( $assignee_id ) ) {
			return;
		}
		$chat_id = self::chat_id_for_user( $assignee_id );
		if ( '' === $chat_id ) {
			return;
		}
		$due = is_string( $due_date ) ? $due_date : '';
		$workflow = self::custom_workflow();
		if ( $workflow ) {
			self::dispatch_custom_workflow( $workflow, $chat_id, $assignee_id, $count, (int) $actor_id, $due );
			return;
		}
		if ( ! class_exists( 'BizCity_Gateway_Sender' ) ) { return; }
		$result = BizCity_Gateway_Sender::instance()->send(
			$chat_id,
			self::message( $count, (int) $actor_id, $due ),
			'text',
			array( 'source' => 'crm_task_handoff' )
		);
		self::log( $assignee_id, $count, is_array( $result ) && ! empty( $result['sent'] ) );
	}

	/** `['slug' => string, 'secret' => string]` from N-06, or `null` when unset/incomplete → keep the built-in template. */
	private static function custom_workflow(): ?array {
		$cfg = get_option( self::OPTION_WORKFLOW, array() );
		$slug = is_array( $cfg ) ? trim( (string) ( $cfg['slug'] ?? '' ) ) : '';
		$secret = is_array( $cfg ) ? (string) ( $cfg['secret'] ?? '' ) : '';
		return ( '' !== $slug && '' !== $secret ) ? array( 'slug' => $slug, 'secret' => $secret ) : null;
	}

	/**
	 * N-06 — hand the ping to the leader's own workflow instead of the built-in template. `chat_id` rides
	 * in the payload so a plain `webhook → action.reply_zalo` node needs no extra configuration (that
	 * action already reads `trigger.chat_id` before its own `override_chat_id` field); still counts and
	 * ids only, never a customer name, phone or contact id.
	 */
	private static function dispatch_custom_workflow( array $workflow, string $chat_id, int $assignee_id, int $count, int $actor_id, string $due_date ): void {
		if ( ! class_exists( 'BizCity_Automation_Trigger_Matcher' ) ) { return; }
		$leader = get_userdata( $actor_id );
		$workspace = apply_filters( 'bizcity_crm_task_handoff_workspace_url', home_url( '/gpt/crm/' ) );
		$result = BizCity_Automation_Trigger_Matcher::instance()->dispatch_webhook(
			$workflow['slug'],
			array(
				'chat_id'            => $chat_id,
				'recipient_user_id'  => $assignee_id,
				'count'              => $count,
				'kind'               => 'task_handoff',
				'leader_name'        => $leader ? sanitize_text_field( (string) $leader->display_name ) : '',
				'due_date'           => $due_date,
				'link'               => esc_url_raw( (string) $workspace ),
			),
			$workflow['secret']
		);
		self::log( $assignee_id, $count, ! is_wp_error( $result ) && ! empty( $result['ok'] ), is_wp_error( $result ) ? $result->get_error_code() : '' );
	}

	private static function log( int $assignee_id, int $count, bool $sent, string $error_code = '' ): void {
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) || ! defined( 'BizCity_Channel_File_Logger::CH_ZALO_BOT' ) ) { return; }
		// Task ids and counts only: the customer never appears in an internal channel log.
		$detail = array( 'assignee_user_id' => $assignee_id, 'tasks' => $count, 'sent' => $sent ? 1 : 0 );
		if ( '' !== $error_code ) { $detail['error_code'] = $error_code; }
		BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_ZALO_BOT, BizCity_Channel_File_Logger::LEVEL_INFO, 'crm_task_handoff_notified', 'Internal task handoff ping.', $detail );
	}

	/** Site option, overridable per assignee. Default off — the `/gpt/` badge is the contract notification. */
	private static function enabled( int $assignee_id ): bool {
		$enabled = (bool) get_option( self::OPTION_ENABLED, false );
		return (bool) apply_filters( self::OPTION_ENABLED, $enabled, $assignee_id );
	}

	/** One ping per assignee per THROTTLE_TTL; returns false when a ping was already sent. */
	private static function claim_throttle( int $assignee_id ): bool {
		$key = self::THROTTLE_PREFIX . $assignee_id;
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, time(), self::THROTTLE_TTL );
		return true;
	}

	/** Internal text: counts, leader name and due date only (contract §1.5). */
	private static function message( int $count, int $actor_id, string $due_date ): string {
		$leader = get_userdata( $actor_id );
		$name   = $leader ? sanitize_text_field( (string) $leader->display_name ) : 'trưởng nhóm';
		$text   = sprintf( 'Bạn có %d việc mới từ %s.', $count, $name );
		if ( '' !== $due_date ) {
			$parts = explode( '-', substr( $due_date, 0, 10 ) );
			if ( 3 === count( $parts ) ) {
				$text .= sprintf( ' Hạn %s/%s.', $parts[2], $parts[1] );
			}
		}
		$workspace = apply_filters( 'bizcity_crm_task_handoff_workspace_url', home_url( '/gpt/crm/' ) );
		return $text . ' Mở "Việc được giao" trong ' . esc_url_raw( (string) $workspace );
	}

	/**
	 * The member's linked Zalo Bot chat, or '' when they never linked one.
	 * Only a `linked` row counts; a pending invite is not a delivery target.
	 *
	 * [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.50 R-LM-8 — resolve through the Channel Gateway, which
	 * counts both bind paths (admin bind in the BE, or the owner in `/gpt/` "Kênh của tôi") plus legacy links.
	 * The legacy-only lookup below stays as a fallback for sites without the canonical resolver.
	 */
	private static function chat_id_for_user( int $user_id ): string {
		if ( class_exists( 'BizCity_Channel_User_Linker' ) && method_exists( 'BizCity_Channel_User_Linker', 'zalo_bot_target_for_user' ) ) {
			$target = BizCity_Channel_User_Linker::zalo_bot_target_for_user( $user_id );
			return (string) ( $target['chat_id'] ?? '' );
		}
		if ( ! class_exists( 'BizCity_Zalobot_User_Linker' ) || ! method_exists( 'BizCity_Zalobot_User_Linker', 'get_links_for_wp_user' ) ) {
			return '';
		}
		foreach ( (array) BizCity_Zalobot_User_Linker::get_links_for_wp_user( $user_id ) as $link ) {
			if ( ! is_array( $link ) || 'linked' !== (string) ( $link['status'] ?? '' ) ) {
				continue;
			}
			$bot_id       = (int) ( $link['bot_id'] ?? 0 );
			$zalo_user_id = trim( (string) ( $link['zalo_user_id'] ?? '' ) );
			if ( $bot_id > 0 && '' !== $zalo_user_id ) {
				return 'zalobot_' . $bot_id . '_private_' . $zalo_user_id;
			}
		}
		return '';
	}
}
