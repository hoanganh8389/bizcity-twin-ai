<?php
/**
 * BZDoc Documents Hub REST controller (R-OF / PHASE-0-RULE-OUTPUT-FILES).
 *
 * Routes (namespace `bzdoc/v1`):
 *   GET    /documents             — list with filters
 *                                   (notebook_id, doc_type, generator, origin,
 *                                    q, status, limit, offset, sort)
 *   POST   /documents             — user upload (origin=upload)
 *   GET    /documents/{id}        — single
 *   DELETE /documents/{id}        — soft-delete
 *   GET    /documents/health      — aggregate generator health (R-OF-9)
 *
 * Consumed by:
 *   - twin-crm Documents tab (frontend bzdocApi.js RTK slice)
 *   - TwinChat Notebook Files tab
 *
 * @package bizcity-doc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZDoc_Documents_Rest {

	const NAMESPACE = 'bzdoc/v1';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes() {
		register_rest_route( self::NAMESPACE, '/documents', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'list_documents' ],
				'permission_callback' => [ __CLASS__, 'can_read' ],
				'args'                => [
					'notebook_id' => [ 'type' => 'integer' ],
					'doc_type'    => [ 'type' => 'string' ],
					'generator'   => [ 'type' => 'string' ],
					'origin'      => [ 'type' => 'string', 'enum' => [ 'upload', 'generated' ] ],
					'status'      => [ 'type' => 'string' ],
					'q'           => [ 'type' => 'string' ],
					'limit'       => [ 'type' => 'integer', 'default' => 50 ],
					'offset'      => [ 'type' => 'integer', 'default' => 0 ],
					'sort'        => [ 'type' => 'string', 'default' => '-created_at' ],
				],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'upload_document' ],
				'permission_callback' => [ __CLASS__, 'can_write' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/documents/health', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'health' ],
			'permission_callback' => [ __CLASS__, 'can_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/documents/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_document' ],
				'permission_callback' => [ __CLASS__, 'can_read' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ __CLASS__, 'delete_document' ],
				'permission_callback' => [ __CLASS__, 'can_write' ],
			],
		] );
	}

	public static function can_read() { return is_user_logged_in(); }
	public static function can_write() { return is_user_logged_in(); }

	/* ─────────────────────────────────────────── */

	public static function list_documents( WP_REST_Request $req ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bzdoc_documents';

		$where  = [ "status <> %s" ];
		$params = [ 'deleted' ];

		$nb = (int) $req->get_param( 'notebook_id' );
		if ( $nb > 0 ) {
			$where[]   = 'notebook_id = %d';
			$params[]  = $nb;
		}
		$dtype = (string) $req->get_param( 'doc_type' );
		if ( $dtype !== '' ) {
			$where[]   = 'doc_type = %s';
			$params[]  = $dtype;
		}
		$gen = (string) $req->get_param( 'generator' );
		if ( $gen !== '' ) {
			$where[]   = 'generator = %s';
			$params[]  = $gen;
		}
		$origin = (string) $req->get_param( 'origin' );
		if ( in_array( $origin, [ 'upload', 'generated' ], true ) ) {
			$where[]   = 'origin = %s';
			$params[]  = $origin;
		}
		$status = (string) $req->get_param( 'status' );
		if ( $status !== '' ) {
			$where[0]  = 'status = %s';
			$params[0] = $status;
		}
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( $q !== '' ) {
			$where[]  = '(title LIKE %s OR file_url LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $q ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$sort = (string) $req->get_param( 'sort' ) ?: '-created_at';
		$dir  = ( $sort[0] === '-' ) ? 'DESC' : 'ASC';
		$col  = ltrim( $sort, '-+' );
		$col_whitelist = [ 'created_at', 'updated_at', 'size_bytes', 'doc_type', 'title', 'id' ];
		if ( ! in_array( $col, $col_whitelist, true ) ) {
			$col = 'created_at';
		}

		$limit  = max( 1, min( 200, (int) $req->get_param( 'limit' ) ) );
		$offset = max( 0, (int) $req->get_param( 'offset' ) );

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		$sql = "SELECT id, user_id, notebook_id, source_skeleton_version, doc_type,
			generator, origin, job_id, media_id, file_url, mime, size_bytes,
			title, status, parent_event_uuid, created_at, updated_at
			FROM {$table}
			WHERE {$where_sql}
			ORDER BY {$col} {$dir}
			LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, [ $limit, $offset ] ) ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$rows = [];
		}

		$out = array_map( [ __CLASS__, 'shape_row' ], $rows );

		return new WP_REST_Response( [
			'ok'        => true,
			'data'      => [
				'documents' => $out,
				'total'     => $total,
				'limit'     => $limit,
				'offset'    => $offset,
			],
		], 200 );
	}

	public static function get_document( WP_REST_Request $req ) {
		global $wpdb;
		$id    = (int) $req['id'];
		$table = $wpdb->prefix . 'bzdoc_documents';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'bzdoc_not_found', 'Document not found', [ 'status' => 404 ] );
		}
		return new WP_REST_Response( [ 'ok' => true, 'data' => self::shape_row( $row ) ], 200 );
	}

	public static function upload_document( WP_REST_Request $req ) {
		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$files = $req->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'bzdoc_no_file', 'No file uploaded (expected field name "file")', [ 'status' => 400 ] );
		}

		// media_handle_upload reads from $_FILES — make sure REST hands us the array.
		$_FILES['file'] = $files['file'];
		$attachment_id  = media_handle_upload( 'file', 0 );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$nb_raw      = $req->get_param( 'notebook_id' );
		$notebook_id = ( $nb_raw === null || $nb_raw === '' ) ? 0 : (int) $nb_raw;

		$title = (string) $req->get_param( 'title' );
		if ( $title === '' ) {
			$post  = get_post( $attachment_id );
			$title = $post ? (string) $post->post_title : 'Upload';
		}

		$doc_id = bzdoc_register_document( [
			'generator'         => 'user_upload',
			'origin'            => 'upload',
			'doc_type'          => self::guess_doc_type( get_post_mime_type( $attachment_id ) ),
			'notebook_id'       => $notebook_id,
			'media_id'          => (int) $attachment_id,
			'title'             => $title,
			'parent_event_uuid' => (string) $req->get_param( 'parent_event_uuid' ),
		] );

		if ( is_wp_error( $doc_id ) ) {
			return $doc_id;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bzdoc_documents';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $doc_id ), ARRAY_A );

		return new WP_REST_Response( [ 'ok' => true, 'data' => self::shape_row( $row ) ], 201 );
	}

	public static function delete_document( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$ok = bzdoc_soft_delete_document( $id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return new WP_REST_Response( [ 'ok' => true, 'data' => [ 'id' => $id ] ], 200 );
	}

	public static function health( WP_REST_Request $req ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bzdoc_documents';

		$by_gen = $wpdb->get_results(
			"SELECT generator, COUNT(*) AS n,
			        SUM(origin = 'upload') AS uploaded,
			        SUM(origin = 'generated') AS generated,
			        MAX(created_at) AS last_at
			 FROM {$table}
			 WHERE status <> 'deleted'
			 GROUP BY generator",
			ARRAY_A
		);

		$generators = [];
		foreach ( (array) $by_gen as $r ) {
			$slug = (string) ( $r['generator'] ?? '' );
			$health = [
				'ok'              => true,
				'queue_depth'     => null,
				'last_success_at' => $r['last_at'] ?? null,
				'last_error'      => null,
				'avg_latency_ms'  => null,
			];
			$health = apply_filters( "bzdoc_generator_health_{$slug}", $health );
			$generators[] = [
				'slug'      => $slug,
				'count'     => (int) $r['n'],
				'uploaded'  => (int) ( $r['uploaded'] ?? 0 ),
				'generated' => (int) ( $r['generated'] ?? 0 ),
				'health'    => $health,
			];
		}

		return new WP_REST_Response( [
			'ok'   => true,
			'data' => [
				'whitelist'  => function_exists( 'bzdoc_get_generator_whitelist' )
					? bzdoc_get_generator_whitelist() : [],
				'generators' => $generators,
				'schema'     => get_option( 'bzdoc_schema_version', '0' ),
			],
		], 200 );
	}

	/* ─── helpers ─── */

	private static function shape_row( $row ): array {
		if ( ! is_array( $row ) ) {
			return [];
		}
		$media_id = isset( $row['media_id'] ) && $row['media_id'] !== null ? (int) $row['media_id'] : null;
		$file_url = (string) ( $row['file_url'] ?? '' );
		if ( $file_url === '' && $media_id ) {
			$resolved = wp_get_attachment_url( $media_id );
			if ( is_string( $resolved ) ) {
				$file_url = $resolved;
			}
		}
		return [
			'id'                      => (int) $row['id'],
			'user_id'                 => (int) ( $row['user_id'] ?? 0 ),
			'notebook_id'             => (int) ( $row['notebook_id'] ?? 0 ),
			'source_skeleton_version' => (int) ( $row['source_skeleton_version'] ?? 0 ),
			'doc_type'                => (string) ( $row['doc_type'] ?? '' ),
			'generator'               => (string) ( $row['generator'] ?? '' ),
			'origin'                  => (string) ( $row['origin'] ?? '' ),
			'job_id'                  => isset( $row['job_id'] ) && $row['job_id'] !== null ? (int) $row['job_id'] : null,
			'media_id'                => $media_id,
			'file_url'                => $file_url,
			'mime'                    => (string) ( $row['mime'] ?? '' ),
			'size_bytes'              => (int) ( $row['size_bytes'] ?? 0 ),
			'title'                   => (string) ( $row['title'] ?? '' ),
			'status'                  => (string) ( $row['status'] ?? '' ),
			'parent_event_uuid'       => $row['parent_event_uuid'] ?? null,
			'created_at'              => (string) ( $row['created_at'] ?? '' ),
			'updated_at'              => (string) ( $row['updated_at'] ?? '' ),
		];
	}

	private static function guess_doc_type( $mime ): string {
		$mime = (string) $mime;
		if ( strpos( $mime, 'image/' ) === 0 ) return 'image';
		if ( strpos( $mime, 'video/' ) === 0 ) return 'video';
		if ( strpos( $mime, 'audio/' ) === 0 ) return 'audio';
		if ( $mime === 'application/pdf' ) return 'pdf';
		if ( in_array( $mime, [
			'application/json',
			'text/json',
		], true ) ) return 'json';
		if ( in_array( $mime, [
			'text/markdown',
			'text/x-markdown',
		], true ) ) return 'markdown';
		if ( in_array( $mime, [
			'text/csv',
			'application/vnd.ms-excel',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		], true ) ) return 'dataset';
		return 'document';
	}
}
