<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @license    GPL-2.0-or-later
 *
 * BizCity_Automation_Repo_Templates — wpdb-backed repo for the
 * `bizcity_automation_templates` table (BE-7).
 *
 * Two sources:
 *   - builtin  : seeded by BizCity_Automation_Templates_Seeder. Slug prefix `tpl_`.
 *   - user     : created via POST /workflows/:id/save-as-template.
 *   - imported : reserved for future cross-site import.
 *
 * Schema: core/diagnostics/changelog/core.automation.json v1.1.0.
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Repo_Templates {

	const TABLE = 'bizcity_automation_templates';

	// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W8 R-AUTO-TEMPLATE-ID — include categories used by JSON templates.
	const CATEGORIES = array( 'general', 'automation', 'cskh', 'care', 'lead', 'report', 'webhook', 'mpr', 'ai', 'test', 'personal', 'ecommerce', 'astrology' ); // [2026-07-21 Johnny Chu] PHASE-ASTRO-WORKFLOW — preserve canonical astro template category.
	// [2026-06-16 Johnny Chu] PHASE-ATH W5 — added 'hub_imported' for Hub-sourced templates.
	const SOURCES    = array( 'builtin', 'user', 'imported', 'hub_imported' );
	const VISIBILITIES = array( 'global', 'private' );

	public static function table(): string {
		return BizCity_Automation_Installer::table( self::TABLE );
	}

	// ─── Read ────────────────────────────────────────────────────────────

	public static function find( int $id ) {
		global $wpdb;
		BizCity_Automation_Installer::ensure();
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	public static function find_by_slug( string $slug ) {
		global $wpdb;
		BizCity_Automation_Installer::ensure();
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE slug = %s', $slug ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	public static function find_by_template_uuid( string $template_uuid ) {
		// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W8 R-AUTO-TEMPLATE-ID — UUID-first template upsert.
		global $wpdb;
		$template_uuid = self::normalise_template_uuid( $template_uuid );
		if ( $template_uuid === '' ) { return null; }
		BizCity_Automation_Installer::ensure();
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — older tenant tables may lack template_uuid until additive repair runs.
		if ( ! BizCity_Automation_Installer::templates_identity_schema_ready() && ! BizCity_Automation_Installer::force_templates_identity_schema() ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE template_uuid = %s LIMIT 1', $template_uuid ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @param array{category?:string,source?:string,visibility?:string,is_active?:int,search?:string,limit?:int,offset?:int} $args
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		BizCity_Automation_Installer::ensure();
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — legacy tenant tables may briefly miss visibility until additive repair completes.
		$has_identity_schema = BizCity_Automation_Installer::templates_identity_schema_ready()
			|| BizCity_Automation_Installer::force_templates_identity_schema();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['category'] ) && in_array( $args['category'], self::CATEGORIES, true ) ) {
			$where[]  = 'category = %s';
			$params[] = (string) $args['category'];
		}
		if ( ! empty( $args['source'] ) && in_array( $args['source'], self::SOURCES, true ) ) {
			$where[]  = 'source = %s';
			$params[] = (string) $args['source'];
		}
		if ( $has_identity_schema && ! empty( $args['visibility'] ) && in_array( $args['visibility'], self::VISIBILITIES, true ) ) {
			// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W8 R-AUTO-TEMPLATE-ID — gallery split global/private recipes.
			$where[]  = 'visibility = %s';
			$params[] = (string) $args['visibility'];
		}
		if ( isset( $args['is_active'] ) && $args['is_active'] !== '' && $args['is_active'] !== null ) {
			$where[]  = 'is_active = %d';
			$params[] = (int) (bool) $args['is_active'];
		} else {
			$where[] = 'is_active = 1';
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '( name LIKE %s OR slug LIKE %s OR description LIKE %s )';
			$params[] = $like; $params[] = $like; $params[] = $like;
		}

		$limit  = max( 1, min( 200, (int) ( $args['limit']  ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$where_sql = implode( ' AND ', $where );

		$order_sql = $has_identity_schema ? 'visibility ASC, source ASC, category ASC, name ASC' : 'source ASC, category ASC, name ASC';
		$rows_sql = "SELECT * FROM " . self::table() . " WHERE {$where_sql} ORDER BY {$order_sql} LIMIT {$limit} OFFSET {$offset}";
		$rows = $wpdb->get_results( $params ? $wpdb->prepare( $rows_sql, ...$params ) : $rows_sql, ARRAY_A );
		$rows = array_map( array( __CLASS__, 'hydrate' ), $rows ?: array() );

		$total_sql = "SELECT COUNT(*) FROM " . self::table() . " WHERE {$where_sql}";
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $total_sql, ...$params ) : $total_sql );

		return array( 'rows' => $rows, 'total' => $total );
	}

	// ─── Write ───────────────────────────────────────────────────────────

	/**
	 * Upsert by template_uuid first, then slug (idempotent — safe for repeated seeder runs).
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	public static function upsert( array $input ) {
		global $wpdb;
		BizCity_Automation_Installer::ensure();

		$row = self::normalise( $input );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( $row['slug'] === '' ) {
			return new WP_Error( 'missing_slug', 'Template slug is required.', array( 'status' => 400 ) );
		}
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — ensure schema is upgraded before writing UUID/version/visibility columns.
		$has_identity_schema = BizCity_Automation_Installer::templates_identity_schema_ready()
			|| BizCity_Automation_Installer::force_templates_identity_schema();
		if ( ! $has_identity_schema ) {
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — legacy fallback keeps seeding alive without writing columns that do not exist yet.
			unset( $row['template_uuid'], $row['template_version'], $row['visibility'] );
		}

		// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W8 R-AUTO-TEMPLATE-ID — UUID survives name/slug edits.
		$existing = ( $has_identity_schema && ! empty( $row['template_uuid'] ) ) ? self::find_by_template_uuid( $row['template_uuid'] ) : null;
		if ( ! $existing ) {
			$existing = self::find_by_slug( $row['slug'] );
		}
		$now      = current_time( 'mysql' );

		if ( $existing ) {
			$row['version']    = (int) $existing['version'] + 1;
			$row['updated_at'] = $now;
			$ok = $wpdb->update( self::table(), $row, array( 'id' => (int) $existing['id'] ) );
			if ( $ok === false ) {
				return new WP_Error( 'db_update_failed', $wpdb->last_error ?: 'update failed', array( 'status' => 500 ) );
			}
			return self::find( (int) $existing['id'] );
		}

		$row['version']    = 1;
		$row['use_count']  = 0;
		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		$row['created_by'] = (int) get_current_user_id();
		$ok = $wpdb->insert( self::table(), $row );
		if ( $ok === false ) {
			return new WP_Error( 'db_insert_failed', $wpdb->last_error ?: 'insert failed', array( 'status' => 500 ) );
		}
		return self::find( (int) $wpdb->insert_id );
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		// Soft delete (preserve history of usage). Builtin templates re-seed on next ensure().
		return $wpdb->update( self::table(), array( 'is_active' => 0, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) ) !== false;
	}

	public static function bump_use_count( int $id ): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET use_count = use_count + 1 WHERE id = %d", $id ) );
	}

	/**
	 * Clone template → bizcity_automation_workflows row.
	 *
	 * @param int                  $template_id
	 * @param array<string,mixed>  $overrides   Allow renaming on instantiate { name, slug, enabled }.
	 * @return array<string,mixed>|WP_Error     The newly created workflow row.
	 */
	public static function instantiate( int $template_id, array $overrides = array() ) {
		$tpl = self::find( $template_id );
		if ( ! $tpl ) {
			return new WP_Error( 'not_found', 'Template không tồn tại.', array( 'status' => 404 ) );
		}
		if ( ! (int) $tpl['is_active'] ) {
			return new WP_Error( 'inactive', 'Template đã tắt.', array( 'status' => 409 ) );
		}

		$slug_seed = isset( $overrides['slug'] ) && $overrides['slug'] !== ''
			? (string) $overrides['slug']
			: 'wf_from_' . $tpl['slug'] . '_' . wp_generate_password( 4, false, false );
		$name      = isset( $overrides['name'] ) && $overrides['name'] !== ''
			? (string) $overrides['name']
			: $tpl['name'] . ' (template)';

		$payload = array(
			'slug'                => $slug_seed,
			'name'                => $name,
			'description'         => (string) ( $tpl['description'] ?? '' ),
			'tags'                => (string) ( $tpl['tags'] ?? '' ),
			'enabled'             => isset( $overrides['enabled'] ) ? (int) (bool) $overrides['enabled'] : 0,
			'graph_json'          => is_string( $tpl['graph_json'] ?? null ) ? $tpl['graph_json'] : wp_json_encode( $tpl['graph'] ?? array( 'nodes' => array(), 'edges' => array() ) ),
			'trigger_type'        => (string) ( $tpl['trigger_type'] ?? 'manual' ),
			'trigger_config_json' => is_string( $tpl['trigger_config_json'] ?? null ) ? $tpl['trigger_config_json'] : ( $tpl['trigger_config'] ? wp_json_encode( $tpl['trigger_config'] ) : null ),
		);

		// [2026-06-07 Johnny Chu] CRM-PATH-1 — zone override: CRM surface passes zone='crm'.
		if ( isset( $overrides['zone'] ) ) {
			$payload['zone'] = in_array( $overrides['zone'], array( 'admin', 'crm' ), true )
				? (string) $overrides['zone']
				: 'admin';
		}

		$wf = BizCity_Automation_Repo_Workflows::create( $payload );
		if ( is_wp_error( $wf ) ) {
			return $wf;
		}
		self::bump_use_count( $template_id );
		$wf['_template_id']   = (int) $template_id;
		$wf['_template_slug'] = (string) $tpl['slug'];
		return $wf;
	}

	/**
	 * Save an existing workflow as a user template.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function save_from_workflow( int $workflow_id, array $meta = array() ) {
		$wf = BizCity_Automation_Repo_Workflows::find( $workflow_id );
		if ( ! $wf ) {
			return new WP_Error( 'not_found', 'Workflow không tồn tại.', array( 'status' => 404 ) );
		}
		$slug = isset( $meta['slug'] ) && $meta['slug'] !== ''
			? sanitize_title_with_dashes( (string) $meta['slug'] )
			: 'usr_' . sanitize_title_with_dashes( (string) $wf['slug'] ) . '_' . wp_generate_password( 4, false, false );

		return self::upsert( array(
			'template_uuid'       => wp_generate_uuid4(),
			'template_version'    => '1.0.0',
			'visibility'          => (string) ( $meta['visibility'] ?? 'private' ),
			'slug'                => $slug,
			'name'                => (string) ( $meta['name']        ?? $wf['name'] ),
			'description'         => (string) ( $meta['description'] ?? ( $wf['description'] ?? '' ) ),
			'category'            => (string) ( $meta['category']    ?? 'general' ),
			'source'              => 'user',
			'trigger_type'        => (string) $wf['trigger_type'],
			'graph_json'          => (string) ( $wf['graph_json'] ?? '' ),
			'trigger_config_json' => (string) ( $wf['trigger_config_json'] ?? '' ),
			'tags'                => (string) ( $meta['tags']        ?? ( $wf['tags'] ?? '' ) ),
			'icon'                => (string) ( $meta['icon']        ?? 'FileText' ),
			'is_active'           => 1,
		) );
	}

	// ─── Validation / hydration ──────────────────────────────────────────

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function normalise( array $in ) {
		$slug     = isset( $in['slug'] ) ? sanitize_title_with_dashes( str_replace( '/', '_', (string) $in['slug'] ) ) : '';
		$template_uuid = isset( $in['template_uuid'] ) ? self::normalise_template_uuid( (string) $in['template_uuid'] ) : '';
		if ( $template_uuid === '' && $slug !== '' ) {
			// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W8 R-AUTO-TEMPLATE-ID — deterministic legacy fallback.
			$template_uuid = self::uuid_from_slug( $slug );
		}
		$template_version = self::normalise_template_version( (string) ( $in['template_version'] ?? '1.0.0' ) );
		$visibility = sanitize_key( (string) ( $in['visibility'] ?? 'private' ) );
		if ( ! in_array( $visibility, self::VISIBILITIES, true ) ) { $visibility = 'private'; }
		$source   = ( $in['source'] ?? 'user' );
		if ( ! in_array( $source, self::SOURCES, true ) ) { $source = 'user'; }
		$category = ( $in['category'] ?? 'general' );
		if ( ! in_array( $category, self::CATEGORIES, true ) ) { $category = 'general'; }

		$trigger_type = (string) ( $in['trigger_type'] ?? 'manual' );
		if ( ! in_array( $trigger_type, BizCity_Automation_Repo_Workflows::TRIGGER_TYPES, true ) ) {
			return new WP_Error( 'invalid_trigger_type', sprintf( 'trigger_type không hợp lệ: %s', $trigger_type ), array( 'status' => 400 ) );
		}

		// graph_json — accept array or string. Validate via workflow validator.
		$graph_json = null;
		if ( array_key_exists( 'graph', $in ) ) {
			$in['graph'] = self::with_template_meta( (array) $in['graph'], $slug, $template_uuid, $template_version, $visibility );
			$check = BizCity_Automation_Repo_Workflows::validate_graph( $in['graph'] );
			if ( is_wp_error( $check ) ) { return $check; }
			$graph_json = wp_json_encode( $in['graph'] );
		} elseif ( array_key_exists( 'graph_json', $in ) ) {
			$graph_json = (string) $in['graph_json'];
			$decoded    = json_decode( $graph_json, true );
			if ( is_array( $decoded ) ) {
				$decoded = self::with_template_meta( $decoded, $slug, $template_uuid, $template_version, $visibility );
				$check = BizCity_Automation_Repo_Workflows::validate_graph( $decoded );
				if ( is_wp_error( $check ) ) { return $check; }
				$graph_json = wp_json_encode( $decoded );
			}
		}

		$trigger_config_json = null;
		if ( array_key_exists( 'trigger_config', $in ) ) {
			$trigger_config_json = is_string( $in['trigger_config'] ) ? $in['trigger_config'] : wp_json_encode( $in['trigger_config'] );
		} elseif ( array_key_exists( 'trigger_config_json', $in ) ) {
			$trigger_config_json = (string) $in['trigger_config_json'];
		}

		$tags_raw = $in['tags'] ?? '';
		$tags_arr = is_array( $tags_raw ) ? $tags_raw : preg_split( '/\s*,\s*/', (string) $tags_raw );
		$tags_arr = array_filter( array_map( 'sanitize_title_with_dashes', $tags_arr ?: array() ) );

		return array(
			'template_uuid'       => $template_uuid !== '' ? $template_uuid : null,
			'template_version'    => $template_version,
			'visibility'          => $visibility,
			'slug'                => $slug,
			'name'                => wp_strip_all_tags( (string) ( $in['name'] ?? '' ) ),
			'description'         => isset( $in['description'] ) ? wp_kses_post( (string) $in['description'] ) : '',
			'category'            => $category,
			'source'              => $source,
			'trigger_type'        => $trigger_type,
			'graph_json'          => $graph_json,
			'trigger_config_json' => $trigger_config_json,
			'tags'                => implode( ',', array_slice( array_unique( $tags_arr ), 0, 16 ) ),
			'icon'                => preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $in['icon'] ?? 'FileText' ) ) ?: 'FileText',
			'is_active'           => isset( $in['is_active'] ) ? (int) (bool) $in['is_active'] : 1,
		);
	}

	public static function hydrate( array $row ): array {
		$row['id']         = (int) $row['id'];
		$row['version']    = (int) $row['version'];
		$row['is_active']  = (int) $row['is_active'];
		$row['use_count']  = (int) $row['use_count'];
		$row['created_by'] = (int) $row['created_by'];
		$row['template_uuid']    = (string) ( $row['template_uuid'] ?? '' );
		$row['template_version'] = (string) ( $row['template_version'] ?? '1.0.0' );
		$row['visibility']       = in_array( (string) ( $row['visibility'] ?? '' ), self::VISIBILITIES, true ) ? (string) $row['visibility'] : 'private';
		$row['graph']          = ! empty( $row['graph_json'] )          ? json_decode( $row['graph_json'], true )          : null;
		$row['trigger_config'] = ! empty( $row['trigger_config_json'] ) ? json_decode( $row['trigger_config_json'], true ) : null;
		return $row;
	}

	private static function normalise_template_uuid( string $value ): string {
		$value = strtolower( trim( $value ) );
		return preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $value ) ? $value : '';
	}

	private static function normalise_template_version( string $value ): string {
		$value = trim( $value );
		if ( ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.\-]+)?$/', $value ) ) {
			return '1.0.0';
		}
		return substr( $value, 0, 20 );
	}

	private static function uuid_from_slug( string $slug ): string {
		$hash = md5( $slug );
		return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-7' . substr( $hash, 13, 3 ) . '-8' . substr( $hash, 17, 3 ) . '-' . substr( $hash, 20, 12 );
	}

	private static function with_template_meta( array $graph, string $slug, string $template_uuid, string $template_version, string $visibility ): array {
		$meta = isset( $graph['meta'] ) && is_array( $graph['meta'] ) ? $graph['meta'] : array();
		if ( $slug !== '' ) { $meta['template'] = $slug; }
		if ( $template_uuid !== '' ) { $meta['template_uuid'] = $template_uuid; }
		$meta['template_version'] = $template_version;
		$meta['visibility']       = $visibility;
		$graph['meta'] = $meta;
		return $graph;
	}
}
