<?php
/**
 * Phase 6.4 — Image Prompts Catalog DB.
 *
 * Two tables:
 *   {prefix}bzdoc_image_prompts  — System catalog of reusable image prompt
 *                                  templates (Raycast-style {argument}).
 *   {prefix}bzdoc_image_jobs     — Per-doc image generation job queue
 *                                  (rate-limit + history of variant runs).
 *
 * NOTE [V3 — KG-Hub exemption]:
 *   bzdoc_image_prompts is SYSTEM CATALOG — static reference data similar
 *   to fonts/themes table. NOT user content → NOT registered via the
 *   `bizcity_kg_register_source_table` filter. User-uploaded reference
 *   images go to {prefix}bzdoc_project_sources with
 *   metadata.role='image_reference' (Smart Sources Standard §2.3).
 *
 * Versioning: own option key `bzdoc_image_prompts_db_version`. We do NOT
 * piggy-back on the main `bzdoc_schema_version` so we can ship Phase 6.4
 * tables independently (no SHOW TABLES probe — slow).
 *
 * @package BizCity_Doc
 * @since   0.4.72  (Phase 6.4)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZDoc_Image_Prompts_Database {

	const SCHEMA_VERSION     = '1.2.0';
	const SCHEMA_VERSION_KEY = 'bzdoc_image_prompts_db_version';

	/**
	 * Self-healing creator. Idempotent — safe to call on every request.
	 */
	public static function maybe_create_tables(): void {
		$current = get_option( self::SCHEMA_VERSION_KEY, '0' );
		if ( version_compare( $current, self::SCHEMA_VERSION, '>=' ) ) {
			return;
		}

		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		$sql = "CREATE TABLE {$prefix}bzdoc_image_prompts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(120) NOT NULL,
			title VARCHAR(255) NOT NULL DEFAULT '',
			description TEXT,
			categories_json TEXT,
			template_json LONGTEXT NOT NULL,
			arguments_json TEXT,
			cover_url VARCHAR(1000) DEFAULT '',
			language VARCHAR(8) NOT NULL DEFAULT 'vi',
			featured TINYINT(1) NOT NULL DEFAULT 0,
			raycast_friendly TINYINT(1) NOT NULL DEFAULT 1,
			cms_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source_attribution VARCHAR(255) DEFAULT '',
			license VARCHAR(80) DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_slug (slug),
			KEY idx_lang_featured (language, featured),
			FULLTEXT KEY ft_search (title, description)
		) {$charset};

		CREATE TABLE {$prefix}bzdoc_image_jobs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			doc_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			prompt_template_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			resolved_prompt LONGTEXT,
			arguments_json TEXT,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			n_variants TINYINT UNSIGNED NOT NULL DEFAULT 1,
			aspect_ratio VARCHAR(10) NOT NULL DEFAULT '1:1',
			model VARCHAR(100) DEFAULT '',
			attachment_ids_json TEXT,
			error_message TEXT,
			cost_estimate_cents INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			completed_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_doc (doc_id),
			KEY idx_user_status (user_id, status),
			KEY idx_created (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::SCHEMA_VERSION_KEY, self::SCHEMA_VERSION );

		// Seed featured prompts on first install (idempotent inside).
		self::maybe_seed();
	}

	/**
	 * Seed a small fallback library of curated featured prompts. Source =
	 * `awesome-gpt-image-2` README (CC-BY-4.0); we credit per-row in
	 * `source_attribution` + `license`. Re-runs are safe — uses INSERT
	 * IGNORE on the unique slug.
	 */
	public static function maybe_seed(): void {
		$file = dirname( __FILE__ ) . '/data/image-prompts-seed.php';
		if ( ! file_exists( $file ) ) {
			return;
		}
		$rows = include $file;
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bzdoc_image_prompts';

		foreach ( $rows as $row ) {
			$slug = isset( $row['slug'] ) ? sanitize_key( $row['slug'] ) : '';
			if ( $slug === '' ) {
				continue;
			}

			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug
			) );
			if ( $exists ) {
				continue;
			}

			$wpdb->insert( $table, [
				'slug'               => $slug,
				'title'              => sanitize_text_field( $row['title'] ?? $slug ),
				'description'        => wp_kses_post( $row['description'] ?? '' ),
				'categories_json'    => wp_json_encode( $row['categories'] ?? [] ),
				'template_json'      => wp_json_encode( $row['template'] ?? [] ),
				'arguments_json'     => wp_json_encode( $row['arguments'] ?? [] ),
				'cover_url'          => esc_url_raw( $row['cover_url'] ?? '' ),
				'language'           => sanitize_text_field( $row['language'] ?? 'vi' ),
				'featured'           => ! empty( $row['featured'] ) ? 1 : 0,
				'raycast_friendly'   => isset( $row['raycast_friendly'] ) ? (int) (bool) $row['raycast_friendly'] : 1,
				'source_attribution' => sanitize_text_field( $row['source_attribution'] ?? '' ),
				'license'            => sanitize_text_field( $row['license'] ?? '' ),
				'created_at'         => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			] );
		}
	}

	/* ── Convenience accessors ─────────────────────────────────────── */

	public static function table_prompts(): string {
		global $wpdb;
		return $wpdb->prefix . 'bzdoc_image_prompts';
	}

	public static function table_jobs(): string {
		global $wpdb;
		return $wpdb->prefix . 'bzdoc_image_jobs';
	}

	public static function get_by_slug( string $slug ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table_prompts() . ' WHERE slug = %s LIMIT 1',
			sanitize_key( $slug )
		), ARRAY_A );
		return $row ?: null;
	}

	public static function get_by_id( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table_prompts() . ' WHERE id = %d LIMIT 1',
			$id
		), ARRAY_A );
		return $row ?: null;
	}

	public static function list_featured( int $limit = 12, string $language = 'vi' ): array {
		global $wpdb;
		$limit = max( 1, min( 50, $limit ) );
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT id, slug, title, description, cover_url, categories_json, arguments_json, language, source_attribution
			 FROM ' . self::table_prompts() . '
			 WHERE featured = 1 AND language = %s
			 ORDER BY updated_at DESC LIMIT %d',
			$language, $limit
		), ARRAY_A );
	}

	public static function search( string $q, int $limit = 20, string $language = 'vi' ): array {
		global $wpdb;
		$q = trim( $q );
		$limit = max( 1, min( 50, $limit ) );
		if ( $q === '' ) {
			return self::list_featured( $limit, $language );
		}
		// MATCH AGAINST in BOOLEAN MODE — fall back to LIKE if FT not ready.
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT id, slug, title, description, cover_url, categories_json, arguments_json, language, source_attribution
			 FROM ' . self::table_prompts() . '
			 WHERE language = %s AND ( title LIKE %s OR description LIKE %s )
			 ORDER BY featured DESC, updated_at DESC LIMIT %d',
			$language, $like, $like, $limit
		), ARRAY_A );
	}
}
