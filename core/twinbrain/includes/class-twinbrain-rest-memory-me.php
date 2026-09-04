<?php
/**
 * BizCity TwinBrain REST — Owner-self Memory Hub (Wave 2.8c TBR.MEM-C1).
 *
 * Endpoints (namespace bizcity-twinbrain/v1, all require is_user_logged_in):
 *
 *   GET    /memory/me               — list current user's memories
 *   POST   /memory/me               — create new explicit memory row
 *   PUT    /memory/me/(?P<id>\d+)   — update memory_text / score / type
 *   DELETE /memory/me/(?P<id>\d+)   — delete memory row
 *
 * Permission: is_user_logged_in() — every handler force-binds
 *   user_id = get_current_user_id() và check ownership trước UPDATE/DELETE
 *   (cross-user access ⇒ 403). Reuses BizCity_User_Memory::upsert_public()
 *   để giữ unique_key dedupe + max-per-user enforcement.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinBrain
 * @since      2026-05-24 (Phase 0.36-UNIFIED Wave 2.8c TBR.MEM-C1)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! trait_exists( 'BizCity_REST_Error' ) ) {
	$__trait = dirname( __DIR__, 2 ) . '/diagnostics/includes/trait-rest-error.php';
	if ( file_exists( $__trait ) ) {
		require_once $__trait;
	}
}

class BizCity_TwinBrain_REST_Memory_Me {

	use BizCity_REST_Error;

	private static $instance = null;
	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	protected function rest_error_module(): string {
		return 'twinbrain.rest.memory_me';
	}

	public function register_routes(): void {
		$ns = defined( 'BIZCITY_TWINBRAIN_REST_NS' ) ? BIZCITY_TWINBRAIN_REST_NS : 'bizcity-twinbrain/v1';

		register_rest_route( $ns, '/memory/me', [
			[
				'methods'             => 'GET',
				'permission_callback' => [ $this, 'perm_logged_in' ],
				'args'                => [
					'tier'   => [ 'type' => 'string',  'required' => false ],
					'type'   => [ 'type' => 'string',  'required' => false ],
					'q'      => [ 'type' => 'string',  'required' => false ],
					'limit'  => [ 'type' => 'integer', 'required' => false, 'default' => 50 ],
				],
				'callback'            => [ $this, 'handle_list' ],
			],
			[
				'methods'             => 'POST',
				'permission_callback' => [ $this, 'perm_logged_in' ],
				'args'                => [
					'memory_text' => [ 'type' => 'string', 'required' => true ],
					'memory_type' => [ 'type' => 'string', 'required' => false ],
					'memory_tier' => [ 'type' => 'string', 'required' => false ],
					'memory_key'  => [ 'type' => 'string', 'required' => false ],
					'score'       => [ 'type' => 'integer', 'required' => false ],
				],
				'callback'            => [ $this, 'handle_create' ],
			],
		] );

		register_rest_route( $ns, '/memory/me/(?P<id>[A-Za-z0-9_-]+)', [
			[
				'methods'             => 'PUT',
				'permission_callback' => [ $this, 'perm_logged_in' ],
				'args'                => [
					'id'          => [ 'type' => 'string', 'required' => true ],
					'memory_text' => [ 'type' => 'string',  'required' => false ],
					'memory_type' => [ 'type' => 'string',  'required' => false ],
					'memory_tier' => [ 'type' => 'string',  'required' => false ],
					'score'       => [ 'type' => 'integer', 'required' => false ],
				],
				'callback'            => [ $this, 'handle_update' ],
			],
			[
				'methods'             => 'DELETE',
				'permission_callback' => [ $this, 'perm_logged_in' ],
				'args'                => [
					'id' => [ 'type' => 'string', 'required' => true ],
				],
				'callback'            => [ $this, 'handle_delete' ],
			],
		] );
	}

	public function perm_logged_in() {
		return is_user_logged_in();
	}

	/* ---------------------------------------------------------------- *
	 *  Helpers                                                         *
	 * ---------------------------------------------------------------- */

	private function table(): string {
		global $wpdb;
		if ( class_exists( 'BizCity_User_Memory' ) ) {
			return BizCity_User_Memory::table();
		}
		return $wpdb->prefix . 'bizcity_memory_users';
	}

	private function fetch_row( $id, int $user_id ) {
		// [2026-09-01 Johnny Chu] PHASE-CB4.5 — REST memory rows are resolved through the Context Bank-backed owner API.
		return $this->find_filestore_row( $id, $user_id );
	}

	// [2026-09-01 Johnny Chu] PHASE-CB4.4 — resolve owner-self IDs from canonical filestore records, never from SQL payload rows.
	private function find_filestore_row( $id, int $user_id ) {
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			return null;
		}
		$rows = BizCity_User_Memory::instance()->get_memories( array(
			'user_id' => $user_id,
			'limit'   => 200,
		) );
		$id = (string) $id;
		foreach ( (array) $rows as $row ) {
			$matches = ctype_digit( $id )
				? (int) ( $row->id ?? 0 ) === (int) $id
				: (string) ( $row->record_id ?? '' ) === $id;
			if ( $matches && ! empty( $row->record_id ) ) {
				return $row;
			}
		}
		return null;
	}

	private function format_row( $row ): array {
		if ( ! $row ) return [];
		return [
			'id'           => (int) ( $row->id ?? 0 ),
			'record_id'    => (string) ( $row->record_id ?? '' ),
			'memory_tier'  => (string) $row->memory_tier,
			'memory_type'  => (string) $row->memory_type,
			'memory_key'   => (string) $row->memory_key,
			'memory_text'  => (string) $row->memory_text,
			'score'        => (int) $row->score,
			'times_seen'   => (int) ( $row->times_seen ?? 0 ),
			'last_seen'    => (string) ( $row->last_seen ?? '' ),
			'created_at'   => (string) ( $row->created_at ?? '' ),
			'updated_at'   => (string) ( $row->updated_at ?? '' ),
		];
	}

	/**
	 * Allow-list memory_tier — keep schema enum tight to avoid invalid values
	 * blowing up UPDATE / INSERT.
	 */
	private function sanitize_tier( $raw, string $fallback = 'explicit' ): string {
		$v = strtolower( trim( (string) $raw ) );
		return in_array( $v, [ 'explicit', 'extracted' ], true ) ? $v : $fallback;
	}

	private function sanitize_type( $raw, string $fallback = 'fact' ): string {
		$v = strtolower( trim( (string) $raw ) );
		$allowed = [ 'identity', 'preference', 'goal', 'pain', 'constraint', 'habit', 'relationship', 'fact', 'request' ];
		return in_array( $v, $allowed, true ) ? $v : $fallback;
	}

	private function sanitize_score( $raw, int $fallback = 60 ): int {
		$v = (int) $raw;
		if ( $v < 0 ) $v = 0;
		if ( $v > 100 ) $v = 100;
		return $v > 0 ? $v : $fallback;
	}

	/* ---------------------------------------------------------------- *
	 *  Handlers                                                        *
	 * ---------------------------------------------------------------- */

	public function handle_list( WP_REST_Request $req ) {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return $this->err_validation( 'memory_me_no_user', 'Cần đăng nhập.' );
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			return $this->err_server( 'memory_me_missing_dep', 'BizCity_User_Memory chưa load.' );
		}

		$tier  = $this->sanitize_tier( $req->get_param( 'tier' ), '' );
		$type  = $req->get_param( 'type' ) ? $this->sanitize_type( $req->get_param( 'type' ), '' ) : '';
		$q     = trim( (string) $req->get_param( 'q' ) );
		$limit = max( 1, min( 200, (int) $req->get_param( 'limit' ) ?: 50 ) );

		$rows = BizCity_User_Memory::instance()->get_memories( [
			'user_id'     => $uid,
			'session_id'  => '',
			'limit'       => $limit,
			'memory_tier' => $tier,
			'memory_type' => $type,
			'order_by'    => 'updated_at',
		] );

		// FE-side q-filter (cheap, list capped @200) — keep BE search SQL-light
		// to avoid LIKE explosion on memory_text without indexes.
		$rows = (array) $rows;
		if ( $q !== '' ) {
			$needle = mb_strtolower( $q );
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $needle ) {
				return mb_stripos( (string) ( $r->memory_text ?? '' ), $needle ) !== false
					|| mb_stripos( (string) ( $r->memory_key  ?? '' ), $needle ) !== false;
			} ) );
		}

		$items   = array_map( [ $this, 'format_row' ], $rows );
		$counts  = [ 'total' => count( $items ), 'explicit' => 0, 'extracted' => 0 ];
		foreach ( $items as $it ) {
			if ( ( $it['memory_tier'] ?? '' ) === 'explicit' )  $counts['explicit']++;
			if ( ( $it['memory_tier'] ?? '' ) === 'extracted' ) $counts['extracted']++;
		}

		return rest_ensure_response( [
			'ok'     => true,
			'items'  => $items,
			'counts' => $counts,
		] );
	}

	public function handle_create( WP_REST_Request $req ) {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return $this->err_validation( 'memory_me_no_user', 'Cần đăng nhập.' );
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			return $this->err_server( 'memory_me_missing_dep', 'BizCity_User_Memory chưa load.' );
		}

		$text = trim( (string) $req->get_param( 'memory_text' ) );
		if ( $text === '' ) {
			return $this->err_validation( 'memory_me_empty_text', 'memory_text không được để trống.' );
		}
		if ( mb_strlen( $text ) > 2000 ) {
			$text = mb_substr( $text, 0, 2000 );
		}

		$tier = $this->sanitize_tier( $req->get_param( 'memory_tier' ), 'explicit' );
		$type = $this->sanitize_type( $req->get_param( 'memory_type' ), 'fact' );
		$key  = trim( (string) $req->get_param( 'memory_key' ) );
		if ( $key === '' ) {
			// Auto-derive key: type:hash(text) — deterministic so upsert dedupes
			// repeat creates from FE button-mashing without leaking duplicates.
			$key = $type . ':manual:' . substr( md5( $text ), 0, 16 );
		}
		$score = $this->sanitize_score( $req->get_param( 'score' ), 80 );
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — REST-created memory rows require a verified canonical UUID.
		$scope = class_exists( 'BizCity_Memory_Identity_Scope' )
			? BizCity_Memory_Identity_Scope::for_write( [ 'user_id' => $uid ] )
			: null;
		if ( ! $scope ) {
			return $this->err_validation( 'memory_me_identity_unavailable', 'Chưa xác định được danh tính bền vững để lưu memory.' );
		}

		$res = BizCity_User_Memory::instance()->upsert_public( [
			'user_id'     => (int) $scope['user_id'],
			'session_id'  => '',
			'identity_uuid' => (string) $scope['identity_uuid'],
			'memory_tier' => $tier,
			'memory_type' => $type,
			'memory_key'  => $key,
			'memory_text' => $text,
			'score'       => $score,
			'metadata'    => wp_json_encode( [ 'source' => 'rest_me_create' ] ),
		] );

		if ( $res === false ) {
			return $this->err_server( 'memory_me_upsert_failed', 'Upsert thất bại.' );
		}

		// [2026-09-01 Johnny Chu] PHASE-CB4.4 — re-fetch the canonical filestore record by owner and key.
		$row = null;
		foreach ( (array) BizCity_User_Memory::instance()->get_memories( array( 'user_id' => $uid, 'limit' => 200 ) ) as $candidate ) {
			if ( (string) ( $candidate->memory_key ?? '' ) === $key && ! empty( $candidate->record_id ) ) {
				$row = $candidate;
				break;
			}
		}

		return rest_ensure_response( [
			'ok'   => true,
			'op'   => (string) $res, // 'insert' | 'update'
			'item' => $this->format_row( $row ),
		] );
	}

	public function handle_update( WP_REST_Request $req ) {
		$uid = (int) get_current_user_id();
		$id  = (string) $req->get_param( 'id' );
		if ( $uid <= 0 ) {
			return $this->err_validation( 'memory_me_no_user', 'Cần đăng nhập.' );
		}
		if ( $id === '' ) {
			return $this->err_validation( 'memory_me_bad_id', 'id không hợp lệ.' );
		}

		$row = $this->find_filestore_row( $id, $uid );
		if ( ! $row ) {
			return $this->err_not_found( 'memory_me_not_owned', 'Không tìm thấy memory (hoặc không phải của bạn).' );
		}

		$text = $req->get_param( 'memory_text' );
		$memory_text = (string) $row->memory_text;
		if ( $text !== null && $text !== '' ) {
			$t = trim( (string) $text );
			if ( mb_strlen( $t ) > 2000 ) $t = mb_substr( $t, 0, 2000 );
			$memory_text = $t;
		}
		$type = $req->get_param( 'memory_type' );
		$memory_type = (string) $row->memory_type;
		if ( $type !== null && $type !== '' ) {
			$memory_type = $this->sanitize_type( $type, $memory_type );
		}
		$tier = $req->get_param( 'memory_tier' );
		$memory_tier = (string) $row->memory_tier;
		if ( $tier !== null && $tier !== '' ) {
			$memory_tier = $this->sanitize_tier( $tier, $memory_tier );
		}
		$score = $req->get_param( 'score' );
		$memory_score = (int) $row->score;
		if ( $score !== null && $score !== '' ) {
			$memory_score = $this->sanitize_score( $score, $memory_score );
		}

		// [2026-09-01 Johnny Chu] PHASE-CB4.4 — reuse the filestore upsert owner for edits; no SQL memory payload mutation.
		$ok = BizCity_User_Memory::instance()->upsert_public( array(
			'user_id'       => $uid,
			'identity_uuid'  => (string) ( $row->identity_uuid ?? '' ),
			'session_id'    => (string) ( $row->session_id ?? '' ),
			'memory_tier'   => $memory_tier,
			'memory_type'   => $memory_type,
			'memory_key'    => (string) $row->memory_key,
			'memory_text'   => $memory_text,
			'score'         => $memory_score,
			'source_log_ids' => (string) ( $row->source_log_ids ?? '' ),
			'metadata'      => (string) ( $row->metadata ?? '' ),
		) );
		if ( $ok === false ) {
			return $this->err_server( 'memory_me_update_failed', 'Update thất bại.' );
		}

		$fresh = $this->find_filestore_row( $id, $uid );
		return rest_ensure_response( [
			'ok'   => true,
			'item' => $this->format_row( $fresh ),
		] );
	}

	public function handle_delete( WP_REST_Request $req ) {
		$uid = (int) get_current_user_id();
		$id  = (string) $req->get_param( 'id' );
		if ( $uid <= 0 ) {
			return $this->err_validation( 'memory_me_no_user', 'Cần đăng nhập.' );
		}
		if ( $id === '' ) {
			return $this->err_validation( 'memory_me_bad_id', 'id không hợp lệ.' );
		}

		$row = $this->find_filestore_row( $id, $uid );
		if ( ! $row ) {
			return $this->err_not_found( 'memory_me_not_owned', 'Không tìm thấy memory (hoặc không phải của bạn).' );
		}

		if ( ! class_exists( 'BizCity_Business_JSONL_File_Store' ) || empty( $row->record_id ) ) {
			return $this->err_server( 'memory_me_delete_failed', 'Xoá memory thất bại.' );
		}
		$delete_receipt = BizCity_Business_JSONL_File_Store::delete_with_receipt( BizCity_User_Memory::BUSINESS_CONTRACT_ID, (string) $row->record_id, array( 'blog_id' => get_current_blog_id(), 'user_id' => $uid ) );
		if ( ! is_array( $delete_receipt ) ) {
			return $this->err_server( 'memory_me_delete_failed', 'Xoá memory thất bại.' );
		}
		do_action( 'bizcity_memory_mirror_delete', 'user', $id, array( 'blog_id' => get_current_blog_id(), 'user_id' => $uid, 'record_id' => (string) $row->record_id, 'filestore_receipt' => $delete_receipt ) );

		return rest_ensure_response( [ 'ok' => true, 'id' => (int) ( $row->id ?? 0 ), 'record_id' => (string) $row->record_id ] );
	}
}
