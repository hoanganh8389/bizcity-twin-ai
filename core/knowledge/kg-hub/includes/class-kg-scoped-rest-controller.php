<?php
/**
 * Bizcity Twin AI — KG-Hub Scoped REST Controller
 *
 * Routes plugin-agnostic ingest/list/attach calls through `BizCity_KG`.
 * All routes mounted under `bizcity-knowledge/v2/scoped/...`
 *
 * Endpoints (PHASE-0-RULE-KG-HUB-CONTRACT.md §3.2):
 *   GET    /scoped/registry                         → list registered plugins
 *   GET    /scoped/scopes/available?plugin=...      → user-accessible scopes
 *   GET    /scoped/{plugin}/{scope_id}/sources      → list sources
 *   POST   /scoped/{plugin}/{scope_id}/sources      → ingest (file/url/text)
 *   DELETE /scoped/{plugin}/{scope_id}/sources/(?P<source_id>\d+)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Scoped_REST_Controller {

	const NAMESPACE_V2 = 'bizcity-knowledge/v2';
	const HOOK_ASYNC_INGEST = 'bizcity_kg_scoped_async_ingest';
	const HOOK_ASYNC_WATCHDOG = 'bizcity_kg_scoped_async_watchdog';
	const ASYNC_SCHEDULE = 'bizcity_kg_async_5min';
	const JOB_ASYNC_WATCHDOG = 'kg.scoped_async_ingest_watchdog';
	const ASYNC_MAX_ATTEMPTS = 3;
	const ASYNC_AUTO_RESUME_MAX = 1;
	const ASYNC_STALE_AFTER = 10 * MINUTE_IN_SECONDS;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function bind_async_ingest() {
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — diagnostics must not
		// bind production ingest/watchdog callbacks or scheduler hooks.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return;
		}
		// [2026-07-23 Johnny Chu] PHASE-0.43 — run large scoped file ingest out-of-band so REST upload does not hit Cloudflare 524.
		add_action( self::HOOK_ASYNC_INGEST, array( __CLASS__, 'run_async_ingest' ), 10, 1 );
		// [2026-07-24 Johnny Chu] PHASE-0.46-ASYNC-INGEST — persist recovery state on the placeholder and sweep jobs lost by a scheduler/process failure.
		add_filter( 'cron_schedules', array( __CLASS__, 'register_async_schedule' ) );
		add_action( self::HOOK_ASYNC_WATCHDOG, array( __CLASS__, 'watchdog' ) );
		add_action( 'init', array( __CLASS__, 'ensure_async_watchdog_registration' ), 20 );
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.5 — defer watchdog registration
	 * to init so core/cron manager load-order never decides whether this hook
	 * is observable in cron registry/logs.
	 */
	public static function ensure_async_watchdog_registration() {
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — do not register or
		// schedule the scoped ingest watchdog in diagnostics CLI.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return;
		}
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->register( array(
				'id'          => self::JOB_ASYNC_WATCHDOG,
				'hook'        => self::HOOK_ASYNC_WATCHDOG,
				'interval'    => self::ASYNC_SCHEDULE,
				'owner'       => 'core/knowledge/kg-hub',
				'description' => 'Retry stale scoped async ingest placeholders and recover lost jobs.',
				'retention'   => 7,
			) );
		} elseif ( ! wp_next_scheduled( self::HOOK_ASYNC_WATCHDOG ) ) {
			wp_schedule_event( time() + 60, self::ASYNC_SCHEDULE, self::HOOK_ASYNC_WATCHDOG );
		}
	}

	public static function register_async_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::ASYNC_SCHEDULE ] ) ) {
			$schedules[ self::ASYNC_SCHEDULE ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'BizCity async ingest watchdog (5 min)',
			);
		}
		return $schedules;
	}

	public function register_routes() {
		register_rest_route( self::NAMESPACE_V2, '/scoped/registry', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_registry' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		register_rest_route( self::NAMESPACE_V2, '/scoped/scopes/available', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_available_scopes' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'plugin'     => [ 'type' => 'string', 'required' => false ],
				'scope_type' => [ 'type' => 'string', 'required' => false ],
			],
		] );

		register_rest_route(
			self::NAMESPACE_V2,
			'/scoped/(?P<plugin>[a-z0-9_\-]+)/(?P<scope_id>\d+)/sources',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list_sources' ],
					'permission_callback' => [ $this, 'check_logged_in' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'ingest_source' ],
					'permission_callback' => [ $this, 'check_logged_in' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE_V2,
			'/scoped/(?P<plugin>[a-z0-9_\-]+)/(?P<scope_id>\d+)/sources/(?P<source_id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_source' ],
					'permission_callback' => [ $this, 'check_logged_in' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_source' ],
					'permission_callback' => [ $this, 'check_logged_in' ],
				],
			]
		);

		// Cross-scope catalog for KGSourcePicker (Hình thức A).
		register_rest_route( self::NAMESPACE_V2, '/scoped/sources/all', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_all_sources' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'plugin'          => [ 'type' => 'string', 'required' => false ],
				'scope_type'      => [ 'type' => 'string', 'required' => false ],
				'search'          => [ 'type' => 'string', 'required' => false ],
				'limit_per_scope' => [ 'type' => 'integer', 'required' => false ],
			],
		] );

		// Attach an existing source from another scope into the current scope.
		register_rest_route(
			self::NAMESPACE_V2,
			'/scoped/(?P<plugin>[a-z0-9_\-]+)/(?P<scope_id>\d+)/sources/attach',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'attach_source' ],
				'permission_callback' => [ $this, 'check_logged_in' ],
			]
		);
	}

	/* ──────────────────────  Permission  ────────────────────── */

	public function check_logged_in() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', [ 'status' => 401 ] );
		}
		return true;
	}

	/* ──────────────────────  Handlers  ────────────────────── */

	public function get_registry() {
		$reg = BizCity_KG::register();
		// Strip non-serializable callbacks.
		$out = [];
		foreach ( $reg as $slug => $entry ) {
			unset( $entry['list_scopes_cb'] );
			$out[] = $entry;
		}
		return rest_ensure_response( [ 'ok' => true, 'data' => $out ] );
	}

	public function get_available_scopes( WP_REST_Request $req ) {
		$user_id = get_current_user_id();
		$ctx     = [];
		if ( $req->get_param( 'plugin' ) ) {
			$ctx['plugin'] = sanitize_key( (string) $req->get_param( 'plugin' ) );
		}
		if ( $req->get_param( 'scope_type' ) ) {
			$ctx['scope_type'] = sanitize_key( (string) $req->get_param( 'scope_type' ) );
		}
		$scopes = BizCity_KG::available_scopes( $user_id, $ctx );
		return rest_ensure_response( [ 'ok' => true, 'data' => $scopes ] );
	}

	public function list_sources( WP_REST_Request $req ) {
		$scope = $this->resolve_scope( $req );
		if ( is_wp_error( $scope ) ) return $scope;

		$args = [
			'limit'  => (int) ( $req->get_param( 'limit' ) ?: 50 ),
			'offset' => (int) ( $req->get_param( 'offset' ) ?: 0 ),
			'search' => (string) ( $req->get_param( 'search' ) ?: '' ),
		];
		$rows = BizCity_KG::list_sources( $scope, $args );
		if ( is_wp_error( $rows ) ) return $rows;
		return rest_ensure_response( [ 'ok' => true, 'data' => $rows ] );
	}

	public function ingest_source( WP_REST_Request $req ) {
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — diagnostics must not
		// create placeholders, move uploads, or enter production KG ingest.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return new WP_Error( 'diagnostics_async_isolated', 'KG ingest worker is isolated during diagnostics CLI.' );
		}
		$scope = $this->resolve_scope( $req );
		if ( is_wp_error( $scope ) ) return $scope;

		// multipart vs JSON: prefer body params (POST fields), fall back to JSON.
		$body = $req->get_body_params();
		if ( empty( $body ) ) {
			$json = $req->get_json_params();
			if ( is_array( $json ) ) {
				$body = $json;
			}
		}
		if ( ! is_array( $body ) ) $body = [];

		$type = isset( $body['type'] ) ? sanitize_key( (string) $body['type'] ) : 'text';

		$payload = [
			'type'          => $type,
			'title'         => isset( $body['title'] ) ? (string) $body['title'] : '',
			'content'       => isset( $body['content'] ) ? (string) $body['content'] : '',
			'url'           => isset( $body['url'] ) ? (string) $body['url'] : '',
			'attachment_id' => isset( $body['attachment_id'] ) ? (int) $body['attachment_id'] : 0,
			'metadata'      => isset( $body['metadata'] ) && is_array( $body['metadata'] ) ? $body['metadata'] : [],
		];

		// File upload (multipart).
		$files = $req->get_file_params();
		if ( $type === 'file' && ! empty( $files['file'] ) ) {
			$payload['file'] = $files['file'];
		}

		// [2026-06-11 Johnny Chu] R-KG-FILE-TYPES — gate file ingestion against plan's accepted_file_types.
		if ( $type === 'file' && ! empty( $payload['file']['name'] ) ) {
			$ext = strtolower( pathinfo( (string) $payload['file']['name'], PATHINFO_EXTENSION ) );
			$uid = get_current_user_id();
			// [2026-07-02 Johnny Chu] HOTFIX R-KG-HUB-FIRST — admin bypass: manage_options users skip
			// file type gate entirely (same pattern as BizCity_Membership_Enforcer::is_exempt_user()).
			$skip_gate = $uid > 0 && user_can( $uid, 'manage_options' );
			if ( ! $skip_gate ) {
				$allowed = array( 'txt', 'md', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt', 'rtf' ); // safe fallback
				if ( class_exists( 'BizCity_Membership_Entitlement' ) ) {
					$ent     = BizCity_Membership_Entitlement::instance()->for_user( $uid );
					$allowed = isset( $ent['accepted_file_types'] ) && is_array( $ent['accepted_file_types'] )
						? $ent['accepted_file_types']
						: $allowed;
				}
				// [2026-07-14 Johnny Chu] HOTFIX — normalize extension list + keep legacy Office aliases.
				$normalized_allowed = array();
				foreach ( (array) $allowed as $type_ext ) {
					$t = sanitize_key( strtolower( trim( (string) $type_ext ) ) );
					if ( $t !== '' && ! in_array( $t, $normalized_allowed, true ) ) {
						$normalized_allowed[] = $t;
					}
				}
				if ( in_array( 'docx', $normalized_allowed, true ) && ! in_array( 'doc', $normalized_allowed, true ) ) {
					$normalized_allowed[] = 'doc';
				}
				if ( in_array( 'xlsx', $normalized_allowed, true ) && ! in_array( 'xls', $normalized_allowed, true ) ) {
					$normalized_allowed[] = 'xls';
				}
				if ( in_array( 'pptx', $normalized_allowed, true ) && ! in_array( 'ppt', $normalized_allowed, true ) ) {
					$normalized_allowed[] = 'ppt';
				}
				$allowed = $normalized_allowed;
				if ( $ext !== '' && ! in_array( $ext, $allowed, true ) ) {
					// [2026-07-14 Johnny Chu] R-KG-FILE-TYPES — include allowed list in unsupported_ext payload.
					$allowed_display = implode(
						', ',
						array_map(
							function ( $x ) {
								return '.' . sanitize_key( (string) $x );
							},
							$allowed
						)
					);
					$err_payload = class_exists( 'BizCity_Error_Payload' )
						? BizCity_Error_Payload::make(
							'unsupported_ext',
							'Định dạng file .' . $ext . ' không được hỗ trợ. Được phép: ' . $allowed_display,
							'Dùng một trong các định dạng được phép: ' . $allowed_display . '.',
							'kg_file_type_not_allowed',
							array(
								'ext'     => $ext,
								'allowed' => array_values( $allowed ),
							)
						)
						: array(
							'success' => false,
							'code'    => 'unsupported_ext',
							'message' => 'Định dạng file .' . $ext . ' không được hỗ trợ. Được phép: ' . $allowed_display,
							'allowed' => array_values( $allowed ),
						);
					return new WP_REST_Response( $err_payload, 415 );
				}
			}
		}

		if ( $type === 'file' && ! empty( $payload['file']['tmp_name'] ) && $this->should_async_file_ingest( $req, $payload ) ) {
			$async = $this->enqueue_async_file_ingest( $scope, $payload, get_current_user_id() );
			if ( is_wp_error( $async ) ) {
				return self::normalize_ingest_error( $async );
			}
			return new WP_REST_Response( array( 'ok' => true, 'data' => $async ), 202 );
		}

		$res = BizCity_KG::ingest( $scope, $payload );
		if ( is_wp_error( $res ) ) return self::normalize_ingest_error( $res );
		return rest_ensure_response( [ 'ok' => true, 'data' => $res ] );
	}

	private function should_async_file_ingest( WP_REST_Request $req, array $payload ): bool {
		// [2026-07-23 Johnny Chu] PHASE-0.43 — FE can force async for upload UI; size threshold catches large files from other clients.
		$async_param = (string) $req->get_param( 'async_ingest' );
		if ( in_array( strtolower( $async_param ), array( '1', 'true', 'yes' ), true ) ) {
			return true;
		}
		$threshold = (int) apply_filters( 'bizcity_kg_scoped_async_file_threshold_bytes', 1024 * 1024 );
		$size      = isset( $payload['file']['size'] ) ? (int) $payload['file']['size'] : 0;
		return $threshold > 0 && $size >= $threshold;
	}

	private function enqueue_async_file_ingest( array $scope, array $payload, int $user_id ) {
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — defensive guard before
		// upload staging, placeholder writes, and scheduler/loopback registration.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return new WP_Error( 'diagnostics_async_isolated', 'KG ingest worker is isolated during diagnostics CLI.' );
		}
		$file = isset( $payload['file'] ) && is_array( $payload['file'] ) ? $payload['file'] : array();
		$tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : 'upload.bin';
		if ( $tmp === '' || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'async_file_missing', 'Không tìm thấy file upload để xử lý nền.', array( 'http_status' => 400 ) );
		}

		$uploads = wp_upload_dir( null, true, false );
		$base    = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : wp_normalize_path( WP_CONTENT_DIR . '/uploads' );
		$dir     = trailingslashit( $base ) . 'bizcity-async-ingest';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return new WP_Error( 'async_ingest_dir_unwritable', 'Không tạo được thư mục xử lý nền cho file upload.', array( 'http_status' => 500 ) );
		}
		@file_put_contents( trailingslashit( $dir ) . 'index.php', "<?php // Silence is golden.\n" );
		@file_put_contents( trailingslashit( $dir ) . '.htaccess', "Require all denied\nDeny from all\n" );

		$job_id = wp_generate_uuid4();
		$blog_id = (int) get_current_blog_id();
		$path   = trailingslashit( $dir ) . $job_id . '-' . $name;
		if ( ! @move_uploaded_file( $tmp, $path ) ) {
			return new WP_Error( 'async_file_move_failed', 'Không chuyển được file upload sang hàng đợi xử lý nền.', array( 'http_status' => 500 ) );
		}

		$payload['file'] = array(
			'name'     => isset( $file['name'] ) ? (string) $file['name'] : $name,
			'type'     => isset( $file['type'] ) ? (string) $file['type'] : '',
			'tmp_name' => $path,
			'error'    => 0,
			'size'     => isset( $file['size'] ) ? (int) $file['size'] : (int) filesize( $path ),
		);
		$placeholder = ( (string) ( $scope['plugin'] ?? '' ) === 'twinchat' )
			? $this->create_async_source_placeholder( $scope, $payload, $user_id, $job_id )
			: new WP_Error( 'async_placeholder_skipped', 'Async source placeholder is only available for TwinChat.' );
		if ( ! is_wp_error( $placeholder ) ) {
			$payload['metadata']['async_placeholder_source_id']    = (int) $placeholder['source_id'];
			$payload['metadata']['async_placeholder_kg_source_id'] = (int) $placeholder['kg_source_id'];
		} elseif ( (string) ( $scope['plugin'] ?? '' ) === 'twinchat' ) {
			// [2026-07-24 Johnny Chu] PHASE-0.46-ASYNC-INGEST — never queue an upload without durable TwinChat/KG placeholders to recover it.
			@unlink( $path );
			self::async_log( 'placeholder_error', array(
				'job_id'   => $job_id,
				'blog_id'  => $blog_id,
				'scope_id' => (int) ( $scope['scope_id'] ?? 0 ),
				'code'     => $placeholder->get_error_code(),
			) );
			return $placeholder;
		}

		// [2026-07-24 Johnny Chu] PHASE-0.46-ASYNC-INGEST — scheduler arguments are only a trigger; source metadata is the durable job record.
		$payload['metadata']['async_state']       = 'queued';
		$payload['metadata']['async_attempt']     = 0;
		$payload['metadata']['async_heartbeat_at'] = time();
		$payload['metadata']['async_file']        = basename( $path );
		$payload['metadata']['async_original_name'] = isset( $file['name'] ) ? (string) $file['name'] : $name;
		$payload['metadata']['async_scope_id']    = (int) ( $scope['scope_id'] ?? 0 );
		$payload['metadata']['async_user_id']     = (int) $user_id;
		$payload['metadata']['async_file_type']   = isset( $payload['file']['type'] ) ? (string) $payload['file']['type'] : '';
		$payload['metadata']['async_file_size']   = isset( $payload['file']['size'] ) ? (int) $payload['file']['size'] : 0;
		if ( ! is_wp_error( $placeholder ) && class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
			// [2026-07-25 Johnny Chu] HOTFIX async-watchdog — preserve 'async_ingest':true so watchdog LIKE '%async_ingest%' still finds this row after the initial INSERT is overwritten.
			$stored_meta = array_merge( (array) ( $payload['metadata'] ?? array() ), array( 'async_state' => 'queued', 'async_ingest' => true ) );
			BizCity_TwinChat_Sources_Database::instance()->update_source( (int) $placeholder['source_id'], array( 'metadata' => $stored_meta ) );
		}

		$job = array(
			'job_id'     => $job_id,
			'blog_id'    => $blog_id,
			'scope'      => $scope,
			'payload'    => $payload,
			'user_id'    => $user_id,
			'created_at' => time(),
		);

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			// [2026-07-23 Johnny Chu] PHASE-0.43 — log and wake cron runner so accepted uploads do not wait silently in Action Scheduler.
			$action_id = as_enqueue_async_action( self::HOOK_ASYNC_INGEST, array( $job ), 'bizcity_kg_async_ingest' );
			self::async_log( 'queued_action_scheduler', array(
				'job_id'       => $job_id,
				'action_id'    => is_numeric( $action_id ) ? (int) $action_id : 0,
				'blog_id'      => $blog_id,
				'scope_id'     => (int) ( $scope['scope_id'] ?? 0 ),
				'source_id'    => ! is_wp_error( $placeholder ) ? (int) $placeholder['source_id'] : 0,
				'kg_source_id' => ! is_wp_error( $placeholder ) ? (int) $placeholder['kg_source_id'] : 0,
				'file_size'    => (int) $payload['file']['size'],
			) );
			// [2026-07-23 Johnny Chu] PHASE-0.43 — Action Scheduler can return 0 when enqueue fails; fall back instead of leaving placeholders stuck.
			if ( ! is_numeric( $action_id ) || (int) $action_id <= 0 ) {
					// [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — delay the WP-Cron fallback so the upload AJAX request can release its cron lock before dispatch.
					$scheduled = wp_schedule_single_event( time() + 5, self::HOOK_ASYNC_INGEST, array( $job ) );
				if ( false === $scheduled && ! wp_next_scheduled( self::HOOK_ASYNC_INGEST, array( $job ) ) ) {
					self::mark_async_placeholder_failed( $payload, 'Không thể xếp lịch xử lý file nền.', 'async_ingest_schedule_failed' );
						self::async_log( 'schedule_failed_file_retained', array( 'job_id' => $job_id, 'source_id' => ! is_wp_error( $placeholder ) ? (int) $placeholder['source_id'] : 0 ) );
					return new WP_Error( 'async_ingest_schedule_failed', 'Không thể xếp lịch xử lý file nền.', array( 'http_status' => 503 ) );
				}
				self::async_log( 'queued_wp_cron_fallback', array(
					'job_id'       => $job_id,
					'blog_id'      => $blog_id,
					'scope_id'     => (int) ( $scope['scope_id'] ?? 0 ),
					'source_id'    => ! is_wp_error( $placeholder ) ? (int) $placeholder['source_id'] : 0,
					'kg_source_id' => ! is_wp_error( $placeholder ) ? (int) $placeholder['kg_source_id'] : 0,
				) );
			}
			$this->spawn_cron();
		} else {
			// [2026-07-23 Johnny Chu] PHASE-0.43 — WP-Cron fallback for sites without Action Scheduler.
			// [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — keep the non-Action-Scheduler path out of the current request's cron lock window.
			$scheduled = wp_schedule_single_event( time() + 5, self::HOOK_ASYNC_INGEST, array( $job ) );
			if ( false === $scheduled && ! wp_next_scheduled( self::HOOK_ASYNC_INGEST, array( $job ) ) ) {
				self::mark_async_placeholder_failed( $payload, 'Không thể xếp lịch xử lý file nền.', 'async_ingest_schedule_failed' );
				self::async_log( 'schedule_failed_file_retained', array( 'job_id' => $job_id, 'source_id' => ! is_wp_error( $placeholder ) ? (int) $placeholder['source_id'] : 0 ) );
				return new WP_Error( 'async_ingest_schedule_failed', 'Không thể xếp lịch xử lý file nền.', array( 'http_status' => 503 ) );
			}
			self::async_log( 'queued_wp_cron', array(
				'job_id'       => $job_id,
				'blog_id'      => $blog_id,
				'scope_id'     => (int) ( $scope['scope_id'] ?? 0 ),
				'source_id'    => ! is_wp_error( $placeholder ) ? (int) $placeholder['source_id'] : 0,
				'kg_source_id' => ! is_wp_error( $placeholder ) ? (int) $placeholder['kg_source_id'] : 0,
				'file_size'    => (int) $payload['file']['size'],
			) );
			$this->spawn_cron();
		}

		return array(
			'accepted'    => true,
			'async'       => true,
			'job_id'      => $job_id,
			'source_id'   => ! is_wp_error( $placeholder ) ? (int) $placeholder['source_id'] : 0,
			'kg_source_id'=> ! is_wp_error( $placeholder ) ? (int) $placeholder['kg_source_id'] : 0,
			'chunk_count' => 0,
			'passage_ids' => array(),
			'title'       => isset( $payload['title'] ) && $payload['title'] !== '' ? (string) $payload['title'] : (string) $payload['file']['name'],
		);
	}

	private function create_async_source_placeholder( array $scope, array $payload, int $user_id, string $job_id ) {
		// [2026-07-23 Johnny Chu] PHASE-0.43 — show async uploads in Sources immediately while parse/chunk/embed continues in background.
		if ( ! class_exists( 'BizCity_TwinChat_Sources_Database' ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'async_placeholder_unavailable', 'Source database not available.' );
		}
		global $wpdb;

		$scope_id = (int) ( $scope['scope_id'] ?? 0 );
		$file     = isset( $payload['file'] ) && is_array( $payload['file'] ) ? $payload['file'] : array();
		$title    = isset( $payload['title'] ) && $payload['title'] !== '' ? sanitize_text_field( (string) $payload['title'] ) : sanitize_file_name( (string) ( $file['name'] ?? 'upload' ) );
		if ( $scope_id <= 0 || $title === '' ) {
			return new WP_Error( 'async_placeholder_invalid', 'Invalid async placeholder payload.' );
		}

		$db = BizCity_TwinChat_Sources_Database::instance();
		$source_id = $db->insert_source( array(
			'project_id'       => (string) $scope_id,
			'notebook_id'      => $scope_id,
			'user_id'          => $user_id,
			'title'            => $title,
			'source_type'      => 'file',
			'source_url'       => '',
			'attachment_id'    => 0,
			'content_text'     => '',
			'content_hash'     => '',
			'embedding_model'  => '',
			'embedding_status' => 'processing',
			'metadata'         => array(
				'origin'       => 'file',
				'async_ingest' => true,
				'job_id'       => $job_id,
			),
		) );
		if ( $source_id <= 0 ) {
			return new WP_Error( 'async_placeholder_insert_failed', 'Failed to create source placeholder.' );
		}

		$tbl = BizCity_KG_Database::instance()->tbl_sources();
		$wpdb->insert( $tbl, array(
			'uuid'          => wp_generate_uuid4(),
			'blog_id'       => (int) get_current_blog_id(),
			'origin_plugin' => 'twinchat',
			'origin_kind'   => 'file',
			'origin_id'     => $source_id,
			'title'         => $title,
			'origin_url'    => null,
			'status'        => 'processing',
			'scope_type'    => 'notebook',
			'scope_id'      => (string) $scope_id,
			'user_id'       => $user_id,
			'passage_count' => 0,
		) );
		$kg_source_id = (int) $wpdb->insert_id;
		if ( $kg_source_id <= 0 ) {
			$db->update_source( $source_id, array(
				'embedding_status' => 'error',
				'error_message'    => 'Không tạo được nguồn xử lý nền.',
			) );
			return new WP_Error( 'async_kg_placeholder_insert_failed', 'Failed to create KG source placeholder.', array( 'db_error' => (string) $wpdb->last_error ) );
		}

		return array(
			'source_id'    => $source_id,
			'kg_source_id' => $kg_source_id,
		);
	}

	private function spawn_cron(): void {
		$url = site_url( 'wp-cron.php?doing_wp_cron=' . microtime( true ) );
		// [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — allow the detached cron request enough time to connect on multisite hosts while remaining non-blocking.
		wp_remote_post( $url, array(
			'timeout'   => 1,
			'blocking'  => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		) );
	}

	private static function async_log( string $event, array $context = array() ): void {
		// [2026-07-23 Johnny Chu] PHASE-0.43 — concise async ingest evidence without paths, SQL, tokens, or content text.
		$safe = array();
		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$safe[ sanitize_key( (string) $key ) ] = $value;
			}
		}
		$event = sanitize_key( $event );
		error_log( '[BizCity KG] async scoped ingest ' . $event . ': ' . wp_json_encode( $safe ) );

		// [2026-07-27 Johnny Chu] HOTFIX learning-log-parity — mirror every async
		// ingest lifecycle event into the SAME canonical per-blog learning log
		// (uploads/.../bizcity_learning_logs/YYYY-MM-DD.log) that the Learning Log
		// UI's "raw log hint" / "Tải JSON" export already point to, instead of
		// leaving this evidence stranded in the shared PHP error log only.
		if ( function_exists( 'bizcity_tc_learning_debug_log' ) ) {
			bizcity_tc_learning_debug_log( '[kg-ingest] async scoped ingest ' . $event . ': ' . wp_json_encode( $safe ) );
		}
	}

	private static function mark_async_placeholder_failed( array $payload, string $message, string $error_code = '' ): void {
		// [2026-07-23 Johnny Chu] PHASE-0.43 — prevent async source placeholders from staying in processing forever after adapter errors.
		$metadata     = isset( $payload['metadata'] ) && is_array( $payload['metadata'] ) ? $payload['metadata'] : array();
		$source_id    = isset( $metadata['async_placeholder_source_id'] ) ? (int) $metadata['async_placeholder_source_id'] : (int) ( $payload['source_id'] ?? 0 );
		$kg_source_id = isset( $metadata['async_placeholder_kg_source_id'] ) ? (int) $metadata['async_placeholder_kg_source_id'] : 0;
		$error        = function_exists( 'mb_substr' ) ? mb_substr( wp_strip_all_tags( $message ), 0, 500 ) : substr( wp_strip_all_tags( $message ), 0, 500 );

		if ( $source_id > 0 && class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
			$source_db = BizCity_TwinChat_Sources_Database::instance();
			$source_row = $source_db->get_source( $source_id );
			$source_meta = $source_row && ! empty( $source_row['metadata'] ) ? json_decode( (string) $source_row['metadata'], true ) : array();
			$source_meta = is_array( $source_meta ) ? $source_meta : array();
			$source_meta['async_state'] = 'error';
			$source_meta['async_error_at'] = time();
			$source_meta['async_heartbeat_at'] = time();
			// [2026-07-23 Johnny Chu] PHASE-0.47-ASYNC-ERROR-CODE — persist the original
			// WP_Error/Exception code alongside the message so the FE can humanize it
			// (e.g. gemini_quota_exhausted → "Quản lý gói và API key" CTA) instead of
			// showing raw text with a blind Retry button. No new DB column — reuses
			// the existing `metadata` JSON.
			if ( $error_code !== '' ) {
				$source_meta['async_error_code'] = sanitize_key( $error_code );
			}
			$source_db->update_source( $source_id, array(
				'embedding_status' => 'error',
				'error_message'    => $error,
				'metadata'         => $source_meta,
			) );
		}
		if ( $kg_source_id > 0 && class_exists( 'BizCity_KG_Database' ) ) {
			global $wpdb;
			$wpdb->update(
				BizCity_KG_Database::instance()->tbl_sources(),
				array( 'status' => 'error', 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => $kg_source_id )
			);
		}
	}

	private static function async_stage_path( $basename ) {
		$uploads = wp_upload_dir( null, true, false );
		$base    = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : wp_normalize_path( WP_CONTENT_DIR . '/uploads' );
		return trailingslashit( $base ) . 'bizcity-async-ingest/' . sanitize_file_name( (string) $basename );
	}

	private static function update_async_state( $source_id, $state, array $extra = array() ) {
		if ( (int) $source_id <= 0 || ! class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
			return;
		}
		$db   = BizCity_TwinChat_Sources_Database::instance();
		$row  = $db->get_source( (int) $source_id );
		$meta = $row && ! empty( $row['metadata'] ) ? json_decode( (string) $row['metadata'], true ) : array();
		$meta = is_array( $meta ) ? $meta : array();
		$meta['async_state'] = sanitize_key( (string) $state );
		$meta['async_heartbeat_at'] = time();
		foreach ( $extra as $key => $value ) {
			$meta[ sanitize_key( (string) $key ) ] = is_scalar( $value ) || null === $value ? $value : wp_json_encode( $value );
		}
		$db->update_source( (int) $source_id, array( 'metadata' => $meta ) );
	}

	private static function schedule_async_retry( array $job, $source_id, $message, string $error_code = '' ) {
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — retries must not
		// reschedule ingest or mutate source state in diagnostics CLI.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return false;
		}
		$meta    = isset( $job['payload']['metadata'] ) && is_array( $job['payload']['metadata'] ) ? $job['payload']['metadata'] : array();
		$attempt = (int) ( $meta['async_attempt'] ?? 0 );
		if ( $attempt >= self::ASYNC_MAX_ATTEMPTS ) {
			return false;
		}
		$meta['async_attempt'] = $attempt + 1;
		$meta['async_state'] = 'queued';
		$meta['async_error'] = function_exists( 'mb_substr' ) ? mb_substr( wp_strip_all_tags( (string) $message ), 0, 500 ) : substr( wp_strip_all_tags( (string) $message ), 0, 500 );
		// [2026-07-23 Johnny Chu] PHASE-0.47-ASYNC-ERROR-CODE — keep the code around
		// even on an in-progress retry so a stuck/never-terminal row (e.g. watchdog
		// never gets to it) still exposes something better than raw text.
		if ( $error_code !== '' ) {
			$meta['async_error_code'] = sanitize_key( $error_code );
		}
		$meta['async_heartbeat_at'] = time();
		if ( $source_id > 0 && class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
			BizCity_TwinChat_Sources_Database::instance()->update_source( (int) $source_id, array( 'metadata' => $meta, 'embedding_status' => 'processing' ) );
		}
		$job['payload']['metadata'] = $meta;
		$scheduled = wp_schedule_single_event( time() + 60, self::HOOK_ASYNC_INGEST, array( $job ) );
		return false !== $scheduled || (bool) wp_next_scheduled( self::HOOK_ASYNC_INGEST, array( $job ) );
	}

	public static function watchdog() {
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — watchdog/retry must not
		// mutate placeholders or schedule ingest from diagnostics CLI.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return;
		}
		if ( ! class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
			return;
		}
		global $wpdb;
		$db   = BizCity_TwinChat_Sources_Database::instance();
		// [2026-07-25 Johnny Chu] HOTFIX async-watchdog — OR fallback on async_state catches rows where async_ingest key was overwritten (pre-fix uploads stuck at materializing).
		// [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — include legacy error placeholders so a retained staged file can resume without a manual click.
		$rows = $wpdb->get_results( "SELECT id, project_id, user_id, title, metadata FROM {$db->table_sources()} WHERE embedding_status IN ('processing','error') AND (metadata LIKE '%async_ingest%' OR metadata LIKE '%async_state%') ORDER BY id ASC LIMIT 25", ARRAY_A ) ?: array();
		foreach ( $rows as $row ) {
			$meta = ! empty( $row['metadata'] ) ? json_decode( (string) $row['metadata'], true ) : array();
			if ( ! is_array( $meta ) || empty( $meta['async_file'] ) ) {
				continue;
			}
			$state = sanitize_key( (string) ( $meta['async_state'] ?? 'queued' ) );
			$beat  = (int) ( $meta['async_heartbeat_at'] ?? 0 );
			if ( in_array( $state, array( 'done', 'duplicate' ), true ) ) {
				continue;
			}
			$attempt = (int) ( $meta['async_attempt'] ?? 0 );
			$path    = self::async_stage_path( $meta['async_file'] );
			$auto_resume = false;
			if ( $state === 'error' ) {
				$auto_resume_count = (int) ( $meta['async_auto_resume_count'] ?? 0 );
				if ( ! file_exists( $path ) || $auto_resume_count >= self::ASYNC_AUTO_RESUME_MAX ) {
					continue;
				}
				$auto_resume = true;
			} elseif ( $beat > 0 && time() - $beat < self::ASYNC_STALE_AFTER ) {
				continue;
			}
			if ( ! file_exists( $path ) || ( ! $auto_resume && $attempt >= self::ASYNC_MAX_ATTEMPTS ) ) {
				// [2026-07-23 Johnny Chu] PHASE-0.47-ASYNC-ERROR-CODE — distinct codes so
				// FE can tell "infra lost the staged file" apart from "genuinely exhausted
				// retry budget", instead of both showing the same generic banner.
				self::mark_async_placeholder_failed(
					array( 'metadata' => $meta, 'source_id' => (int) $row['id'] ),
					! file_exists( $path ) ? 'File xử lý nền đã thất lạc.' : 'Đã vượt quá số lần thử xử lý file.',
					! file_exists( $path ) ? 'async_file_lost' : 'async_max_attempts_exceeded'
				);
				// [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — keep the staged artifact for an explicit manual retry; terminal watchdog handling must not destroy the user's upload.
				if ( file_exists( $path ) ) {
					self::async_log( 'watchdog_file_retained', array(
						'job_id'    => isset( $meta['job_id'] ) ? (string) $meta['job_id'] : '',
						'source_id' => (int) $row['id'],
						'attempt'   => $attempt,
					) );
				}
				continue;
			}
			if ( $auto_resume ) {
				// [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — reset transient retry budget for one bounded recovery pass over pre-fix error rows.
				$meta['async_attempt'] = 0;
				$meta['async_auto_resume_count'] = (int) ( $meta['async_auto_resume_count'] ?? 0 ) + 1;
				$meta['async_auto_resume_at'] = time();
				unset( $meta['async_error'], $meta['async_error_code'] );
			} else {
				$meta['async_attempt'] = $attempt + 1;
			}
			$meta['async_state'] = 'queued';
			$meta['async_heartbeat_at'] = time();
			$meta['async_retry_at'] = time();
			$db->update_source( (int) $row['id'], array( 'metadata' => $meta, 'error_message' => null, 'embedding_status' => 'processing' ) );
			$job = array(
				'job_id' => isset( $meta['job_id'] ) ? (string) $meta['job_id'] : '',
				'blog_id' => (int) get_current_blog_id(),
				'scope' => array( 'plugin' => 'twinchat', 'scope_type' => 'notebook', 'scope_id' => (int) $row['project_id'] ),
				'payload' => array( 'type' => 'file', 'title' => (string) $row['title'], 'metadata' => $meta, 'file' => array( 'name' => (string) ( $meta['async_original_name'] ?? $meta['async_file'] ), 'type' => (string) ( $meta['async_file_type'] ?? '' ), 'tmp_name' => $path, 'error' => 0, 'size' => (int) ( $meta['async_file_size'] ?? filesize( $path ) ) ) ),
				'user_id' => (int) $row['user_id'],
				'created_at' => time(),
			);
			wp_schedule_single_event( time() + ( $auto_resume ? 5 : 0 ), self::HOOK_ASYNC_INGEST, array( $job ) );
			self::async_log( $auto_resume ? 'watchdog_auto_resume' : 'watchdog_retry', array( 'job_id' => $job['job_id'], 'source_id' => (int) $row['id'], 'attempt' => $meta['async_attempt'] ) );
			self::instance()->spawn_cron();
		}
	}

	/**
	 * [2026-07-25 Johnny Chu] HOTFIX async-retry — true whenever the staged temp file for an async
	 * upload is still on disk. Used to tell the FE whether a one-click retry is possible or the
	 * source must be deleted and re-uploaded.
	 */
	public static function async_source_file_exists( string $basename ): bool {
		if ( $basename === '' ) {
			return false;
		}
		return file_exists( self::async_stage_path( $basename ) );
	}

	/**
	 * [2026-07-25 Johnny Chu] HOTFIX async-retry — manual one-click retry for a source stuck 'failed'
	 * after async ingest exhausted ASYNC_MAX_ATTEMPTS. Resets attempt counter + re-schedules
	 * run_async_ingest() from the still-staged temp file. Fails closed with a clear WP_Error when
	 * the temp file was already unlinked (terminal failure / max attempts already consumed).
	 */
	public static function retry_async_source( int $source_id, int $notebook_id = 0 ) {
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — manual retry must not
		// mutate a placeholder or schedule ingest in diagnostics CLI.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return new WP_Error( 'diagnostics_async_isolated', 'KG ingest worker is isolated during diagnostics CLI.' );
		}
		if ( ! class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
			return new WP_Error( 'service_unavailable', 'Sources service not available.', array( 'status' => 503 ) );
		}
		$db  = BizCity_TwinChat_Sources_Database::instance();
		$row = $db->get_source( $source_id );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Source not found.', array( 'status' => 404 ) );
		}
		if ( $notebook_id > 0 && (int) ( $row['project_id'] ?? 0 ) !== $notebook_id ) {
			return new WP_Error( 'forbidden', 'Access denied.', array( 'status' => 403 ) );
		}
		$meta = ! empty( $row['metadata'] ) ? json_decode( (string) $row['metadata'], true ) : array();
		$meta = is_array( $meta ) ? $meta : array();
		if ( empty( $meta['async_file'] ) ) {
			return new WP_Error( 'retry_unsupported', 'Nguồn này không phải upload xử lý nền, không thể thử lại tự động.', array( 'status' => 400 ) );
		}
		$path = self::async_stage_path( $meta['async_file'] );
		if ( ! file_exists( $path ) ) {
			// [2026-07-28 Johnny Chu] HOTFIX — missing staging evidence does not prove that retry exhaustion deleted the original upload.
			return new WP_Error( 'async_file_missing', 'Không còn bản staging cục bộ để tiếp tục xử lý. File có thể đã mất trong quá trình chuyển hàng đợi hoặc bị hệ thống lưu trữ dọn; hãy tải lại file.', array( 'status' => 410 ) );
		}

		$meta['async_attempt']      = 0;
		$meta['async_state']        = 'queued';
		$meta['async_heartbeat_at'] = time();
		$meta['async_retry_at']     = time();
		$meta['async_ingest']       = true;
		$db->update_source( $source_id, array( 'metadata' => $meta, 'error_message' => null, 'embedding_status' => 'processing' ) );

		$kg_source_id = isset( $meta['async_placeholder_kg_source_id'] ) ? (int) $meta['async_placeholder_kg_source_id'] : 0;
		if ( $kg_source_id > 0 && class_exists( 'BizCity_KG_Database' ) ) {
			global $wpdb;
			$wpdb->update(
				BizCity_KG_Database::instance()->tbl_sources(),
				array( 'status' => 'processing', 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => $kg_source_id )
			);
		}

		$job = array(
			'job_id'     => isset( $meta['job_id'] ) ? (string) $meta['job_id'] : wp_generate_uuid4(),
			'blog_id'    => (int) get_current_blog_id(),
			'scope'      => array(
				'plugin'     => 'twinchat',
				'scope_type' => 'notebook',
				'scope_id'   => (int) ( $row['project_id'] ?? 0 ),
			),
			'payload'    => array(
				'type'     => 'file',
				'title'    => (string) ( $row['title'] ?? '' ),
				'metadata' => $meta,
				'file'     => array(
					'name'     => (string) ( $meta['async_original_name'] ?? $meta['async_file'] ),
					'type'     => (string) ( $meta['async_file_type'] ?? '' ),
					'tmp_name' => $path,
					'error'    => 0,
					'size'     => (int) ( $meta['async_file_size'] ?? filesize( $path ) ),
				),
			),
			'user_id'    => (int) ( $row['user_id'] ?? 0 ),
			'created_at' => time(),
		);
		wp_schedule_single_event( time(), self::HOOK_ASYNC_INGEST, array( $job ) );
		self::instance()->spawn_cron();
		self::async_log( 'manual_retry', array( 'source_id' => $source_id, 'kg_source_id' => $kg_source_id ) );
		return true;
	}

	private static function mark_async_placeholder_duplicate( array $payload, array $result ): void {
		// [2026-07-23 Johnny Chu] PHASE-0.43 — hide transient placeholder when async ingest dedups to an existing source.
		$metadata     = isset( $payload['metadata'] ) && is_array( $payload['metadata'] ) ? $payload['metadata'] : array();
		$source_id    = isset( $metadata['async_placeholder_source_id'] ) ? (int) $metadata['async_placeholder_source_id'] : (int) ( $payload['source_id'] ?? 0 );
		$kg_source_id = isset( $metadata['async_placeholder_kg_source_id'] ) ? (int) $metadata['async_placeholder_kg_source_id'] : 0;
		$dedup_id     = isset( $result['source_id'] ) ? (int) $result['source_id'] : 0;
		if ( $source_id <= 0 || $dedup_id <= 0 || $dedup_id === $source_id ) {
			return;
		}
		if ( class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
			BizCity_TwinChat_Sources_Database::instance()->update_source( $source_id, array(
				'embedding_status' => 'duplicate',
				'error_message'    => null,
			) );
		}
		if ( $kg_source_id > 0 && class_exists( 'BizCity_KG_Database' ) ) {
			global $wpdb;
			$wpdb->update(
				BizCity_KG_Database::instance()->tbl_sources(),
				array( 'status' => 'deleted', 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => $kg_source_id )
			);
		}
	}

	public static function run_async_ingest( $job ): void {
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — async ingest entry
		// must not switch blog/user context or process files in diagnostics CLI.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return;
		}
		if ( ! is_array( $job ) ) {
			return;
		}
		$scope   = isset( $job['scope'] ) && is_array( $job['scope'] ) ? $job['scope'] : array();
		$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
		$user_id = isset( $job['user_id'] ) ? (int) $job['user_id'] : 0;
		$blog_id = isset( $job['blog_id'] ) ? (int) $job['blog_id'] : 0;
		$job_id  = isset( $job['job_id'] ) ? (string) $job['job_id'] : '';
		$file    = isset( $payload['file'] ) && is_array( $payload['file'] ) ? $payload['file'] : array();
		$path    = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$switched = false;
		$retry_file = false;
		$cleanup_file = false;
		$source_id = 0;

		@set_time_limit( 0 );
		@ignore_user_abort( true );

		try {
			// [2026-07-23 Johnny Chu] PHASE-0.43 — async worker must return to the upload blog before tenant DB writes.
			if ( $blog_id > 0 && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_current_blog_id' ) && (int) get_current_blog_id() !== $blog_id ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
			if ( $user_id > 0 && function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( $user_id );
			}
			// [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — rebuild the physical staging path after blog switch instead of trusting a serialized path from the enqueue request.
			$async_meta = isset( $payload['metadata'] ) && is_array( $payload['metadata'] ) ? $payload['metadata'] : array();
			$staged_basename = isset( $async_meta['async_file'] ) ? sanitize_file_name( (string) $async_meta['async_file'] ) : '';
			if ( $staged_basename !== '' ) {
				$path = self::async_stage_path( $staged_basename );
				$payload['file']['tmp_name'] = $path;
			}
			$source_id = isset( $payload['metadata']['async_placeholder_source_id'] ) ? (int) $payload['metadata']['async_placeholder_source_id'] : 0;
			$attempt   = (int) ( $payload['metadata']['async_attempt'] ?? 0 ) + 1;
			self::update_async_state( $source_id, 'running', array( 'async_attempt' => $attempt, 'async_started_at' => time() ) );
			self::async_log( 'start', array(
				'job_id'       => $job_id,
				'blog_id'      => $blog_id,
				'scope_id'     => (int) ( $scope['scope_id'] ?? 0 ),
				'user_id'      => $user_id,
				'source_id'    => isset( $payload['metadata']['async_placeholder_source_id'] ) ? (int) $payload['metadata']['async_placeholder_source_id'] : 0,
				'kg_source_id' => isset( $payload['metadata']['async_placeholder_kg_source_id'] ) ? (int) $payload['metadata']['async_placeholder_kg_source_id'] : 0,
				'file_size'    => isset( $file['size'] ) ? (int) $file['size'] : 0,
			) );
			if ( $path === '' || ! is_readable( $path ) ) {
				// [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — persist a safe discriminator for missing versus unreadable staging without exposing the filesystem path.
				self::async_log( 'file_check_failed', array(
					'job_id'       => $job_id,
					'blog_id'      => $blog_id,
					'source_id'    => $source_id,
					'path_present' => $path !== '' ? 1 : 0,
					'file_exists'  => $path !== '' && file_exists( $path ) ? 1 : 0,
					'readable'    => $path !== '' && is_readable( $path ) ? 1 : 0,
				) );
				throw new Exception( 'async ingest file missing' );
			}
			self::update_async_state( $source_id, 'materializing', array( 'async_materializing_at' => time() ) );
			$res = BizCity_KG::ingest( $scope, $payload );
			if ( is_wp_error( $res ) ) {
				// [2026-07-23 Johnny Chu] PHASE-0.47-ASYNC-ERROR-CODE — the code was already
				// captured into async_log() below; now also persist it on the source row
				// so REST/FE can humanize the terminal error instead of raw text only.
				$err_code   = $res->get_error_code();
				$retry_file = self::schedule_async_retry( $job, $source_id, $res->get_error_message(), $err_code );
				if ( ! $retry_file ) {
					self::mark_async_placeholder_failed( $payload, $res->get_error_message(), $err_code );
				}
				self::async_log( 'wp_error', array(
					'job_id'       => $job_id,
					'blog_id'      => $blog_id,
					'scope_id'     => (int) ( $scope['scope_id'] ?? 0 ),
					'source_id'    => isset( $payload['metadata']['async_placeholder_source_id'] ) ? (int) $payload['metadata']['async_placeholder_source_id'] : 0,
					'kg_source_id' => isset( $payload['metadata']['async_placeholder_kg_source_id'] ) ? (int) $payload['metadata']['async_placeholder_kg_source_id'] : 0,
					'code'         => $res->get_error_code(),
				) );
				return;
			}
			self::update_async_state( $source_id, 'persisting', array( 'async_persisting_at' => time() ) );
			if ( is_array( $res ) && ! empty( $res['duplicate'] ) ) {
				self::mark_async_placeholder_duplicate( $payload, $res );
			}
			$cleanup_file = true;
			self::update_async_state( $source_id, is_array( $res ) && ! empty( $res['duplicate'] ) ? 'duplicate' : 'done', array( 'async_finished_at' => time(), 'async_attempt' => $attempt ) );
			self::async_log( 'done', array(
				'job_id'       => $job_id,
				'blog_id'      => $blog_id,
				'scope_id'     => (int) ( $scope['scope_id'] ?? 0 ),
				'source_id'    => is_array( $res ) && isset( $res['source_id'] ) ? (int) $res['source_id'] : 0,
				'kg_source_id' => is_array( $res ) && isset( $res['kg_source_id'] ) ? (int) $res['kg_source_id'] : 0,
				'chunk_count'  => is_array( $res ) && isset( $res['chunk_count'] ) ? (int) $res['chunk_count'] : 0,
				'duplicate'    => is_array( $res ) && ! empty( $res['duplicate'] ) ? 1 : 0,
			) );
		} catch ( Throwable $e ) {
			// [2026-07-23 Johnny Chu] PHASE-0.47-ASYNC-ERROR-CODE — PHP exceptions don't
			// carry a WP_Error-style string code; use a fixed bucket so FE still knows
			// this was an unexpected runtime failure, not a business-logic rejection.
			$error_code = 'async ingest file missing' === $e->getMessage() ? 'async_file_missing' : 'async_ingest_exception';
			$retry_file = self::schedule_async_retry( $job, $source_id, $e->getMessage(), $error_code );
			if ( ! $retry_file ) {
				self::mark_async_placeholder_failed( $payload, $e->getMessage(), $error_code );
			}
			self::async_log( 'exception', array(
				'job_id'       => $job_id,
				'blog_id'      => $blog_id,
				'scope_id'     => (int) ( $scope['scope_id'] ?? 0 ),
				'source_id'    => isset( $payload['metadata']['async_placeholder_source_id'] ) ? (int) $payload['metadata']['async_placeholder_source_id'] : 0,
				'kg_source_id' => isset( $payload['metadata']['async_placeholder_kg_source_id'] ) ? (int) $payload['metadata']['async_placeholder_kg_source_id'] : 0,
				'class'        => get_class( $e ),
				'error_code'   => $error_code,
			) );
		} finally {
			if ( $switched && function_exists( 'restore_current_blog' ) ) {
				restore_current_blog();
			}
			if ( $cleanup_file && $path !== '' && file_exists( $path ) ) {
				@unlink( $path );
			}
		}
	}

	/**
	 * Phase 0.7 / Wave UI-ERR — translate adapter / tier WP_Errors into proper
	 * HTTP status codes so the SourcesPanel receives 4xx (not 500) and can
	 * render an actionable upgrade/retry message instead of a raw stack.
	 *
	 * Maps:
	 *   tier_required               → 402 Payment Required
	 *   insufficient_credit         → 402
	 *   pdf_extract_empty           → 422 Unprocessable Entity (scan PDF)
	 *   office_adapter_pending      → 422
	 *   adapter_empty               → 422
	 *   unsupported_ext             → 415 Unsupported Media Type
	 *   file_missing|file_read_failed → 400
	 *   pdf_file_*                  → 400
	 *   anything else with explicit data.http_status → honor it
	 *   fallback                    → 500
	 *
	 * Honors `data.http_status` set by adapters (see PDF/Office adapters).
	 */
	private static function normalize_ingest_error( WP_Error $err ) {
		$code = $err->get_error_code();
		$data = (array) $err->get_error_data();
		$explicit = isset( $data['http_status'] ) ? (int) $data['http_status'] : 0;
		$map = [
			'tier_required'          => 402,
			'insufficient_credit'    => 402,
			'quota_exceeded_free'    => 402,
			// [2026-07-14 Johnny Chu] HOTFIX — LiteParse disabled/parse misses are ingest issues, not server 500s.
			'liteparse_skipped'      => 422,
			'liteparse_empty'        => 422,
			'liteparse_unknown'      => 422,
			'liteparse_file_missing' => 400,
			'liteparse_file_unreadable' => 400,
			// [2026-07-15 Johnny Chu] HOTFIX — Gemini fallback parse errors are content/adapter issues, not server 500s.
			'gemini_invalid_json'    => 422,
			'gemini_no_pages'        => 422,
			'gemini_empty_response'  => 422,
			'gemini_file_unreadable' => 400,
			'gemini_read_failed'     => 400,
			'gemini_mime_unknown'    => 400,
			'gemini_file_too_large'  => 413,
			'gemini_page_limit_exceeded' => 413,
			'gemini_rate_limited'    => 429,
			'gemini_quota_exhausted' => 402,
			'gemini_upstream_error'  => 502,
			'pdf_extract_empty'      => 422,
			'office_adapter_pending' => 422, // legacy stub code (kept for back-compat)
			'office_extract_empty'   => 422,
			'office_docx_empty'      => 422,
			'office_xlsx_empty'      => 422,
			'office_pptx_empty'      => 422,
			'office_rtf_empty'       => 422,
			'office_xlsx_no_sheets'  => 422,
			'office_unknown_format'  => 415,
			'office_unsupported_kind'=> 415,
			'office_zip_missing'     => 500,
			'office_zip_open_failed' => 422,
			'office_file_too_large'  => 413,
			'adapter_empty'          => 422,
			// URL / web import errors (Wave 0.7 — surface 422/502 instead of 500).
			'no_url'                 => 400,
			'url_fetch_failed'       => 502,
			'url_empty'              => 422,
			'url_empty_text'         => 422,
			'youtube_no_captions'    => 422,
			'youtube_invalid_url'    => 400,
			'youtube_not_a_yt_url'   => 400,
			'youtube_player_response_missing' => 422,
			'youtube_player_response_invalid' => 422,
			'youtube_empty_transcript'        => 422,
			// Wave E0.AV — audio/video adapter
			'av_file_missing'        => 400,
			'av_file_unreadable'     => 400,
			'av_file_too_large'      => 413,
			'av_invalid_kind'        => 400,
			'av_missing_media_url'   => 400,
			'av_no_public_url'       => 500,
			'av_tmp_copy_failed'     => 500,
			'av_sideload_failed'     => 500,
			'av_client_missing'      => 500,
			'av_not_configured'      => 503,
			'av_transport_error'     => 502,
			'av_provider_error'      => 502,
			'av_invalid_response'    => 502,
			'av_no_speech'           => 422,
			'unsupported_ext'        => 415,
			'file_missing'           => 400,
			'file_read_failed'       => 400,
			'pdf_file_missing'       => 400,
			'pdf_file_unreadable'    => 400,
			'invalid_scope'          => 400,
			'file_too_large'         => 413,
		];
		$status = $explicit ?: ( isset( $map[ $code ] ) ? $map[ $code ] : 500 );
		$data['status'] = $status; // WP REST reads `data.status`
		return new WP_Error( $code, $err->get_error_message(), $data );
	}

	public function delete_source( WP_REST_Request $req ) {
		$scope = $this->resolve_scope( $req );
		if ( is_wp_error( $scope ) ) return $scope;

		$source_id = (int) $req->get_param( 'source_id' );
		$res = BizCity_KG::delete_source( $scope, $source_id );
		if ( is_wp_error( $res ) ) return $res;
		return rest_ensure_response( [ 'ok' => (bool) $res ] );
	}

	public function get_source( WP_REST_Request $req ) {
		$scope = $this->resolve_scope( $req );
		if ( is_wp_error( $scope ) ) return $scope;

		$source_id = (int) $req->get_param( 'source_id' );
		$res = BizCity_KG::get_source( $scope, $source_id );
		if ( is_wp_error( $res ) ) return $res;
		if ( ! $res ) {
			return new WP_Error( 'not_found', 'Source not found.', [ 'status' => 404 ] );
		}
		return rest_ensure_response( [ 'ok' => true, 'data' => $res ] );
	}

	public function list_all_sources( WP_REST_Request $req ) {
		$args = [
			'plugin'          => (string) ( $req->get_param( 'plugin' ) ?: '' ),
			'scope_type'      => (string) ( $req->get_param( 'scope_type' ) ?: '' ),
			'search'          => (string) ( $req->get_param( 'search' ) ?: '' ),
			'limit_per_scope' => (int) ( $req->get_param( 'limit_per_scope' ) ?: 50 ),
		];
		$rows = BizCity_KG::list_all_sources( get_current_user_id(), $args );
		return rest_ensure_response( [ 'ok' => true, 'data' => $rows ] );
	}

	public function attach_source( WP_REST_Request $req ) {
		$dest = $this->resolve_scope( $req );
		if ( is_wp_error( $dest ) ) return $dest;

		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) $body = $req->get_body_params();
		if ( ! is_array( $body ) ) $body = [];

		$from_plugin   = isset( $body['from_plugin'] ) ? sanitize_key( (string) $body['from_plugin'] ) : '';
		$from_scope_id = isset( $body['from_scope_id'] ) ? (int) $body['from_scope_id'] : 0;
		$source_id     = isset( $body['source_id'] ) ? (int) $body['source_id'] : 0;
		if ( $from_plugin === '' || $from_scope_id <= 0 || $source_id <= 0 ) {
			return new WP_Error( 'invalid_attach', 'from_plugin + from_scope_id + source_id required', [ 'status' => 400 ] );
		}

		$res = BizCity_KG::attach_source(
			[ 'plugin' => $from_plugin, 'scope_id' => $from_scope_id ],
			$source_id,
			$dest
		);
		if ( is_wp_error( $res ) ) return $res;
		return rest_ensure_response( [ 'ok' => true, 'data' => $res ] );
	}

	/* ──────────────────────  internals  ────────────────────── */

	private function resolve_scope( WP_REST_Request $req ) {
		$plugin   = sanitize_key( (string) $req->get_param( 'plugin' ) );
		$scope_id = (int) $req->get_param( 'scope_id' );
		if ( $plugin === '' || $scope_id <= 0 ) {
			return new WP_Error( 'invalid_scope', 'plugin + scope_id required', [ 'status' => 400 ] );
		}
		$scope = [ 'plugin' => $plugin, 'scope_id' => $scope_id ];
		// SECURITY: verify the current user is allowed to access this scope before
		// allowing any read or write operation on the underlying KG data.
		if ( ! $this->authorize_scope( $scope ) ) {
			return new WP_Error( 'forbidden', 'Scope not accessible.', [ 'status' => 403 ] );
		}
		return $scope;
	}

	/**
	 * Returns true when the current user can access the given plugin scope.
	 * Delegates to BizCity_KG::available_scopes() so each plugin's registered
	 * list_scopes_cb enforces its own ownership rules.
	 */
	private function authorize_scope( array $scope ) {
		$user_id = get_current_user_id();
		if ( user_can( $user_id, 'manage_options' ) ) return true; // admins bypass
		$available = BizCity_KG::available_scopes( $user_id, [ 'plugin' => $scope['plugin'] ] );
		foreach ( $available as $s ) {
			if ( (int) $s['scope_id'] === (int) $scope['scope_id'] ) return true;
		}
		return false;
	}
}
