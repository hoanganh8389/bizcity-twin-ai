<?php
/**
 * Agent mode access — contract agent-mode-access@1.0.0 (PHASE-0.87 CL-13, R-TAA-14).
 *
 * "Which vertical brain modes may the agent use FOR this user" has one answer:
 * user_can( $user_id, 'bizcity_agent_mode_<mode>' ), a meta capability mapped here by the mode's `access.mode`:
 *   grantable → primitive cap 'bizcity_agent_access_<mode>' (role or per-user; a per-user explicit false wins).
 *   delegated → the data owner answers through 'bizcity_agent_mode_delegate' (no answer ⇒ denied, AMA-3).
 * Site admins always pass. Storage = WordPress capabilities only (AMA-2): no table, no option besides the seed marker.
 *
 * The cell never evaluates capabilities: the site projects modes_for_user() into the bundle (owner_agent.principal.modes).
 *
 * // @axis twin-agent-axis@1 block owner_agent
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Agent_Mode_Access', false ) ) {
	return;
}

final class BizCity_Agent_Mode_Access {

	const META_PREFIX     = 'bizcity_agent_mode_';
	const PRIM_PREFIX     = 'bizcity_agent_access_';
	const SEEDED_OPTION   = 'bizcity_agent_modes_seeded';
	const SIGNATURE       = '0.87.1';
	const FILTER_REGISTER = 'bizcity_agent_modes_register';
	const FILTER_DELEGATE = 'bizcity_agent_mode_delegate';
	/** Fired when a role, a per-user grant or a delegated answer may have changed (the bundle re-sync listens). */
	const HOOK_CHANGED    = 'bizcity_agent_modes_changed';

	/** Roles that use TwinChat / Twin GPT get the own-data modes by default (contract §3). */
	const OWN_DATA_ROLES = array( 'editor', 'author', 'contributor' );

	private static $registered = false;
	/** @var array<string,array>|null */
	private static $modes = null;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
		add_action( 'init', array( __CLASS__, 'ensure_seeded' ), 25 );
		foreach ( array( 'set_user_role', 'add_user_role', 'remove_user_role' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'changed' ), 10, 0 );
		}
		add_action( 'updated_user_meta', array( __CLASS__, 'on_user_meta' ), 10, 3 );
	}

	/** v1 modes (contract §2). `available` = the linked source exists; a mode without it is not registered (fail closed). */
	public static function defaults(): array {
		$own = array( 'mode' => 'grantable', 'default_roles' => self::OWN_DATA_ROLES );
		$biz = array( 'mode' => 'delegated' );
		return array(
			'notebook'      => array( 'label' => 'Sổ ghi chú', 'packs' => array( 'owner_knowledge', 'notebook_meta' ), 'tools' => array( 'notebook_search', 'notebook_remember' ), 'scope' => 'own', 'access' => $own, 'available' => true ),
			'astro_self'    => array( 'label' => 'Chiêm tinh của tôi', 'packs' => array( 'astro_self' ), 'tools' => array( 'astro_self' ), 'scope' => 'own', 'access' => $own, 'available' => true ),
			'sales'         => array( 'label' => 'Doanh số', 'packs' => array( 'sales' ), 'tools' => array( 'biz_sales' ), 'scope' => 'site', 'access' => $biz, 'available' => class_exists( 'WooCommerce' ) ),
			'orders'        => array( 'label' => 'Đơn hàng', 'packs' => array( 'orders' ), 'tools' => array( 'biz_orders' ), 'scope' => 'site', 'access' => $biz, 'available' => class_exists( 'WooCommerce' ) ),
			'customers'     => array( 'label' => 'Khách hàng', 'packs' => array( 'customers' ), 'tools' => array( 'biz_customer_find' ), 'scope' => 'site', 'access' => $biz, 'available' => class_exists( 'BizCity_CRM_Staff_Policy' ) ),
			'stock'         => array( 'label' => 'Tồn kho', 'packs' => array( 'stock' ), 'tools' => array( 'biz_stock' ), 'scope' => 'site', 'access' => $biz, 'available' => class_exists( 'WooCommerce' ) ),
			'deep_analysis' => array( 'label' => 'Phân tích sâu', 'packs' => array(), 'tools' => array( 'request_deep_analysis' ), 'scope' => 'site', 'access' => array( 'mode' => 'grantable', 'default_roles' => array() ), 'available' => true ),
			// [2026-10-01 Claude Opus 5.5] PHASE-0.88 Q88-3 — MCP-only modes (booking.*, automation.run in core/mcp): administrators by default, others must be granted.
			'booking'       => array( 'label' => 'Lịch hẹn', 'packs' => array(), 'tools' => array(), 'scope' => 'site', 'access' => array( 'mode' => 'grantable', 'default_roles' => array() ), 'available' => class_exists( 'BizCity_Scheduler_Tools' ) ),
			'automation'    => array( 'label' => 'Tự động hoá', 'packs' => array(), 'tools' => array(), 'scope' => 'site', 'access' => array( 'mode' => 'grantable', 'default_roles' => array() ), 'available' => class_exists( 'BizCity_Automation_Repo_Workflows' ) ),
		);
	}

	/** The one registry (AMA-1). @return array<string,array> */
	public static function modes(): array {
		if ( null !== self::$modes ) {
			return self::$modes;
		}
		$raw = function_exists( 'apply_filters' ) ? apply_filters( self::FILTER_REGISTER, self::defaults() ) : self::defaults();
		$out = array();
		foreach ( (array) $raw as $id => $m ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $m ) || empty( $m['available'] ) ) {
				continue;
			}
			$access = (array) ( $m['access'] ?? array() );
			if ( ! in_array( $access['mode'] ?? '', array( 'grantable', 'delegated' ), true ) ) {
				continue;
			}
			$m['id']     = $id;
			$m['access'] = $access;
			$out[ $id ]  = $m;
		}
		return self::$modes = $out;
	}

	/** Test seam / after an extension registers late. */
	public static function reset(): void {
		self::$modes = null;
	}

	/**
	 * @param string[] $caps
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( ! is_string( $cap ) || 0 !== strpos( $cap, self::META_PREFIX ) ) {
			return $caps;
		}
		$user_id = (int) $user_id;
		$id      = substr( $cap, strlen( self::META_PREFIX ) );
		$mode    = self::modes()[ $id ] ?? null;
		if ( null === $mode || $user_id <= 0 ) {
			return array( 'do_not_allow' );
		}
		if ( self::is_site_admin( $user_id ) ) {
			return array( 'exist' );
		}
		if ( 'delegated' === $mode['access']['mode'] ) {
			return apply_filters( self::FILTER_DELEGATE, false, $id, $user_id ) ? array( 'exist' ) : array( 'do_not_allow' );
		}
		return array( self::PRIM_PREFIX . $id );
	}

	public static function can( string $mode, int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, self::META_PREFIX . sanitize_key( $mode ) );
	}

	/** Allowed mode ids for a user, registry order (contract §6: what the bundle projects). @return string[] */
	public static function modes_for_user( int $user_id ): array {
		$out = array();
		foreach ( array_keys( self::modes() ) as $id ) {
			if ( self::can( $id, $user_id ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Why each mode is allowed or not (UI §7) — source is one of admin, role, user, delegated, denied.
	 *
	 * @return array<string,array{allowed:bool,source:string,label:string}>
	 */
	public static function explain( int $user_id ): array {
		$out   = array();
		$admin = $user_id > 0 && self::is_site_admin( $user_id );
		$user  = $user_id > 0 && function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
		foreach ( self::modes() as $id => $m ) {
			$allowed = self::can( $id, $user_id );
			if ( $admin ) {
				$source = 'admin';
			} elseif ( 'delegated' === $m['access']['mode'] ) {
				$source = $allowed ? 'delegated' : 'denied';
			} elseif ( $user && isset( $user->caps[ self::PRIM_PREFIX . $id ] ) ) {
				$source = $user->caps[ self::PRIM_PREFIX . $id ] ? 'user' : 'denied';
			} else {
				$source = $allowed ? 'role' : 'denied';
			}
			$out[ $id ] = array( 'allowed' => $allowed, 'source' => $source, 'label' => (string) $m['label'] );
		}
		return $out;
	}

	/** Stable pseudonymous principal id for the cell (owner-agent-block §4): sha256(blog_id|user_id|site_salt). */
	public static function user_hash( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';
		return hash( 'sha256', $blog . '|' . $user_id . '|' . $salt );
	}

	public static function changed(): void {
		self::$modes = null;
		do_action( self::HOOK_CHANGED );
	}

	/** Per-user grants live in `{prefix}capabilities` user meta. */
	public static function on_user_meta( $meta_id, $user_id, $meta_key ): void {
		if ( is_string( $meta_key ) && '_capabilities' === substr( $meta_key, -13 ) ) {
			self::changed();
		}
	}

	/** Seed grantable primitive caps once per (blog, mode) — never re-applied over an admin's later change. */
	public static function ensure_seeded(): void {
		if ( ! function_exists( 'wp_roles' ) ) {
			return;
		}
		$seeded = get_option( self::SEEDED_OPTION, array() );
		$seeded = is_array( $seeded ) ? $seeded : array();
		$dirty  = false;
		foreach ( self::modes() as $id => $m ) {
			if ( 'grantable' !== $m['access']['mode'] || ( $seeded[ $id ] ?? '' ) === self::SIGNATURE ) {
				continue;
			}
			$defaults = (array) ( $m['access']['default_roles'] ?? array() );
			foreach ( wp_roles()->role_objects as $slug => $role ) {
				$grant = 'administrator' === $slug || in_array( $slug, $defaults, true )
					|| ( 'own' === ( $m['scope'] ?? '' ) && ( $role->has_cap( 'bizcity_access_gpt' ) || $role->has_cap( 'bizcity_access_twinchat' ) ) );
				if ( $grant && ! $role->has_cap( self::PRIM_PREFIX . $id ) ) {
					$role->add_cap( self::PRIM_PREFIX . $id );
				}
			}
			$seeded[ $id ] = self::SIGNATURE;
			$dirty         = true;
		}
		if ( $dirty ) {
			update_option( self::SEEDED_OPTION, $seeded, true );
		}
	}

	public static function is_site_admin( int $user_id ): bool {
		if ( class_exists( 'BizCity_Network_Admin_Capability' ) ) {
			return BizCity_Network_Admin_Capability::can_manage( $user_id );
		}
		return user_can( $user_id, 'manage_options' );
	}
}

if ( ! function_exists( 'bizcity_agent_modes_for_user' ) ) {
	/** @return string[] agent-mode-access@1 modes of a user (contract §3 helper). */
	function bizcity_agent_modes_for_user( int $user_id ): array {
		return BizCity_Agent_Mode_Access::modes_for_user( $user_id );
	}
}
