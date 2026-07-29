<?php
/**
 * Bizcity Twin AI — Membership_Entitlement
 *
 * PHASE-MEMBERSHIP M2.
 *
 * Merges the two entitlement tiers into a single per-user view:
 *   Tầng 1 — SITE TIER cap from the hub (BizCity_LLM_Client::get_entitlement()).
 *   Tầng 2 — USER PLAN local limits (Plan Registry × subscription).
 *
 * Golden rule: effective limit = min( user_plan_local, site_tier_ceiling ).
 *
 * Fail-OPEN (R-GW-8): if the hub is unreachable we DON'T block the user —
 * we fall back to the local plan limits with site_tier='free' and clamp by
 * the free ceiling only.
 *
 * PHP 7.4-safe.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-06-04
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Membership_Entitlement {

	/** @var BizCity_Membership_Entitlement|null */
	private static $instance = null;

	/** @var array in-request cache user_id => entitlement-array */
	private $cache = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Effective per-user entitlement (site tier × user plan).
	 *
	 * @param int $user_id
	 * @return array {
	 *   user_id, site_tier, user_plan, limits[], features[], accepted_file_types[], balance_usd, bypass
	 * }
	 */
	public function for_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( isset( $this->cache[ $user_id ] ) ) {
			return $this->cache[ $user_id ];
		}

		$registry = BizCity_Membership_Plan_Registry::instance();
		$manager  = BizCity_Membership_Manager::instance();

		// Tầng 1 — site tier from hub (fail-OPEN to 'free').
		$site = array(
			'tier'                => 'free',
			'balance_usd'         => 0.0,
			'bypass'              => false,
			'master_level'        => '',
			'accepted_file_types' => array(),
			'kg_max_file_size_mb' => 0,
		);
		if ( $user_id > 0 && class_exists( 'BizCity_LLM_Client' ) ) {
			$client = BizCity_LLM_Client::instance();
			if ( method_exists( $client, 'is_ready' ) ? $client->is_ready() : true ) {
				$ent = $client->get_entitlement( max( 1, $user_id ) );
				if ( is_array( $ent ) ) {
					$site['tier']        = isset( $ent['tier'] ) ? sanitize_key( (string) $ent['tier'] ) : 'free';
					$site['balance_usd'] = isset( $ent['balance_usd'] ) ? (float) $ent['balance_usd'] : 0.0;
					$site['bypass']      = ! empty( $ent['bypass'] );
					$site['master_level'] = isset( $ent['master_level'] ) ? sanitize_key( (string) $ent['master_level'] ) : '';
					if ( isset( $ent['accepted_file_types'] ) && is_array( $ent['accepted_file_types'] ) ) {
						$types = array();
						foreach ( (array) $ent['accepted_file_types'] as $ext ) {
							$_ext = sanitize_key( strtolower( trim( (string) $ext ) ) );
							if ( $_ext !== '' && ! in_array( $_ext, $types, true ) ) {
								$types[] = $_ext;
							}
						}
						$site['accepted_file_types'] = $types;
					}
					if ( isset( $ent['kg_max_file_size_mb'] ) && (int) $ent['kg_max_file_size_mb'] > 0 ) {
						$site['kg_max_file_size_mb'] = (int) $ent['kg_max_file_size_mb'];
					}
				}
			}
		}

		// Tầng 2 — local user plan.
		$plan   = $manager->plan_for_user( $user_id );
		$limits = $registry->limits( $plan );

		// Merge — clamp each limit by the site-tier ceiling.
		$ceiling = $registry->site_tier_ceiling( $site['tier'] );
		foreach ( $limits as $k => $v ) {
			if ( isset( $ceiling[ $k ] ) ) {
				$limits[ $k ] = min( (int) $v, (int) $ceiling[ $k ] );
			}
		}

		// [2026-07-02 Johnny Chu] HOTFIX R-KG-HUB-FIRST — Resolve accepted_file_types hub-first.
		// Rule: mặc định luôn theo license hub (master_level). Chỉ khi admin chọn mode='restrict'
		// cho plan cụ thể thì mới intersect với danh sách tùy chỉnh của plan đó.
		// Aligns với QUOTA-DUAL-LAYER-ARCHITECTURE: hub = trần, local = phân phối trong trần.
		$master_level = (string) $site['master_level'];
		if ( $user_id > 0 ) {
			if ( $master_level === '' ) {
				$master_level = class_exists( 'BizCity_User_Meta_Cache' )
					? (string) BizCity_User_Meta_Cache::get( $user_id, '_bizcity_master_level', '' )
					: (string) get_user_meta( $user_id, '_bizcity_master_level', true );
			}
		}
		if ( $master_level === '' ) {
			$master_level = (string) get_option( 'bizcity_hub_master_level', 'free' );
		}
		if ( $master_level === '' ) {
			$master_level = 'free';
		}

		// [2026-07-14 Johnny Chu] R-KG-FILE-TYPES — source of truth priority:
		// 1) exact accepted_file_types from hub entitlement payload
		// 2) cached option written by BizCity_LLM_Client::get_entitlement()
		// 3) static fallback by master_level mapping
		$hub_types = ! empty( $site['accepted_file_types'] )
			? (array) $site['accepted_file_types']
			: array();
		if ( empty( $hub_types ) ) {
			$cached_raw = (string) get_option( 'bizcity_hub_accepted_file_types', '' );
			if ( $cached_raw !== '' ) {
				$cached = json_decode( $cached_raw, true );
				if ( is_array( $cached ) ) {
					foreach ( $cached as $ext ) {
						$_ext = sanitize_key( strtolower( trim( (string) $ext ) ) );
						if ( $_ext !== '' && ! in_array( $_ext, $hub_types, true ) ) {
							$hub_types[] = $_ext;
						}
					}
				}
			}
		}
		if ( empty( $hub_types ) ) {
			$hub_types = BizCity_Membership_Plan_Registry::hub_file_types_for_level( $master_level );
		}
		$plan_def     = $registry->get( $plan );
		$ft_mode      = isset( $plan_def['kg_file_types_mode'] ) ? (string) $plan_def['kg_file_types_mode'] : 'inherit';
		if ( $ft_mode === 'restrict' ) {
			// Admin đã explicit restrict plan này — dùng intersection: hub là trần, local là giới hạn
			$local_types    = $registry->kg_accepted_file_types( $plan );
			$intersected    = array_values( array_intersect( $hub_types, $local_types ) );
			$accepted_types = ! empty( $intersected ) ? $intersected : $hub_types; // safety fallback
		} else {
			// 'inherit' (default): dùng toàn bộ types do hub license cấp, không hạn chế thêm
			$accepted_types = $hub_types;
		}

		// [2026-07-14 Johnny Chu] HOTFIX — normalize accepted file types + legacy office aliases.
		$normalized_types = array();
		foreach ( (array) $accepted_types as $type ) {
			$t = sanitize_key( strtolower( trim( (string) $type ) ) );
			if ( $t !== '' && ! in_array( $t, $normalized_types, true ) ) {
				$normalized_types[] = $t;
			}
		}
		if ( in_array( 'docx', $normalized_types, true ) && ! in_array( 'doc', $normalized_types, true ) ) {
			$normalized_types[] = 'doc';
		}
		if ( in_array( 'xlsx', $normalized_types, true ) && ! in_array( 'xls', $normalized_types, true ) ) {
			$normalized_types[] = 'xls';
		}
		if ( in_array( 'pptx', $normalized_types, true ) && ! in_array( 'ppt', $normalized_types, true ) ) {
			$normalized_types[] = 'ppt';
		}
		$accepted_types = $normalized_types;

		$result = array(
			'user_id'               => $user_id,
			'site_tier'             => $site['tier'],
			'user_plan'             => $plan,
			'limits'                => $limits,
			'features'              => $registry->features( $plan ),
			// [2026-07-02 Johnny Chu] HOTFIX R-KG-HUB-FIRST — hub-first file types (xem logic bên trên).
			'accepted_file_types'   => $accepted_types,
			// [2026-06-11 Johnny Chu] R-KG-FILE-TYPES — max file size in MB from local plan.
			'kg_max_file_size_mb'   => $registry->kg_max_file_size_mb( $plan ),
			'balance_usd'           => $site['balance_usd'],
			'bypass'                => $site['bypass'],
		);

		/**
		 * Filter the merged per-user entitlement.
		 *
		 * @param array $result
		 * @param int   $user_id
		 */
		$result = apply_filters( 'bizcity_membership_entitlement', $result, $user_id );

		$this->cache[ $user_id ] = $result;
		return $result;
	}

	/**
	 * Whether a plan feature is enabled for the user (e.g. 'image', 'video').
	 *
	 * @param int    $user_id
	 * @param string $feature
	 * @return bool
	 */
	public function can_use_feature( $user_id, $feature ) {
		$ent = $this->for_user( $user_id );
		return in_array( (string) $feature, (array) $ent['features'], true );
	}

	/**
	 * Effective daily limit for a feature key (0 = none/blocked).
	 *
	 * @param int    $user_id
	 * @param string $limit_key  e.g. 'chat_msgs_per_day'
	 * @return int
	 */
	public function limit( $user_id, $limit_key ) {
		$ent = $this->for_user( $user_id );
		return isset( $ent['limits'][ $limit_key ] ) ? (int) $ent['limits'][ $limit_key ] : 0;
	}

	/**
	 * Clear the in-request cache (e.g. after a plan change in the same request).
	 *
	 * @param int $user_id  0 = clear all
	 * @return void
	 */
	public function flush_cache( $user_id = 0 ) {
		$user_id = (int) $user_id;
		if ( $user_id > 0 ) {
			unset( $this->cache[ $user_id ] );
			return;
		}
		$this->cache = array();
	}
}
