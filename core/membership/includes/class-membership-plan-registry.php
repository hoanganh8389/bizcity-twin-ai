<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Membership Plan Registry — CRUD client-defined plans (Free / Pro / Plus / …).
 *
 * Plans stored in site option `bizcity_membership_plans` (JSON-ish array).
 * Each plan: price (USD-anchored), billing cycle, AI request quota, features.
 *
 * Currency is anchored to USD. VND display = price × option
 * `bizcity_membership_usd_to_vnd`. PayPal charges in USD.
 *
 * PHP 7.4-safe — no union types, no nullsafe, no match, no enums.
 *
 * @since 2026-06-04 (PHASE-MEMBERSHIP M1)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Membership_Plan_Registry {

	const OPT_PLANS       = 'bizcity_membership_plans';
	const OPT_DEFAULT     = 'bizcity_membership_default_plan';
	const OPT_ROLE_MAP    = 'bizcity_membership_role_map';
	const OPT_USD_TO_VND  = 'bizcity_membership_usd_to_vnd';

	const DEFAULT_PLAN    = 'free';
	const DEFAULT_RATE    = 25000; // 1 USD ≈ 25,000 VND fallback.

	/** @var BizCity_Membership_Plan_Registry|null */
	private static $instance = null;

	/** @var array|null in-request cache of normalized plans. */
	private $cache = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Default seed plans (used when option is empty).
	 *
	 * @return array
	 */
	public static function seed() {
		return array(
			// [2026-06-11 Johnny Chu] R-KG-FILE-TYPES — seed updated with kg_accepted_file_types per plan.
			'free' => array(
				'label'                  => 'Free',
				'price'                  => 0,
				'currency'               => 'USD',
				'billing_cycle'          => 'lifetime',
				'paypal_plan_id'         => '',
				'limits'                 => array(
					'chat_msgs_per_day'   => 30,
					'kg_passages_per_day' => 20,
					'image_per_day'       => 0,
					'video_per_day'       => 0,
				),
				'kg_accepted_file_types' => array( 'txt', 'md', 'docx', 'xlsx', 'pptx', 'rtf' ),
				'kg_max_file_size_mb'    => 5,
				'features'               => array( 'chat', 'kg.text' ),
				'models'                 => array( 'fast' ),
			),
			'pro' => array(
				'label'                  => 'Pro',
				'price'                  => 9,
				'currency'               => 'USD',
				'billing_cycle'          => 'month',
				'paypal_plan_id'         => '',
				'limits'                 => array(
					'chat_msgs_per_day'   => 500,
					'kg_passages_per_day' => 200,
					'image_per_day'       => 20,
					'video_per_day'       => 0,
				),
				'kg_accepted_file_types' => array( 'txt', 'md', 'docx', 'xlsx', 'pptx', 'rtf', 'pdf', 'doc', 'xls', 'ppt' ),
				'kg_max_file_size_mb'    => 20,
				'features'               => array( 'chat', 'kg.text', 'kg.office', 'image', 'search' ),
				'models'                 => array( 'fast', 'reasoning' ),
			),
			'plus' => array(
				'label'                  => 'Plus',
				'price'                  => 29,
				'currency'               => 'USD',
				'billing_cycle'          => 'month',
				'paypal_plan_id'         => '',
				'limits'                 => array(
					'chat_msgs_per_day'   => 3000,
					'kg_passages_per_day' => 1000,
					'image_per_day'       => 100,
					'video_per_day'       => 10,
				),
				'kg_accepted_file_types' => array( 'txt', 'md', 'docx', 'xlsx', 'pptx', 'rtf', 'pdf', 'doc', 'xls', 'ppt', 'odt', 'ods', 'odp', 'csv', 'tsv', 'mp3', 'mp4', 'm4a', 'wav', 'ogg' ),
				'kg_max_file_size_mb'    => 50,
				'features'               => array( 'chat', 'kg.text', 'kg.office', 'kg.av', 'image', 'video', 'search', 'astrology' ),
				'models'                 => array( 'fast', 'reasoning', 'vision' ),
			),
		);
	}

	/**
	 * All plans, normalized. Falls back to seed() when option empty.
	 *
	 * @return array slug => plan-array
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( self::OPT_PLANS, array() );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			$stored = self::seed();
		}

		$normalized = array();
		foreach ( $stored as $slug => $plan ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug === '' || ! is_array( $plan ) ) {
				continue;
			}
			$normalized[ $slug ] = $this->normalize( $slug, $plan );
		}

		// Always guarantee a 'free' plan exists.
		if ( ! isset( $normalized['free'] ) ) {
			$seed               = self::seed();
			$normalized['free'] = $this->normalize( 'free', $seed['free'] );
		}

		/**
		 * Filter the full plan registry.
		 *
		 * @param array $normalized slug => plan-array
		 */
		$normalized  = apply_filters( 'bizcity_membership_plans', $normalized );
		$this->cache = $normalized;

		return $normalized;
	}

	/**
	 * Normalize one plan to a stable shape.
	 *
	 * @param string $slug
	 * @param array  $plan
	 * @return array
	 */
	private function normalize( $slug, array $plan ) {
		$limits = isset( $plan['limits'] ) && is_array( $plan['limits'] ) ? $plan['limits'] : array();

		// [2026-06-08 Johnny Chu] PHASE-MEMBERSHIP M1 — normalize: added video_per_day limit.
		return array(
			'slug'                   => $slug,
			'label'                  => isset( $plan['label'] ) ? (string) $plan['label'] : ucfirst( $slug ),
			'price'                  => isset( $plan['price'] ) ? (float) $plan['price'] : 0.0,
			'currency'               => isset( $plan['currency'] ) ? (string) $plan['currency'] : 'USD',
			'billing_cycle'          => isset( $plan['billing_cycle'] ) ? (string) $plan['billing_cycle'] : 'lifetime',
			'paypal_plan_id'         => isset( $plan['paypal_plan_id'] ) ? (string) $plan['paypal_plan_id'] : '',
			'limits'                 => array(
				'chat_msgs_per_day'      => isset( $limits['chat_msgs_per_day'] ) ? (int) $limits['chat_msgs_per_day'] : 0,
				'kg_passages_per_day'    => isset( $limits['kg_passages_per_day'] ) ? (int) $limits['kg_passages_per_day'] : 0,
				'image_per_day'          => isset( $limits['image_per_day'] ) ? (int) $limits['image_per_day'] : 0,
				'video_per_day'          => isset( $limits['video_per_day'] ) ? (int) $limits['video_per_day'] : 0,
			),
			// [2026-06-11 Johnny Chu] R-KG-FILE-TYPES — normalize accepted file types array.
			'kg_accepted_file_types' => isset( $plan['kg_accepted_file_types'] ) && is_array( $plan['kg_accepted_file_types'] )
				? array_values( $plan['kg_accepted_file_types'] )
				: array( 'txt', 'md', 'docx', 'xlsx', 'pptx', 'rtf' ),
			// [2026-07-02 Johnny Chu] HOTFIX R-KG-HUB-FIRST — file types mode:
			// 'inherit' (default) = dùng toàn bộ types do hub license cấp (master_level);
			// 'restrict' = admin đã explicit config danh sách giới hạn (intersect với hub).
			'kg_file_types_mode'     => isset( $plan['kg_file_types_mode'] ) && $plan['kg_file_types_mode'] === 'restrict'
				? 'restrict'
				: 'inherit',
			// [2026-06-11 Johnny Chu] R-KG-FILE-TYPES — normalize max file size MB.
			'kg_max_file_size_mb'    => isset( $plan['kg_max_file_size_mb'] ) && (int) $plan['kg_max_file_size_mb'] > 0
				? (int) $plan['kg_max_file_size_mb']
				: 5,
			'features'               => isset( $plan['features'] ) && is_array( $plan['features'] ) ? array_values( $plan['features'] ) : array(),
			'models'                 => isset( $plan['models'] ) && is_array( $plan['models'] ) ? array_values( $plan['models'] ) : array(),
		);
	}

	/**
	 * Whether a plan slug exists.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public function exists( $slug ) {
		$all = $this->all();
		return isset( $all[ sanitize_key( (string) $slug ) ] );
	}

	/**
	 * Get one plan (or the default/free plan when slug missing).
	 *
	 * @param string $slug
	 * @return array
	 */
	public function get( $slug ) {
		$all  = $this->all();
		$slug = sanitize_key( (string) $slug );
		if ( isset( $all[ $slug ] ) ) {
			return $all[ $slug ];
		}
		$default = $this->default_slug();
		if ( isset( $all[ $default ] ) ) {
			return $all[ $default ];
		}
		return $all['free'];
	}

	/**
	 * Limits array for a plan.
	 *
	 * @param string $slug
	 * @return array
	 */
	public function limits( $slug ) {
		$plan = $this->get( $slug );
		return $plan['limits'];
	}

	/**
	 * Feature keys for a plan.
	 *
	 * @param string $slug
	 * @return array
	 */
	public function features( $slug ) {
		$plan = $this->get( $slug );
		return $plan['features'];
	}

	/**
	 * [2026-06-11 Johnny Chu] R-KG-FILE-TYPES — accepted file type extensions for a plan.
	 *
	 * @param string $slug
	 * @return string[]
	 */
	public function kg_accepted_file_types( $slug ) {
		$plan = $this->get( $slug );
		return isset( $plan['kg_accepted_file_types'] ) && is_array( $plan['kg_accepted_file_types'] )
			? $plan['kg_accepted_file_types']
			: array( 'txt', 'md', 'docx', 'xlsx', 'pptx', 'rtf' );
	}

	/**
	 * [2026-06-11 Johnny Chu] R-KG-FILE-TYPES — max file size in MB for a plan.
	 *
	 * @param string $slug
	 * @return int
	 */
	public function kg_max_file_size_mb( $slug ) {
		$plan = $this->get( $slug );
		return isset( $plan['kg_max_file_size_mb'] ) && (int) $plan['kg_max_file_size_mb'] > 0
			? (int) $plan['kg_max_file_size_mb']
			: 5;
	}

	/**
	 * Default plan slug for users without an explicit assignment.
	 *
	 * @return string
	 */
	public function default_slug() {
		$slug = get_option( self::OPT_DEFAULT, self::DEFAULT_PLAN );
		$slug = sanitize_key( (string) $slug );
		return $slug !== '' ? $slug : self::DEFAULT_PLAN;
	}

	/**
	 * Map of WP role slug => plan slug.
	 *
	 * @return array
	 */
	public function role_map() {
		$map = get_option( self::OPT_ROLE_MAP, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * USD → VND conversion rate (display only; PayPal charges USD).
	 *
	 * @return float
	 */
	public function usd_to_vnd() {
		$rate = (float) get_option( self::OPT_USD_TO_VND, self::DEFAULT_RATE );
		return $rate > 0 ? $rate : self::DEFAULT_RATE;
	}

	/**
	 * Price formatted for display, e.g. "$9.00 (≈ 225.000₫)".
	 *
	 * @param string $slug
	 * @return string
	 */
	public function price_label( $slug ) {
		$plan  = $this->get( $slug );
		$price = (float) $plan['price'];
		if ( $price <= 0 ) {
			return __( 'Free', 'bizcity-twin-ai' );
		}
		$vnd = (int) round( $price * $this->usd_to_vnd() );
		return sprintf( '$%s (≈ %s₫)', number_format( $price, 2 ), number_format( $vnd ) );
	}

	/**
	 * Persist the full plan set. Validates shape via normalize().
	 *
	 * @param array $plans slug => plan-array
	 * @return bool
	 */
	public function save( array $plans ) {
		$clean = array();
		foreach ( $plans as $slug => $plan ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug === '' || ! is_array( $plan ) ) {
				continue;
			}
			$clean[ $slug ] = $this->normalize( $slug, $plan );
		}
		if ( ! isset( $clean['free'] ) ) {
			$seed          = self::seed();
			$clean['free'] = $this->normalize( 'free', $seed['free'] );
		}
		$this->cache = null;
		return update_option( self::OPT_PLANS, $clean );
	}

	/**
	 * Clear the in-request cache (after option changes).
	 *
	 * @return void
	 */
	public function flush_cache() {
		$this->cache = null;
	}

	/**
	 * [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7-recurring — persist the PayPal
	 * billing-plan id created during provisioning for a single plan slug.
	 *
	 * @param string $slug
	 * @param string $paypal_plan_id
	 * @return bool
	 */
	public function set_paypal_plan_id( $slug, $paypal_plan_id ) {
		$slug = sanitize_key( (string) $slug );
		if ( $slug === '' ) {
			return false;
		}
		$all = $this->all();
		if ( ! isset( $all[ $slug ] ) ) {
			return false;
		}
		$all[ $slug ]['paypal_plan_id'] = sanitize_text_field( (string) $paypal_plan_id );
		return $this->save( $all );
	}

	const OPT_PAYPAL_PRODUCT = 'bizcity_membership_paypal_product';

	/**
	 * PayPal catalog product id (one per site, shared by all billing plans).
	 *
	 * @return string
	 */
	public function paypal_product_id() {
		return (string) get_option( self::OPT_PAYPAL_PRODUCT, '' );
	}

	/**
	 * Persist the PayPal catalog product id.
	 *
	 * @param string $product_id
	 * @return void
	 */
	public function set_paypal_product_id( $product_id ) {
		update_option( self::OPT_PAYPAL_PRODUCT, sanitize_text_field( (string) $product_id ) );
	}

	/* ── Hub license file types (Tầng 1 — R-KG-HUB-FIRST) ─────────────── */

	/**
	 * [2026-07-02 Johnny Chu] HOTFIX R-KG-HUB-FIRST — File types granted by the hub
	 * master_level (site's license from bizcity.vn). Used as the BASELINE when
	 * a plan's kg_file_types_mode = 'inherit' (default). Local plan can only
	 * restrict further when mode = 'restrict'.
	 *
	 * master_premium / enterprise / business / plus → all types incl. AV
	 * master_pro / pro / starter                   → office + pdf
	 * free / unknown                                → text-only
	 *
	 * @param string $level  Hub master level slug (_bizcity_master_level or bizcity_hub_master_level).
	 * @return string[]
	 */
	public static function hub_file_types_for_level( $level ) {
		$level    = sanitize_key( (string) $level );
		$base     = array( 'txt', 'md', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt', 'rtf' );
		$with_pdf = array_merge( $base, array( 'pdf', 'csv', 'tsv', 'html' ) );
		$full     = array_merge( $with_pdf, array( 'odt', 'ods', 'odp', 'mp3', 'mp4', 'm4a', 'wav', 'ogg' ) );
		switch ( $level ) {
			case 'master_premium':
			case 'enterprise':
			case 'business':
			case 'plus':
				return $full;
			case 'master_pro':
			case 'pro':
			case 'starter':
				return $with_pdf;
			case 'free':
			default:
				return $base;
		}
	}

	/* ── Site-tier ceiling (Tầng 1 từ hub) ──────────────────────────────── */

	/**
	 * Per-feature hard ceiling imposed by the SITE tier from the hub.
	 *
	 * effective limit = min( user_plan_local, site_tier_ceiling ). A client on
	 * the hub "free" site tier cannot grant a member more than the free ceiling,
	 * even if the local plan says otherwise.
	 *
	 * Returns an empty array for unknown/unlimited tiers (no clamping).
	 *
	 * @param string $tier  hub tier slug: free|starter|pro|plus|business|enterprise
	 * @return array  limit-key => int ceiling
	 */
	public function site_tier_ceiling( $tier ) {
		$tier = sanitize_key( (string) $tier );

		$ceilings = array(
			'free' => array(
				'kg_passages_per_day' => 50,
				'chat_msgs_per_day'   => 50,
				'image_per_day'       => 5,
			),
			'starter' => array(
				'kg_passages_per_day' => 200,
				'chat_msgs_per_day'   => 500,
				'image_per_day'       => 30,
			),
			'pro' => array(
				'kg_passages_per_day' => 1000,
				'chat_msgs_per_day'   => 3000,
				'image_per_day'       => 150,
			),
		);

		// Tiers with no entry (plus/business/enterprise/…) = unlimited (no clamp).
		$ceiling = isset( $ceilings[ $tier ] ) ? $ceilings[ $tier ] : array();

		/**
		 * Filter the per-feature ceiling for a hub site tier.
		 *
		 * @param array  $ceiling
		 * @param string $tier
		 */
		$ceiling = apply_filters( 'bizcity_membership_site_tier_ceiling', $ceiling, $tier );
		return is_array( $ceiling ) ? $ceiling : array();
	}
}
