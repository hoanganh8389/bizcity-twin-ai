<?php
/**
 * CRM framework boundary — canonical channel zone policy (PHASE-0.60 C4).
 *
 * @package BizCity_Twin_CRM
 */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_CRM_Zone_Registry', false ) ) {
	final class BizCity_CRM_Zone_Registry {
		private static $zones = array(
			'facebook' => array( 'zone' => 'customer', 'access_mode' => 'membership', 'crm_mode' => 'customer_inbox', 'context_policy' => 'conversation_summary', 'ai_policy' => 'customer_care' ),
			'messenger' => array( 'zone' => 'customer', 'access_mode' => 'membership', 'crm_mode' => 'customer_inbox', 'context_policy' => 'conversation_summary', 'ai_policy' => 'customer_care' ),
			'zalo_oa' => array( 'zone' => 'customer', 'access_mode' => 'membership', 'crm_mode' => 'customer_inbox', 'context_policy' => 'conversation_summary', 'ai_policy' => 'customer_care' ),
			'zalo_personal' => array( 'zone' => 'customer', 'access_mode' => 'owner_only', 'crm_mode' => 'customer_inbox', 'context_policy' => 'conversation_summary', 'ai_policy' => 'customer_care', 'transport_default' => 'managed_1api' ),
			'webchat' => array( 'zone' => 'customer', 'access_mode' => 'membership', 'crm_mode' => 'customer_inbox', 'context_policy' => 'conversation_summary', 'ai_policy' => 'customer_care' ),
			'email' => array( 'zone' => 'customer', 'access_mode' => 'membership', 'crm_mode' => 'customer_inbox', 'context_policy' => 'conversation_summary', 'ai_policy' => 'customer_care' ),
			'instagram' => array( 'zone' => 'customer', 'access_mode' => 'membership', 'crm_mode' => 'customer_inbox', 'context_policy' => 'conversation_summary', 'ai_policy' => 'customer_care' ),
			'whatsapp' => array( 'zone' => 'customer', 'access_mode' => 'membership', 'crm_mode' => 'customer_inbox', 'context_policy' => 'conversation_summary', 'ai_policy' => 'customer_care' ),
			'zalo_bot' => array( 'zone' => 'admin', 'access_mode' => 'linked_user', 'crm_mode' => 'disabled', 'context_policy' => 'admin_command', 'ai_policy' => 'admin_command' ),
			'telegram' => array( 'zone' => 'admin', 'access_mode' => 'linked_user', 'crm_mode' => 'disabled', 'context_policy' => 'admin_command', 'ai_policy' => 'admin_command' ),
			'twinchat_be' => array( 'zone' => 'admin', 'access_mode' => 'linked_user', 'crm_mode' => 'disabled', 'context_policy' => 'admin_command', 'ai_policy' => 'admin_command' ),
		);

		public static function for_channel( string $channel ): array {
			$channel = sanitize_key( $channel );
			return self::$zones[ $channel ] ?? array( 'zone' => 'customer', 'access_mode' => 'membership', 'crm_mode' => 'customer_inbox', 'context_policy' => 'conversation_summary', 'ai_policy' => 'customer_care' );
		}

		public static function is_customer( string $channel ): bool { return 'customer' === self::for_channel( $channel )['zone']; }
		public static function is_admin( string $channel ): bool { return 'admin' === self::for_channel( $channel )['zone']; }
	}
}
