<?php
/**
 * CRUD for bizcity_creator_templates table.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCC_Template_Manager {

	/* ── Read ── */

	public static function get_all( string $status = '' ): array {
		global $wpdb;
		$t = BZCC_Installer::table_templates();

		$where = '';
		if ( $status ) {
			$where = $wpdb->prepare( ' WHERE status = %s', $status );
		}

		return $wpdb->get_results( "SELECT * FROM {$t}{$where} ORDER BY sort_order ASC, id ASC" );
	}

	public static function get_all_active(): array {
		return self::get_all( 'active' );
	}

	public static function get_by_id( int $id ): ?object {
		global $wpdb;
		$t = BZCC_Installer::table_templates();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	public static function get_by_slug( string $slug ): ?object {
		global $wpdb;
		$t = BZCC_Installer::table_templates();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE slug = %s", $slug ) );
	}

	public static function get_by_category( int $category_id, string $status = 'active' ): array {
		global $wpdb;
		$t = BZCC_Installer::table_templates();

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE category_id = %d AND status = %s ORDER BY sort_order ASC",
			$category_id,
			$status
		) );
	}

	public static function get_featured( int $limit = 10 ): array {
		global $wpdb;
		$t = BZCC_Installer::table_templates();

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE is_featured = 1 AND status = 'active' ORDER BY sort_order ASC LIMIT %d",
			$limit
		) );
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
