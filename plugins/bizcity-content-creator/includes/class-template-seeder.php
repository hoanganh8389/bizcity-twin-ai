<?php
/**
 * Template Seeder — auto-import JSON templates shipped in /templates/.
 *
 * Strategy:
 *   - Per-site option `bzcc_seed_version` tracks last seeded BZCC_VERSION.
 *   - When option !== BZCC_VERSION, scan /templates/*.json + /templates/seed/*.json.
 *   - For each template entry:
 *       • If slug not in DB → INSERT.
 *       • If slug exists → skip (preserve user edits) unless filter
 *         `bzcc_seed_overwrite_existing` returns true.
 *   - Persist per-file SHA1 in `bzcc_seed_fingerprints` for future change detection.
 *   - Multisite-safe: option is per-blog, so each subsite seeds independently
 *     on first `init` after activation / version bump.
 *
 * Workflow for adding new templates:
 *   1. Drop new bzcc-template-*.json into /templates/ (or /templates/seed/).
 *   2. Bump BZCC_VERSION in bizcity-content-creator.php.
 *   3. On next page load (any site), seeder auto-imports templates whose slug
 *      doesn't yet exist on that site.
 *
 * @package bizcity-content-creator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCC_Template_Seeder {

	const OPT_VERSION      = 'bzcc_seed_version';
	const OPT_FINGERPRINTS = 'bzcc_seed_fingerprints';
	const LOCK_TRANSIENT   = 'bzcc_seed_lock';
	// [2026-06-22 Johnny Chu] R-PERF — flag set after successful seed; avoids SELECT COUNT(*) every request
	const OPT_SEEDED_FLAG  = 'bzcc_seed_seeded';

	/* ──────────────────────────────────────────────────────────────
	 * CANONICAL CATEGORY LIST — Phase 0 Foundation
	 * Add new categories here. Existing slugs are never deleted.
	 * ────────────────────────────────────────────────────────────── */
	private static function category_definitions(): array {
		return [
			// Phase 0 — Core groups
			[ 'slug' => 'kinh-doanh',    'title' => 'Kinh doanh & Chiến lược',  'icon_emoji' => '💼', 'sort_order' => 1  ],
			[ 'slug' => 'marketing',     'title' => 'Marketing & Quảng cáo',    'icon_emoji' => '📣', 'sort_order' => 2  ],
			[ 'slug' => 'ban-hang',      'title' => 'Bán hàng & Copywriting',   'icon_emoji' => '🛒', 'sort_order' => 3  ],
			[ 'slug' => 'xay-kenh',      'title' => 'Xây kênh & Social Media',  'icon_emoji' => '📱', 'sort_order' => 4  ],
			[ 'slug' => 'video',         'title' => 'Video & TikTok',            'icon_emoji' => '🎬', 'sort_order' => 5  ],
			[ 'slug' => 'viet-lach',     'title' => 'Viết lách & Sáng tạo',     'icon_emoji' => '✍️', 'sort_order' => 6  ],
			[ 'slug' => 'phat-trien-bn', 'title' => 'Phát triển bản thân',      'icon_emoji' => '🌱', 'sort_order' => 7  ],
			[ 'slug' => 'van-hanh',      'title' => 'Vận hành & Quản lý',       'icon_emoji' => '⚙️', 'sort_order' => 8  ],
			[ 'slug' => 'phap-ly',       'title' => 'Pháp lý & Văn bản',        'icon_emoji' => '⚖️', 'sort_order' => 9  ],
			[ 'slug' => 'hoc-tap',       'title' => 'Học tập & Giáo dục',       'icon_emoji' => '📚', 'sort_order' => 10 ],
			// Phase 1+ — Extend here (bump BZCC_VERSION to trigger seeding)
		];
	}

	/** Register lazy boot on init (after tables ensured). */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'maybe_seed' ], 5 );
	}

	/** Run seeding when version differs OR when template table is empty. */
	public static function maybe_seed(): void {
		$version_ok = ( get_option( self::OPT_VERSION ) === BZCC_VERSION );
		$seeded_ok  = (bool) get_option( self::OPT_SEEDED_FLAG );

		// [2026-07-11 Johnny Chu] HOTFIX — always verify physical table before trusting seed flags.
		// Clone/migrate can copy options but miss table/data on target blog.
		if ( ! class_exists( 'BZCC_Installer' ) ) {
			return;
		}
		if ( ! BZCC_Installer::tables_exist() ) {
			BZCC_Installer::maybe_create_tables();
			if ( ! BZCC_Installer::tables_exist() ) {
				return;
			}
		}

		if ( $version_ok ) {
			$count = self::template_count();

			// [2026-07-11 Johnny Chu] HOTFIX — stale seeded flag must not block reseed when table is empty.
			if ( $seeded_ok && $count > 0 ) {
				return;
			}

			if ( $count > 0 ) {
				update_option( self::OPT_SEEDED_FLAG, 1, true );
				return;
			}

			// [2026-07-11 Johnny Chu] HOTFIX — clear stale flag so next request cannot fast-skip on empty data.
			if ( $seeded_ok ) {
				delete_option( self::OPT_SEEDED_FLAG );
			}
			// Fall through: version ok but table is empty → force re-seed.
		}

		// Cross-request lock — avoid concurrent seeds (e.g. multiple admin tabs).
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::LOCK_TRANSIENT, 1, 60 );

		try {
			if ( ! BZCC_Installer::tables_exist() ) {
				return;
			}
			self::run();
			update_option( self::OPT_VERSION, BZCC_VERSION );
			// [2026-07-11 Johnny Chu] HOTFIX — mark seeded only when data really exists.
			if ( self::template_count() > 0 ) {
				update_option( self::OPT_SEEDED_FLAG, 1, true );
			} else {
				delete_option( self::OPT_SEEDED_FLAG );
			}
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Return current template row count.
	 */
	private static function template_count(): int {
		global $wpdb;
		$t = BZCC_Installer::table_templates();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Scan template directories and import. Always seeds categories first. */
	public static function run(): array {
		// 1. Seed / update canonical categories before templates.
		$cat_stats = self::seed_categories();

		$dirs = [
			BZCC_DIR . 'templates',
			BZCC_DIR . 'templates/seed',
		];

		$overwrite    = (bool) apply_filters( 'bzcc_seed_overwrite_existing', false );
		$fingerprints = (array) get_option( self::OPT_FINGERPRINTS, [] );

		$stats = [
			'categories_added'   => $cat_stats['added'],
			'categories_updated' => $cat_stats['updated'],
			'files'    => 0,
			'imported' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => [],
		];

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$files = glob( trailingslashit( $dir ) . 'bzcc-template-*.json' ) ?: [];
			foreach ( $files as $file ) {
				$stats['files']++;
				$raw = @file_get_contents( $file );
				if ( ! $raw ) {
					$stats['errors'][] = basename( $file ) . ': empty/unreadable';
					continue;
				}

				$hash = sha1( $raw );
				// [2026-07-11 Johnny Chu] HOTFIX — use relative path key to avoid basename collisions
				// between /templates and /templates/seed files with same filename.
				$key  = ltrim( str_replace( BZCC_DIR, '', $file ), '/\\' );
				if ( $key === '' ) {
					$key = basename( $file );
				}

				$data = json_decode( $raw, true );
				if ( ! is_array( $data ) || empty( $data['templates'] ) || ! is_array( $data['templates'] ) ) {
					$stats['errors'][] = $key . ': invalid JSON or missing "templates" array';
					continue;
				}

				$file_changed = ( ( $fingerprints[ $key ] ?? '' ) !== $hash );

				foreach ( $data['templates'] as $tpl ) {
					$res = self::import_one( $tpl, $overwrite, $file_changed );
					if ( $res === 'inserted' ) {
						$stats['imported']++;
					} elseif ( $res === 'updated' ) {
						$stats['updated']++;
					} elseif ( $res === 'skipped' ) {
						$stats['skipped']++;
					} else {
						$stats['errors'][] = $key . ': ' . $res;
					}
				}

				$fingerprints[ $key ] = $hash;
			}
		}

		update_option( self::OPT_FINGERPRINTS, $fingerprints, false );

		do_action( 'bzcc_seed_complete', $stats );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[BZCC Seeder] ' . wp_json_encode( $stats ) );
		}

		return $stats;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Category Seeder
	 * Rules:
	 *   • Slug not in DB → INSERT.
	 *   • Slug exists + title/emoji/sort changed → UPDATE those fields only
	 *     (tool_count is never touched here — it's managed separately).
	 *   • Never DELETE categories (safe for user data).
	 * ────────────────────────────────────────────────────────────── */
	public static function seed_categories(): array {
		global $wpdb;
		$t   = BZCC_Installer::table_categories();
		$now = current_time( 'mysql', true );

		$stats = [ 'added' => 0, 'updated' => 0 ];

		foreach ( self::category_definitions() as $def ) {
			$slug     = sanitize_title( $def['slug'] );
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, title, icon_emoji, sort_order FROM {$t} WHERE slug = %s", $slug
			) );

			if ( ! $existing ) {
				$wpdb->insert( $t, [
					'slug'        => $slug,
					'title'       => $def['title'],
					'description' => $def['description'] ?? '',
					'icon_emoji'  => $def['icon_emoji'] ?? '',
					'sort_order'  => (int) ( $def['sort_order'] ?? 0 ),
					'status'      => 'active',
					'created_at'  => $now,
					'updated_at'  => $now,
				] );
				$stats['added']++;
			} else {
				// Update only if something changed.
				$needs_update = (
					$existing->title      !== $def['title'] ||
					$existing->icon_emoji !== ( $def['icon_emoji'] ?? '' ) ||
					(int) $existing->sort_order !== (int) ( $def['sort_order'] ?? 0 )
				);
				if ( $needs_update ) {
					$wpdb->update( $t, [
						'title'      => $def['title'],
						'icon_emoji' => $def['icon_emoji'] ?? '',
						'sort_order' => (int) ( $def['sort_order'] ?? 0 ),
						'updated_at' => $now,
					], [ 'slug' => $slug ] );
					$stats['updated']++;
				}
			}
		}

		return $stats;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Resolve category slug → integer ID (with cache).
	 * ────────────────────────────────────────────────────────────── */
	private static array $cat_id_cache = [];

	private static function resolve_category( $raw ): int {
		if ( is_numeric( $raw ) ) {
			return (int) $raw;
		}
		$slug = (string) $raw;
		if ( $slug === '' ) {
			return 0;
		}
		if ( isset( self::$cat_id_cache[ $slug ] ) ) {
			return self::$cat_id_cache[ $slug ];
		}
		$cat = BZCC_Category_Manager::get_by_slug( $slug );
		$id  = $cat ? (int) $cat->id : 0;
		self::$cat_id_cache[ $slug ] = $id;
		return $id;
	}

	/**
	 * Import a single template entry.
	 *
	 * Rules:
	 *   • Slug not in DB                                              → INSERT.
	 *   • Slug exists + category_id=0 + JSON has a real category     → patch category_id only.
	 *   • Slug exists + $overwrite=true + $file_changed=true         → full UPDATE.
	 *   • Otherwise                                                   → skip.
	 *
	 * @return string 'inserted' | 'updated' | 'skipped' | error message
	 */
	private static function import_one( array $tpl, bool $overwrite, bool $file_changed ) {
		if ( empty( $tpl['slug'] ) || empty( $tpl['title'] ) ) {
			return 'missing slug or title';
		}

		$slug = sanitize_title( $tpl['slug'] );

		// Encode JSON columns
		foreach ( [ 'form_fields', 'wizard_steps', 'output_platforms', 'settings' ] as $col ) {
			if ( isset( $tpl[ $col ] ) && is_array( $tpl[ $col ] ) ) {
				$tpl[ $col ] = wp_json_encode( $tpl[ $col ], JSON_UNESCAPED_UNICODE );
			}
		}

		// Resolve category_id (slug string or numeric).
		$resolved_cat = self::resolve_category( $tpl['category_id'] ?? 0 );
		$tpl['category_id'] = $resolved_cat;

		$tpl['slug'] = $slug;
		unset( $tpl['id'], $tpl['use_count'], $tpl['created_at'], $tpl['updated_at'] );

		$existing = BZCC_Template_Manager::get_by_slug( $slug );

		if ( ! $existing ) {
			$new_id = BZCC_Template_Manager::insert( $tpl );
			return $new_id ? 'inserted' : 'insert failed';
		}

		// Patch category_id=0 → resolved category (safe, non-destructive).
		if ( $resolved_cat > 0 && (int) $existing->category_id === 0 ) {
			global $wpdb;
			$t = BZCC_Installer::table_templates();
			$wpdb->update( $t,
				[ 'category_id' => $resolved_cat, 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => (int) $existing->id ]
			);
			BZCC_Category_Manager::update_tool_count( $resolved_cat );
			return 'updated';
		}

		// Full overwrite when explicitly enabled and file changed.
		if ( $overwrite && $file_changed ) {
			$ok = BZCC_Template_Manager::update( (int) $existing->id, $tpl );
			return $ok ? 'updated' : 'update failed';
		}

		return 'skipped';
	}
}
