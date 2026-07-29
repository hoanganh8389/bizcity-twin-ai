<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @license    GPL-2.0-or-later
 *
 * BizCity_Automation_Repo_Config_Packs — Wave 1 editable config packs.
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Repo_Config_Packs {

	const TABLE_PACKS = 'bizcity_automation_config_packs';
	const TABLE_ROWS  = 'bizcity_automation_config_rows';

	const SCHEMAS = array( 'content_calendar', 'product_catalog', 'automation_scenarios' );
	const STATUSES = array( 'draft', 'valid', 'warning', 'error', 'approved', 'archived' );

	private static $canonical_fields = array(
		'content_calendar' => array( 'day_index', 'date', 'time', 'channel', 'content_pillar', 'objective', 'title', 'content', 'image_brief', 'hashtags', 'cta', 'approval_status' ),
		'product_catalog' => array( 'category', 'vendor', 'product_name', 'price_vnd', 'usp', 'comparison_product', 'comparison_angle', 'sales_channel', 'payment_terms', 'content_notes' ),
		'automation_scenarios' => array( 'scenario_slug', 'enabled', 'guru_slug', 'scenario_type', 'trigger_type', 'schedule', 'channel', 'source_calendar', 'source_product_catalog', 'product_filter', 'approval_required', 'target_fb_page_id', 'target_wp_status', 'zalo_instance_id', 'reply_mode', 'action_chain', 'extra_json' ),
	);

	public static function table_packs(): string {
		return BizCity_Automation_Installer::table( self::TABLE_PACKS );
	}

	public static function table_rows(): string {
		return BizCity_Automation_Installer::table( self::TABLE_ROWS );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function create_pack( array $input ) {
		global $wpdb;
		// [2026-07-20 Johnny Chu] PHASE-1-TEMPLATES-AUTOMATION — ensure datatable pack tables before write.
		BizCity_Automation_Installer::ensure();

		$schema_key = self::sanitize_schema( (string) ( $input['schema_key'] ?? 'content_calendar' ) );
		$name       = wp_strip_all_tags( (string) ( $input['name'] ?? '' ) );
		if ( $name === '' ) {
			$name = self::default_pack_name( $schema_key );
		}

		$pack_key = 'gacp_' . gmdate( 'Ymd_His' ) . '_' . strtolower( wp_generate_password( 6, false, false ) );
		$now      = current_time( 'mysql' );
		$row      = array(
			'pack_key'      => $pack_key,
			'name'          => $name,
			'schema_key'    => $schema_key,
			'guru_id'       => max( 0, (int) ( $input['guru_id'] ?? 0 ) ),
			'owner_user_id' => max( 0, (int) ( $input['owner_user_id'] ?? get_current_user_id() ) ),
			'source_type'   => sanitize_key( (string) ( $input['source_type'] ?? 'manual' ) ),
			'source_ref'    => sanitize_textarea_field( (string) ( $input['source_ref'] ?? '' ) ),
			'version'       => 1,
			'status'        => 'draft',
			'created_at'    => $now,
			'updated_at'    => $now,
			'created_by'    => (int) get_current_user_id(),
		);

		$ok = $wpdb->insert( self::table_packs(), $row );
		if ( $ok === false ) {
			return new WP_Error( 'db_insert_failed', $wpdb->last_error ?: 'insert failed', array( 'status' => 500 ) );
		}
		$pack_id = (int) $wpdb->insert_id;

		$rows = isset( $input['rows'] ) && is_array( $input['rows'] ) ? $input['rows'] : array();
		self::replace_rows( $pack_id, $schema_key, $rows );
		self::refresh_pack_status( $pack_id );

		return self::find_pack( $pack_id, true );
	}

	/**
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public static function query_packs( array $args = array() ): array {
		global $wpdb;
		BizCity_Automation_Installer::ensure();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['schema_key'] ) ) {
			$where[]  = 'schema_key = %s';
			$params[] = self::sanitize_schema( (string) $args['schema_key'] );
		}
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		} else {
			$where[] = "status <> 'archived'";
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '( name LIKE %s OR pack_key LIKE %s OR source_ref LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$limit     = max( 1, min( 100, (int) ( $args['limit'] ?? 50 ) ) );
		$offset    = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$where_sql = implode( ' AND ', $where );
		$rows_sql  = 'SELECT * FROM ' . self::table_packs() . " WHERE {$where_sql} ORDER BY updated_at DESC LIMIT {$limit} OFFSET {$offset}";
		$rows      = $wpdb->get_results( $params ? $wpdb->prepare( $rows_sql, ...$params ) : $rows_sql, ARRAY_A );
		$rows      = array_map( array( __CLASS__, 'hydrate_pack' ), $rows ?: array() );

		$total_sql = 'SELECT COUNT(*) FROM ' . self::table_packs() . " WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $total_sql, ...$params ) : $total_sql );

		return array( 'rows' => $rows, 'total' => $total );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function find_pack( int $id, bool $with_rows = false ) {
		global $wpdb;
		BizCity_Automation_Installer::ensure();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_packs() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$pack = self::hydrate_pack( $row );
		if ( $with_rows ) {
			$pack['rows'] = self::get_rows( $id, array( 'limit' => 500 ) )['rows'];
		}
		return $pack;
	}

	/**
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public static function get_rows( int $pack_id, array $args = array() ): array {
		global $wpdb;
		BizCity_Automation_Installer::ensure();

		$where  = array( 'pack_id = %d' );
		$params = array( $pack_id );
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'search_text LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}
		if ( ! empty( $args['validation_status'] ) ) {
			$where[]  = 'validation_status = %s';
			$params[] = sanitize_key( (string) $args['validation_status'] );
		}

		$limit     = max( 1, min( 500, (int) ( $args['limit'] ?? 100 ) ) );
		$offset    = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$where_sql = implode( ' AND ', $where );
		$rows_sql  = 'SELECT * FROM ' . self::table_rows() . " WHERE {$where_sql} ORDER BY row_order ASC, id ASC LIMIT {$limit} OFFSET {$offset}";
		$rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$params ), ARRAY_A );
		$rows      = array_map( array( __CLASS__, 'hydrate_row' ), $rows ?: array() );

		$total_sql = 'SELECT COUNT(*) FROM ' . self::table_rows() . " WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $total_sql, ...$params ) );

		return array( 'rows' => $rows, 'total' => $total );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update_row( int $pack_id, int $row_id, array $row_json ) {
		global $wpdb;
		$pack = self::find_pack( $pack_id, false );
		if ( ! $pack ) {
			return new WP_Error( 'not_found', 'Bảng cấu hình không tồn tại.', array( 'status' => 404 ) );
		}

		$normal = self::normalize_config_row( (string) $pack['schema_key'], $row_json, $row_id );
		$ok = $wpdb->update(
			self::table_rows(),
			array(
				'row_json'               => wp_json_encode( $normal['row_json'] ),
				'canonical_json'         => wp_json_encode( $normal['canonical_json'] ),
				'search_text'            => $normal['search_text'],
				'validation_status'      => $normal['validation_status'],
				'validation_errors_json' => wp_json_encode( $normal['validation_errors'] ),
				'updated_at'             => current_time( 'mysql' ),
			),
			array( 'id' => $row_id, 'pack_id' => $pack_id )
		);
		if ( $ok === false ) {
			return new WP_Error( 'db_update_failed', $wpdb->last_error ?: 'update failed', array( 'status' => 500 ) );
		}
		self::refresh_pack_status( $pack_id );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_rows() . ' WHERE id = %d AND pack_id = %d', $row_id, $pack_id ), ARRAY_A );
		return $row ? self::hydrate_row( $row ) : new WP_Error( 'not_found', 'Dòng cấu hình không tồn tại.', array( 'status' => 404 ) );
	}

	/**
	 * Replace all rows for a saved pack. Used by DataTable add-row/add-column flows.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function replace_all_rows( int $pack_id, array $rows, array $meta = array() ) {
		global $wpdb;
		$pack = self::find_pack( $pack_id, false );
		if ( ! $pack ) {
			return new WP_Error( 'not_found', 'Bảng cấu hình không tồn tại.', array( 'status' => 404 ) );
		}

		// [2026-07-20 Johnny Chu] PHASE-1-TEMPLATES-AUTOMATION — bulk-save dynamic datatable edits without activating workflow.
		self::replace_rows( $pack_id, (string) $pack['schema_key'], $rows );
		$update = array(
			'updated_at' => current_time( 'mysql' ),
			'version'    => (int) $pack['version'] + 1,
		);
		if ( isset( $meta['name'] ) && trim( (string) $meta['name'] ) !== '' ) {
			$update['name'] = wp_strip_all_tags( (string) $meta['name'] );
		}
		$wpdb->update( self::table_packs(), $update, array( 'id' => $pack_id ) );
		self::refresh_pack_status( $pack_id );
		return self::find_pack( $pack_id, true );
	}

	public static function validate_pack( int $pack_id ) {
		$pack = self::find_pack( $pack_id, false );
		if ( ! $pack ) {
			return new WP_Error( 'not_found', 'Bảng cấu hình không tồn tại.', array( 'status' => 404 ) );
		}
		$rows = self::get_rows( $pack_id, array( 'limit' => 500 ) );
		foreach ( $rows['rows'] as $row ) {
			self::update_row( $pack_id, (int) $row['id'], is_array( $row['row_json'] ) ? $row['row_json'] : array() );
		}
		self::refresh_pack_status( $pack_id );
		return self::find_pack( $pack_id, true );
	}

	private static function replace_rows( int $pack_id, string $schema_key, array $rows ): void {
		global $wpdb;
		$wpdb->delete( self::table_rows(), array( 'pack_id' => $pack_id ), array( '%d' ) );
		$order = 1;
		foreach ( $rows as $input_row ) {
			if ( ! is_array( $input_row ) ) {
				continue;
			}
			$normal = self::normalize_config_row( $schema_key, $input_row, $order );
			$wpdb->insert( self::table_rows(), array(
				'pack_id'                => $pack_id,
				'row_key'                => 'row_' . str_pad( (string) $order, 4, '0', STR_PAD_LEFT ),
				'row_order'              => $order,
				'row_json'               => wp_json_encode( $normal['row_json'] ),
				'canonical_json'         => wp_json_encode( $normal['canonical_json'] ),
				'search_text'            => $normal['search_text'],
				'validation_status'      => $normal['validation_status'],
				'validation_errors_json' => wp_json_encode( $normal['validation_errors'] ),
				'updated_at'             => current_time( 'mysql' ),
			) );
			$order++;
		}
	}

	private static function refresh_pack_status( int $pack_id ): void {
		global $wpdb;
		$table = self::table_rows();
		$has_error = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE pack_id = %d AND validation_status = 'error'", $pack_id ) );
		$has_warning = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE pack_id = %d AND validation_status = 'warning'", $pack_id ) );
		$status = $has_error > 0 ? 'error' : ( $has_warning > 0 ? 'warning' : 'valid' );
		$wpdb->update( self::table_packs(), array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $pack_id ) );
	}

	private static function normalize_config_row( string $schema_key, array $row, int $fallback_order ): array {
		$clean = array();
		foreach ( $row as $key => $value ) {
			$k = sanitize_key( (string) $key );
			if ( $k === '' ) {
				continue;
			}
			// [2026-07-20 Johnny Chu] PHASE-1-TEMPLATES-AUTOMATION — preserve loose *_json columns as parsed JSON for LLM/runtime use.
			if ( is_string( $value ) && substr( $k, -5 ) === '_json' && trim( $value ) !== '' ) {
				$decoded = json_decode( $value, true );
				$clean[ $k ] = is_array( $decoded ) ? self::sanitize_deep( $decoded ) : sanitize_textarea_field( $value );
			} else {
				$clean[ $k ] = is_array( $value ) ? self::sanitize_deep( $value ) : sanitize_textarea_field( (string) $value );
			}
		}

		$canonical = array();
		foreach ( self::$canonical_fields[ $schema_key ] ?? array() as $field ) {
			if ( array_key_exists( $field, $clean ) ) {
				$canonical[ $field ] = self::normalize_value( $field, $clean[ $field ] );
			}
		}

		$errors = self::validate_row( $schema_key, $canonical, $clean );
		$status = empty( $errors ) ? 'valid' : 'error';

		return array(
			'row_json'          => $clean,
			'canonical_json'    => $canonical,
			'search_text'       => self::build_search_text( $clean ),
			'validation_status' => $status,
			'validation_errors' => $errors,
		);
	}

	private static function validate_row( string $schema_key, array $canonical, array $row ): array {
		$required = array(
			'content_calendar' => array( 'channel' ),
			'product_catalog' => array( 'product_name', 'price_vnd', 'usp', 'comparison_product' ),
			'automation_scenarios' => array( 'scenario_slug', 'enabled', 'guru_slug', 'scenario_type', 'trigger_type', 'channel', 'action_chain' ),
		);
		$errors = array();
		foreach ( $required[ $schema_key ] ?? array() as $field ) {
			$value = $canonical[ $field ] ?? ( $row[ $field ] ?? '' );
			if ( $value === '' || $value === null ) {
				$errors[] = array( 'field' => $field, 'message' => 'Thiếu dữ liệu bắt buộc.' );
			}
		}
		if ( $schema_key === 'content_calendar' ) {
			$title = trim( (string) ( $canonical['title'] ?? '' ) );
			$content = trim( (string) ( $canonical['content'] ?? '' ) );
			$topic = trim( (string) ( $row['topic'] ?? '' ) );
			if ( $title === '' && $content === '' && $topic === '' ) {
				$errors[] = array( 'field' => 'title', 'message' => 'Cần title/content hoặc topic để AI expand.' );
			}
		}
		foreach ( $row as $field => $value ) {
			if ( substr( (string) $field, -5 ) === '_json' && trim( (string) $value ) !== '' && ! is_array( $value ) ) {
				json_decode( (string) $value, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					$errors[] = array( 'field' => (string) $field, 'message' => 'JSON không hợp lệ.' );
				}
			}
		}
		return $errors;
	}

	private static function normalize_value( string $field, $value ) {
		if ( in_array( $field, array( 'price_vnd', 'day_index' ), true ) ) {
			return (int) preg_replace( '/[^0-9]/', '', (string) $value );
		}
		if ( in_array( $field, array( 'enabled', 'approval_required' ), true ) ) {
			$v = strtolower( trim( (string) $value ) );
			return in_array( $v, array( '1', 'yes', 'true', 'on', 'co', 'có' ), true ) ? 1 : 0;
		}
		return is_array( $value ) ? self::sanitize_deep( $value ) : sanitize_textarea_field( (string) $value );
	}

	private static function build_search_text( array $row ): string {
		$parts = array();
		array_walk_recursive( $row, static function ( $value ) use ( &$parts ) {
			if ( is_scalar( $value ) ) {
				$parts[] = strtolower( remove_accents( wp_strip_all_tags( (string) $value ) ) );
			}
		} );
		$text = preg_replace( '/\s+/', ' ', implode( ' ', $parts ) );
		return trim( (string) $text );
	}

	private static function sanitize_deep( array $value ): array {
		$out = array();
		foreach ( $value as $k => $v ) {
			$key = is_string( $k ) ? sanitize_key( $k ) : (int) $k;
			$out[ $key ] = is_array( $v ) ? self::sanitize_deep( $v ) : sanitize_textarea_field( (string) $v );
		}
		return $out;
	}

	private static function sanitize_schema( string $schema_key ): string {
		$schema_key = sanitize_key( $schema_key );
		return in_array( $schema_key, self::SCHEMAS, true ) ? $schema_key : 'content_calendar';
	}

	private static function default_pack_name( string $schema_key ): string {
		$labels = array(
			'content_calendar' => 'Content Calendar',
			'product_catalog' => 'Product Catalog',
			'automation_scenarios' => 'Automation Scenarios',
		);
		return ( $labels[ $schema_key ] ?? 'Config Pack' ) . ' ' . gmdate( 'Y-m-d H:i' );
	}

	private static function hydrate_pack( array $row ): array {
		return array(
			'id'            => (int) $row['id'],
			'pack_key'      => (string) $row['pack_key'],
			'name'          => (string) $row['name'],
			'schema_key'    => (string) $row['schema_key'],
			'guru_id'       => (int) $row['guru_id'],
			'owner_user_id' => (int) $row['owner_user_id'],
			'source_type'   => (string) $row['source_type'],
			'source_ref'    => (string) $row['source_ref'],
			'version'       => (int) $row['version'],
			'status'        => (string) $row['status'],
			'created_at'    => (string) $row['created_at'],
			'updated_at'    => (string) $row['updated_at'],
			'created_by'    => (int) $row['created_by'],
		);
	}

	private static function hydrate_row( array $row ): array {
		return array(
			'id'                     => (int) $row['id'],
			'pack_id'                => (int) $row['pack_id'],
			'row_key'                => (string) $row['row_key'],
			'row_order'              => (int) $row['row_order'],
			'row_json'               => self::decode_json( $row['row_json'] ?? '', array() ),
			'canonical_json'         => self::decode_json( $row['canonical_json'] ?? '', array() ),
			'search_text'            => (string) $row['search_text'],
			'validation_status'      => (string) $row['validation_status'],
			'validation_errors_json' => self::decode_json( $row['validation_errors_json'] ?? '', array() ),
			'updated_at'             => (string) $row['updated_at'],
		);
	}

	private static function decode_json( $json, array $fallback ): array {
		$data = is_string( $json ) && $json !== '' ? json_decode( $json, true ) : null;
		return is_array( $data ) ? $data : $fallback;
	}
}