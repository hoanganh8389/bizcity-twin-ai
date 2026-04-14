<?php
/**
 * CRUD for bizcity_creator_categories table.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCC_Category_Manager {

	/* ── Read ── */

	public static function get_all( string $status = '' ): array {
		global $wpdb;
		$t = BZCC_Installer::table_categories();

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
		$t = BZCC_Installer::table_categories();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	public static function get_by_slug( string $slug ): ?object {
		global $wpdb;
		$t = BZCC_Installer::table_categories();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE slug = %s", $slug ) );
	}

	/* ── Write ── */

	public static function insert( array $data ): int {
		global $wpdb;
		$t = BZCC_Installer::table_categories();

		$data = self::sanitize( $data );
		$data['created_at'] = current_time( 'mysql', true );
		$data['updated_at'] = $data['created_at'];

		$wpdb->insert( $t, $data );
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$t = BZCC_Installer::table_categories();

		$data = self::sanitize( $data );
		$data['updated_at'] = current_time( 'mysql', true );

		return (bool) $wpdb->update( $t, $data, [ 'id' => $id ] );
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		$t = BZCC_Installer::table_categories();

		return (bool) $wpdb->delete( $t, [ 'id' => $id ] );
	}

	/* ── Counters ── */

	public static function update_tool_count( int $id ): void {
		global $wpdb;
		$t_cat = BZCC_Installer::table_categories();
		$t_tpl = BZCC_Installer::table_templates();

		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t_tpl} WHERE category_id = %d AND status = 'active'",
			$id
		) );

		$wpdb->update( $t_cat, [ 'tool_count' => $count ], [ 'id' => $id ] );
	}

	public static function refresh_all_counts(): void {
		$cats = self::get_all();
		foreach ( $cats as $cat ) {
			self::update_tool_count( (int) $cat->id );
		}
	}

	/* ── Sanitize ── */

	private static function sanitize( array $data ): array {
		$allowed = [
			'slug', 'title', 'description', 'icon_url', 'icon_emoji',
			'parent_id', 'sort_order', 'tool_count', 'status',
			'created_at', 'updated_at',
		];

		return array_intersect_key( $data, array_flip( $allowed ) );
	}
}
