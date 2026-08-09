<?php
/**
 * Concrete channel policy adapters for the canonical TwinBrain boundary.
 *
 * These classes only pin channel identity policy. Runtime execution remains in
 * BizCity_TwinBrain_Channel_Adapter::handle().
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-02
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_TwinBrain_Channel_Adapter' ) ) {
	return;
}

if ( ! class_exists( 'BizCity_TwinBrain_Adapter_Messenger' ) ) {
	final class BizCity_TwinBrain_Adapter_Messenger extends BizCity_TwinBrain_Channel_Adapter {
		public function normalize_envelope( $raw_payload ): array {
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — enforce Messenger guest identity policy at the channel boundary.
			$payload = is_array( $raw_payload ) ? $raw_payload : array();
			$payload['platform'] = 'FB_MESS';
			$payload['channel_class'] = 'guest_channel';
			$payload['identity_is_stable'] = true;
			return parent::normalize_envelope( $payload );
		}
	}
}

if ( ! class_exists( 'BizCity_TwinBrain_Adapter_ZaloOA' ) ) {
	final class BizCity_TwinBrain_Adapter_ZaloOA extends BizCity_TwinBrain_Channel_Adapter {
		public function normalize_envelope( $raw_payload ): array {
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — enforce Zalo OA guest identity policy at the channel boundary.
			$payload = is_array( $raw_payload ) ? $raw_payload : array();
			$payload['platform'] = 'ZALO_OA';
			$payload['channel_class'] = 'guest_channel';
			$payload['identity_is_stable'] = true;
			return parent::normalize_envelope( $payload );
		}
	}
}

if ( ! class_exists( 'BizCity_TwinBrain_Adapter_WebChat' ) ) {
	final class BizCity_TwinBrain_Adapter_WebChat extends BizCity_TwinBrain_Channel_Adapter {
		public function normalize_envelope( $raw_payload ): array {
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — keep consumer WebChat on guest identity policy.
			$payload = is_array( $raw_payload ) ? $raw_payload : array();
			$payload['platform'] = 'WEBCHAT';
			$payload['channel_class'] = 'guest_channel';
			$payload['identity_is_stable'] = ! array_key_exists( 'identity_is_stable', $payload ) || ! empty( $payload['identity_is_stable'] );
			return parent::normalize_envelope( $payload );
		}
	}
}

if ( ! class_exists( 'BizCity_TwinBrain_Adapter_ZaloBot' ) ) {
	final class BizCity_TwinBrain_Adapter_ZaloBot extends BizCity_TwinBrain_Channel_Adapter {
		public function normalize_envelope( $raw_payload ): array {
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — enforce Zalo Bot linker-backed user identity policy.
			$payload = is_array( $raw_payload ) ? $raw_payload : array();
			$payload['platform'] = 'ZALO_BOT';
			$payload['channel_class'] = 'user_bound';
			$payload['identity_guest_bind'] = false;
			$payload['identity_is_stable'] = true;
			return parent::normalize_envelope( $payload );
		}
	}
}

if ( ! class_exists( 'BizCity_TwinBrain_Adapter_Telegram' ) ) {
	final class BizCity_TwinBrain_Adapter_Telegram extends BizCity_TwinBrain_Channel_Adapter {
		public function normalize_envelope( $raw_payload ): array {
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — enforce Telegram user-bound identity policy.
			$payload = is_array( $raw_payload ) ? $raw_payload : array();
			$payload['platform'] = 'TELEGRAM';
			$payload['channel_class'] = 'user_bound';
			$payload['identity_guest_bind'] = false;
			$payload['identity_is_stable'] = true;
			return parent::normalize_envelope( $payload );
		}
	}
}
