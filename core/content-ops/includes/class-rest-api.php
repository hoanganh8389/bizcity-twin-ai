<?php
/**
 * Content Ops — REST API
 *
 * Namespace: bizcity-content/v1
 * Perm: manage_options (write) / edit_posts (read-only routes like readiness).
 *
 * @package BizCity_Twin_AI
 * @subpackage Content_Ops
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Content_REST_API {

	const NS = 'bizcity-content/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function perm_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function perm_read(): bool {
		return current_user_can( 'edit_posts' ) || current_user_can( 'manage_options' );
	}

	public static function register_routes(): void {
		$ns = self::NS;

		// POSTS
		register_rest_route( $ns, '/posts', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_posts' ),
				'permission_callback' => array( __CLASS__, 'perm_read' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_post' ),
				'permission_callback' => array( __CLASS__, 'perm_manage' ),
			),
		) );
		register_rest_route( $ns, '/posts/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_post' ),
				'permission_callback' => array( __CLASS__, 'perm_read' ),
			),
			array(
				'methods'             => 'PUT,PATCH',
				'callback'            => array( __CLASS__, 'update_post' ),
				'permission_callback' => array( __CLASS__, 'perm_manage' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_post' ),
				'permission_callback' => array( __CLASS__, 'perm_manage' ),
			),
		) );

		register_rest_route( $ns, '/posts/(?P<id>\d+)/targets', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'attach_target' ),
			'permission_callback' => array( __CLASS__, 'perm_manage' ),
		) );
		register_rest_route( $ns, '/posts/(?P<id>\d+)/targets/(?P<tid>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'detach_target' ),
			'permission_callback' => array( __CLASS__, 'perm_manage' ),
		) );
		register_rest_route( $ns, '/posts/(?P<id>\d+)/schedule', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'schedule_post' ),
			'permission_callback' => array( __CLASS__, 'perm_manage' ),
		) );
		register_rest_route( $ns, '/posts/(?P<id>\d+)/publish-now', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'publish_now' ),
			'permission_callback' => array( __CLASS__, 'perm_manage' ),
		) );
		register_rest_route( $ns, '/posts/(?P<id>\d+)/sync-wp', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'sync_wp' ),
			'permission_callback' => array( __CLASS__, 'perm_manage' ),
		) );

		// CALENDAR
		register_rest_route( $ns, '/calendar', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'calendar' ),
			'permission_callback' => array( __CLASS__, 'perm_read' ),
		) );

		// ASSETS
		register_rest_route( $ns, '/assets', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_assets' ),
				'permission_callback' => array( __CLASS__, 'perm_read' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_asset' ),
				'permission_callback' => array( __CLASS__, 'perm_manage' ),
			),
		) );

		// AI
		register_rest_route( $ns, '/ai/generate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'ai_generate' ),
			'permission_callback' => array( __CLASS__, 'perm_manage' ),
		) );
		register_rest_route( $ns, '/ai/jobs', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'ai_jobs' ),
			'permission_callback' => array( __CLASS__, 'perm_read' ),
		) );

		// SCHEDULER
		register_rest_route( $ns, '/scheduler/run', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'scheduler_run' ),
			'permission_callback' => array( __CLASS__, 'perm_manage' ),
		) );
		register_rest_route( $ns, '/scheduler/status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'scheduler_status' ),
			'permission_callback' => array( __CLASS__, 'perm_read' ),
		) );

		// READINESS
		register_rest_route( $ns, '/readiness', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'readiness' ),
			'permission_callback' => array( __CLASS__, 'perm_read' ),
		) );
	}

	/* ---------- POSTS ---------- */

	public static function list_posts( WP_REST_Request $req ) {
		$args = array(
			'blog_id'  => get_current_blog_id(),
			'status'   => sanitize_text_field( (string) $req->get_param( 'status' ) ),
			'source'   => sanitize_text_field( (string) $req->get_param( 'source' ) ),
			'q'        => sanitize_text_field( (string) $req->get_param( 'q' ) ),
			'per_page' => (int) $req->get_param( 'per_page' ),
			'page'     => (int) $req->get_param( 'page' ),
		);
		return rest_ensure_response( BizCity_Content_Post_Repo::query( array_filter( $args ) ) );
	}

	public static function create_post( WP_REST_Request $req ) {
		$data = array(
			'title'   => wp_strip_all_tags( (string) $req->get_param( 'title' ) ),
			'body'    => wp_kses_post( (string) $req->get_param( 'body' ) ),
			'excerpt' => sanitize_textarea_field( (string) $req->get_param( 'excerpt' ) ),
			'kind'    => sanitize_key( (string) ( $req->get_param( 'kind' ) ?: 'post' ) ),
			'tone'    => sanitize_text_field( (string) $req->get_param( 'tone' ) ),
			'source'  => 'manual',
		);
		$id = BizCity_Content_Post_Repo::create( $data );
		return rest_ensure_response( array( 'id' => $id, 'post' => BizCity_Content_Post_Repo::find( $id ) ) );
	}

	public static function get_post( WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$row  = BizCity_Content_Post_Repo::find( $id );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
		}
		$row['targets'] = BizCity_Content_Post_Repo::list_targets( $id );
		return rest_ensure_response( $row );
	}

	public static function update_post( WP_REST_Request $req ) {
		$id    = (int) $req['id'];
		$patch = array();
		foreach ( array( 'title', 'body', 'excerpt', 'kind', 'tone', 'status', 'scheduled_at' ) as $k ) {
			$val = $req->get_param( $k );
			if ( $val === null ) {
				continue;
			}
			if ( $k === 'body' ) {
				$patch[ $k ] = wp_kses_post( (string) $val );
			} elseif ( $k === 'title' ) {
				$patch[ $k ] = wp_strip_all_tags( (string) $val );
			} else {
				$patch[ $k ] = sanitize_text_field( (string) $val );
			}
		}
		BizCity_Content_Post_Repo::update( $id, $patch );
		return rest_ensure_response( BizCity_Content_Post_Repo::find( $id ) );
	}

	public static function delete_post( WP_REST_Request $req ) {
		BizCity_Content_Post_Repo::soft_delete( (int) $req['id'] );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function attach_target( WP_REST_Request $req ) {
		$id          = (int) $req['id'];
		$platform    = sanitize_key( (string) $req->get_param( 'platform' ) );
		$instance_id = sanitize_text_field( (string) $req->get_param( 'instance_id' ) );
		if ( ! $platform ) {
			return new WP_Error( 'bad_request', 'platform required', array( 'status' => 400 ) );
		}
		$tid = BizCity_Content_Post_Repo::attach_target( $id, $platform, $instance_id );
		return rest_ensure_response( array( 'target_id' => $tid ) );
	}

	public static function detach_target( WP_REST_Request $req ) {
		BizCity_Content_Post_Repo::delete_target( (int) $req['tid'] );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function schedule_post( WP_REST_Request $req ) {
		$post_id    = (int) $req['id'];
		$run_at     = sanitize_text_field( (string) $req->get_param( 'run_at' ) );
		$target_ids = (array) $req->get_param( 'target_ids' );
		if ( ! $run_at ) {
			return new WP_Error( 'bad_request', 'run_at required (YYYY-MM-DD HH:MM:SS UTC)', array( 'status' => 400 ) );
		}
		$enqueued = array();
		foreach ( $target_ids as $tid ) {
			$enqueued[] = BizCity_Content_Scheduler::enqueue( $post_id, (int) $tid, $run_at );
		}
		BizCity_Content_Post_Repo::update( $post_id, array(
			'status'       => 'scheduled',
			'scheduled_at' => $run_at,
		) );
		return rest_ensure_response( array( 'queue_ids' => $enqueued ) );
	}

	public static function publish_now( WP_REST_Request $req ) {
		$post_id = (int) $req['id'];
		$targets = BizCity_Content_Post_Repo::list_targets( $post_id );
		$now     = current_time( 'mysql' );
		$queue   = array();
		foreach ( $targets as $t ) {
			$queue[] = BizCity_Content_Scheduler::enqueue( $post_id, (int) $t['id'], $now );
		}
		$res = BizCity_Content_Scheduler::run();
		return rest_ensure_response( array( 'queue_ids' => $queue, 'result' => $res ) );
	}

	public static function sync_wp( WP_REST_Request $req ) {
		$id    = (int) $req['id'];
		$type  = sanitize_key( (string) ( $req->get_param( 'post_type' ) ?: BizCity_Content_CPT_Bridge::CPT ) );
		$wp_id = BizCity_Content_CPT_Bridge::sync_to_wp( $id, $type );
		return rest_ensure_response( array( 'wp_post_id' => $wp_id ) );
	}

	/* ---------- CALENDAR ---------- */

	public static function calendar( WP_REST_Request $req ) {
		global $wpdb;
		$from     = sanitize_text_field( (string) $req->get_param( 'from' ) ) ?: gmdate( 'Y-m-d', time() - 86400 * 30 );
		$to       = sanitize_text_field( (string) $req->get_param( 'to' ) )   ?: gmdate( 'Y-m-d', time() + 86400 * 60 );
		$table    = BizCity_Content_Post_Repo::table();
		$tg_table = BizCity_Content_Post_Repo::targets_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.title, p.status, p.scheduled_at, p.published_at, p.kind
				 FROM $table p
				 WHERE p.deleted_at IS NULL
				   AND ( (p.scheduled_at BETWEEN %s AND %s) OR (p.published_at BETWEEN %s AND %s) )
				 ORDER BY COALESCE(p.scheduled_at, p.published_at) ASC",
				$from . ' 00:00:00', $to . ' 23:59:59',
				$from . ' 00:00:00', $to . ' 23:59:59'
			),
			ARRAY_A
		);
		return rest_ensure_response( array( 'items' => $rows ?: array() ) );
	}

	/* ---------- ASSETS ---------- */

	public static function list_assets( WP_REST_Request $req ) {
		return rest_ensure_response( BizCity_Content_Asset_Repo::query(
			array(
				'blog_id'  => get_current_blog_id(),
				'type'     => sanitize_key( (string) $req->get_param( 'type' ) ),
				'per_page' => (int) $req->get_param( 'per_page' ),
				'page'     => (int) $req->get_param( 'page' ),
			)
		) );
	}

	public static function create_asset( WP_REST_Request $req ) {
		$id = BizCity_Content_Asset_Repo::create( array(
			'type'  => sanitize_key( (string) ( $req->get_param( 'type' ) ?: 'image' ) ),
			'url'   => esc_url_raw( (string) $req->get_param( 'url' ) ),
			'mime'  => sanitize_text_field( (string) $req->get_param( 'mime' ) ),
			'title' => sanitize_text_field( (string) $req->get_param( 'title' ) ),
		) );
		return rest_ensure_response( array( 'id' => $id ) );
	}

	/* ---------- AI ---------- */

	public static function ai_generate( WP_REST_Request $req ) {
		$kind     = sanitize_key( (string) ( $req->get_param( 'kind' ) ?: 'idea' ) );
		$platform = strtoupper( sanitize_text_field( (string) ( $req->get_param( 'platform' ) ?: 'FACEBOOK' ) ) );
		$brief    = (string) $req->get_param( 'brief' );
		$post_id  = (int) $req->get_param( 'post_id' );

		switch ( $kind ) {
			case 'idea':
				$res = BizCity_Content_LLM_Proxy::generate_ideas( $brief, $platform, max( 1, (int) ( $req->get_param( 'n' ) ?: 3 ) ) );
				break;
			case 'caption':
				$post = $post_id ? BizCity_Content_Post_Repo::find( $post_id ) : array( 'title' => '', 'body' => $brief );
				$res  = BizCity_Content_LLM_Proxy::generate_caption( (array) $post, $platform );
				break;
			case 'image_prompt':
				$post = $post_id ? BizCity_Content_Post_Repo::find( $post_id ) : array( 'title' => '', 'body' => $brief );
				$res  = BizCity_Content_LLM_Proxy::generate_image_prompt( (array) $post );
				break;
			default:
				return new WP_Error( 'bad_request', 'unknown kind', array( 'status' => 400 ) );
		}
		return rest_ensure_response( $res );
	}

	public static function ai_jobs( WP_REST_Request $req ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_ai_jobs';
		$limit = max( 1, min( 200, (int) ( $req->get_param( 'per_page' ) ?: 50 ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, kind, model, tokens_in, tokens_out, cost_usd, latency_ms, status, created_at FROM $table ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
		return rest_ensure_response( array( 'items' => $rows ?: array() ) );
	}

	/* ---------- SCHEDULER ---------- */

	public static function scheduler_run( WP_REST_Request $req ) {
		return rest_ensure_response( BizCity_Content_Scheduler::run() );
	}

	public static function scheduler_status( WP_REST_Request $req ) {
		return rest_ensure_response( BizCity_Content_Scheduler::status() );
	}

	/* ---------- READINESS ---------- */

	public static function readiness( WP_REST_Request $req ) {
		return rest_ensure_response( array(
			'channels'  => BizCity_Content_Channel_Readiness::matrix(),
			'llm_ready' => BizCity_Content_LLM_Proxy::is_ready(),
		) );
	}
}
