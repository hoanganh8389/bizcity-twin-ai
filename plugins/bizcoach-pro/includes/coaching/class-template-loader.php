<?php
/**
 * BizCoach Pro — Template Loader.
 *
 * Boot-time loader: scan `data/coach-templates/*.json` (file seed), then overlay
 * with rows from `wp_bcpro_templates` (DB user-created). DB takes precedence per slug.
 *
 * Validates schema_version; logs warning + skips on mismatch (R-PP/R-DDV friendly).
 *
 * @since 0.1.0 (PHASE-0.36 / R-PROD-HUB)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Template_Loader' ) ) { return; }

class BizCoach_Pro_Template_Loader {

	const SUPPORTED_SCHEMA = '1.1';

	/** Boot — called on plugins_loaded@9. Idempotent. */
	public static function boot() {
		BizCoach_Pro_Template_Registry::reset();
		self::load_files();
		self::load_db_overrides();
	}

	private static function load_files() {
		$dir = BCPRO_TEMPLATE_DIR;
		if ( ! is_dir( $dir ) ) { return; }

		$files = glob( $dir . '*.json' );
		if ( ! is_array( $files ) ) { return; }

		foreach ( $files as $path ) {
			$raw = @file_get_contents( $path );
			if ( $raw === false || $raw === '' ) { continue; }
			$tpl = json_decode( $raw, true );
			if ( ! is_array( $tpl ) ) {
				self::log_warning( 'invalid JSON: ' . basename( $path ) );
				continue;
			}
			$tpl['source'] = 'file';
			if ( ! self::validate( $tpl, basename( $path ) ) ) { continue; }
			BizCoach_Pro_Template_Registry::set( $tpl );
		}
	}

	private static function load_db_overrides() {
		global $wpdb;
		$table = $wpdb->prefix . 'bcpro_templates';
		$blog  = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		// IMPORTANT: bizcoach-pro is a structural facade and does NOT install its
		// own `bcpro_templates` table (see class-installer.php). On sites where
		// the table was never created (most multisite blogs), querying it spams
		// "Table doesn't exist" errors on every plugins_loaded.
		//
		// Strategy: probe `SHOW TABLES LIKE` once per blog and cache the boolean
		// in object cache (12h). If absent, silently skip DB overrides — file
		// seed templates remain available. The cache is bumped via the
		// `bcpro/cache/invalidate` action `template` (post-install hook).
		$exists_key = 'table_exists:bcpro_templates:blog:' . $blog;
		$exists     = false;
		if ( class_exists( 'BizCoach_Pro_Cache' ) ) {
			$exists = (bool) BizCoach_Pro_Cache::remember(
				'bcpro_templates',
				$exists_key,
				12 * HOUR_IN_SECONDS,
				function () use ( $wpdb, $table ) {
					$found = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
					return $found === $table ? 1 : 0;
				}
			);
		} else {
			$exists = ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		}
		if ( ! $exists ) { return; }

		// Cache the DB rows (CACHE-STRATEGY.md §3 — group `bcpro_templates`,
		// 1h TTL, invalidated by `bcpro/cache/invalidate` action 'template').
		// Per-blog key because the WHERE clause is blog-scoped.
		$producer = function () use ( $wpdb, $table, $blog ) {
			$sql  = $wpdb->prepare(
				"SELECT slug, label, base_type, schema_version, schema_json, status FROM {$table} WHERE status = %s AND (blog_id = %d OR blog_id = 0)",
				'active',
				$blog
			);
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			return is_array( $rows ) ? $rows : array();
		};

		if ( class_exists( 'BizCoach_Pro_Cache' ) ) {
			$rows = BizCoach_Pro_Cache::remember(
				'bcpro_templates',
				'all:active:blog:' . $blog,
				HOUR_IN_SECONDS,
				$producer
			);
		} else {
			$rows = $producer();
		}
		if ( ! is_array( $rows ) ) { return; }

		foreach ( $rows as $row ) {
			$schema = is_string( $row['schema_json'] ) ? json_decode( $row['schema_json'], true ) : null;
			$tpl    = is_array( $schema ) ? $schema : [];
			$tpl['slug']           = $row['slug'];
			$tpl['label']          = isset( $tpl['label'] ) ? $tpl['label'] : $row['label'];
			$tpl['base_type']      = isset( $tpl['base_type'] ) ? $tpl['base_type'] : $row['base_type'];
			$tpl['schema_version'] = $row['schema_version'];
			$tpl['status']         = $row['status'];
			$tpl['source']         = 'db';
			if ( ! self::validate( $tpl, 'db:' . $row['slug'] ) ) { continue; }
			BizCoach_Pro_Template_Registry::set( $tpl ); // overrides file
		}
	}

	private static function validate( array $tpl, $where ) {
		if ( empty( $tpl['slug'] ) || ! is_string( $tpl['slug'] ) ) {
			self::log_warning( 'missing slug: ' . $where );
			return false;
		}
		$ver = isset( $tpl['schema_version'] ) ? (string) $tpl['schema_version'] : '';
		if ( $ver === '' ) {
			self::log_warning( 'missing schema_version: ' . $where );
			return false;
		}
		if ( version_compare( $ver, self::SUPPORTED_SCHEMA, '>' ) ) {
			self::log_warning( 'unsupported schema_version ' . $ver . ' (max ' . self::SUPPORTED_SCHEMA . '): ' . $where );
			return false;
		}
		return true;
	}

	private static function log_warning( $msg ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log( '[bizcoach-pro] template loader: ' . $msg );
		}
	}
}
