<?php
/**
 * BizCoach Pro — REST routes (skeleton, Sprint I extends).
 *
 * Namespace: bizcoach-pro/v1 (separate from bizcity/v1 gateway per R-NS).
 *
 * Routes (Sprint H stub):
 *   GET  /bizcoach-pro/v1/templates                  — list active templates (slug+label+icon)
 *   GET  /bizcoach-pro/v1/templates/(?P<slug>...)    — fetch one template (full schema)
 *
 * Routes (Sprint I add):
 *   POST /bizcoach-pro/v1/coach-map                  — create coachee + run generators
 *   GET  /bizcoach-pro/v1/coach-maps/(?P<user_id>\d+) — list coach maps for user
 *   POST /bizcoach-pro/v1/templates/import           — admin upload JSON template
 *
 * @since 0.1.0 (PHASE-0.36 / R-PROD-HUB)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Rest' ) ) { return; }

class BizCoach_Pro_Rest {

	const NS = 'bizcoach-pro/v1';

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/templates',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'list_templates' ],
				'permission_callback' => '__return_true', // public — read-only catalog
			]
		);

		register_rest_route(
			self::NS,
			'/templates/(?P<slug>[a-z0-9_\-]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_template' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'slug' => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
				],
			]
		);

		// Sprint I — list coach-maps for the FE artifact dialog. Returns the
		// coachee rows + resolved public_url (from bccm_action_plans) so the
		// twinchat "Add as source" button can attach the artifact directly.
		register_rest_route(
			self::NS,
			'/coach-maps',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'list_coach_maps' ],
				'permission_callback' => [ __CLASS__, 'permission_logged_in' ],
				'args'                => [
					'coach_type' => [ 'required' => false, 'sanitize_callback' => 'sanitize_key' ],
					'user_id'    => [ 'required' => false, 'sanitize_callback' => 'absint' ],
					'limit'      => [ 'required' => false, 'sanitize_callback' => 'absint' ],
				],
			]
		);

		// Sprint I — direct create endpoint (wraps Persona Provider tool).
		// Used by FE "Tạo bản đồ" submit when the chat layer does not route
		// through the Layer 6 dispatch pipeline.
		register_rest_route(
			self::NS,
			'/coach-map',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'create_coach_map' ],
				'permission_callback' => [ __CLASS__, 'permission_logged_in' ],
				'args'                => [
					'template_slug' => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
					'payload'       => [ 'required' => false ],
				],
			]
		);

		// Sprint I — passages preview for a single artifact. Used by twinchat
		// "View as MD" preview before user adds it as a source.
		register_rest_route(
			self::NS,
			'/coach-maps/(?P<id>\d+)/passages',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_passages' ],
				'permission_callback' => [ __CLASS__, 'permission_logged_in' ],
				'args'                => [
					'id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
				],
			]
		);
	}

	public static function permission_logged_in() {
		return is_user_logged_in();
	}

	public static function list_templates( $request ) {
		$out = [];
		foreach ( BizCoach_Pro_Template_Registry::all( 'active' ) as $tpl ) {
			$out[] = [
				'slug'        => isset( $tpl['slug'] ) ? $tpl['slug'] : '',
				'label'       => isset( $tpl['label'] ) ? $tpl['label'] : '',
				'icon'        => isset( $tpl['icon'] ) ? $tpl['icon'] : '🗺️',
				'description' => isset( $tpl['description'] ) ? $tpl['description'] : '',
				'source'      => isset( $tpl['source'] ) ? $tpl['source'] : 'file',
			];
		}
		return rest_ensure_response( [ 'templates' => $out, 'count' => count( $out ) ] );
	}

	public static function get_template( $request ) {
		$slug = sanitize_key( $request['slug'] );
		$tpl  = BizCoach_Pro_Template_Registry::get( $slug );
		if ( ! $tpl ) {
			return new WP_Error( 'bcpro_template_not_found', 'Template not found', [ 'status' => 404 ] );
		}
		return rest_ensure_response( $tpl );
	}

	/**
	 * GET /coach-maps?coach_type=&user_id=&limit=
	 *
	 * Returns the artifact list the FE dialog needs. Each item carries:
	 *   id, coach_type, full_name, dob, created_at, updated_at,
	 *   public_url, gens_count, status_summary
	 *
	 * Permission: logged-in. Non-admins are forced to user_id = current.
	 */
	public static function list_coach_maps( $request ) {
		if ( ! class_exists( 'BizCoach_Pro_Artifact_Service' ) ) {
			return new WP_Error( 'bcpro_service_missing', 'Artifact service not loaded', [ 'status' => 500 ] );
		}
		$current = get_current_user_id();
		$req_user = (int) $request->get_param( 'user_id' );
		$user_id  = ( $req_user > 0 && current_user_can( 'manage_options' ) ) ? $req_user : $current;
		$type  = sanitize_key( (string) $request->get_param( 'coach_type' ) );
		$limit = max( 1, min( 200, (int) $request->get_param( 'limit' ) ?: 50 ) );

		$rows = BizCoach_Pro_Artifact_Service::list_for_user( $user_id, $type, $limit );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$id  = (int) $row['id'];
			$art = BizCoach_Pro_Artifact_Service::get_artifact( $id );
			$gens = is_array( $art ) ? (array) $art['gens'] : array();
			$success = 0;
			foreach ( $gens as $g ) {
				if ( ( $g['status'] ?? '' ) === 'success' ) { $success++; }
			}
			$tpl   = BizCoach_Pro_Template_Registry::get( (string) $row['coach_type'] );
			$icon  = is_array( $tpl ) && isset( $tpl['icon'] )  ? (string) $tpl['icon']  : '🗺️';
			$label = is_array( $tpl ) && isset( $tpl['label'] ) ? (string) $tpl['label'] : (string) $row['coach_type'];

			$out[] = array(
				'id'              => $id,
				'coach_type'      => (string) $row['coach_type'],
				'template_label'  => $label,
				'template_icon'   => $icon,
				'full_name'       => (string) $row['full_name'],
				'dob'             => (string) $row['dob'],
				'created_at'      => (string) $row['created_at'],
				'updated_at'      => (string) $row['updated_at'],
				'public_url'      => BizCoach_Pro_Artifact_Service::get_public_url( $id ),
				'gens_total'      => count( $gens ),
				'gens_success'    => $success,
				'preview_endpoint' => rest_url( self::NS . '/coach-maps/' . $id . '/passages' ),
				'astro_urls'      => BizCoach_Pro_Artifact_Service::get_astro_report_urls( $id ),
				'artifact_urls'   => BizCoach_Pro_Artifact_Service::get_artifact_urls( $id, (string) $row['coach_type'] ),
			);
		}
		return rest_ensure_response( array(
			'count'     => count( $out ),
			'user_id'   => $user_id,
			'coach_type'=> $type,
			'items'     => $out,
		) );
	}

	/**
	 * POST /coach-map  body={ template_slug, payload:{...} }
	 * Wraps Persona Provider tool_create_coach_map() for direct FE submit.
	 */
	public static function create_coach_map( $request ) {
		if ( ! class_exists( 'BizCoach_Pro_Persona_Provider' ) ) {
			return new WP_Error( 'bcpro_persona_missing', 'Persona provider not loaded', [ 'status' => 500 ] );
		}
		$slug    = sanitize_key( (string) $request->get_param( 'template_slug' ) );
		$payload = $request->get_param( 'payload' );
		if ( ! is_array( $payload ) ) { $payload = array(); }

		$persona = new BizCoach_Pro_Persona_Provider();
		$result  = $persona->tool_create_coach_map(
			array( 'template_slug' => $slug, 'payload' => $payload ),
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) { return $result; }

		// Resolve public URL (likely empty until generators run).
		$result['public_url'] = class_exists( 'BizCoach_Pro_Artifact_Service' )
			? BizCoach_Pro_Artifact_Service::get_public_url( (int) $result['coachee_id'] )
			: '';
		return rest_ensure_response( $result );
	}

	/**
	 * GET /coach-maps/{id}/passages — preview MD passages an artifact emits.
	 * Permission: logged-in; non-admin can only see their own artifact.
	 */
	public static function get_passages( $request ) {
		$id = (int) $request['id'];
		if ( ! class_exists( 'BizCoach_Pro_Artifact_Service' ) ) {
			return new WP_Error( 'bcpro_service_missing', 'Artifact service not loaded', [ 'status' => 500 ] );
		}
		$art = BizCoach_Pro_Artifact_Service::get_artifact( $id );
		if ( ! $art ) {
			return new WP_Error( 'bcpro_not_found', 'Coach map not found', [ 'status' => 404 ] );
		}
		if ( (int) $art['user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'bcpro_forbidden', 'Forbidden', [ 'status' => 403 ] );
		}
		$persona  = new BizCoach_Pro_Persona_Provider();
		$passages = $persona->render_to_passages( 'coach_map', array( 'id' => $id ) );
		return rest_ensure_response( array(
			'artifact_id' => $id,
			'coach_type'  => $art['coach_type'],
			'title'       => $art['title'],
			'public_url'  => BizCoach_Pro_Artifact_Service::get_public_url( $id ),
			'passages'    => $passages,
			'count'       => count( $passages ),
		) );
	}
}
