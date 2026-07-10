<?php
/**
 * Adapter: reminder_personal (TwinBrain master tool — user-level reminder
 * with HIL confirm flow, see PHASE-SCHEDULER-HIL-CONFIRM.md).
 *
 * @package Bizcity_Twin_AI
 * @since   2026-06-03 (SCH-NC W2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once __DIR__ . '/class-scheduler-adapter-base.php';

if ( class_exists( 'BizCity_Scheduler_Adapter_Reminder_Personal' ) ) {
	return;
}

final class BizCity_Scheduler_Adapter_Reminder_Personal extends BizCity_Scheduler_Adapter_Base {

	public function event_type() {
		return 'reminder_personal';
	}

	public function label() {
		return 'Nhắc cá nhân';
	}

	public function metadata_schema() {
		// inbound{} là MUST cho HIL flow + reply-back; rest tuỳ chọn.
		return [
			'inbound'              => [ 'type' => 'array',  'required' => true ],
			'reminder_text'        => [ 'type' => 'string', 'required' => false ],
			'reminder_repeat'      => [ 'type' => 'string', 'required' => false ],
			'reminder_advance_min' => [ 'type' => 'int',    'required' => false ],
		];
	}

	/**
	 * Validate: inbound{} là tùy chọn nếu user có bizcity_default_notify_channel.
	 * Nếu inbound{} có mặt thì platform phải nằm trong whitelist.
	 *
	 * @param array $payload
	 * @return true|WP_Error
	 */
	public function validate( array $payload ) {
		// [2026-06-03 Johnny Chu] SCH-NC W2 — base check trước, sau đó kiểm
		// chi tiết inbound{platform, chat_id} cho HIL routing.
		// [2026-06-15 Johnny Chu] R-UNIFY — inbound optional khi user có default notify
		// channel; thêm ZALO_BOT vào whitelist cho admin reminder via Zalo Bot.
		$base = parent::validate( $payload );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$meta    = $this->meta_array( $payload['metadata'] ?? [] );
		$inbound = isset( $meta['inbound'] ) && is_array( $meta['inbound'] ) ? $meta['inbound'] : [];

		// inbound required ONLY khi không có notify.target và user không có default channel.
		if ( empty( $inbound['platform'] ) || empty( $inbound['chat_id'] ) ) {
			// Cho phép bỏ qua inbound nếu có notify.target hoặc user có default channel.
			$has_notify_target = ! empty( $meta['notify']['target']['platform'] )
				&& ! empty( $meta['notify']['target']['chat_id'] );
			$user_id           = isset( $payload['user_id'] ) ? (int) $payload['user_id'] : get_current_user_id();
			$has_user_default  = false;
			if ( $user_id > 0 ) {
				// [2026-06-22 Johnny Chu] R-PERF — route via BizCity_User_Meta_Cache to avoid WP meta prime
				$pref            = class_exists( 'BizCity_User_Meta_Cache' )
					? BizCity_User_Meta_Cache::get( $user_id, 'bizcity_default_notify_channel', array() )
					: get_user_meta( $user_id, 'bizcity_default_notify_channel', true );
				$has_user_default = is_array( $pref )
					&& ! empty( $pref['platform'] )
					&& ! empty( $pref['chat_id'] );
			}
			$has_site_default = false;
			if ( ! $has_user_default ) {
				$global           = get_option( 'bizcity_default_notify_channel', [] );
				$has_site_default = is_array( $global )
					&& ! empty( $global['platform'] )
					&& ! empty( $global['chat_id'] );
			}
			if ( ! $has_notify_target && ! $has_user_default && ! $has_site_default ) {
				return new WP_Error(
					'sched_adapter_inbound_required',
					'[reminder_personal] Cần metadata.inbound{platform,chat_id} hoặc cấu hình kênh thông báo mặc định.'
				);
			}
			return true; // target sẽ được resolve_target() xử lý khi fire.
		}

		// inbound có mặt — kiểm platform.
		// [2026-06-15 Johnny Chu] R-UNIFY — thêm ZALO_BOT cho admin reminder via Zalo Bot.
		$allowed_platforms = [ 'ZALO', 'ZALO_BOT', 'FACEBOOK', 'TELEGRAM', 'WEBCHAT', 'TWINBRAIN', 'ADMIN' ];
		if ( ! in_array( strtoupper( (string) $inbound['platform'] ), $allowed_platforms, true ) ) {
			return new WP_Error(
				'sched_adapter_inbound_platform',
				sprintf( '[reminder_personal] inbound.platform "%s" không hợp lệ.', $inbound['platform'] )
			);
		}
		return true;
	}
}
