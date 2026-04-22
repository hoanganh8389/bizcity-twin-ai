<?php
/**
 * Database installer — creates 7 tables for Code Builder.
 *
 * Tables:
 *   bizcity_code_projects       — top-level project (website / landing page)
 *   bizcity_code_pages           — pages within a project
 *   bizcity_code_variants        — code variants per page (parallel generation)
 *   bizcity_code_assets          — screenshots, images, media attached to project
 *   bizcity_code_generations     — generation history / checkpoints
 *   bizcity_code_sources         — reference sources per project (files, URLs, text)
 *   bizcity_code_source_chunks   — source chunks for RAG embedding
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCode_Installer {

	/* ── Table helpers ── */

	public static function table_projects(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_code_projects';
	}

	public static function table_pages(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_code_pages';
	}

	public static function table_variants(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_code_variants';
	}

	public static function table_assets(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_code_assets';
	}

	public static function table_generations(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_code_generations';
	}

	public static function table_sources(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_code_sources';
	}

	public static function table_source_chunks(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_code_source_chunks';
	}

	/* ── Activation ── */

	public static function activate(): void {
		self::create_tables();
	}

	/* ── Self-healing ── */

	public static function maybe_create_tables(): void {
		if ( get_option( 'bzcode_db_version' ) === BZCODE_SCHEMA_VERSION ) {
			return;
		}
		self::create_tables();
	}

	/* ── dbDelta ── */

	public static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$t_proj    = self::table_projects();
		$t_page    = self::table_pages();
		$t_variant = self::table_variants();
		$t_asset   = self::table_assets();
		$t_gen     = self::table_generations();
		$t_src     = self::table_sources();
		$t_chunk   = self::table_source_chunks();

		$sql = "
CREATE TABLE {$t_proj} (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  title         VARCHAR(255) NOT NULL DEFAULT '',
  slug          VARCHAR(150) NOT NULL DEFAULT '',
  stack         VARCHAR(50) NOT NULL DEFAULT 'html_tailwind',
  description   TEXT NOT NULL,
  settings_json LONGTEXT NOT NULL,
  status        VARCHAR(20) NOT NULL DEFAULT 'draft',
  publish_url   VARCHAR(500) NOT NULL DEFAULT '',
  created_at    DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at    DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_slug (slug),
  KEY idx_status (status)
) {$charset};

CREATE TABLE {$t_page} (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  title       VARCHAR(255) NOT NULL DEFAULT 'index',
  slug        VARCHAR(150) NOT NULL DEFAULT 'index',
  sort_order  INT NOT NULL DEFAULT 0,
  status      VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at  DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at  DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (id),
  KEY idx_project (project_id),
  KEY idx_sort (project_id, sort_order)
) {$charset};

CREATE TABLE {$t_variant} (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  page_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  variant_index   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  code            LONGTEXT NOT NULL,
  history_json    LONGTEXT NOT NULL,
  model_used      VARCHAR(100) NOT NULL DEFAULT '',
  generation_type VARCHAR(20) NOT NULL DEFAULT 'create',
  is_selected     TINYINT(1) NOT NULL DEFAULT 0,
  status          VARCHAR(20) NOT NULL DEFAULT 'generating',
  error_message   VARCHAR(500) NOT NULL DEFAULT '',
  token_input     INT UNSIGNED NOT NULL DEFAULT 0,
  token_output    INT UNSIGNED NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at      DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (id),
  KEY idx_page (page_id),
  KEY idx_page_variant (page_id, variant_index),
  KEY idx_status (status)
) {$charset};

CREATE TABLE {$t_asset} (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  asset_type    VARCHAR(30) NOT NULL DEFAULT 'image',
  url           VARCHAR(500) NOT NULL DEFAULT '',
  filename      VARCHAR(255) NOT NULL DEFAULT '',
  metadata_json LONGTEXT NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (id),
  KEY idx_project (project_id),
  KEY idx_type (asset_type)
) {$charset};

CREATE TABLE {$t_gen} (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  variant_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  user_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  action          VARCHAR(20) NOT NULL DEFAULT 'create',
  status          VARCHAR(20) NOT NULL DEFAULT 'pending',
  prompt          TEXT NOT NULL,
  model           VARCHAR(100) NOT NULL DEFAULT '',
  code_snapshot   LONGTEXT NOT NULL,
  tokens_used     INT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms     INT UNSIGNED NOT NULL DEFAULT 0,
  error_message   TEXT NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  completed_at    DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (id),
  KEY idx_project (project_id),
  KEY idx_user_status (user_id, status),
  KEY idx_created (created_at)
) {$charset};

CREATE TABLE {$t_src} (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  user_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  title            VARCHAR(255) NOT NULL DEFAULT '',
  source_type      VARCHAR(30) NOT NULL DEFAULT 'file',
  source_url       VARCHAR(1000) NOT NULL DEFAULT '',
  attachment_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  content_text     LONGTEXT NOT NULL,
  content_hash     VARCHAR(64) NOT NULL DEFAULT '',
  char_count       INT UNSIGNED NOT NULL DEFAULT 0,
  token_estimate   INT UNSIGNED NOT NULL DEFAULT 0,
  chunk_count      INT UNSIGNED NOT NULL DEFAULT 0,
  embedding_model  VARCHAR(100) NOT NULL DEFAULT '',
  embedding_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  status           VARCHAR(20) NOT NULL DEFAULT 'ready',
  created_at       DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (id),
  KEY idx_project (project_id),
  KEY idx_user (user_id),
  KEY idx_embed_status (embedding_status)
) {$charset};

CREATE TABLE {$t_chunk} (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  project_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  chunk_index      INT UNSIGNED NOT NULL DEFAULT 0,
  content          TEXT NOT NULL,
  token_count      INT UNSIGNED NOT NULL DEFAULT 0,
  embedding        LONGTEXT NOT NULL,
  embedding_model  VARCHAR(100) NOT NULL DEFAULT '',
  created_at       DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (id),
  KEY idx_source (source_id),
  KEY idx_project (project_id),
  KEY idx_source_index (source_id, chunk_index)
) {$charset};
";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$old_suppress = $wpdb->suppress_errors( true );
		if ( isset( $wpdb->gwpdb ) && $wpdb->gwpdb instanceof wpdb ) {
			$wpdb->gwpdb->suppress_errors( true );
		} elseif ( method_exists( $wpdb, 'biz_ensure_gwpdb' ) ) {
			$gw = $wpdb->biz_ensure_gwpdb();
			if ( $gw ) {
				$gw->suppress_errors( true );
			}
		}

		dbDelta( $sql );

		$wpdb->suppress_errors( $old_suppress );
		if ( isset( $wpdb->gwpdb ) && $wpdb->gwpdb instanceof wpdb ) {
			$wpdb->gwpdb->suppress_errors( false );
		}

		update_option( 'bzcode_db_version', BZCODE_SCHEMA_VERSION );
	}
}
