<?php
/**
 * Bizcity Twin AI — Membership Woo Mapper
 *
 * SPRINT-8 WC-0/WC-0A foundation.
 *
 * Derived index from Woo product meta => membership offer map option
 * `bizcity_membership_woo_map`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-07-17
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Membership_Woo_Mapper', false ) ) {
	return;
}

class BizCity_Membership_Woo_Mapper {

	const OPT_MAP = 'bizcity_membership_woo_map';

	const META_OFFER_CODE    = '_bizcity_membership_offer_code';
	const META_PLAN_SLUG     = 'offer_plan_slug';
	const META_DURATION_COUNT = 'duration_count';
	const META_DURATION_UNIT = 'duration_unit';
	const META_GRANT_MODE    = 'grant_mode';

	const NONCE_ACTION = 'bizcity_membership_woo_offer_meta';
	const NONCE_FIELD  = '_bizcity_membership_woo_offer_nonce';

	/** @var BizCity_Membership_Woo_Mapper|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function init() {
		if ( ! self::woo_ready() ) {
			return;
		}

		// [2026-07-17 Johnny Chu] SPRINT-8 WC-0 — Woo product meta panel + derived offer map rebuild hooks.
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( __CLASS__, 'register_product_metabox' ) );
			add_action( 'save_post_product', array( __CLASS__, 'save_product_offer_meta' ), 10, 3 );
		}

		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_offer_meta' ), 10, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'on_product_lifecycle_change' ) );
		add_action( 'trashed_post', array( __CLASS__, 'on_product_lifecycle_change' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'on_product_lifecycle_change' ) );
	}

	public static function woo_ready() {
		return class_exists( 'WooCommerce' ) && post_type_exists( 'product' );
	}

	public static function register_product_metabox() {
		if ( ! self::woo_ready() ) {
			return;
		}
		add_meta_box(
			'bizcity_membership_woo_offer_meta',
			__( 'Twin Membership Offer', 'bizcity-twin-ai' ),
			array( __CLASS__, 'render_product_metabox' ),
			'product',
			'side',
			'default'
		);
	}

	public static function render_product_metabox( $post ) {
		if ( ! $post || empty( $post->ID ) ) {
			return;
		}

		$mapper = self::instance();
		$meta   = $mapper->read_offer_meta( (int) $post->ID, 0, false );

		$plans = array();
		if ( class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			$plans = BizCity_Membership_Plan_Registry::instance()->all();
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		echo '<p><label for="bizcity_membership_offer_code"><strong>' . esc_html__( 'Offer code', 'bizcity-twin-ai' ) . '</strong></label></p>';
		echo '<input type="text" class="widefat" id="bizcity_membership_offer_code" name="bizcity_membership_offer_code" value="' . esc_attr( $meta['offer_code'] ) . '" placeholder="twgpt_pro_30d">';

		echo '<p style="margin-top:10px;"><label for="bizcity_membership_offer_plan_slug"><strong>' . esc_html__( 'Plan slug', 'bizcity-twin-ai' ) . '</strong></label></p>';
		echo '<select class="widefat" id="bizcity_membership_offer_plan_slug" name="bizcity_membership_offer_plan_slug">';
		echo '<option value="">' . esc_html__( 'Select a plan', 'bizcity-twin-ai' ) . '</option>';
		foreach ( $plans as $slug => $plan ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $meta['plan_slug'], (string) $slug, false ) . '>' . esc_html( isset( $plan['label'] ) ? $plan['label'] : $slug ) . '</option>';
		}
		echo '</select>';

		echo '<p style="margin-top:10px;"><label for="bizcity_membership_duration_count"><strong>' . esc_html__( 'Duration', 'bizcity-twin-ai' ) . '</strong></label></p>';
		echo '<div style="display:flex;gap:8px;">';
		echo '<input type="number" min="1" step="1" class="small-text" id="bizcity_membership_duration_count" name="bizcity_membership_duration_count" value="' . esc_attr( $meta['duration_count'] ) . '">';
		echo '<select class="widefat" id="bizcity_membership_duration_unit" name="bizcity_membership_duration_unit">';
		$units = array( 'day', 'week', 'month', 'year', 'lifetime' );
		foreach ( $units as $unit ) {
			echo '<option value="' . esc_attr( $unit ) . '" ' . selected( $meta['duration_unit'], $unit, false ) . '>' . esc_html( $unit ) . '</option>';
		}
		echo '</select>';
		echo '</div>';

		echo '<p style="margin-top:10px;"><label for="bizcity_membership_grant_mode"><strong>' . esc_html__( 'Grant mode', 'bizcity-twin-ai' ) . '</strong></label></p>';
		echo '<select class="widefat" id="bizcity_membership_grant_mode" name="bizcity_membership_grant_mode">';
		$modes = array( 'replace', 'extend', 'stack' );
		foreach ( $modes as $mode ) {
			echo '<option value="' . esc_attr( $mode ) . '" ' . selected( $meta['grant_mode'], $mode, false ) . '>' . esc_html( $mode ) . '</option>';
		}
		echo '</select>';

		echo '<p style="margin-top:10px;color:#646970;font-size:12px;">'
			. esc_html__( 'Offer map được build từ product meta vào option bizcity_membership_woo_map.', 'bizcity-twin-ai' )
			. '</p>';
	}

	public static function save_product_offer_meta( $post_id, $post, $update ) {
		if ( ! self::woo_ready() ) {
			return;
		}
		if ( ! $post || $post->post_type !== 'product' ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$mapper = self::instance();

		$offer_code = isset( $_POST['bizcity_membership_offer_code'] )
			? sanitize_key( (string) wp_unslash( $_POST['bizcity_membership_offer_code'] ) )
			: '';
		$plan_slug = isset( $_POST['bizcity_membership_offer_plan_slug'] )
			? $mapper->resolve_plan_slug( (string) wp_unslash( $_POST['bizcity_membership_offer_plan_slug'] ) )
			: '';
		$duration_count = isset( $_POST['bizcity_membership_duration_count'] )
			? max( 1, (int) wp_unslash( $_POST['bizcity_membership_duration_count'] ) )
			: 1;
		$duration_unit = isset( $_POST['bizcity_membership_duration_unit'] )
			? $mapper->sanitize_duration_unit( (string) wp_unslash( $_POST['bizcity_membership_duration_unit'] ) )
			: 'month';
		$grant_mode = isset( $_POST['bizcity_membership_grant_mode'] )
			? $mapper->sanitize_grant_mode( (string) wp_unslash( $_POST['bizcity_membership_grant_mode'] ) )
			: 'replace';

		if ( $offer_code === '' ) {
			delete_post_meta( $post_id, self::META_OFFER_CODE );
			delete_post_meta( $post_id, self::META_PLAN_SLUG );
			delete_post_meta( $post_id, self::META_DURATION_COUNT );
			delete_post_meta( $post_id, self::META_DURATION_UNIT );
			delete_post_meta( $post_id, self::META_GRANT_MODE );
		} else {
			update_post_meta( $post_id, self::META_OFFER_CODE, $offer_code );
			update_post_meta( $post_id, self::META_PLAN_SLUG, $plan_slug );
			update_post_meta( $post_id, self::META_DURATION_COUNT, $duration_count );
			update_post_meta( $post_id, self::META_DURATION_UNIT, $duration_unit );
			update_post_meta( $post_id, self::META_GRANT_MODE, $grant_mode );
		}

		$mapper->rebuild_index();
	}

	public static function save_variation_offer_meta( $variation_id, $loop ) {
		unset( $loop );
		if ( ! self::woo_ready() ) {
			return;
		}
		// [2026-07-17 Johnny Chu] SPRINT-8 WC-0A — always rebuild derived offer map after variation saves.
		self::instance()->rebuild_index();
	}

	public static function on_product_lifecycle_change( $post_id ) {
		if ( ! self::woo_ready() ) {
			return;
		}
		$post_type = get_post_type( $post_id );
		if ( $post_type !== 'product' && $post_type !== 'product_variation' ) {
			return;
		}
		self::instance()->rebuild_index();
	}

	public function rebuild_index() {
		if ( ! self::woo_ready() ) {
			$empty = array(
				'version'    => '1.0.0',
				'updated_at' => current_time( 'mysql' ),
				'items'      => array(),
			);
			update_option( self::OPT_MAP, $empty, false );
			return $empty;
		}

		$ids = get_posts( array(
			'post_type'              => array( 'product', 'product_variation' ),
			'post_status'            => array( 'publish', 'private', 'draft', 'pending', 'future' ),
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_key'               => self::META_OFFER_CODE,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
		) );

		$items = array();
		$now   = current_time( 'mysql' );
		foreach ( (array) $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$is_variation = ( $post->post_type === 'product_variation' );
			$parent_id    = $is_variation ? (int) $post->post_parent : 0;
			$meta         = $this->read_offer_meta( (int) $post_id, $parent_id, true );
			if ( $meta['offer_code'] === '' || $meta['plan_slug'] === '' ) {
				continue;
			}

			$row = array(
				'offer_code'     => $meta['offer_code'],
				'plan_slug'      => $meta['plan_slug'],
				'duration_count' => $meta['duration_count'],
				'duration_unit'  => $meta['duration_unit'],
				'grant_mode'     => $meta['grant_mode'],
				'product_id'     => $is_variation ? $parent_id : (int) $post_id,
				'variation_id'   => $is_variation ? (int) $post_id : 0,
				'source'         => $is_variation ? 'variation' : 'product',
				'updated_at'     => $now,
			);

			$code = $row['offer_code'];
			if ( ! isset( $items[ $code ] ) ) {
				$items[ $code ] = $row;
				continue;
			}

			$existing = $items[ $code ];
			if ( (int) $row['variation_id'] > 0 && (int) $existing['variation_id'] === 0 ) {
				$items[ $code ] = $row;
				continue;
			}
			if ( (int) $row['variation_id'] === 0 && (int) $existing['variation_id'] > 0 ) {
				continue;
			}
			if ( (int) $row['variation_id'] > (int) $existing['variation_id'] ) {
				$items[ $code ] = $row;
				continue;
			}
			if ( (int) $row['product_id'] > (int) $existing['product_id'] ) {
				$items[ $code ] = $row;
			}
		}

		ksort( $items );
		$payload = array(
			'version'    => '1.0.0',
			'updated_at' => $now,
			'items'      => $items,
		);
		update_option( self::OPT_MAP, $payload, false );
		return $payload;
	}

	public function get_map() {
		$map = get_option( self::OPT_MAP, array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		$map['version']    = isset( $map['version'] ) ? (string) $map['version'] : '1.0.0';
		$map['updated_at'] = isset( $map['updated_at'] ) ? (string) $map['updated_at'] : '';
		$map['items']      = isset( $map['items'] ) && is_array( $map['items'] ) ? $map['items'] : array();
		return $map;
	}

	public function get_offer( $offer_code ) {
		$offer_code = sanitize_key( (string) $offer_code );
		if ( $offer_code === '' ) {
			return array();
		}
		$map = $this->get_map();
		return isset( $map['items'][ $offer_code ] ) && is_array( $map['items'][ $offer_code ] )
			? $map['items'][ $offer_code ]
			: array();
	}

	private function read_offer_meta( $post_id, $parent_id = 0, $allow_parent_fallback = true ) {
		$post_id  = (int) $post_id;
		$parent_id = (int) $parent_id;

		$offer_code = sanitize_key( (string) get_post_meta( $post_id, self::META_OFFER_CODE, true ) );
		$plan_slug  = $this->resolve_plan_slug( (string) get_post_meta( $post_id, self::META_PLAN_SLUG, true ) );

		$duration_count = (int) get_post_meta( $post_id, self::META_DURATION_COUNT, true );
		$duration_unit  = $this->sanitize_duration_unit( (string) get_post_meta( $post_id, self::META_DURATION_UNIT, true ) );
		$grant_mode     = $this->sanitize_grant_mode( (string) get_post_meta( $post_id, self::META_GRANT_MODE, true ) );

		if ( $allow_parent_fallback && $parent_id > 0 ) {
			if ( $plan_slug === '' ) {
				$plan_slug = $this->resolve_plan_slug( (string) get_post_meta( $parent_id, self::META_PLAN_SLUG, true ) );
			}
			if ( $duration_count <= 0 ) {
				$duration_count = (int) get_post_meta( $parent_id, self::META_DURATION_COUNT, true );
			}
			if ( $duration_unit === '' ) {
				$duration_unit = $this->sanitize_duration_unit( (string) get_post_meta( $parent_id, self::META_DURATION_UNIT, true ) );
			}
			if ( $grant_mode === '' ) {
				$grant_mode = $this->sanitize_grant_mode( (string) get_post_meta( $parent_id, self::META_GRANT_MODE, true ) );
			}
		}

		if ( $duration_count <= 0 ) {
			$duration_count = 1;
		}
		if ( $duration_unit === '' ) {
			$duration_unit = 'month';
		}
		if ( $grant_mode === '' ) {
			$grant_mode = 'replace';
		}

		return array(
			'offer_code'     => $offer_code,
			'plan_slug'      => $plan_slug,
			'duration_count' => $duration_count,
			'duration_unit'  => $duration_unit,
			'grant_mode'     => $grant_mode,
		);
	}

	private function sanitize_duration_unit( $value ) {
		$value = sanitize_key( (string) $value );
		$allowed = array( 'day', 'week', 'month', 'year', 'lifetime' );
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	private function sanitize_grant_mode( $value ) {
		$value = sanitize_key( (string) $value );
		$allowed = array( 'replace', 'extend', 'stack' );
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	private function resolve_plan_slug( $value ) {
		$value = sanitize_key( (string) $value );
		if ( $value === '' ) {
			return '';
		}
		if ( class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			$registry = BizCity_Membership_Plan_Registry::instance();
			if ( ! $registry->exists( $value ) ) {
				return '';
			}
		}
		return $value;
	}
}
