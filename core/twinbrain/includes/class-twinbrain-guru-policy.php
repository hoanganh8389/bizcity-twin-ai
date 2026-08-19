<?php
/**
 * TwinBrain Vertical Plugin capability policy gateway.
 *
 * BizCity_TwinBrain_Guru_Policy is a shipped compatibility identifier; the
 * governed product concept is actor/surface access to a Vertical Plugin.
 *
 * This is the single decision boundary for sensitive Vertical Plugin
 * capabilities while notebook/Guru scope fields remain separate.
 *
 * Cache Contract (R-CACHE): group `tbguru`, key `vertical_{blog_id}_{generation}_{guru_id}`,
 * TTL medium; invalidate the group/generation after Guru policy mutation.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_Guru_Policy' ) ) {
	return;
}

final class BizCity_TwinBrain_Guru_Policy {

	const CAP_WOO_BIZOPS = 'woo_bizops';
	const CAP_GURU_CHAT = 'guru_chat';
	const STATUS_ENFORCED = 'enforced';
	const STATUS_PENDING   = 'pending';

	const REASON_GURU_NOT_ASSIGNED = 'guru_not_assigned';
	const REASON_GURU_NOT_FOUND = 'guru_not_found';
	const REASON_BINDING_MISMATCH = 'channel_binding_mismatch';
	const REASON_BINDING_PENDING = 'channel_binding_pending';
	const REASON_IDENTITY_UNLINKED = 'identity_unlinked';
	const REASON_CAPABILITY_MISSING = 'capability_missing';
	const REASON_ZONE_NOT_ALLOWED = 'zone_not_allowed';
	const REASON_VERTICAL_NOT_ALLOWED = 'vertical_not_allowed';
	const REASON_POLICY_PENDING = 'guru_policy_pending';
	const REASON_ROLE_NOT_ALLOWED = 'role_not_allowed';
	const REASON_PLAN_NOT_ALLOWED = 'plan_not_allowed';
	const REASON_RESOURCE_NOT_OWNED = 'resource_not_owned';
	const CACHE_GROUP = 'tbguru';
	const CACHE_TTL = 300;
	const CACHE_VERSION = '3';
	const CACHE_GENERATION_OPTION = 'bizcity_twinbrain_guru_policy_generation';
	const SUPPORTED_VERTICALS = array( 'woo_bizops' );

	public static function normalize_verticals( $raw ): array {
		// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — normalize policy input against the server-owned vertical catalog.
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw = is_array( $decoded ) ? $decoded : array();
		}
		$values = is_array( $raw ) ? array_map( 'sanitize_key', $raw ) : array();
		$values = array_values( array_unique( array_filter( $values ) ) );
		return array_values( array_intersect( $values, self::SUPPORTED_VERTICALS ) );
	}

	/**
	 * Decide whether an actor may use a sensitive capability through a Guru.
	 *
	 * @param array $context {user_id:int, guru_id:int, surface:string, capability:string}.
	 * @return array
	 */
	public static function decide( array $context ): array {
		// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — centralize sensitive capability decisions and fail closed before resolver execution.
		$user_id   = (int) ( $context['user_id'] ?? 0 );
		$guru_id   = (int) ( $context['guru_id'] ?? 0 );
		$surface   = sanitize_key( (string) ( $context['surface'] ?? '' ) );
		$capability = sanitize_key( (string) ( $context['capability'] ?? '' ) );
		$base      = array(
			'allowed'    => false,
			'status'     => self::STATUS_ENFORCED,
			'reason'     => '',
			'capability' => $capability,
			'guru_id'    => $guru_id,
			'user_id'    => $user_id,
			'surface'    => $surface,
			'evidence'   => array(),
		);

		$is_woo = self::CAP_WOO_BIZOPS === $capability;
		if ( $guru_id <= 0 ) {
			$base['reason'] = self::REASON_GURU_NOT_ASSIGNED;
			return $base;
		}
		$policy = self::read_guru_vertical_policy( $guru_id );
		if ( isset( $policy['exists'] ) && ! $policy['exists'] ) {
			$base['reason'] = self::REASON_GURU_NOT_FOUND;
			$base['evidence'] = $policy['evidence'];
			return $base;
		}
		$binding = self::verify_channel_binding( $context, $guru_id );
		if ( ! empty( $binding['checked'] ) && empty( $binding['matched'] ) ) {
			$base['reason'] = (string) ( $binding['reason'] ?? self::REASON_BINDING_MISMATCH );
			$base['evidence'] = array( 'source' => 'channel_binding', 'platform' => $binding['platform'], 'account_id' => $binding['account_id'] );
			return $base;
		}
		if ( $user_id <= 0 ) {
			$base['reason'] = self::REASON_IDENTITY_UNLINKED;
			return $base;
		}
		if ( $is_woo && self::is_customer_zone( $surface ) ) {
			$base['reason'] = self::REASON_ZONE_NOT_ALLOWED;
			return $base;
		}
		if ( $is_woo && ! self::has_woo_capability( $user_id ) ) {
			$base['reason'] = self::REASON_CAPABILITY_MISSING;
			return $base;
		}
		$audience_context = $context;
		$audience_context['required_role'] = sanitize_key( (string) ( $context['required_role'] ?? $policy['min_role'] ?? '' ) );
		$audience_context['required_plan'] = sanitize_key( (string) ( $context['required_plan'] ?? $policy['min_plan'] ?? '' ) );
		$audience = self::verify_audience( $audience_context, $user_id );
		if ( ! empty( $audience['reason'] ) ) {
			$base['reason'] = $audience['reason'];
			$base['evidence'] = array( 'source' => 'audience_policy', 'role' => $audience['role'], 'tier' => $audience['tier'] );
			return $base;
		}
		$resource = self::verify_resource( $context, $user_id );
		if ( ! empty( $resource['reason'] ) ) {
			$base['reason'] = $resource['reason'];
			$base['evidence'] = array( 'source' => 'resource_policy', 'resource_scope' => $resource['scope'] );
			return $base;
		}

		$base['status'] = $policy['status'];
		$base['evidence'] = $policy['evidence'];
		if ( self::STATUS_PENDING === $policy['status'] ) {
			$base['reason'] = self::REASON_POLICY_PENDING;
			return $base;
		}
		if ( $is_woo && ! in_array( self::CAP_WOO_BIZOPS, $policy['allowed_verticals'], true ) ) {
			$base['reason'] = self::REASON_VERTICAL_NOT_ALLOWED;
			return $base;
		}

		if ( ! $is_woo ) {
			$base['evidence']['capability_policy'] = 'generic_guru_access';
		}
		$base['allowed'] = true;
		return $base;
	}

	/**
	 * Convert a deny decision to the standard user-facing error payload.
	 *
	 * @param array $decision Decision returned by decide().
	 * @return array
	 */
	public static function deny_payload( array $decision ): array {
		// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — keep policy denials consistent across REST, stream and automation callers.
		$reason = sanitize_key( (string) ( $decision['reason'] ?? self::REASON_VERTICAL_NOT_ALLOWED ) );
		$messages = array(
			self::REASON_GURU_NOT_ASSIGNED => array( 'Chưa xác định được scope notebook cho yêu cầu Woo BizOps.', 'Chọn scope notebook hợp lệ cho channel hoặc workflow trước khi hỏi dữ liệu WooCommerce.' ),
			self::REASON_GURU_NOT_FOUND => array( 'Scope notebook của yêu cầu không tồn tại trên site hiện tại.', 'Chọn lại scope notebook thuộc site hiện tại rồi thử lại.' ),
			self::REASON_BINDING_MISMATCH => array( 'Scope notebook không khớp channel đang được cấu hình.', 'Dùng scope notebook đang bind với channel hoặc cập nhật binding trong Workspace.' ),
			self::REASON_BINDING_PENDING => array( 'Chưa xác minh được scope notebook của channel.', 'Kiểm tra Channel Gateway và binding Workspace trước khi truy vấn.' ),
			self::REASON_IDENTITY_UNLINKED => array( 'Chưa xác định được tài khoản WordPress liên kết.', 'Liên kết tài khoản channel với người dùng WordPress có quyền WooCommerce.' ),
			self::REASON_CAPABILITY_MISSING => array( 'Tài khoản chưa có quyền xem dữ liệu WooCommerce.', 'Cấp quyền WooCommerce phù hợp cho tài khoản đang liên kết.' ),
			self::REASON_ZONE_NOT_ALLOWED => array( 'Kênh này không được phép truy vấn Woo BizOps.', 'Dùng Twin GPT hoặc kênh quản trị được cấp quyền thay vì kênh CSKH.' ),
			self::REASON_POLICY_PENDING => array( 'Vertical Plugin chưa được cấp quyền Woo BizOps.', 'Mở cấu hình Vertical Plugin và cấp quyền Woo BizOps sau khi policy đã sẵn sàng.' ),
			self::REASON_VERTICAL_NOT_ALLOWED => array( 'Vertical Plugin chưa được cấp quyền Woo BizOps.', 'Mở cấu hình Vertical Plugin và bật capability Woo BizOps trước khi truy vấn.' ),
			self::REASON_ROLE_NOT_ALLOWED => array( 'Vai trò hiện tại chưa được cấp quyền gọi Vertical Plugin.', 'Liên hệ quản trị viên để được cấp vai trò phù hợp.' ),
			self::REASON_PLAN_NOT_ALLOWED => array( 'Gói hiện tại chưa được cấp quyền gọi Vertical Plugin.', 'Nâng cấp gói hoặc liên hệ quản trị viên để được cấp quyền.' ),
			self::REASON_RESOURCE_NOT_OWNED => array( 'Tài nguyên WooCommerce không thuộc tài khoản đang dùng.', 'Chọn tài nguyên thuộc tài khoản đã liên kết hoặc liên hệ quản trị viên.' ),
		);
		$message = $messages[ $reason ] ?? $messages[ self::REASON_VERTICAL_NOT_ALLOWED ];
		if ( class_exists( 'BizCity_Error_Payload' ) ) {
			return BizCity_Error_Payload::make( 'permission_denied', $message[0], $message[1], 'admin_capability_required' );
		}
		return array(
			'success'   => false,
			'_degraded' => true,
			'code'      => 'permission_denied',
			'message'   => $message[0],
			'hint'      => $message[1],
			'help_code' => 'admin_capability_required',
		);
	}

	private static function is_customer_zone( string $surface ): bool {
		return in_array( $surface, array( 'facebook', 'messenger', 'zalo_oa', 'zalo_personal', 'webchat', 'email' ), true );
	}

	private static function has_woo_capability( int $user_id ): bool {
		return function_exists( 'user_can' ) && ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) );
	}

	private static function verify_audience( array $context, int $user_id ): array {
		$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : false;
		$roles = $user && isset( $user->roles ) ? array_map( 'sanitize_key', (array) $user->roles ) : array();
		$role = ! empty( $roles ) ? (string) reset( $roles ) : '';
		$tier = function_exists( 'apply_filters' ) ? sanitize_key( (string) apply_filters( 'bizcity_twinweb_user_tier', 'free', $user_id ) ) : 'free';
		$required_role = sanitize_key( (string) ( $context['required_role'] ?? '' ) );
		$required_plan = sanitize_key( (string) ( $context['required_plan'] ?? '' ) );
		if ( $required_role !== '' ) {
			$rank = array( 'subscriber' => 0, 'contributor' => 1, 'author' => 2, 'editor' => 3, 'administrator' => 4 );
			$best = -1;
			foreach ( $roles as $candidate ) {
				$best = max( $best, isset( $rank[ $candidate ] ) ? $rank[ $candidate ] : -1 );
			}
			if ( ! isset( $rank[ $required_role ] ) || $best < $rank[ $required_role ] ) {
				return array( 'reason' => self::REASON_ROLE_NOT_ALLOWED, 'role' => $role, 'tier' => $tier );
			}
		}
		if ( $required_plan !== '' ) {
			$rank = array( 'free' => 0, 'plus' => 1, 'pro' => 2 );
			if ( ! isset( $rank[ $required_plan ] ) || ( $rank[ $tier ] ?? 0 ) < $rank[ $required_plan ] ) {
				return array( 'reason' => self::REASON_PLAN_NOT_ALLOWED, 'role' => $role, 'tier' => $tier );
			}
		}
		return array( 'reason' => '', 'role' => $role, 'tier' => $tier );
	}

	private static function verify_resource( array $context, int $user_id ): array {
		$resource = isset( $context['target_resource'] ) && is_array( $context['target_resource'] ) ? $context['target_resource'] : array();
		$scope = sanitize_key( (string) ( $resource['scope'] ?? '' ) );
		$owner = (int) ( $resource['owner_user_id'] ?? 0 );
		$blog_id = (int) ( $resource['blog_id'] ?? 0 );
		$current_blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( $blog_id > 0 && $current_blog > 0 && $blog_id !== $current_blog ) {
			return array( 'reason' => self::REASON_RESOURCE_NOT_OWNED, 'scope' => $scope );
		}
		if ( $owner > 0 && $owner !== $user_id && ! user_can( $user_id, 'manage_options' ) ) {
			return array( 'reason' => self::REASON_RESOURCE_NOT_OWNED, 'scope' => $scope );
		}
		return array( 'reason' => '', 'scope' => $scope );
	}

	private static function verify_channel_binding( array $context, int $guru_id ): array {
		$platform = strtoupper( sanitize_key( (string) ( $context['platform'] ?? '' ) ) );
		$account_id = sanitize_text_field( (string) ( $context['account_id'] ?? '' ) );
		if ( $platform === '' || $account_id === '' ) {
			return array( 'checked' => false, 'matched' => true, 'platform' => $platform, 'account_id' => $account_id );
		}
		if ( ! class_exists( 'BizCity_Channel_Binding' ) || ! is_callable( array( 'BizCity_Channel_Binding', 'resolve' ) ) ) {
			return array( 'checked' => true, 'matched' => false, 'reason' => self::REASON_BINDING_PENDING, 'platform' => $platform, 'account_id' => $account_id );
		}
		$binding = BizCity_Channel_Binding::resolve( $platform, $account_id );
		$matched = is_array( $binding ) && (int) ( $binding['character_id'] ?? 0 ) === $guru_id && (int) ( $binding['status'] ?? 1 ) === 1;
		return array( 'checked' => true, 'matched' => $matched, 'reason' => self::REASON_BINDING_MISMATCH, 'platform' => $platform, 'account_id' => $account_id );
	}

	private static function read_guru_vertical_policy( int $guru_id ): array {
		global $wpdb;
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$generation = (int) get_option( self::CACHE_GENERATION_OPTION . '_' . $blog_id, 1 );
		$cache_key = 'vertical_' . self::CACHE_VERSION . '_' . $blog_id . '_' . $generation . '_' . $guru_id;
		$cached = class_exists( 'BizCity_Cache' )
			? BizCity_Cache::get( self::CACHE_GROUP, $cache_key )
			: wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) && isset( $cached['status'], $cached['allowed_verticals'] ) ) {
			return $cached;
		}
		$table = $wpdb->prefix . 'bizcity_characters';
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( $table ) ) {
			$result = array( 'status' => self::STATUS_PENDING, 'allowed_verticals' => array(), 'evidence' => array( 'source' => 'characters_table_unavailable' ) );
			self::cache_policy( $cache_key, $result );
			return $result;
		}
		if ( ! function_exists( 'bizcity_column_exists' ) || ! bizcity_column_exists( $table, 'allowed_verticals' ) ) {
			$result = array( 'status' => self::STATUS_PENDING, 'exists' => true, 'allowed_verticals' => array(), 'evidence' => array( 'source' => 'allowed_verticals_column_pending' ) );
			self::cache_policy( $cache_key, $result );
			return $result;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, allowed_verticals, min_role, min_plan FROM ' . $table . ' WHERE id=%d LIMIT 1', $guru_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			$result = array( 'status' => self::STATUS_ENFORCED, 'exists' => false, 'allowed_verticals' => array(), 'evidence' => array( 'source' => 'guru_not_found_current_tenant' ) );
			self::cache_policy( $cache_key, $result );
			return $result;
		}
		$raw = $row['allowed_verticals'] ?? '';
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : array();
		$allowed = self::normalize_verticals( $decoded );
		$result = array(
			'status' => self::STATUS_ENFORCED,
			'exists' => true,
			'allowed_verticals' => $allowed,
			'min_role' => sanitize_key( (string) ( $row['min_role'] ?? '' ) ),
			'min_plan' => sanitize_key( (string) ( $row['min_plan'] ?? '' ) ),
			'evidence' => array( 'source' => 'bizcity_characters.allowed_verticals', 'configured' => ! empty( $allowed ) ),
		);
		self::cache_policy( $cache_key, $result );
		return $result;
	}

	private static function cache_policy( string $cache_key, array $value ): void {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $value, self::CACHE_TTL );
			return;
		}
		wp_cache_set( $cache_key, $value, self::CACHE_GROUP, self::CACHE_TTL );
	}

	public static function invalidate( int $guru_id = 0 ): void {
		// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — provide a mutation hook for future canonical Guru policy writers.
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$option = self::CACHE_GENERATION_OPTION . '_' . $blog_id;
		update_option( $option, (int) get_option( $option, 1 ) + 1, false );
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) && class_exists( 'BizCity_Cache' ) ) {
	BizCity_Cache_Registry::register( self::CACHE_GROUP, 'core.twinbrain.guru-policy', array(
		'vertical_{blog_id}_{generation}_{guru_id}' => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Guru vertical capability policy' ),
	) );
}
