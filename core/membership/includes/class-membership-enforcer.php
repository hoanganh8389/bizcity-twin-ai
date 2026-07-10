<?php
/**
 * Bizcity Twin AI — Membership_Enforcer
 *
 * PHASE-MEMBERSHIP M2.
 *
 * Injects per-user plan limits into the EXISTING cost-guard filters so the
 * KG Cost Guard (and any module reading these filters) honours each WP user's
 * membership plan — without modifying KG Cost Guard itself.
 *
 * Filters hooked (already referenced by BizCity_KG_Cost_Guard):
 *   - bizcity_kg_quota_per_user  → per-user daily passage quota.
 *   - bizcity_kg_user_is_exempt  → exempt 'plus' members with hub bypass.
 *
 * Precedence: membership runs on the CLIENT and is normally the only hook.
 * We use priority 20 so we win over any default, but we never RAISE a quota
 * above what the merged entitlement allows (safe-by-min).
 *
 * PHP 7.4-safe.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-06-04
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Membership_Enforcer {

	/** @var bool */
	private static $booted = false;

	/**
	 * WP administrators (manage_options) bypass all quota enforcement.
	 * Site owner / admin should never be blocked by end-user plan limits.
	 *
	 * [2026-06-09 Johnny Chu] HOTFIX — admin exemption.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	private static function is_exempt_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		return user_can( $user_id, 'manage_options' );
	}

	public static function init() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		$self = new self();
		add_filter( 'bizcity_kg_quota_per_user',            array( $self, 'kg_quota' ), 20 );
		add_filter( 'bizcity_kg_user_is_exempt',            array( $self, 'kg_exempt' ), 20, 2 );
		// [2026-06-05 Johnny Chu] PHASE-MEMBERSHIP BE-3B — chat message quota gate
		add_filter( 'bizcity_twinchat_can_send_message',    array( $self, 'chat_can_send' ), 20, 2 );
		add_action( 'bizcity_twinchat_message_sent',        array( $self, 'chat_incr' ), 20 );
	}

	/**
	 * Override the per-user KG passage quota with the member's effective limit.
	 *
	 * Safe-by-min: only lowers (or keeps) the incoming default — never raises
	 * above the merged entitlement ceiling.
	 *
	 * @param int $default
	 * @return int
	 */
	public function kg_quota( $default ) {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return $default;
		}
		// [2026-06-09 Johnny Chu] HOTFIX — admin bypass: site owners skip plan quota.
		if ( self::is_exempt_user( $uid ) ) {
			return $default;
		}
		if ( ! class_exists( 'BizCity_Membership_Entitlement' ) ) {
			return $default;
		}
		$limit = BizCity_Membership_Entitlement::instance()->limit( $uid, 'kg_passages_per_day' );
		if ( $limit <= 0 ) {
			return $default;
		}
		// Respect the lower of (incoming default, plan limit) when default is positive.
		$default = (int) $default;
		if ( $default > 0 ) {
			return min( $default, $limit );
		}
		return $limit;
	}

	/**
	 * Exempt 'plus' members (with hub bypass) from the hard passage quota.
	 * They still obey the site-wide daily USD cap.
	 *
	 * @param bool $default
	 * @param int  $user_id
	 * @return bool
	 */
	public function kg_exempt( $default, $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Membership_Entitlement' ) ) {
			return $default;
		}
		// [2026-06-09 Johnny Chu] HOTFIX — admin bypass.
		if ( self::is_exempt_user( $user_id ) ) {
			return true;
		}
		$ent = BizCity_Membership_Entitlement::instance()->for_user( $user_id );
		if ( ! empty( $ent['bypass'] ) && isset( $ent['user_plan'] ) && $ent['user_plan'] === 'plus' ) {
			return true;
		}
		return $default;
	}

	/**
	 * Gate: can the user send a TwinChat message?
	 * Returns true when quota OK, WP_Error when limit reached.
	 *
	 * @param bool|WP_Error $default
	 * @param int           $user_id
	 * @return bool|WP_Error
	 */
	public function chat_can_send( $default, $user_id ) {
		// [2026-06-05 Johnny Chu] PHASE-MEMBERSHIP BE-3B — chat gate
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Membership_Usage' ) ) {
			return $default;
		}
		// [2026-06-09 Johnny Chu] HOTFIX — admin bypass: site owner không bị giới hạn chat.
		if ( self::is_exempt_user( $user_id ) ) {
			return $default;
		}
		$usage = BizCity_Membership_Usage::instance();
		if ( ! $usage->can( $user_id, 'chat_msgs_per_day' ) ) {
			$plan      = BizCity_Membership_Manager::instance()->plan_for_user( $user_id );
			// [2026-06-09 Johnny Chu] PHASE-D D-BE-QUOTA — R-MEMBERSHIP-QUOTA-ERROR-UX:
			// error_data phải đủ 8 fields để FE render QuotaErrorBanner đúng chuẩn.
			$limit     = $usage->limit_for( $user_id, 'chat_msgs_per_day' );
			$used      = $usage->used( $user_id, 'chat_msgs_per_day' );
			$registry  = class_exists( 'BizCity_Membership_Plan_Registry' )
				? BizCity_Membership_Plan_Registry::instance()
				: null;
			$plan_obj  = $registry ? $registry->get( $plan ) : array();
			$plan_label = isset( $plan_obj['label'] ) ? (string) $plan_obj['label'] : ucfirst( (string) $plan );
			return new WP_Error(
				'quota_exceeded',
				'Bạn đã dùng hết lượt chat hôm nay.',
				array(
					'feature'    => 'chat_msgs_per_day',
					'plan'       => $plan,
					'plan_label' => $plan_label,
					'limit'      => $limit,
					'used'       => $used,
					'resets_at'  => 'ngày mai 00:00 UTC',
					'hint'       => 'Nâng cấp gói để nhắn tin không bị giới hạn.',
					'help_code'  => 'membership_quota_exceeded',
				)
			);
		}
		return $default;
	}

	/**
	 * Increment the chat_msgs_per_day counter after a message is sent.
	 *
	 * @param int $user_id
	 * @return void
	 */
	public function chat_incr( $user_id ) {
		// [2026-06-05 Johnny Chu] PHASE-MEMBERSHIP BE-3B — increment chat usage
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Membership_Usage' ) ) {
			return;
		}
		// [2026-06-09 Johnny Chu] HOTFIX — admin bypass: không đếm usage cho admin.
		if ( self::is_exempt_user( $user_id ) ) {
			return;
		}
		BizCity_Membership_Usage::instance()->incr( $user_id, 'chat_msgs_per_day' );
	}
}
