<?php
/**
 * CRUD for bizcity_creator_templates table.
 *
 * ## Cache Contract (R-CACHE)
 *
 * Group : bzcc
 * Backend: WP object cache (in-memory, Tier 1 only — no transient needed,
 *          list is rebuilt cheaply on each request when not Redis-cached).
 *
 * | Cache key             | Covers                              | TTL        | Invalidated by                  |
 * |----------------------|-------------------------------------|------------|---------------------------------|
 * | templates_all        | get_all() — every row               | TTL_MEDIUM | flush_group('bzcc')             |
 * | templates_active     | get_all('active')                   | TTL_MEDIUM | flush_group('bzcc')             |
 * | template_id_{id}     | get_by_id($id)                      | TTL_MEDIUM | flush_group('bzcc')             |
 * | template_slug_{slug} | get_by_slug($slug)                  | TTL_MEDIUM | flush_group('bzcc')             |
 * | templates_cat_{cid}_{status} | get_by_category($cid,$status) | TTL_MEDIUM | flush_group('bzcc')      |
 * | templates_featured_{limit}   | get_featured($limit)          | TTL_MEDIUM | flush_group('bzcc')      |
 *
 * Related caches invalidated via bizcity_cache_flushed hook:
 *   - BZCC_Category_Manager (group: bzcc_cat) listens to flush 'bzcc'
 *     and clears its own category counts when template data changes.
 *
 * @since 1.3.8 — added BizCity_Cache layer
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCC_Template_Manager {

	/* ── Read ── */

	public static function get_all( string $status = '' ): array {
		// [2026-06-09 Johnny Chu] R-CACHE — object cache to prevent duplicate queries per request.
		$cache_key = $status ? 'templates_' . sanitize_key( $status ) : 'templates_all';
		$cached    = BizCity_Cache::get( 'bzcc', $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$t = BZCC_Installer::table_templates();

		$where = '';
		if ( $status ) {
			$where = $wpdb->prepare( ' WHERE status = %s', $status );
		}

		$result = $wpdb->get_results( "SELECT * FROM {$t}{$where} ORDER BY sort_order ASC, id ASC" );
		if ( ! is_array( $result ) ) {
			$result = array();
		}

		BizCity_Cache::set( 'bzcc', $cache_key, $result );
		return $result;
	}

	public static function get_all_active(): array {
		return self::get_all( 'active' );
	}

	public static function get_by_id( int $id ): ?object {
		$cache_key = 'template_id_' . $id;
		$cached    = BizCity_Cache::get( 'bzcc', $cache_key );
		if ( false !== $cached ) {
			return $cached ?: null; // stored as 0/false means not-found
		}

		global $wpdb;
		$t   = BZCC_Installer::table_templates();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );

		// Cache both found and not-found (store 0 for null to distinguish from cache miss).
		BizCity_Cache::set( 'bzcc', $cache_key, $row ? $row : 0 );
		return $row ?: null;
	}

	public static function get_by_slug( string $slug ): ?object {
		$cache_key = 'template_slug_' . sanitize_key( $slug );
		$cached    = BizCity_Cache::get( 'bzcc', $cache_key );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		global $wpdb;
		$t   = BZCC_Installer::table_templates();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE slug = %s", $slug ) );

		BizCity_Cache::set( 'bzcc', $cache_key, $row ? $row : 0 );
		return $row ?: null;
	}

	public static function get_by_category( int $category_id, string $status = 'active' ): array {
		$cache_key = 'templates_cat_' . $category_id . '_' . sanitize_key( $status );
		$cached    = BizCity_Cache::get( 'bzcc', $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$t      = BZCC_Installer::table_templates();
		$result = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE category_id = %d AND status = %s ORDER BY sort_order ASC",
			$category_id,
			$status
		) );
		if ( ! is_array( $result ) ) {
			$result = array();
		}

		BizCity_Cache::set( 'bzcc', $cache_key, $result );
		return $result;
	}

	public static function get_featured( int $limit = 10 ): array {
		$cache_key = 'templates_featured_' . $limit;
		$cached    = BizCity_Cache::get( 'bzcc', $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$t      = BZCC_Installer::table_templates();
		$result = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE is_featured = 1 AND status = 'active' ORDER BY sort_order ASC LIMIT %d",
			$limit
		) );
		if ( ! is_array( $result ) ) {
			$result = array();
		}

		BizCity_Cache::set( 'bzcc', $cache_key, $result );
		return $result;
	}

	/* ── Write ── */

	public static function insert( array $data ): int {
		global $wpdb;
		$t = BZCC_Installer::table_templates();

		$data = self::sanitize( $data );
		$data['created_at'] = current_time( 'mysql', true );
		$data['updated_at'] = $data['created_at'];

		if ( ! isset( $data['settings'] ) ) {
			$data['settings'] = '{}';
		}

		if ( empty( $data['author_id'] ) ) {
			$data['author_id'] = get_current_user_id();
		}

		$wpdb->insert( $t, $data );
		$id = (int) $wpdb->insert_id;

		if ( $id ) {
			// [2026-06-09 Johnny Chu] R-CACHE — invalidate all bzcc caches on write.
			BizCity_Cache::flush_group( 'bzcc' );
		}

		if ( $id && ! empty( $data['category_id'] ) ) {
			BZCC_Category_Manager::update_tool_count( (int) $data['category_id'] );
		}

		if ( $id && function_exists( 'bzcc_invalidate_skill_sync' ) ) {
			bzcc_invalidate_skill_sync();
		}

		return $id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$t = BZCC_Installer::table_templates();

		// Capture old category for count refresh
		$old = self::get_by_id( $id );

		$data = self::sanitize( $data );
		$data['updated_at'] = current_time( 'mysql', true );

		$ok = (bool) $wpdb->update( $t, $data, [ 'id' => $id ] );

		// Refresh counts on both old and new category
		if ( $ok ) {
			// [2026-06-09 Johnny Chu] R-CACHE — invalidate all bzcc caches on write.
			BizCity_Cache::flush_group( 'bzcc' );
			$old_cat = $old ? (int) $old->category_id : 0;
			$new_cat = (int) ( $data['category_id'] ?? $old_cat );
			if ( $old_cat ) {
				BZCC_Category_Manager::update_tool_count( $old_cat );
			}
			if ( $new_cat && $new_cat !== $old_cat ) {
				BZCC_Category_Manager::update_tool_count( $new_cat );
			}
			if ( function_exists( 'bzcc_invalidate_skill_sync' ) ) {
				bzcc_invalidate_skill_sync();
			}
		}

		return $ok;
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		$t = BZCC_Installer::table_templates();

		$tpl = self::get_by_id( $id );
		$ok  = (bool) $wpdb->delete( $t, [ 'id' => $id ] );

		if ( $ok ) {
			// [2026-06-09 Johnny Chu] R-CACHE — invalidate all bzcc caches on write.
			BizCity_Cache::flush_group( 'bzcc' );
		}

		if ( $ok && $tpl && (int) $tpl->category_id ) {
			BZCC_Category_Manager::update_tool_count( (int) $tpl->category_id );
		}

		if ( $ok && function_exists( 'bzcc_invalidate_skill_sync' ) ) {
			bzcc_invalidate_skill_sync();
		}

		return $ok;
	}

	/* ── Duplicate ── */

	public static function duplicate( int $id ): int {
		$tpl = self::get_by_id( $id );
		if ( ! $tpl ) {
			return 0;
		}

		$data              = (array) $tpl;
		$data['slug']      = $tpl->slug . '-copy-' . wp_generate_password( 4, false );
		$data['title']     = $tpl->title . ' (bản sao)';
		$data['status']    = 'draft';
		$data['use_count'] = 0;
		unset( $data['id'] );

		return self::insert( $data );
	}

	/* ── Counter ── */

	public static function increment_use_count( int $id ): void {
		global $wpdb;
		$t = BZCC_Installer::table_templates();

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET use_count = use_count + 1 WHERE id = %d",
			$id
		) );
	}

	/* ── Sanitize ── */

	private static function sanitize( array $data ): array {
		$allowed = [
			'slug', 'category_id', 'title', 'description',
			'icon_url', 'icon_emoji', 'form_fields',
			'system_prompt', 'outline_prompt', 'chunk_prompt',
			'model_purpose', 'temperature', 'max_tokens',
			'wizard_steps', 'output_platforms', 'skill_id',
			'tags', 'badge_text', 'badge_color',
			'use_count', 'is_featured', 'sort_order',
			'settings', 'status', 'author_id',
			'created_at', 'updated_at',
		];

		return array_intersect_key( $data, array_flip( $allowed ) );
	}
}
// [2026-06-21 Johnny Chu] R-CACHE — Register bzcc group in central Cache Registry.
// Called at file-load time (outside hooks) so diagnostics can enumerate groups on boot.
if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'bzcc', 'modules.content-creator', array(
		'templates_all'              => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'All templates' ),
		'templates_active'           => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Active templates list' ),
		'template_id_{id}'           => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Single template by ID' ),
		'template_slug_{slug}'       => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Single template by slug' ),
		'templates_cat_{cid}_{status}' => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Templates by category + status' ),
		'templates_featured_{limit}' => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Featured templates' ),
		'skill_sync_fp'              => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Skill-sync COUNT+MAX fingerprint' ),
	) );
}