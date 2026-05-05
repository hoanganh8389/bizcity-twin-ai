<?php
/**
 * Bizcity Twin AI — TwinChat Admin Menu
 *
 * Registers the admin page that hosts the React workspace bundle.
 * Tries Vite-built `ui/dist/` assets, falls back to a placeholder div.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 * @since 2026-05-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Admin_Menu {

	const PAGE_SLUG = 'bizcity-twinchat';

	private static $instance = null;

	/** Populated by enqueue_assets() so render_page() does not re-query. */
	private $resolved_nb_id   = 0;
	private $resolved_nb_name = '';
	private $resolved_nb_list = [];
	private $bundle_built     = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		// 2026-05-06 — TwinChat is now the default dashboard (replaces WebChat dashboard).
		// Position 2 = top of admin sidebar, capability 'read' = visible to end users.
		add_menu_page(
			'Twin AI',
			'Twin',
			'read',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'dashicons-format-chat',
			2
		);

		// Wave 10d.5c — Admin diagnostic: source learning progress debugger.
		// URL: /wp-admin/admin.php?page=bizcity-twinchat-diag-sources&nb=2
		// Access: manage_options only. Avoids REST nonce requirement.
		add_submenu_page(
			self::PAGE_SLUG,
			'Diag: Source Progress',
			'Diag Sources',
			'manage_options',
			'bizcity-twinchat-diag-sources',
			[ $this, 'render_diag_sources_page' ]
		);

		// Enqueue assets only on our admin page.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		// Make the main entry script a JS module (Vite output requires type="module").
		add_filter( 'script_loader_tag', [ $this, 'add_module_type' ], 10, 3 );
	}

	/**
	 * Enqueue Vite-built CSS & JS for the TwinChat admin page.
	 * Runs on admin_enqueue_scripts — assets end up in <head> / footer properly.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== 'toplevel_page_' . self::PAGE_SLUG ) {
			return;
		}

		$dist_dir = BIZCITY_TWINCHAT_UI_DIR . 'dist/';
		$dist_url = trailingslashit( BIZCITY_TWINCHAT_URL ) . 'ui/dist/';
		$manifest = $dist_dir . '.vite/manifest.json';
		if ( ! file_exists( $manifest ) ) {
			$manifest = $dist_dir . 'manifest.json';
		}
		if ( ! file_exists( $manifest ) ) {
			return;
		}

		$json = json_decode( (string) file_get_contents( $manifest ), true );
		if ( ! is_array( $json ) ) {
			return;
		}

		$entry_js  = '';
		$chunk_js  = [];
		$entry_css = [];
		foreach ( $json as $entry ) {
			if ( isset( $entry['file'] ) && substr( (string) $entry['file'], -3 ) === '.js' ) {
				if ( ! empty( $entry['isEntry'] ) ) {
					$entry_js = $dist_url . $entry['file'];
				} else {
					$chunk_js[] = $dist_url . $entry['file'];
				}
			}
			if ( isset( $entry['css'] ) && is_array( $entry['css'] ) ) {
				foreach ( $entry['css'] as $css_file ) {
					$entry_css[] = $dist_url . $css_file;
				}
			}
		}

		if ( ! $entry_js ) {
			return;
		}

		// ── Cache-bust by manifest mtime — bump tự động khi `npm run build`. ───
		$ver = (string) BIZCITY_TWINCHAT_VERSION;
		if ( file_exists( $manifest ) ) {
			$ver .= '.' . filemtime( $manifest );
		}

		// ── CSS in <head> via wp_enqueue_style ──────────────────────────────────
		foreach ( $entry_css as $i => $css_url ) {
			wp_enqueue_style(
				'bizcity-twinchat-' . $i,
				$css_url,
				[],
				$ver
			);
		}

		// ── modulepreload <link> tags in <head> ─────────────────────────────────
		foreach ( $chunk_js as $chunk_url ) {
			// Capture by value so the closure carries the correct URL.
			$url = $chunk_url;
			add_action(
				'admin_head',
				static function () use ( $url ) {
					echo '<link rel="modulepreload" href="' . esc_url( $url ) . '" />' . "\n";
				}
			);
		}

		// ── Resolve notebook & build inline config ───────────────────────────────
		$user_id = get_current_user_id();
		$nb_id   = $this->resolve_notebook_id( $user_id );
		$nb_name = '';
		$nb_list = [];
		if ( class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			$svc = BizCity_KG_Notebook_Service::instance();
			if ( $nb_id ) {
				$nb = $svc->get( $nb_id );
				if ( $nb && isset( $nb['name'] ) ) {
					$nb_name = (string) $nb['name'];
				}
			}
			foreach ( $svc->list_for_user( $user_id, [ 'limit' => 50 ] ) as $row ) {
				$nb_list[] = [ 'id' => (int) $row['id'], 'name' => (string) $row['name'] ];
			}
		}

		$config = (string) wp_json_encode( [
			'restRoot'     => esc_url_raw( rest_url( BIZCITY_TWINCHAT_REST_NS . '/' ) ),
			'kgRoot'       => esc_url_raw( rest_url( 'bizcity-knowledge/v2/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'userId'       => $user_id,
			// Fallback: if resolve_notebook_id returned 0 but we have notebooks in the list,
			// use the first one so the frontend always gets a valid notebookId.
			'notebookId'   => $nb_id ? $nb_id : ( ! empty( $nb_list ) ? $nb_list[0]['id'] : 0 ),
			'notebookName' => $nb_name,
			'notebookList' => $nb_list,
			'pluginUrl'    => BIZCITY_TWINCHAT_URL,
			// Twin Debug bridge — when ON, FE prints BE traces to console and
			// turns on its own per-stage tracing. Driven by the same gate as
			// `BizCity_Twin_Debug::is_enabled()` (constant / option / ?twin_debug=1).
			'debug'        => class_exists( 'BizCity_Twin_Debug' ) ? BizCity_Twin_Debug::is_enabled() : false,
		] );

		// ── Main entry script in footer (React needs the DOM node first) ─────────
		wp_enqueue_script(
			'bizcity-twinchat-app',
			$entry_js,
			[ 'wp-i18n' ], // wp.i18n must be on window before our bundle boots.
			$ver,
			true   // in_footer = true
		);
		// Inline config runs BEFORE the module so window.BIZCITY_TWINCHAT is ready.
		wp_add_inline_script(
			'bizcity-twinchat-app',
			'window.BIZCITY_TWINCHAT = ' . $config . ';',
			'before'
		);
		// Load translations from /languages/. JSON files generated via `wp i18n make-json`
		// are named bizcity-twin-ai-{locale}-{md5(handle)}.json.
		wp_set_script_translations(
			'bizcity-twinchat-app',
			'bizcity-twin-ai',
			BIZCITY_TWIN_AI_PATH . 'languages'
		);

		// Cache resolved data for render_page().
		$this->resolved_nb_id   = $nb_id;
		$this->resolved_nb_name = $nb_name;
		$this->resolved_nb_list = $nb_list;
		$this->bundle_built     = true;
	}

	/**
	 * Add type="module" to the TwinChat entry script tag.
	 * Vite ESM output requires this attribute.
	 *
	 * @param string $tag    Full <script ...> HTML tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script URL.
	 * @return string
	 */
	public function add_module_type( $tag, $handle, $src ) {
		if ( $handle !== 'bizcity-twinchat-app' ) {
			return $tag;
		}
		// Replace ONLY the <script src=...></script> opening for our handle, keep
		// the surrounding "before" / "after" inline scripts (window.BIZCITY_TWINCHAT, i18n)
		// that WP injects in the same $tag string.
		// Pattern: match the bare <script ...src="...bizcity-twinchat-app..." ...></script>.
		$module_tag = '<script type="module" src="' . esc_url( $src ) . '" id="bizcity-twinchat-app-js"></script>' . "\n";
		// Replace the WP-generated tag for this handle.
		$pattern = '#<script[^>]+id=["\']bizcity-twinchat-app-js["\'][^>]*></script>#i';
		if ( preg_match( $pattern, $tag ) ) {
			return preg_replace( $pattern, $module_tag, $tag );
		}
		// Fallback: simpler pattern by src match.
		$src_quoted = preg_quote( $src, '#' );
		$pattern2 = '#<script[^>]+src=["\']' . $src_quoted . '["\'][^>]*></script>#i';
		if ( preg_match( $pattern2, $tag ) ) {
			return preg_replace( $pattern2, $module_tag, $tag );
		}
		return $tag;
	}

	/**
	 * Resolve which notebook the workspace should open with.
	 *
	 * Resolution order (no manual setup required):
	 *   1. ?notebook_id=N in the URL (must be owned by the user) — lets the UI switch context.
	 *   2. User meta `bizcity_twinchat_notebook_id` (sticky last-used).
	 *   3. Most recently updated notebook owned by the user.
	 *   4. Auto-create a default "TwinChat" notebook on first run.
	 *
	 * The resolved id is persisted back to user meta so subsequent visits open the same notebook.
	 *
	 * @param int $user_id
	 * @return int Notebook id (0 if KG-Hub not loaded — caller should render a notice).
	 */
	private function resolve_notebook_id( $user_id ) {
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return 0;
		}
		$svc       = BizCity_KG_Notebook_Service::instance();
		$meta_key  = 'bizcity_twinchat_notebook_id';
		$resolved  = 0;

		// 1) URL override.
		if ( isset( $_GET['notebook_id'] ) ) {
			$candidate = (int) $_GET['notebook_id'];
			if ( $candidate > 0 ) {
				$nb = $svc->get( $candidate );
				if ( $nb && (int) $nb['owner_id'] === (int) $user_id ) {
					$resolved = $candidate;
				}
			}
		}

		// 2) Sticky user meta.
		if ( ! $resolved ) {
			$candidate = (int) get_user_meta( $user_id, $meta_key, true );
			if ( $candidate > 0 ) {
				$nb = $svc->get( $candidate );
				if ( $nb && (int) $nb['owner_id'] === (int) $user_id ) {
					$resolved = $candidate;
				}
			}
		}

		// 3) Most recently updated notebook owned by user.
		if ( ! $resolved ) {
			$list = $svc->list_for_user( $user_id, [ 'limit' => 1 ] );
			if ( ! empty( $list ) && isset( $list[0]['id'] ) ) {
				$resolved = (int) $list[0]['id'];
			}
		}

		// 4) Auto-create a default notebook on first run.
		if ( ! $resolved ) {
			$user = get_user_by( 'id', $user_id );
			$name = $user ? sprintf( '%s\'s TwinChat', $user->display_name ) : 'TwinChat Notebook';
			$nb   = $svc->create(
				[
					'name'        => $name,
					'description' => 'Auto-created on first TwinChat workspace visit.',
				],
				$user_id
			);
			if ( $nb && isset( $nb['id'] ) ) {
				$resolved = (int) $nb['id'];
			}
		}

		// Persist (sticky).
		if ( $resolved && (int) get_user_meta( $user_id, $meta_key, true ) !== $resolved ) {
			update_user_meta( $user_id, $meta_key, $resolved );
		}

		/**
		 * Filter the notebook id resolved for the TwinChat workspace.
		 *
		 * @param int $resolved
		 * @param int $user_id
		 */
		return (int) apply_filters( 'bizcity_twinchat_resolved_notebook_id', $resolved, $user_id );
	}

	public function render_page() {
		$nb_id   = $this->resolved_nb_id;
		$nb_name = $this->resolved_nb_name;
		$nb_list = $this->resolved_nb_list;

		echo '<div class="wrap">';

		if ( ! $this->bundle_built ) {
			echo '<div style="padding:24px;color:#b32d2e;">';
			echo '<strong>UI bundle not built yet.</strong><br/>';
			echo 'Run <code>cd modules/twinchat/ui &amp;&amp; npm install &amp;&amp; npm run build</code>.';
			echo '</div>';
		}

		echo '<div id="bizcity-twinchat-root"
			  data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"
			  style="height:calc(100vh - 160px);min-height:600px;border:1px solid #dcdcde;border-radius:6px;background:#fff;"></div>';
		echo '</div>';
	}

	/* ── Wave 10d.5c — Diagnostic admin page ───────────────────────────── */

	/**
	 * Render admin diagnostic page for source extraction_progress.
	 * Access: /wp-admin/admin.php?page=bizcity-twinchat-diag-sources&nb=2
	 * Optional: &fix=1 (with WP nonce) to auto-backfill origin_id by title match.
	 * Bypasses REST cookie/nonce issue — runs inside normal admin context.
	 */
	public function render_diag_sources_page() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Access denied.' );
		}

		$nb     = max( 0, (int) ( $_GET['nb'] ?? 0 ) );
		$do_fix = isset( $_GET['fix'], $_GET['_wpnonce'] )
		          && wp_verify_nonce(
		              sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ),
		              'bizcity_diag_fix_' . $nb
		          );

		echo '<div class="wrap"><h1>🔍 Diag: Source Extraction Progress</h1>';
		echo '<form method="get" style="margin-bottom:16px;">';
		echo '<input type="hidden" name="page" value="bizcity-twinchat-diag-sources" />';
		echo '<label><strong>Notebook ID:</strong> ';
		echo '<input type="number" name="nb" value="' . esc_attr( (string) $nb ) . '" min="1" style="width:80px;" /></label>';
		echo ' <button type="submit" class="button button-primary">Chạy Diagnostic</button></form>';

		if ( $nb <= 0 ) {
			echo '<p>Nhập notebook ID và nhấn "Chạy Diagnostic".</p></div>';
			return;
		}

		$out      = [ '=== DIAG notebook_id=' . $nb . ' ===' ];
		$mirror_ok = false;
		$w_rows    = [];
		$kg_rows   = [];

		// [1] webchat_sources
		$tbl_w  = $wpdb->prefix . 'bizcity_webchat_sources';
		$w_rows = (array) ( $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, embedding_status FROM {$tbl_w} WHERE project_id=%s ORDER BY id DESC LIMIT 50",
			(string) $nb
		), ARRAY_A ) ?: [] );
		$out[] = "\n[1] webchat_sources ({$tbl_w}): " . count( $w_rows ) . ' rows';
		foreach ( $w_rows as $r ) {
			$out[] = '    id=' . $r['id'] . '  emb=' . $r['embedding_status'] . '  title=' . mb_substr( (string) $r['title'], 0, 60 );
		}
		$w_ids = array_map( static fn( $r ) => (int) $r['id'], $w_rows );

		// [2] kg_sources
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			$out[] = "\n[!] BizCity_KG_Database not loaded.";
		} else {
			$kg      = BizCity_KG_Database::instance();
			$tbl_s   = $kg->tbl_sources();
			$tbl_p   = $kg->tbl_passages();
			$kg_rows = (array) ( $wpdb->get_results( $wpdb->prepare(
				"SELECT id, origin_id, origin_kind, title, passage_count FROM {$tbl_s}
				  WHERE scope_type=%s AND scope_id=%s ORDER BY id DESC LIMIT 50",
				'notebook', (string) $nb
			), ARRAY_A ) ?: [] );
			$out[] = "\n[2] kg_sources ({$tbl_s}): " . count( $kg_rows ) . ' rows';
			foreach ( $kg_rows as $r ) {
				$out[] = '    id=' . $r['id'] . '  origin_id=' . $r['origin_id'] . '  kind=' . $r['origin_kind'] . '  passages=' . $r['passage_count'] . '  title=' . mb_substr( (string) $r['title'], 0, 60 );
			}
			$kg_ids    = array_map( static fn( $r ) => (int) $r['id'], $kg_rows );
			$probe_ids = array_values( array_unique( array_merge( $w_ids, $kg_ids ) ) );

			// [3] aggregate
			if ( $probe_ids ) {
				$ph  = implode( ',', array_fill( 0, count( $probe_ids ), '%d' ) );
				$agg = (array) ( $wpdb->get_results( $wpdb->prepare(
					"SELECT source_id, COUNT(*) AS n,
						SUM(extraction_status='done') AS done_n,
						SUM(extraction_status='error') AS error_n
					   FROM {$tbl_p}
					  WHERE notebook_id=%d AND source_id IN ({$ph}) GROUP BY source_id",
					array_merge( [ $nb ], $probe_ids )
				), ARRAY_A ) ?: [] );
				$out[] = "\n[3] kg_passages aggregate (probed: " . implode( ',', $probe_ids ) . ')';
				foreach ( $agg as $a ) {
					$out[] = '    source_id=' . $a['source_id'] . '  total=' . $a['n'] . '  done=' . $a['done_n'] . '  err=' . $a['error_n'];
				}
				if ( ! $agg ) { $out[] = '    (no rows — source_ids NOT in kg_passages)'; }
			}

			// [4] ALL passages on notebook
			$any = (array) ( $wpdb->get_results( $wpdb->prepare(
				"SELECT source_id, COUNT(*) n, SUM(extraction_status='done') d FROM {$tbl_p} WHERE notebook_id=%d GROUP BY source_id ORDER BY n DESC LIMIT 20",
				$nb
			), ARRAY_A ) ?: [] );
			$out[] = "\n[4] kg_passages — ALL source_ids for nb={$nb}:";
			foreach ( $any as $a ) { $out[] = '    source_id=' . $a['source_id'] . '  total=' . $a['n'] . '  done=' . $a['d']; }
			if ( ! $any ) { $out[] = '    (none)'; }

			// [5] verdict
			$out[] = "\n=== VERDICT ===";
			foreach ( $kg_rows as $r ) {
				if ( (int) $r['origin_id'] > 0 && in_array( (int) $r['origin_id'], $w_ids, true ) ) { $mirror_ok = true; break; }
			}
			$rs_on = (bool) get_option( 'bizcity_kg_unified_read_enabled', false );
			$out[] = 'kg_sources.origin_id → webchat_sources.id : ' . ( $mirror_ok ? 'OK ✓' : 'MISSING ✗ (this is why % shows 0)' );
			$out[] = 'bizcity_kg_unified_read_enabled : ' . ( $rs_on ? 'ON (kg_sources path)' : 'OFF (webchat_sources path, Wave 10d.5c patch active)' );

			// [6] backfill if fix requested
			if ( $do_fix && ! $mirror_ok && $w_rows && $kg_rows ) {
				$updated = 0;
				foreach ( $kg_rows as $krow ) {
					if ( (int) $krow['origin_id'] > 0 ) continue;
					foreach ( $w_rows as $wr ) {
						if ( trim( (string) $krow['title'] ) === trim( (string) $wr['title'] ) ) {
							$wpdb->update( $tbl_s, [ 'origin_id' => (int) $wr['id'] ], [ 'id' => (int) $krow['id'] ] );
							$updated++;
							break;
						}
					}
				}
				$out[] = "\n[FIX] Backfilled origin_id for {$updated} kg_sources rows (matched by title).";
				$out[] = 'Reload Sources panel để xem % cập nhật.';
			}
		}

		// [5] kg_source_chunks — what the sweep cron ACTUALLY queries (DIFFERENT table!)
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$tbl_chunks = BizCity_KG_Database::instance()->tbl_source_chunks();
			$chunks_exist = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_chunks ) ) === $tbl_chunks );
			if ( $chunks_exist ) {
				$chunk_agg = (array) ( $wpdb->get_results( $wpdb->prepare(
					"SELECT source_id, extraction_status, COUNT(*) AS n
					   FROM {$tbl_chunks}
					  WHERE notebook_id = %d
					  GROUP BY source_id, extraction_status
					  ORDER BY source_id, extraction_status",
					$nb
				), ARRAY_A ) ?: [] );
				$out[] = "\n[5] kg_source_chunks ({$tbl_chunks}) — what sweep cron sees:";
				if ( $chunk_agg ) {
					$by_src = [];
					foreach ( $chunk_agg as $c ) {
						$sid = $c['source_id'] ?? 'NULL';
						$by_src[ $sid ][ $c['extraction_status'] ] = (int) $c['n'];
					}
					foreach ( $by_src as $sid => $statuses ) {
						$total   = array_sum( $statuses );
						$pending = $statuses['pending'] ?? 0;
						$done    = $statuses['done']    ?? 0;
						$error   = $statuses['error']   ?? 0;
						$flag    = $pending > 0 ? ' ← PENDING (sweep will re-enqueue!)' : '';
						$out[]   = "    source_id={$sid}  total={$total}  pending={$pending}  done={$done}  err={$error}{$flag}";
					}
				} else {
					$out[] = '    (no rows for this notebook_id — sweep has nothing to re-enqueue ✓)';
				}
				// Sweep candidate count (mirrors sweep cron query)
				$sweep_count = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(DISTINCT source_id) FROM {$tbl_chunks}
					  WHERE extraction_status = 'pending'
					    AND notebook_id = %d
					    AND source_id IS NOT NULL AND source_id > 0
					    AND created_at < UTC_TIMESTAMP() - INTERVAL 5 MINUTE",
					$nb
				) );
				$out[] = "    → Sweep candidate source_ids (pending > 5min): {$sweep_count}";
			} else {
				$out[] = "\n[5] kg_source_chunks: table does not exist (sweep cron inactive)";
			}
		}

		echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:20px;border-radius:8px;overflow:auto;max-height:620px;font-size:13px;line-height:1.6;">';
		echo esc_html( implode( "\n", $out ) );
		echo '</pre>';

		if ( ! $mirror_ok && $w_rows && $kg_rows ) {
			$fix_url = wp_nonce_url(
				admin_url( 'admin.php?page=bizcity-twinchat-diag-sources&nb=' . $nb . '&fix=1' ),
				'bizcity_diag_fix_' . $nb
			);
			echo '<p><a href="' . esc_url( $fix_url ) . '" class="button button-secondary">🔧 Auto-fix: backfill origin_id by title</a>';
			echo ' &nbsp; <em style="color:#888;font-size:12px;">Sau khi fix: reload Sources panel trong TwinChat.</em></p>';
		} elseif ( $mirror_ok ) {
			echo '<p style="color:#0a5;font-weight:600;">✓ origin_id OK — code patch đã active, reload Sources panel sẽ thấy đúng %.</p>';
		}

		echo '</div>';
	}
}
