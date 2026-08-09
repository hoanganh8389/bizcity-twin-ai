<?php
/**
 * BizCity CRM — standalone QR Link repository backed by a per-blog option.
 *
 * Cache Contract (R-CACHE):
 *   group: bzcqrl
 *   keys: qr_links_{args_hash}, qr_link_{id}, qr_link_pages, qr_link_store
 *   invalidations: create / update / delete flush the group
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_CRM_QR_Link_Repository' ) ) :

class BizCity_CRM_QR_Link_Repository {

	const CACHE_GROUP   = 'bzcqrl';
	const OPTION_KEY    = 'bizcity_crm_qr_link_store';
	const TYPE_URL      = 'url';
	const TYPE_PAGE     = 'page';
	const STATUS_ACTIVE = 'active';

	/**
	 * List active QR Links.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list( array $args = array() ): array {
		// [2026-08-01 Johnny Chu] PHASE-CG-QR-LINK — list records from the per-blog option.
		$args = wp_parse_args( $args, array(
			'q'      => '',
			'limit'  => 100,
			'offset' => 0,
		) );
		$cache_key = 'qr_links_' . md5( serialize( $args ) );
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) { return $cached; }
		}

		$limit  = max( 1, min( 200, (int) $args['limit'] ) );
		$offset = max( 0, (int) $args['offset'] );
		$query  = strtolower( trim( (string) $args['q'] ) );
		$rows   = array_filter( self::all(), static function ( $row ) use ( $query ) {
			if ( (string) ( $row['status'] ?? self::STATUS_ACTIVE ) !== self::STATUS_ACTIVE ) {
				return false;
			}
			if ( $query === '' ) {
				return true;
			}
			$haystack = strtolower( implode( ' ', array(
				(string) ( $row['title'] ?? '' ),
				(string) ( $row['target_url'] ?? '' ),
				(string) ( $row['slug'] ?? '' ),
			) ) );
			return strpos( $haystack, $query ) !== false;
		} );
		usort( $rows, static function ( $left, $right ) {
			return (int) ( $right['id'] ?? 0 ) <=> (int) ( $left['id'] ?? 0 );
		} );
		$result = array_slice( array_map( array( __CLASS__, 'hydrate' ), $rows ), $offset, $limit );
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result );
		}
		return $result;
	}

	/** @return int|WP_Error */
	public static function create( array $data ) {
		// [2026-08-01 Johnny Chu] PHASE-CG-QR-LINK — persist a new QR Link without a custom table.
		$normalized = self::normalize_payload( $data );
		if ( is_wp_error( $normalized ) ) { return $normalized; }
		$rows = self::all();
		$now  = current_time( 'mysql' );
		$row   = array_merge( $normalized, array(
			'id'         => self::next_id( $rows ),
			'slug'       => self::new_slug(),
			'status'     => self::STATUS_ACTIVE,
			'created_by' => get_current_user_id() ?: null,
			'created_at' => $now,
			'updated_at' => $now,
		) );
		$rows[] = $row;
		if ( ! self::save( $rows ) ) {
			return new WP_Error( 'gateway_degraded', 'Không thể lưu QR Link lúc này.' );
		}
		$id = (int) $row['id'];
		self::flush();
		if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_qr_link_created', array( 'qr_link_id' => $id ) );
		}
		return $id;
	}

	/** @return array|WP_Error */
	public static function update( int $id, array $patch ) {
		// [2026-08-01 Johnny Chu] PHASE-CG-QR-LINK — update one option-backed record atomically.
		$existing = self::get( $id );
		if ( ! $existing ) {
			return new WP_Error( 'not_found', 'Không tìm thấy QR Link.' );
		}
		$normalized = self::normalize_payload( array_merge( $existing, $patch ) );
		if ( is_wp_error( $normalized ) ) { return $normalized; }
		$normalized['updated_at'] = current_time( 'mysql' );
		$normalized = array_merge( $existing, $normalized );
		$rows = self::all();
		$found = false;
		foreach ( $rows as $index => $row ) {
			if ( (int) ( $row['id'] ?? 0 ) !== $id ) { continue; }
			$rows[ $index ] = $normalized;
			$found = true;
			break;
		}
		if ( ! $found || ! self::save( $rows ) ) {
			return new WP_Error( 'gateway_degraded', 'Không thể cập nhật QR Link lúc này.' );
		}
		self::flush();
		if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_qr_link_updated', array( 'qr_link_id' => $id ) );
		}
		return self::get( $id );
	}

	public static function delete( int $id ): bool {
		// [2026-08-01 Johnny Chu] PHASE-CG-QR-LINK — archive one record inside the per-blog option.
		$rows = self::all();
		$found = false;
		foreach ( $rows as $index => $row ) {
			if ( (int) ( $row['id'] ?? 0 ) !== $id ) { continue; }
			$rows[ $index ]['status'] = 'archived';
			$rows[ $index ]['deleted_at'] = current_time( 'mysql' );
			$rows[ $index ]['updated_at'] = current_time( 'mysql' );
			$found = true;
			break;
		}
		$ok = $found && self::save( $rows );
		if ( $ok ) {
			self::flush();
			if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
				BizCity_CRM_Event_Emitter::emit( 'crm_qr_link_deleted', array( 'qr_link_id' => $id ) );
			}
		}
		return (bool) $ok;
	}

	public static function get( int $id ): ?array {
		// [2026-08-01 Johnny Chu] PHASE-CG-QR-LINK — resolve a record from blog-scoped option data.
		$cache_key = 'qr_link_' . $id;
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) { return $cached; }
		}
		$result = null;
		foreach ( self::all() as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $id && (string) ( $row['status'] ?? self::STATUS_ACTIVE ) === self::STATUS_ACTIVE ) {
				$result = self::hydrate( $row );
				break;
			}
		}
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result );
		}
		return $result;
	}

	public static function pages(): array {
		$cache_key = 'qr_link_pages';
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) { return $cached; }
		}
		$posts = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		$result = array_values( array_map( static function ( $post ) {
			return array(
				'id'     => (int) $post->ID,
				'title'  => get_the_title( $post ),
				'url'    => (string) get_permalink( $post ),
				'status' => (string) $post->post_status,
			);
		}, $posts ) );
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result );
		}
		return $result;
	}

	public static function target_url( array $row ): string {
		if ( (string) ( $row['target_type'] ?? '' ) === self::TYPE_PAGE ) {
			$url = get_permalink( (int) ( $row['page_id'] ?? 0 ) );
			return $url ? (string) $url : '';
		}
		return (string) ( $row['target_url'] ?? '' );
	}

	private static function normalize_payload( array $data ) {
		// [2026-08-01 Johnny Chu] PHASE-CG-QR-LINK — validate URL/Page targets before option write.
		$title = trim( sanitize_text_field( (string) ( $data['title'] ?? '' ) ) );
		if ( $title === '' ) {
			return new WP_Error( 'invalid_param', 'Nhập tiêu đề QR Link.' );
		}
		$type = sanitize_key( (string) ( $data['target_type'] ?? self::TYPE_URL ) );
		if ( ! in_array( $type, array( self::TYPE_URL, self::TYPE_PAGE ), true ) ) {
			return new WP_Error( 'invalid_param', 'Chọn loại đích là Custom URL hoặc WordPress Page.' );
		}
		$row = array( 'title' => $title, 'target_type' => $type );
		if ( $type === self::TYPE_PAGE ) {
			$page_id = absint( $data['page_id'] ?? 0 );
			$page    = $page_id ? get_post( $page_id ) : null;
			if ( ! $page || $page->post_type !== 'page' ) {
				return new WP_Error( 'invalid_param', 'Chọn một WordPress Page hợp lệ.' );
			}
			$row['page_id']    = $page_id;
			$row['target_url'] = null;
		} else {
			$url = trim( esc_url_raw( (string) ( $data['target_url'] ?? '' ) ) );
			if ( ! function_exists( 'wp_http_validate_url' ) || ! wp_http_validate_url( $url ) ) {
				return new WP_Error( 'invalid_param', 'Nhập URL http(s) hợp lệ.' );
			}
			$row['target_url'] = $url;
			$row['page_id']    = null;
		}
		return $row;
	}

	private static function hydrate( array $row ): array {
		$row['id']                = (int) $row['id'];
		$row['page_id']           = $row['page_id'] !== null ? (int) $row['page_id'] : null;
		$row['target_url_resolved'] = self::target_url( $row );
		return $row;
	}

	private static function new_slug(): string {
		$slug = 'qrl_' . strtolower( wp_generate_password( 12, false, false ) );
		$slugs = array_map( static function ( $row ) { return (string) ( $row['slug'] ?? '' ); }, self::all() );
		while ( in_array( $slug, $slugs, true ) ) {
			$slug = 'qrl_' . strtolower( wp_generate_password( 12, false, false ) );
		}
		return $slug;
	}

	private static function next_id( array $rows ): int {
		$ids = array_map( static function ( $row ) { return (int) ( $row['id'] ?? 0 ); }, $rows );
		return $ids ? max( $ids ) + 1 : 1;
	}

	private static function all(): array {
		// [2026-08-01 Johnny Chu] PHASE-CG-QR-LINK — keep the small option store isolated by cache group.
		$cache_key = 'qr_link_store';
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached && is_array( $cached ) ) { return $cached; }
		}
		$rows = get_option( self::OPTION_KEY, array() );
		$rows = is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $rows );
		}
		return $rows;
	}

	private static function save( array $rows ): bool {
		// [2026-08-01 Johnny Chu] PHASE-CG-QR-LINK — avoid false failures when an inline edit is unchanged.
		$rows = array_values( $rows );
		$before = get_option( self::OPTION_KEY, array() );
		if ( is_array( $before ) && $before === $rows ) {
			return true;
		}
		return (bool) update_option( self::OPTION_KEY, $rows, false );
	}

	private static function flush(): void {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
	}
}

endif;

// [2026-08-01 Johnny Chu] PHASE-CG-QR-LINK — register cache keys for diagnostics.
if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'bzcqrl', 'modules.twin-crm', array(
		'qr_links_{args_hash}' => array( 'ttl' => 300, 'desc' => 'Standalone QR Link list keyed by filters' ),
		'qr_link_{id}'         => array( 'ttl' => 300, 'desc' => 'Single standalone QR Link by ID' ),
		'qr_link_pages'        => array( 'ttl' => 300, 'desc' => 'WordPress Page selector options' ),
		'qr_link_store'        => array( 'ttl' => 300, 'desc' => 'Per-blog option-backed QR Link store' ),
	) );
}