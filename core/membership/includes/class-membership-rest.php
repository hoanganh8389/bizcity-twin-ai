<?php
/**
 * Bizcity Twin AI — Membership_REST
 *
 * PHASE-MEMBERSHIP M4 / M6.
 *
 * Same-origin REST proxy so the TwinChat SPA (and any client UI) can read plans,
 * read the current user's entitlement, and run a PayPal one-time checkout —
 * WITHOUT ever talking to bizcity-llm-router. Membership money is the client's
 * own PayPal revenue (R-GW-8: distinct money type, self-billing is allowed).
 *
 * Namespace: bizcity-membership/v1 (dedicated; NOT bizcity/v1 which is the hub
 * router namespace, and NOT a channel namespace).
 *
 *   GET  /wp-json/bizcity-membership/v1/plans     (public)      → plan catalog
 *   GET  /wp-json/bizcity-membership/v1/me         (logged-in)  → entitlement + usage
 *   GET  /wp-json/bizcity-membership/v1/me/affiliate (logged-in) → referral link + privacy-safe stats
 *   POST /wp-json/bizcity-membership/v1/checkout   (logged-in)  → { approve_url }
 *   POST /wp-json/bizcity-membership/v1/capture    (logged-in)  → fulfill order
 *   POST /wp-json/bizcity-membership/v1/webhook    (public)     → PayPal backup
 *
 * Fail-OPEN: PayPal/config errors return 200 + { success:false, _degraded:true }
 * so the FE never enters a retry loop.
 *
 * PHP 7.4-safe — no union types, no nullsafe, no match, no enums.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-06-04
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Membership_REST {

	const NS = 'bizcity-membership/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-7 — normalize /me payload contract in mixed-version deploys.
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'normalize_me_contract_response' ), 10, 3 );
	}

	/**
	 * Normalize /me response so account contract keys are always present.
	 *
	 * @param mixed           $response REST response.
	 * @param array           $handler  Route handler metadata.
	 * @param WP_REST_Request $request  Current request.
	 * @return mixed
	 */
	public static function normalize_me_contract_response( $response, $handler, $request ) {
		if ( ! ( $request instanceof WP_REST_Request ) ) {
			return $response;
		}

		$route = (string) $request->get_route();
		if ( $route !== '/bizcity-membership/v1/me' || strtoupper( (string) $request->get_method() ) !== 'GET' ) {
			return $response;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$resp = rest_ensure_response( $response );
		$data = $resp->get_data();
		$data = is_array( $data ) ? $data : array();

		$uid = get_current_user_id();
		if ( ! isset( $data['woo_projection'] ) || ! is_array( $data['woo_projection'] ) ) {
			$data['woo_projection'] = self::current_user_woo_projection( $uid );
		}
		if ( ! isset( $data['commerce_capacity'] ) || ! is_array( $data['commerce_capacity'] ) ) {
			$data['commerce_capacity'] = self::commerce_capacity_snapshot();
		}

		$resp->set_data( $data );
		return $resp;
	}

	public static function register_routes() {
		register_rest_route( self::NS, '/plans', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'plans' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-07-17 Johnny Chu] SPRINT-10 SB-3A — admin quick-edit plan sheet endpoint for Control Plane Commerce tab.
		register_rest_route( self::NS, '/admin/plans-sheet', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'admin_get_plans_sheet' ),
				'permission_callback' => array( __CLASS__, 'require_admin' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'admin_put_plans_sheet' ),
				'permission_callback' => array( __CLASS__, 'require_admin' ),
			),
		) );

		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-2 — preview/import built-in Membership policy templates without overwriting custom plans by default.
		register_rest_route( self::NS, '/admin/plan-templates', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'admin_get_plan_templates' ),
			'permission_callback' => array( __CLASS__, 'require_admin' ),
		) );

		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-2 — explicit admin import for a selected template.
		register_rest_route( self::NS, '/admin/plan-templates/import', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_import_plan_template' ),
			'permission_callback' => array( __CLASS__, 'require_admin' ),
		) );

		register_rest_route( self::NS, '/me', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'me' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		register_rest_route( self::NS, '/checkout', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'checkout' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		register_rest_route( self::NS, '/capture', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'capture' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		register_rest_route( self::NS, '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'webhook' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP BE-3A — payment history + cancel subscription
		register_rest_route( self::NS, '/me/payments', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'me_payments' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-6 — referral code/link + privacy-safe affiliate summary for /gpt/myaccount/.
		register_rest_route( self::NS, '/me/affiliate', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'me_affiliate' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		register_rest_route( self::NS, '/me/cancel', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'me_cancel' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		// [2026-07-17 Johnny Chu] PHASE-D G-2 — profile update (name/phone/bio).
		register_rest_route( self::NS, '/me/profile', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'me_update_profile' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		// [2026-07-17 Johnny Chu] PHASE-D G-3 — authenticated password change.
		register_rest_route( self::NS, '/me/change-password', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'me_change_password' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		// [2026-07-17 Johnny Chu] PHASE-D G-4 — HTML invoice for a single payment.
		register_rest_route( self::NS, '/me/invoice/(?P<id>[A-Za-z0-9_\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'me_invoice' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
			'args'                => array(
				'id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
	}

	/* ── Permissions ────────────────────────────────────────────────────── */

	public static function require_login() {
		return is_user_logged_in()
			? true
			: new WP_Error( 'not_logged_in', 'Bạn cần đăng nhập.', array( 'status' => 401 ) );
	}

	public static function require_admin() {
		// [2026-07-17 Johnny Chu] SPRINT-10 SB-3A — restrict plan quick-sheet API to site admins.
		return current_user_can( 'manage_options' )
			? true
			: new WP_Error( 'permission_denied', 'Bạn không có quyền quản trị.', array( 'status' => 403 ) );
	}

	/* ── Endpoints ──────────────────────────────────────────────────────── */

	/**
	 * Public plan catalog (price label included for the Pricing UI).
	 */
	public static function plans( $request ) {
		$registry = BizCity_Membership_Plan_Registry::instance();
		$out      = array();
		foreach ( $registry->all() as $slug => $plan ) {
			$out[] = array(
				'slug'          => $slug,
				'label'         => isset( $plan['label'] ) ? $plan['label'] : ucfirst( $slug ),
				'price'         => isset( $plan['price'] ) ? (float) $plan['price'] : 0.0,
				'currency'      => isset( $plan['currency'] ) ? $plan['currency'] : 'USD',
				'billing_cycle' => isset( $plan['billing_cycle'] ) ? $plan['billing_cycle'] : 'lifetime',
				'price_label'   => $registry->price_label( $slug ),
				'limits'        => isset( $plan['limits'] ) ? $plan['limits'] : array(),
				'features'      => isset( $plan['features'] ) ? $plan['features'] : array(),
				'models'        => isset( $plan['models'] ) ? $plan['models'] : array(),
			);
		}
		return new WP_REST_Response( array( 'success' => true, 'plans' => $out ), 200 );
	}

	/**
	 * Admin plan quick-sheet payload for Twin GPT Commerce tab.
	 *
	 * GET /admin/plans-sheet
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function admin_get_plans_sheet( $request ) {
		// [2026-07-17 Johnny Chu] SPRINT-10 SB-3A — expose compact admin plan sheet payload for CG quick edit.
		unset( $request );
		if ( ! class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'_degraded' => true,
				'code'      => 'module_not_loaded',
				'message'   => 'Membership plan registry chưa sẵn sàng.',
				'hint'      => 'Kiểm tra trạng thái module Membership rồi tải lại trang Control Plane.',
				'help_code' => 'module_not_loaded',
				'plans'     => array(),
			), 200 );
		}

		$registry = BizCity_Membership_Plan_Registry::instance();
		$all      = $registry->all();
		$rows     = array();
		// [2026-07-17 Johnny Chu] SPRINT-12 SB-3B — include Woo offer mapping in plans-sheet response for Control Plane.
		$woo_offer_map = self::admin_plan_woo_offer_map();

		foreach ( $all as $slug => $plan ) {
			$rows[] = self::to_admin_plan_sheet_row( $slug, $plan, $woo_offer_map['by_plan'] );
		}

		usort( $rows, function ( $a, $b ) {
			$ra = isset( $a['rank'] ) ? (int) $a['rank'] : 0;
			$rb = isset( $b['rank'] ) ? (int) $b['rank'] : 0;
			if ( $ra === $rb ) {
				$sa = isset( $a['slug'] ) ? (string) $a['slug'] : '';
				$sb = isset( $b['slug'] ) ? (string) $b['slug'] : '';
				return strcmp( $sa, $sb );
			}
			return $ra < $rb ? -1 : 1;
		} );

		return new WP_REST_Response( array(
			'success'           => true,
			'plans'             => $rows,
			'woo_offer_summary' => array(
				'available'  => ! empty( $woo_offer_map['available'] ),
				'total'      => isset( $woo_offer_map['total'] ) ? (int) $woo_offer_map['total'] : 0,
				'updated_at' => isset( $woo_offer_map['updated_at'] ) ? (string) $woo_offer_map['updated_at'] : '',
			),
		), 200 );
	}

	/**
	 * Save admin quick-sheet edits to membership plan registry.
	 *
	 * PUT /admin/plans-sheet
	 * Body: { plans: [{slug,label,rank,consumes_seat,audience,price,billing_cycle,limits:{...}}] }
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function admin_put_plans_sheet( $request ) {
		// [2026-07-17 Johnny Chu] SPRINT-10 SB-3 — membership admin plan quick-sheet save endpoint for Twin GPT Commerce tab.
		if ( ! class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'_degraded' => true,
				'code'      => 'module_not_loaded',
				'message'   => 'Membership plan registry chưa sẵn sàng.',
				'hint'      => 'Kiểm tra module Membership trước khi lưu quick sheet.',
				'help_code' => 'module_not_loaded',
			), 200 );
		}

		$rows = $request->get_param( 'plans' );
		if ( ! is_array( $rows ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'code'      => 'invalid_param',
				'message'   => 'Thiếu payload plans[] để cập nhật.',
				'hint'      => 'Gửi lại request với body JSON có mảng plans hợp lệ.',
				'help_code' => 'invalid_param_generic',
			), 200 );
		}

		$registry = BizCity_Membership_Plan_Registry::instance();
		$all      = $registry->all();
		$updated  = 0;
		$invalid  = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$slug = isset( $row['slug'] ) ? sanitize_key( (string) $row['slug'] ) : '';
			if ( $slug === '' || ! isset( $all[ $slug ] ) || ! is_array( $all[ $slug ] ) ) {
				if ( $slug !== '' ) {
					$invalid[] = $slug;
				}
				continue;
			}

			$plan = $all[ $slug ];

			if ( array_key_exists( 'label', $row ) ) {
				$plan['label'] = sanitize_text_field( (string) $row['label'] );
			}
			if ( array_key_exists( 'rank', $row ) ) {
				$plan['rank'] = max( 0, min( 10000, (int) $row['rank'] ) );
			}
			if ( array_key_exists( 'consumes_seat', $row ) ) {
				$plan['consumes_seat'] = ! empty( $row['consumes_seat'] );
			}
			if ( array_key_exists( 'audience', $row ) ) {
				$plan['audience'] = sanitize_text_field( (string) $row['audience'] );
			}
			if ( array_key_exists( 'price', $row ) ) {
				$plan['price'] = max( 0, (float) $row['price'] );
			}
			if ( array_key_exists( 'billing_cycle', $row ) ) {
				$plan['billing_cycle'] = self::sanitize_billing_cycle( (string) $row['billing_cycle'] );
			}

			if ( isset( $row['limits'] ) && is_array( $row['limits'] ) ) {
				$limits = isset( $plan['limits'] ) && is_array( $plan['limits'] ) ? $plan['limits'] : array();
				$keys   = array( 'chat_msgs_per_day', 'kg_passages_per_day', 'image_per_day', 'video_per_day' );
				foreach ( $keys as $key ) {
					if ( array_key_exists( $key, $row['limits'] ) ) {
						$limits[ $key ] = max( 0, (int) $row['limits'][ $key ] );
					}
				}
				$plan['limits'] = $limits;
			}

			$all[ $slug ] = $plan;
			$updated++;
		}

		if ( $updated <= 0 ) {
			return new WP_REST_Response( array(
				'success'      => false,
				'code'         => 'invalid_param',
				'message'      => 'Không có plan hợp lệ để cập nhật.',
				'hint'         => 'Kiểm tra slug plan trong payload và thử lưu lại.',
				'help_code'    => 'invalid_param_generic',
				'invalid_slugs'=> array_values( array_unique( $invalid ) ),
			), 200 );
		}

		$registry->save( $all );
		$registry->flush_cache();
		if ( class_exists( 'BizCity_Membership_Entitlement' ) ) {
			BizCity_Membership_Entitlement::instance()->flush_cache( 0 );
		}

		do_action( 'bizcity_twinweb_flush_effective_config', (int) get_current_blog_id() );
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'bizcity_twinweb' );
		}

		// [2026-07-17 Johnny Chu] SPRINT-12 SB-3B — return Woo offer mapping alongside updated plans for one-shot admin refresh.
		$woo_offer_map = self::admin_plan_woo_offer_map();

		$out = array();
		foreach ( $registry->all() as $slug => $plan ) {
			$out[] = self::to_admin_plan_sheet_row( $slug, $plan, $woo_offer_map['by_plan'] );
		}

		usort( $out, function ( $a, $b ) {
			$ra = isset( $a['rank'] ) ? (int) $a['rank'] : 0;
			$rb = isset( $b['rank'] ) ? (int) $b['rank'] : 0;
			if ( $ra === $rb ) {
				$sa = isset( $a['slug'] ) ? (string) $a['slug'] : '';
				$sb = isset( $b['slug'] ) ? (string) $b['slug'] : '';
				return strcmp( $sa, $sb );
			}
			return $ra < $rb ? -1 : 1;
		} );

		return new WP_REST_Response( array(
			'success'           => true,
			'updated_count'     => $updated,
			'invalid_slugs'     => array_values( array_unique( $invalid ) ),
			'plans'             => $out,
			'woo_offer_summary' => array(
				'available'  => ! empty( $woo_offer_map['available'] ),
				'total'      => isset( $woo_offer_map['total'] ) ? (int) $woo_offer_map['total'] : 0,
				'updated_at' => isset( $woo_offer_map['updated_at'] ) ? (string) $woo_offer_map['updated_at'] : '',
			),
		), 200 );
	}

	/**
	 * Preview built-in Membership policy templates for admin import.
	 *
	 * GET /admin/plan-templates
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function admin_get_plan_templates( $request ) {
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-2 — expose JSON policy templates as preview-only admin payload.
		unset( $request );
		$current = class_exists( 'BizCity_Membership_Plan_Registry' )
			? BizCity_Membership_Plan_Registry::instance()->all()
			: array();

		$templates = self::load_membership_plan_templates( $current );
		foreach ( $templates as $idx => $template ) {
			if ( isset( $templates[ $idx ]['raw_plans'] ) ) {
				unset( $templates[ $idx ]['raw_plans'] );
			}
		}
		return new WP_REST_Response( array(
			'success'   => true,
			'templates' => $templates,
		), 200 );
	}

	/**
	 * Import a built-in Membership policy template.
	 *
	 * POST /admin/plan-templates/import
	 * Body: { template_id:string, overwrite?:bool }
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function admin_import_plan_template( $request ) {
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-2 — missing-only template import; explicit overwrite required for custom plans.
		if ( ! class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'_degraded' => true,
				'code'      => 'module_not_loaded',
				'message'   => 'Membership plan registry chưa sẵn sàng.',
				'hint'      => 'Kiểm tra module Membership rồi tải lại trang quản trị.',
				'help_code' => 'module_not_loaded',
			), 200 );
		}

		$template_id = sanitize_key( (string) $request->get_param( 'template_id' ) );
		$overwrite   = ! empty( $request->get_param( 'overwrite' ) );
		if ( $template_id === '' ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'code'      => 'invalid_param',
				'message'   => 'Thiếu template_id để import.',
				'hint'      => 'Chọn một template Membership policy rồi import lại.',
				'help_code' => 'invalid_param_generic',
			), 200 );
		}

		$registry  = BizCity_Membership_Plan_Registry::instance();
		$current   = $registry->all();
		$templates = self::load_membership_plan_templates( $current );
		$selected  = null;
		foreach ( $templates as $template ) {
			if ( is_array( $template ) && sanitize_key( (string) ( $template['template_id'] ?? '' ) ) === $template_id ) {
				$selected = $template;
				break;
			}
		}

		if ( ! is_array( $selected ) || empty( $selected['importable'] ) || empty( $selected['raw_plans'] ) || ! is_array( $selected['raw_plans'] ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'code'      => 'invalid_param',
				'message'   => 'Template không tồn tại hoặc không hỗ trợ import plan.',
				'hint'      => 'Chọn template có schema Membership policy và thử lại.',
				'help_code' => 'invalid_param_generic',
			), 200 );
		}

		$all      = $current;
		$imported = array();
		$updated  = array();
		$skipped  = array();

		foreach ( $selected['raw_plans'] as $plan_row ) {
			if ( ! is_array( $plan_row ) ) {
				continue;
			}
			$slug = sanitize_key( (string) ( $plan_row['slug'] ?? '' ) );
			if ( $slug === '' ) {
				continue;
			}

			$exists = isset( $all[ $slug ] ) && is_array( $all[ $slug ] );
			if ( $exists && ! $overwrite ) {
				$skipped[] = $slug;
				continue;
			}

			$all[ $slug ] = self::merge_template_plan_row( $exists ? $all[ $slug ] : array(), $plan_row );
			if ( $exists ) {
				$updated[] = $slug;
			} else {
				$imported[] = $slug;
			}
		}

		if ( empty( $imported ) && empty( $updated ) ) {
			return new WP_REST_Response( array(
				'success'      => true,
				'changed'      => false,
				'imported'     => array(),
				'updated'      => array(),
				'skipped'      => $skipped,
				'message'      => 'Không có plan mới để import; các slug đã tồn tại.',
				'template_id'  => $template_id,
				'overwrite'    => $overwrite,
			), 200 );
		}

		$registry->save( $all );
		$registry->flush_cache();
		if ( class_exists( 'BizCity_Membership_Entitlement' ) ) {
			BizCity_Membership_Entitlement::instance()->flush_cache( 0 );
		}
		do_action( 'bizcity_twinweb_flush_effective_config', (int) get_current_blog_id() );
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'bizcity_twinweb' );
		}

		return new WP_REST_Response( array(
			'success'     => true,
			'changed'     => true,
			'template_id' => $template_id,
			'overwrite'   => $overwrite,
			'imported'    => $imported,
			'updated'     => $updated,
			'skipped'     => $skipped,
			'plans'       => array_values( array_map( function ( $slug ) use ( $registry ) {
				return self::to_admin_plan_sheet_row( $slug, $registry->get( $slug ) );
			}, array_unique( array_merge( $imported, $updated ) ) ) ),
		), 200 );
	}

	/**
	 * Load built-in plan templates from disk.
	 *
	 * @param array $current_plans Current plan map.
	 * @return array
	 */
	private static function load_membership_plan_templates( array $current_plans ) {
		$dir = defined( 'BIZCITY_TWIN_AI_DIR' )
			? BIZCITY_TWIN_AI_DIR . 'core/membership/templates/'
			: dirname( __DIR__ ) . '/templates/';
		$files = is_dir( $dir ) ? glob( $dir . '*.json' ) : array();
		$out   = array();

		foreach ( (array) $files as $file ) {
			if ( ! is_readable( $file ) ) {
				continue;
			}
			$data = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}

			$template_id = sanitize_key( (string) ( $data['template_id'] ?? basename( $file, '.json' ) ) );
			$plans       = isset( $data['plans'] ) && is_array( $data['plans'] ) ? array_values( $data['plans'] ) : array();
			$presets     = isset( $data['presets'] ) && is_array( $data['presets'] ) ? $data['presets'] : array();
			$preview     = array();

			foreach ( $plans as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$slug = sanitize_key( (string) ( $row['slug'] ?? '' ) );
				if ( $slug === '' ) {
					continue;
				}
				$preview[] = array(
					'slug'          => $slug,
					'label'         => sanitize_text_field( (string) ( $row['label'] ?? ucfirst( $slug ) ) ),
					'rank'          => isset( $row['rank'] ) ? (int) $row['rank'] : 0,
					'audience'      => sanitize_text_field( (string) ( $row['audience'] ?? '' ) ),
					'consumes_seat' => ! empty( $row['consumes_seat'] ),
					'exists'        => isset( $current_plans[ $slug ] ),
					'default_action'=> isset( $current_plans[ $slug ] ) ? 'skip_existing' : 'import_missing',
					'limits'        => isset( $row['limits'] ) && is_array( $row['limits'] ) ? $row['limits'] : array(),
					'models'        => isset( $row['models'] ) && is_array( $row['models'] ) ? array_values( $row['models'] ) : array(),
					'answer_modes'  => isset( $row['answer_modes'] ) && is_array( $row['answer_modes'] ) ? array_values( $row['answer_modes'] ) : array(),
				);
			}

			$out[] = array(
				'template_id'  => $template_id,
				'label'        => sanitize_text_field( (string) ( $data['label'] ?? $template_id ) ),
				'schema'       => sanitize_text_field( (string) ( $data['schema'] ?? '' ) ),
				'version'      => sanitize_text_field( (string) ( $data['version'] ?? '' ) ),
				'phase_id'     => sanitize_text_field( (string) ( $data['phase_id'] ?? '' ) ),
				'importable'   => ! empty( $preview ),
				'plan_count'   => count( $preview ),
				'preset_count' => count( $presets ),
				'plans'        => $preview,
				'raw_plans'    => $plans,
			);
		}

		usort( $out, function ( $a, $b ) {
			return strcmp( (string) ( $a['template_id'] ?? '' ), (string) ( $b['template_id'] ?? '' ) );
		} );

		return $out;
	}

	/**
	 * Merge a template plan row into an existing plan without touching billing fields unless provided.
	 *
	 * @param array $existing Existing normalized plan.
	 * @param array $row      Template plan row.
	 * @return array
	 */
	private static function merge_template_plan_row( array $existing, array $row ) {
		$out = $existing;
		$scalar_keys = array( 'label', 'rank', 'audience', 'consumes_seat', 'price', 'currency', 'billing_cycle', 'paypal_plan_id', 'kg_max_file_size_mb', 'kg_file_types_mode' );
		foreach ( $scalar_keys as $key ) {
			if ( array_key_exists( $key, $row ) ) {
				$out[ $key ] = $row[ $key ];
			}
		}

		$array_keys = array( 'limits', 'features', 'models', 'answer_modes', 'kg_accepted_file_types' );
		foreach ( $array_keys as $key ) {
			if ( isset( $row[ $key ] ) && is_array( $row[ $key ] ) ) {
				$out[ $key ] = $row[ $key ];
			}
		}

		return $out;
	}

	/**
	 * Normalize one plan into quick-sheet row shape.
	 *
	 * @param string $slug Plan slug.
	 * @param array  $plan Plan payload.
	 * @return array
	 */
	private static function to_admin_plan_sheet_row( $slug, array $plan, array $woo_offers_by_plan = array() ) {
		$limits = isset( $plan['limits'] ) && is_array( $plan['limits'] ) ? $plan['limits'] : array();
		$slug   = sanitize_key( (string) $slug );
		$offers = isset( $woo_offers_by_plan[ $slug ] ) && is_array( $woo_offers_by_plan[ $slug ] )
			? array_values( $woo_offers_by_plan[ $slug ] )
			: array();

		return array(
			'slug'          => $slug,
			'label'         => isset( $plan['label'] ) ? sanitize_text_field( (string) $plan['label'] ) : ucfirst( (string) $slug ),
			'rank'          => isset( $plan['rank'] ) ? (int) $plan['rank'] : 0,
			'consumes_seat' => ! empty( $plan['consumes_seat'] ),
			'audience'      => isset( $plan['audience'] ) ? sanitize_text_field( (string) $plan['audience'] ) : '',
			'price'         => isset( $plan['price'] ) ? (float) $plan['price'] : 0.0,
			'currency'      => isset( $plan['currency'] ) ? sanitize_text_field( (string) $plan['currency'] ) : 'USD',
			'billing_cycle' => self::sanitize_billing_cycle( isset( $plan['billing_cycle'] ) ? (string) $plan['billing_cycle'] : 'month' ),
			'limits'        => array(
				'chat_msgs_per_day'   => isset( $limits['chat_msgs_per_day'] ) ? (int) $limits['chat_msgs_per_day'] : 0,
				'kg_passages_per_day' => isset( $limits['kg_passages_per_day'] ) ? (int) $limits['kg_passages_per_day'] : 0,
				'image_per_day'       => isset( $limits['image_per_day'] ) ? (int) $limits['image_per_day'] : 0,
				'video_per_day'       => isset( $limits['video_per_day'] ) ? (int) $limits['video_per_day'] : 0,
			),
			'woo_offers'    => $offers,
		);
	}

	/**
	 * [2026-07-17 Johnny Chu] SPRINT-12 SB-3B — resolve Woo offer map grouped by plan
	 * for admin plans-sheet responses.
	 *
	 * @return array{available:bool,total:int,updated_at:string,by_plan:array}
	 */
	private static function admin_plan_woo_offer_map() {
		$out = array(
			'available'  => false,
			'total'      => 0,
			'updated_at' => '',
			'by_plan'    => array(),
		);

		if ( ! class_exists( 'BizCity_Membership_Woo_Mapper' ) ) {
			return $out;
		}

		$map = (array) BizCity_Membership_Woo_Mapper::instance()->get_map();
		$out['available']  = true;
		$out['updated_at'] = isset( $map['updated_at'] ) ? (string) $map['updated_at'] : '';
		$items             = isset( $map['items'] ) && is_array( $map['items'] ) ? $map['items'] : array();

		foreach ( $items as $offer_code => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$plan_slug = sanitize_key( (string) ( isset( $row['plan_slug'] ) ? $row['plan_slug'] : '' ) );
			if ( $plan_slug === '' ) {
				continue;
			}

			$duration_unit = sanitize_key( (string) ( isset( $row['duration_unit'] ) ? $row['duration_unit'] : 'month' ) );
			if ( ! in_array( $duration_unit, array( 'day', 'week', 'month', 'year', 'lifetime' ), true ) ) {
				$duration_unit = 'month';
			}

			$item = array(
				'offer_code'     => sanitize_key( (string) $offer_code ),
				'plan_slug'      => $plan_slug,
				'duration_count' => max( 1, (int) ( isset( $row['duration_count'] ) ? $row['duration_count'] : 1 ) ),
				'duration_unit'  => $duration_unit,
				'grant_mode'     => sanitize_key( (string) ( isset( $row['grant_mode'] ) ? $row['grant_mode'] : 'replace' ) ),
				'product_id'     => isset( $row['product_id'] ) ? (int) $row['product_id'] : 0,
				'variation_id'   => isset( $row['variation_id'] ) ? (int) $row['variation_id'] : 0,
				'source'         => sanitize_key( (string) ( isset( $row['source'] ) ? $row['source'] : 'product' ) ),
			);

			if ( ! isset( $out['by_plan'][ $plan_slug ] ) ) {
				$out['by_plan'][ $plan_slug ] = array();
			}
			$out['by_plan'][ $plan_slug ][] = $item;
			$out['total']++;
		}

		foreach ( $out['by_plan'] as $plan_slug => $rows ) {
			usort( $rows, function ( $a, $b ) {
				$unit_order = array( 'day' => 1, 'week' => 2, 'month' => 3, 'year' => 4, 'lifetime' => 5 );
				$ua = isset( $unit_order[ (string) $a['duration_unit'] ] ) ? (int) $unit_order[ (string) $a['duration_unit'] ] : 99;
				$ub = isset( $unit_order[ (string) $b['duration_unit'] ] ) ? (int) $unit_order[ (string) $b['duration_unit'] ] : 99;
				if ( $ua === $ub ) {
					$ca = isset( $a['duration_count'] ) ? (int) $a['duration_count'] : 0;
					$cb = isset( $b['duration_count'] ) ? (int) $b['duration_count'] : 0;
					if ( $ca === $cb ) {
						$oa = isset( $a['offer_code'] ) ? (string) $a['offer_code'] : '';
						$ob = isset( $b['offer_code'] ) ? (string) $b['offer_code'] : '';
						return strcmp( $oa, $ob );
					}
					return $ca < $cb ? -1 : 1;
				}
				return $ua < $ub ? -1 : 1;
			} );
			$out['by_plan'][ $plan_slug ] = array_values( $rows );
		}

		return $out;
	}

	/**
	 * Sanitize billing cycle to known values.
	 *
	 * @param string $value Raw cycle.
	 * @return string
	 */
	private static function sanitize_billing_cycle( $value ) {
		$value = sanitize_key( (string) $value );
		$allowed = array( 'day', 'week', 'month', 'year', 'lifetime', 'once' );
		return in_array( $value, $allowed, true ) ? $value : 'month';
	}

	/**
	 * Current user's latest Woo projection status for account continuity UI.
	 *
	 * @param int $uid Current user ID.
	 * @return array
	 */
	private static function current_user_woo_projection( $uid ) {
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-6 — expose read-only Woo projection state in /me for account checkout continuity.
		$uid = (int) $uid;
		$out = array(
			'available'        => false,
			'_degraded'        => false,
			'degraded_reasons' => array(),
			'status'           => 'none',
			'order_id'         => 0,
			'order_number'     => '',
			'order_status'     => '',
			'offer_code'       => '',
			'plan_slug'        => '',
			'reason'           => '',
			'projected_at'     => '',
			'applied_at'       => '',
			'expires_at'       => '',
			'seat_delta'       => 0,
		);

		if ( $uid <= 0 ) {
			return $out;
		}

		if ( ! class_exists( 'BizCity_Membership_Woo_Projector' ) || ! BizCity_Membership_Woo_Projector::woo_ready() ) {
			$out['_degraded']        = true;
			$out['degraded_reasons'] = array( 'woo_inactive' );
			return $out;
		}

		$out['available'] = true;
		$order = null;
		$order_id = (int) ( class_exists( 'BizCity_User_Meta_Cache' )
			? BizCity_User_Meta_Cache::get( $uid, BizCity_Membership_Woo_Projector::USER_META_OFFER_ORDER_ID, 0 )
			: get_user_meta( $uid, BizCity_Membership_Woo_Projector::USER_META_OFFER_ORDER_ID, true ) );
		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( ! $order || ! is_object( $order ) || (int) $order->get_user_id() !== $uid ) {
				$order = null;
			}
		}

		if ( ! $order && function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( array(
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
				'customer_id' => $uid,
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => BizCity_Membership_Woo_Projector::META_STATUS,
						'compare' => 'EXISTS',
					),
				),
			) );
			if ( ! empty( $orders ) && is_object( $orders[0] ) ) {
				$order = $orders[0];
			}
		}

		if ( ! $order || ! is_object( $order ) ) {
			$out['offer_code'] = (string) ( class_exists( 'BizCity_User_Meta_Cache' ) ? BizCity_User_Meta_Cache::get( $uid, BizCity_Membership_Woo_Projector::USER_META_OFFER_CODE, '' ) : get_user_meta( $uid, BizCity_Membership_Woo_Projector::USER_META_OFFER_CODE, true ) );
			$out['plan_slug']  = (string) ( class_exists( 'BizCity_User_Meta_Cache' ) ? BizCity_User_Meta_Cache::get( $uid, BizCity_Membership_Woo_Projector::USER_META_OFFER_PLAN, '' ) : get_user_meta( $uid, BizCity_Membership_Woo_Projector::USER_META_OFFER_PLAN, true ) );
			$out['applied_at'] = (string) ( class_exists( 'BizCity_User_Meta_Cache' ) ? BizCity_User_Meta_Cache::get( $uid, BizCity_Membership_Woo_Projector::USER_META_OFFER_APPLIED_AT, '' ) : get_user_meta( $uid, BizCity_Membership_Woo_Projector::USER_META_OFFER_APPLIED_AT, true ) );
			return $out;
		}

		$out['order_id']     = (int) $order->get_id();
		$out['order_number'] = method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $out['order_id'];
		$out['order_status'] = method_exists( $order, 'get_status' ) ? sanitize_key( (string) $order->get_status() ) : '';
		$out['status']       = sanitize_key( (string) $order->get_meta( BizCity_Membership_Woo_Projector::META_STATUS, true ) );
		$out['status']       = $out['status'] !== '' ? $out['status'] : 'none';
		$out['offer_code']   = sanitize_key( (string) $order->get_meta( BizCity_Membership_Woo_Projector::META_OFFER_CODE, true ) );
		$out['plan_slug']    = sanitize_key( (string) $order->get_meta( BizCity_Membership_Woo_Projector::META_PLAN_SLUG, true ) );
		$out['reason']       = sanitize_key( (string) $order->get_meta( BizCity_Membership_Woo_Projector::META_LAST_REASON, true ) );
		$out['projected_at'] = (string) $order->get_meta( BizCity_Membership_Woo_Projector::META_PROJECTED_AT, true );
		$out['applied_at']   = (string) ( class_exists( 'BizCity_User_Meta_Cache' ) ? BizCity_User_Meta_Cache::get( $uid, BizCity_Membership_Woo_Projector::USER_META_OFFER_APPLIED_AT, '' ) : get_user_meta( $uid, BizCity_Membership_Woo_Projector::USER_META_OFFER_APPLIED_AT, true ) );
		$out['expires_at']   = (string) $order->get_meta( BizCity_Membership_Woo_Projector::META_EXPIRES_AT, true );
		$out['seat_delta']   = (int) $order->get_meta( BizCity_Membership_Woo_Projector::META_SEAT_DELTA, true );

		return $out;
	}

	/**
	 * Hub seat capacity snapshot for end-user commerce continuity.
	 *
	 * @return array
	 */
	private static function commerce_capacity_snapshot() {
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-6 — surface Hub seat capacity contract in /me without exposing member identity.
		if ( class_exists( 'BizCity_Membership_Woo_Projector' ) && method_exists( 'BizCity_Membership_Woo_Projector', 'get_capacity_snapshot' ) ) {
			$snapshot = BizCity_Membership_Woo_Projector::get_capacity_snapshot();
			return is_array( $snapshot ) ? $snapshot : array();
		}
		return array(
			'seat_limit'       => null,
			'seat_used'        => 0,
			'seat_remaining'   => null,
			'at_capacity'      => false,
			'over_capacity'    => false,
			'capacity_bucket'  => 'capacity_unknown',
			'_degraded'        => true,
			'degraded_reasons' => array( 'projector_missing' ),
		);
	}

	/**
	 * Deterministic privacy-safe referral code for current-user account UI.
	 *
	 * @param int $uid User ID.
	 * @return string
	 */
	private static function current_user_referral_code( $uid ) {
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-6 — generate stable code without exposing raw user ID or creating a new ledger.
		$uid = max( 0, (int) $uid );
		if ( $uid <= 0 ) {
			return '';
		}
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : (string) get_option( 'siteurl', '' );
		$hash = hash_hmac( 'sha256', (string) get_current_blog_id() . '|' . (string) $uid, $salt );
		return 'TWIN-' . strtoupper( substr( $hash, 0, 10 ) );
	}

	/**
	 * Privacy-safe affiliate snapshot for account dashboard.
	 *
	 * External affiliate/wallet modules can provide canonical stats through
	 * `bizcity_membership_affiliate_payload`; fallback intentionally returns
	 * zero stats with _degraded=true instead of fake conversions.
	 *
	 * @param int $uid User ID.
	 * @return array
	 */
	private static function current_user_affiliate_snapshot( $uid ) {
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-6 — account-safe affiliate read model contract without duplicate ledger.
		$uid  = max( 0, (int) $uid );
		$code = self::current_user_referral_code( $uid );
		$base = array(
			'available'        => false,
			'_degraded'        => true,
			'degraded_reasons' => array( 'affiliate_read_model_unavailable' ),
			'source'           => 'fallback',
			'referral_code'    => $code,
			'share_url'        => $code !== '' ? add_query_arg( 'ref', rawurlencode( $code ), home_url( '/gpt/' ) ) : '',
			'stats'            => array(
				'clicks'           => 0,
				'signups'          => 0,
				'paid_conversions' => 0,
				'pending_reward'   => 0.0,
				'payable_reward'   => 0.0,
				'total_reward'     => 0.0,
				'currency'         => 'USD',
			),
			'referred_accounts' => array(),
			'payout'            => array(
				'available' => false,
				'status'    => 'not_connected',
				'method'    => '',
				'currency'  => 'USD',
				'message'   => 'Affiliate payout chưa kết nối với Wallet.',
			),
		);

		$provided = apply_filters( 'bizcity_membership_affiliate_payload', null, $uid, $base );
		if ( is_array( $provided ) ) {
			$base = array_merge( $base, $provided );
			if ( ! isset( $provided['_degraded'] ) ) {
				$base['_degraded'] = false;
			}
			if ( empty( $provided['source'] ) ) {
				$base['source'] = 'provider';
			}
		}

		return self::sanitize_affiliate_payload( $base );
	}

	/**
	 * Sanitize affiliate payload and strip referred-user PII.
	 *
	 * @param array $payload Raw payload.
	 * @return array
	 */
	private static function sanitize_affiliate_payload( $payload ) {
		$stats  = isset( $payload['stats'] ) && is_array( $payload['stats'] ) ? $payload['stats'] : array();
		$payout = isset( $payload['payout'] ) && is_array( $payload['payout'] ) ? $payload['payout'] : array();
		$rows   = isset( $payload['referred_accounts'] ) && is_array( $payload['referred_accounts'] ) ? $payload['referred_accounts'] : array();
		$safe_rows = array();
		foreach ( array_slice( $rows, 0, 20 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$safe_rows[] = array(
				'masked_label'  => sanitize_text_field( (string) ( $row['masked_label'] ?? 'Người dùng ẩn danh' ) ),
				'status'        => sanitize_key( (string) ( $row['status'] ?? 'pending' ) ),
				'joined_at'     => sanitize_text_field( (string) ( $row['joined_at'] ?? '' ) ),
				'paid_at'       => sanitize_text_field( (string) ( $row['paid_at'] ?? '' ) ),
				'reward_status' => sanitize_key( (string) ( $row['reward_status'] ?? 'pending' ) ),
			);
		}

		return array(
			'success'          => true,
			'available'        => ! empty( $payload['available'] ),
			'_degraded'        => ! empty( $payload['_degraded'] ),
			'degraded_reasons' => isset( $payload['degraded_reasons'] ) && is_array( $payload['degraded_reasons'] ) ? array_values( array_map( 'sanitize_key', $payload['degraded_reasons'] ) ) : array(),
			'source'           => sanitize_key( (string) ( $payload['source'] ?? 'fallback' ) ),
			'referral_code'    => sanitize_text_field( (string) ( $payload['referral_code'] ?? '' ) ),
			'share_url'        => esc_url_raw( (string) ( $payload['share_url'] ?? '' ) ),
			'stats'            => array(
				'clicks'           => max( 0, (int) ( $stats['clicks'] ?? 0 ) ),
				'signups'          => max( 0, (int) ( $stats['signups'] ?? 0 ) ),
				'paid_conversions' => max( 0, (int) ( $stats['paid_conversions'] ?? 0 ) ),
				'pending_reward'   => max( 0, (float) ( $stats['pending_reward'] ?? 0 ) ),
				'payable_reward'   => max( 0, (float) ( $stats['payable_reward'] ?? 0 ) ),
				'total_reward'     => max( 0, (float) ( $stats['total_reward'] ?? 0 ) ),
				'currency'         => sanitize_text_field( (string) ( $stats['currency'] ?? 'USD' ) ),
			),
			'referred_accounts' => $safe_rows,
			'payout'            => array(
				'available' => ! empty( $payout['available'] ),
				'status'    => sanitize_key( (string) ( $payout['status'] ?? 'not_connected' ) ),
				'method'    => sanitize_text_field( (string) ( $payout['method'] ?? '' ) ),
				'currency'  => sanitize_text_field( (string) ( $payout['currency'] ?? ( $stats['currency'] ?? 'USD' ) ) ),
				'message'   => sanitize_text_field( (string) ( $payout['message'] ?? '' ) ),
			),
		);
	}

	/**
	 * Current user's effective entitlement + usage snapshot.
	 */
	public static function me( $request ) {
		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP BE-3A — add subscription + profile blocks
		$uid = get_current_user_id();
		$ent = BizCity_Membership_Entitlement::instance()->for_user( $uid );

		$usage = class_exists( 'BizCity_Membership_Usage' )
			? BizCity_Membership_Usage::instance()->snapshot( $uid )
			: array();

		$paypal_enabled = class_exists( 'BizCity_Membership_PayPal_Gateway' )
			&& BizCity_Membership_PayPal_Gateway::instance()->is_ready();

		// Subscription row (latest, any status).
		$subscription = array();
		if ( class_exists( 'BizCity_Membership_Manager' ) ) {
			$sub_row = BizCity_Membership_Manager::instance()->latest_subscription( $uid );
			if ( $sub_row ) {
				$subscription = array(
					'status'                 => (string) $sub_row['status'],
					'plan_slug'              => (string) $sub_row['plan_slug'],
					'paypal_subscription_id' => (string) $sub_row['paypal_subscription_id'],
					'start_date'             => (string) $sub_row['start_date'],
					'expiration_date'        => (string) $sub_row['expiration_date'],
					'source'                 => (string) $sub_row['source'],
				);
			}
		}

		// [2026-06-17 Johnny Chu] PHASE-PLANS-UNIFIED — include hub master plan info
		// from bizcity_hub_* options (synced via BizCity_LLM_Client::get_plan_config).
		// This is the canonical tier for LLM/KG/astro quota — separate from local subscription.
		$hub_plan = array(
			'master_level'      => (string) get_option( 'bizcity_hub_master_level', 'free' ),
			'master_label'      => (string) get_option( 'bizcity_hub_master_label', 'Free' ),
			'price_usd'         => (float)  get_option( 'bizcity_hub_price_usd', 0 ),
			'monthly_credit_usd'=> (float)  get_option( 'bizcity_hub_monthly_credit_usd', 0 ),
			'daily_cap_usd'     => (float)  get_option( 'bizcity_hub_daily_cap_usd', 1 ),
			'max_requests_day'  => (int)    get_option( 'bizcity_hub_max_requests_day', 100 ),
			'image_calls_day'   => (int)    get_option( 'bizcity_hub_image_calls_day', 5 ),
			'video_calls_day'   => (int)    get_option( 'bizcity_hub_video_calls_day', 1 ),
			'kg_batch_size'     => (int)    get_option( 'bizcity_hub_kg_batch_size', 5 ),
			'kg_quota_per_user' => (int)    get_option( 'bizcity_hub_kg_quota_per_user', 100 ),
			'plugins_enabled'   => json_decode( (string) get_option( 'bizcity_hub_plugins_enabled', '[]' ), true ),
		);
		// Fetch fresh from hub if stale (no master_level cached or request param refresh=1).
		$refresh = (bool) $request->get_param( 'refresh' );
		if ( $refresh || $hub_plan['master_level'] === 'free' ) {
			if ( class_exists( 'BizCity_LLM_Client' ) ) {
				$llm = BizCity_LLM_Client::instance();
				if ( $llm->is_ready() ) {
					$fresh = $llm->get_plan_config( array( 'force_refresh' => $refresh ) );
					if ( is_array( $fresh ) && ! empty( $fresh['ok'] ) ) {
						$hub_plan['master_level']       = (string) ( $fresh['master_level'] ?? 'free' );
						$hub_plan['master_label']       = (string) ( $fresh['master_label'] ?? 'Free' );
						$hub_plan['price_usd']          = (float)  ( $fresh['plan']['price_usd'] ?? 0 );
						$hub_plan['monthly_credit_usd'] = (float)  ( $fresh['plan']['monthly_credit_usd'] ?? 0 );
						$hub_plan['daily_cap_usd']      = (float)  ( $fresh['plan']['daily_cap_usd'] ?? 1 );
						$hub_plan['max_requests_day']   = (int)    ( $fresh['plan']['max_requests_day'] ?? 100 );
						$hub_plan['image_calls_day']    = (int)    ( $fresh['plan']['image_calls_day'] ?? 5 );
						$hub_plan['video_calls_day']    = (int)    ( $fresh['plan']['video_calls_day'] ?? 1 );
						$hub_plan['kg_batch_size']      = (int)    ( $fresh['kg_config']['batch_size'] ?? 5 );
						$hub_plan['kg_quota_per_user']  = (int)    ( $fresh['kg_config']['quota_per_user'] ?? 100 );
						$hub_plan['plugins_enabled']    = isset( $fresh['plugins_enabled'] )
							? $fresh['plugins_enabled']
							: ( $fresh['features'] ?? array() );
					}
				}
			}
		}

		// WP user profile + usermeta.
		// [2026-06-05 Johnny Chu] PHASE-MEMBERSHIP BE-3A — extend profile with first_name/last_name/phone/bio
		$wp_user = get_userdata( $uid );
		$profile = array(
			'display_name' => $wp_user ? (string) $wp_user->display_name : '',
			'first_name'   => $wp_user ? (string) $wp_user->first_name   : '',
			'last_name'    => $wp_user ? (string) $wp_user->last_name    : '',
			'email'        => $wp_user ? (string) $wp_user->user_email   : '',
			'phone'        => class_exists( 'BizCity_User_Meta_Cache' ) ? (string) BizCity_User_Meta_Cache::get( $uid, 'phone', '' ) : (string) get_user_meta( $uid, 'phone', true ), // [2026-06-22 Johnny Chu] R-PERF
			'bio'          => $wp_user ? (string) $wp_user->description  : '',
			'avatar_url'   => get_avatar_url( $uid, array( 'size' => 96 ) ),
			'gravatar_url' => 'https://www.gravatar.com/profile',
			'registered'   => $wp_user ? substr( $wp_user->user_registered, 0, 10 ) : '',
			'username'     => $wp_user ? (string) $wp_user->user_login    : '',
		);

		return new WP_REST_Response( array(
			'success'        => true,
			'entitlement'    => $ent,
			'usage'          => $usage,
			'paypal_enabled' => $paypal_enabled,
			'subscription'   => $subscription,
			'profile'        => $profile,
			'woo_projection' => self::current_user_woo_projection( $uid ),
			'commerce_capacity' => self::commerce_capacity_snapshot(),
			// [2026-06-17 Johnny Chu] PHASE-PLANS-UNIFIED — hub master plan for LLM/KG/astro
			'hub_plan'       => $hub_plan,
		), 200 );
	}

	/**
	 * Create a one-time PayPal order. Returns approve_url for FE redirect.
	 * Fail-OPEN: config/gateway errors → 200 + _degraded.
	 */
	public static function checkout( $request ) {
		$plan_slug  = sanitize_key( (string) $request->get_param( 'plan_slug' ) );
		$return_url = esc_url_raw( (string) $request->get_param( 'return_url' ) );
		$cancel_url = esc_url_raw( (string) $request->get_param( 'cancel_url' ) );
		$uid        = get_current_user_id();

		if ( $plan_slug === '' ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Thiếu plan_slug.' ), 200 );
		}

		if ( ! class_exists( 'BizCity_Membership_PayPal_Gateway' ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'PayPal gateway chưa load.',
			), 200 );
		}

		$gateway = BizCity_Membership_PayPal_Gateway::instance();
		if ( ! $gateway->is_ready() ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'PayPal chưa được cấu hình trên site này.',
			), 200 );
		}

		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7-recurring — recurring plans use
		// PayPal Subscriptions v2 (auto-charge); one-time/lifetime use Orders v2.
		$plan = BizCity_Membership_Plan_Registry::instance()->get( $plan_slug );
		if ( $gateway->is_recurring_plan( $plan ) ) {
			$sub = $gateway->create_subscription( $plan_slug, $uid, $return_url, $cancel_url );
			if ( is_wp_error( $sub ) ) {
				return new WP_REST_Response( array(
					'success'   => false,
					'_degraded' => true,
					'message'   => $sub->get_error_message(),
				), 200 );
			}
			return new WP_REST_Response( array(
				'success'         => true,
				'kind'            => 'subscription',
				'subscription_id' => $sub['id'],
				'approve_url'     => $sub['approve_url'],
			), 200 );
		}

		$order = $gateway->create_order( $plan_slug, $uid, $return_url, $cancel_url );
		if ( is_wp_error( $order ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => $order->get_error_message(),
			), 200 );
		}

		return new WP_REST_Response( array(
			'success'     => true,
			'kind'        => 'order',
			'order_id'    => $order['id'],
			'approve_url' => $order['approve_url'],
		), 200 );
	}

	/**
	 * Capture an approved order (one-time) OR activate an approved subscription
	 * (recurring). PayPal returns ?token=<order_id> for orders and
	 * ?subscription_id=<id> for subscriptions.
	 */
	public static function capture( $request ) {
		if ( ! class_exists( 'BizCity_Membership_PayPal_Gateway' ) ) {
			return new WP_REST_Response( array( 'success' => false, '_degraded' => true, 'message' => 'PayPal gateway chưa load.' ), 200 );
		}
		$gateway = BizCity_Membership_PayPal_Gateway::instance();

		// Recurring path: a subscription_id means PayPal approved a subscription.
		$subscription_id = sanitize_text_field( (string) $request->get_param( 'subscription_id' ) );
		if ( $subscription_id !== '' ) {
			$result = $gateway->activate_subscription( $subscription_id );
			if ( is_wp_error( $result ) ) {
				return new WP_REST_Response( array(
					'success'   => false,
					'_degraded' => true,
					'message'   => $result->get_error_message(),
				), 200 );
			}
			if ( isset( $result['user_id'] ) && (int) $result['user_id'] !== get_current_user_id() ) {
				return new WP_REST_Response( array(
					'success' => false,
					'message' => 'Subscription không thuộc về tài khoản hiện tại.',
				), 200 );
			}
			return new WP_REST_Response( array(
				'success'   => true,
				'kind'      => 'subscription',
				'status'    => isset( $result['status'] ) ? $result['status'] : 'active',
				'plan_slug' => isset( $result['plan_slug'] ) ? $result['plan_slug'] : '',
			), 200 );
		}

		$order_id = sanitize_text_field( (string) $request->get_param( 'order_id' ) );
		if ( $order_id === '' ) {
			// PayPal returns ?token=<order_id> to the return_url.
			$order_id = sanitize_text_field( (string) $request->get_param( 'token' ) );
		}
		if ( $order_id === '' ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Thiếu order_id/subscription_id.' ), 200 );
		}

		$result = $gateway->capture_order( $order_id );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => $result->get_error_message(),
			), 200 );
		}

		// Guard: only fulfill the buyer's own order (custom_id carries user_id).
		if ( isset( $result['user_id'] ) && (int) $result['user_id'] !== get_current_user_id() ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => 'Order không thuộc về tài khoản hiện tại.',
			), 200 );
		}

		return new WP_REST_Response( array(
			'success'   => true,
			'kind'      => 'order',
			'status'    => isset( $result['status'] ) ? $result['status'] : 'completed',
			'plan_slug' => isset( $result['plan_slug'] ) ? $result['plan_slug'] : '',
		), 200 );
	}

	/**
	 * Payment history for the current user (login-only, self-cap).
	 */
	public static function me_payments( $request ) {
		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP BE-3A — /me/payments
		if ( ! class_exists( 'BizCity_Membership_Payments' ) ) {
			return new WP_REST_Response( array( 'success' => true, 'payments' => array() ), 200 );
		}
		$uid  = get_current_user_id();
		$rows = BizCity_Membership_Payments::instance()->recent( array( 'user_id' => $uid, 'limit' => 50 ) );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'         => (string) $r['transaction_id'],
				'type'       => (string) $r['gateway'],
				'plan_slug'  => (string) $r['plan_slug'],
				'amount'     => (float) $r['amount'],
				'currency'   => (string) $r['currency'],
				'status'     => (string) $r['status'],
				'created_at' => $r['paid_at'] ? (string) $r['paid_at'] : (string) $r['created_at'],
			);
		}
		return new WP_REST_Response( array( 'success' => true, 'payments' => $out ), 200 );
	}

	/**
	 * Affiliate/referral summary for the current user.
	 *
	 * GET /me/affiliate
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function me_affiliate( $request ) {
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-6 — expose referral link and privacy-safe stats without inventing payout ledger.
		unset( $request );
		$uid = get_current_user_id();
		return new WP_REST_Response( self::current_user_affiliate_snapshot( $uid ), 200 );
	}

	/**
	 * Cancel the current user's active subscription. Fail-OPEN.
	 */
	public static function me_cancel( $request ) {
		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP BE-3A — /me/cancel
		if ( ! class_exists( 'BizCity_Membership_Manager' ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'Membership manager chưa load.',
			), 200 );
		}
		$uid     = get_current_user_id();
		$manager = BizCity_Membership_Manager::instance();
		$sub_row = $manager->latest_subscription( $uid );

		if ( ! $sub_row || $sub_row['status'] !== BizCity_Membership_Manager::STATUS_ACTIVE ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => 'Không tìm thấy subscription đang active.',
			), 200 );
		}

		// Attempt PayPal-side cancel if gateway is available.
		$paypal_sub_id = (string) $sub_row['paypal_subscription_id'];
		if ( $paypal_sub_id !== '' && class_exists( 'BizCity_Membership_PayPal_Gateway' ) ) {
			$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
			$result = BizCity_Membership_PayPal_Gateway::instance()->cancel_subscription( $paypal_sub_id, $reason );
			if ( is_wp_error( $result ) ) {
				return new WP_REST_Response( array(
					'success'   => false,
					'_degraded' => true,
					'message'   => $result->get_error_message(),
				), 200 );
			}
		}

		// Downgrade user to free locally.
		$manager->clear_plan( $uid, BizCity_Membership_Manager::STATUS_CANCELLED );

		// [2026-07-17 Johnny Chu] PHASE-D G-1 — fire cancelled action for email notification.
		do_action( 'bizcity_membership_plan_cancelled', $uid );

		return new WP_REST_Response( array( 'success' => true, 'status' => 'cancelled' ), 200 );
	}

	/**
	 * PayPal webhook backup path. Best-effort: if the event carries a completed
	 * capture resource we fulfill from it (idempotent on transaction_id).
	 *
	 * NOTE: signature verification is delegated to PayPal's transmission headers
	 * in a future hardening pass; capture() remains the primary, authenticated
	 * fulfillment path.
	 */
	public static function webhook( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( array( 'success' => false ), 200 );
		}

		$type = isset( $body['event_type'] ) ? (string) $body['event_type'] : '';

		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7-recurring — subscription activate
		// + recurring renewal (auto-charge) lifecycle.
		$resource = isset( $body['resource'] ) && is_array( $body['resource'] ) ? $body['resource'] : array();

		if ( $type === 'BILLING.SUBSCRIPTION.ACTIVATED' ) {
			$sub_id = isset( $resource['id'] ) ? sanitize_text_field( (string) $resource['id'] ) : '';
			if ( $sub_id !== '' && class_exists( 'BizCity_Membership_PayPal_Gateway' ) ) {
				BizCity_Membership_PayPal_Gateway::instance()->activate_subscription( $sub_id );
			}
			return new WP_REST_Response( array( 'success' => true, 'handled' => $type ), 200 );
		}

		if ( $type === 'PAYMENT.SALE.COMPLETED' ) {
			// Recurring renewal charge. Only act when tied to a subscription.
			if ( ! empty( $resource['billing_agreement_id'] ) && class_exists( 'BizCity_Membership_PayPal_Gateway' ) ) {
				BizCity_Membership_PayPal_Gateway::instance()->handle_recurring_payment( $resource );
			}
			return new WP_REST_Response( array( 'success' => true, 'handled' => $type ), 200 );
		}

		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7 — subscription lifecycle:
		// cancel / expire / suspend a recurring plan → downgrade the owner.
		$cancel_events = array(
			'BILLING.SUBSCRIPTION.CANCELLED',
			'BILLING.SUBSCRIPTION.EXPIRED',
			'BILLING.SUBSCRIPTION.SUSPENDED',
		);
		if ( in_array( $type, $cancel_events, true ) ) {
			$sub_id = isset( $resource['id'] ) ? sanitize_text_field( (string) $resource['id'] ) : '';
			if ( $sub_id !== '' && class_exists( 'BizCity_Membership_Manager' ) ) {
				$status = ( $type === 'BILLING.SUBSCRIPTION.EXPIRED' )
					? BizCity_Membership_Manager::STATUS_EXPIRED
					: BizCity_Membership_Manager::STATUS_CANCELLED;
				BizCity_Membership_Manager::instance()->cancel_by_paypal_subscription( $sub_id, $status );
			}
			return new WP_REST_Response( array( 'success' => true, 'handled' => $type ), 200 );
		}

		if ( $type !== 'PAYMENT.CAPTURE.COMPLETED' && $type !== 'CHECKOUT.ORDER.APPROVED' ) {
			return new WP_REST_Response( array( 'success' => true, 'ignored' => $type ), 200 );
		}

		// For APPROVED orders, re-capture authoritatively via the API.
		$order_id = isset( $resource['id'] ) ? sanitize_text_field( (string) $resource['id'] ) : '';

		if ( $order_id !== '' && class_exists( 'BizCity_Membership_PayPal_Gateway' ) && $type === 'CHECKOUT.ORDER.APPROVED' ) {
			BizCity_Membership_PayPal_Gateway::instance()->capture_order( $order_id );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/* ── Profile update ─────────────────────────────────────────────────── */

	/**
	 * Update the current user's editable profile fields.
	 * POST /me/profile — first_name, last_name, display_name, phone, bio.
	 *
	 * [2026-07-17 Johnny Chu] PHASE-D G-2 — member self-service profile update.
	 */
	public static function me_update_profile( $request ) {
		$uid = get_current_user_id();

		$allowed = array( 'first_name', 'last_name', 'display_name', 'description' );
		$user_data = array( 'ID' => $uid );

		$first_name = $request->get_param( 'first_name' );
		if ( null !== $first_name ) {
			$user_data['first_name'] = sanitize_text_field( (string) $first_name );
		}
		$last_name = $request->get_param( 'last_name' );
		if ( null !== $last_name ) {
			$user_data['last_name'] = sanitize_text_field( (string) $last_name );
		}
		$display_name = $request->get_param( 'display_name' );
		if ( null !== $display_name ) {
			$display_name = sanitize_text_field( (string) $display_name );
			if ( $display_name !== '' ) {
				$user_data['display_name'] = $display_name;
			}
		}
		$bio = $request->get_param( 'bio' );
		if ( null !== $bio ) {
			$user_data['description'] = sanitize_textarea_field( (string) $bio );
		}

		if ( count( $user_data ) > 1 ) {
			$result = wp_update_user( $user_data );
			if ( is_wp_error( $result ) ) {
				return new WP_REST_Response( array(
					'success' => false,
					'message' => $result->get_error_message(),
				), 200 );
			}
		}

		// Phone stored as user_meta (not a core WP field).
		$phone = $request->get_param( 'phone' );
		if ( null !== $phone ) {
			$phone = sanitize_text_field( (string) $phone );
			if ( class_exists( 'BizCity_User_Meta_Cache' ) ) {
				BizCity_User_Meta_Cache::set( $uid, 'phone', $phone );
			} else {
				update_user_meta( $uid, 'phone', $phone );
			}
		}

		return new WP_REST_Response( array( 'success' => true, 'message' => 'Hồ sơ đã được cập nhật.' ), 200 );
	}

	/* ── Password change ────────────────────────────────────────────────── */

	/**
	 * Authenticated password change.
	 * POST /me/change-password — current_password, new_password.
	 *
	 * [2026-07-17 Johnny Chu] PHASE-D G-3 — member password change via REST.
	 */
	public static function me_change_password( $request ) {
		$uid      = get_current_user_id();
		$current  = (string) $request->get_param( 'current_password' );
		$new_pass = (string) $request->get_param( 'new_password' );

		if ( $current === '' || $new_pass === '' ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => 'Vui lòng điền mật khẩu hiện tại và mật khẩu mới.',
			), 200 );
		}

		if ( strlen( $new_pass ) < 8 ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => 'Mật khẩu mới phải có ít nhất 8 ký tự.',
			), 200 );
		}

		$user = get_userdata( $uid );
		if ( ! $user ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Không tìm thấy tài khoản.' ), 200 );
		}

		// Verify current password.
		if ( ! wp_check_password( $current, $user->user_pass, $uid ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => 'Mật khẩu hiện tại không đúng.',
			), 200 );
		}

		wp_set_password( $new_pass, $uid );

		// Re-authenticate to keep the session valid after password change.
		$user = get_userdata( $uid );
		wp_set_auth_cookie( $uid, true );

		return new WP_REST_Response( array( 'success' => true, 'message' => 'Mật khẩu đã được thay đổi thành công.' ), 200 );
	}

	/* ── Invoice ────────────────────────────────────────────────────────── */

	/**
	 * Return a printable HTML invoice for a single payment owned by the current user.
	 * GET /me/invoice/{id} — id is transaction_id.
	 *
	 * [2026-07-17 Johnny Chu] PHASE-D G-4 — member self-service invoice.
	 */
	public static function me_invoice( $request ) {
		if ( ! class_exists( 'BizCity_Membership_Payments' ) || ! class_exists( 'BizCity_Membership_Emails' ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'Invoice generator chưa load.',
			), 200 );
		}

		$txn_id  = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$uid     = get_current_user_id();
		$payment = BizCity_Membership_Payments::instance()->find_by_transaction( $txn_id );

		if ( ! $payment ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Không tìm thấy giao dịch.' ), 200 );
		}

		// Security: member can only access their own invoices.
		if ( (int) $payment['user_id'] !== $uid ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Không có quyền truy cập hóa đơn này.' ), 200 );
		}

		$html = BizCity_Membership_Emails::instance()->render_invoice_html( $uid, $payment );

		// Return as data URI embedded JSON so the FE can open a new window.
		return new WP_REST_Response( array(
			'success' => true,
			'html'    => $html,
		), 200 );
	}
}
