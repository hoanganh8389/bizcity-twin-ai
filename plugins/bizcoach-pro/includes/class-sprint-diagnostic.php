<?php
/**
 * BizCoach Pro — Sprint Diagnostic.
 *
 * URL: /wp-admin/tools.php?page=bizcoach-pro-diag
 *
 * Implements PHASE-0 RULE Diagnostic-Driven Validation (R-DDV) for the
 * bizcoach-pro facade plugin (PHASE-0.36 / R-PROD-HUB / R-NO-CONFLICT).
 *
 * Sections (read-only — no schema mutation, no API calls):
 *   F.1  Plugin health        (constants, classes, REST routes)
 *   F.2  Legacy bccm_* schema (11 tables present? owned by bizcoach-map)
 *   F.3  Template registry    (active count, schema version, JSON files)
 *   F.4  Persona / Intent     (id, tool prefix, source kinds)
 *   F.5  Astro pipeline       (lib functions, API key configured, cache health)
 *   F.6  R-NO-CONFLICT        (vs legacy bizcoach-map persona)
 *
 * Mirrors BizCity_CRM_Sprint_Diagnostic singleton + tools.php menu pattern.
 *
 * @since 0.1.0 (PHASE-0.36)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Sprint_Diagnostic' ) ) { return; }

class BizCoach_Pro_Sprint_Diagnostic {

	const SLUG = 'bizcoach-pro-diag';

	private static $instance = null;
	public static function instance(): self {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register' ), 12 );
		add_action( 'admin_post_bcpro_diag_smoke',    array( $this, 'handle_smoke_post' ) );
		add_action( 'admin_post_bcpro_diag_backfill', array( $this, 'handle_backfill_post' ) );
	}

	/**
	 * Parity matrix — keyed by canonical template slug. Each entry is what we
	 * promised to port from `bizcoach-map`. Used by F.7 to surface what is
	 * missing per template, plus drives the F.8 smoke runner.
	 *
	 * legacy_fe = file under plugins/bizcoach-map/templates/ that legacy
	 *             frontend.php → bccm_render_public_map() loads. Empty string
	 *             means "no legacy FE existed" (= true parity gap).
	 */
	public static function parity_matrix(): array {
		return array(
			'career_coach' => array( 'label' => 'Career Coach',      'icon' => '💼', 'legacy_fe' => 'frontend-career.php' ),
			'biz_coach'    => array( 'label' => 'Business Coach',    'icon' => '🏢', 'legacy_fe' => 'frontend-bizcoach.php' ),
			'baby_coach'   => array( 'label' => 'Baby Coach',        'icon' => '👶', 'legacy_fe' => 'frontend-baby.php' ),
			'mental_coach' => array( 'label' => 'Mindfulness Coach', 'icon' => '🧘', 'legacy_fe' => '' ),
			'tiktok_coach' => array( 'label' => 'TikTok Coach',      'icon' => '🎬', 'legacy_fe' => 'frontend-tiktokcoach.php' ),
			'astro_coach'  => array( 'label' => 'Astro Coach',       'icon' => '🌟', 'legacy_fe' => 'frontend-astro.php' ),
			'tarot_coach'  => array( 'label' => 'Tarot Coach',       'icon' => '🔮', 'legacy_fe' => 'frontend-tarot.php' ),
			'health_coach' => array( 'label' => 'Health Coach',      'icon' => '🏃', 'legacy_fe' => 'frontend-health.php' ),
		);
	}

	public function register(): void {
		add_management_page(
			'BizCoach Pro — Sprint Console',
			'BizCoach · Sprint Console',
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }

		echo '<div class="wrap"><h1>BizCoach Pro — Sprint Diagnostic '
			. '<small style="font-weight:normal;font-size:13px;color:#666">PHASE-0.36 / R-PROD-HUB</small></h1>';
		echo '<p style="color:#555">Spec: <code>plugins/bizcity-twin-ai/PHASE-0.36-BIZCOACH-MAP-FRAMEWORK.md</code> · '
			. 'Facade over legacy <code>bccm_*</code> tables (no schema fork).</p>';

		self::render_section( 'F.1 — Plugin health',          self::compute_f1_tasks() );
		self::render_section( 'F.2 — Legacy bccm_* schema (owned by bizcoach-map)', self::compute_f2_tasks() );
		self::render_section( 'F.3 — Template registry',      self::compute_f3_tasks() );
		self::render_section( 'F.4 — Persona / Intent providers', self::compute_f4_tasks() );
		self::render_section( 'F.5 — Astro pipeline (freeastrologyapi.com)', self::compute_f5_tasks() );
		self::render_section( 'F.6 — R-NO-CONFLICT (vs legacy bizcoach-map)', self::compute_f6_tasks() );

		self::render_parity_matrix();
		self::render_section( 'F.9 — Sprint I (Intent + REST surface)', self::compute_f9_tasks() );
		self::render_section( 'F.11 — Legacy admin pages health (R-NO-CONFLICT verify)', self::compute_f11_tasks() );
		self::render_section( 'F.14 — Astro Persona Dialog (bizcoach_astro → PersonalArtifactDialog)', self::compute_f14_tasks() );
		self::render_section( 'F.15 — PHASE-0.1-ASTRO Multi-provider Gateway (bizcity-llm-router)', self::compute_f15_tasks() );
		self::render_section( 'F.16 — Cache health (object cache + bcpro/cache/invalidate)', self::compute_f16_tasks() );

		$run_live_g6 = isset( $_GET['bcpro_diag_g6'] ) && $_GET['bcpro_diag_g6'] === '1';
		self::render_g6_header( $run_live_g6 );
		self::render_section( 'G.6 — PHASE-0.2-ASTRO gateway probes (BizCoach_Pro_Astro_Client)', self::compute_g6_tasks( $run_live_g6 ) );

		self::render_legacy_adopter();
		self::render_coachee_browser();
		self::render_smoke_runner();
		self::render_progress_board();

		echo '</div>';
	}

	/* ============================================================
	 * F.13 — Progress Board (live changelog from CHANGELOG-LIVE.md)
	 *
	 * Single source of truth = plugins/bizcoach-pro/CHANGELOG-LIVE.md.
	 * Cập nhật file đó → page này tự refresh. Dùng để trace tiến độ
	 * giữa các session, không phải để chạy check pass/fail.
	 * ============================================================ */
	public static function render_progress_board(): void {
		$path = defined( 'BCPRO_DIR' ) ? BCPRO_DIR . 'CHANGELOG-LIVE.md' : '';
		echo '<h2 style="margin-top:32px;border-top:2px solid #2271b1;padding-top:16px">'
			. 'F.13 — Progress Board <small style="font-weight:normal;font-size:12px;color:#666">'
			. 'live changelog · roadmap trace</small></h2>';

		if ( ! $path || ! is_readable( $path ) ) {
			echo '<p style="color:#b32d2e">⚠ Không đọc được <code>CHANGELOG-LIVE.md</code> tại: <code>'
				. esc_html( $path ?: '(BCPRO_DIR undefined)' ) . '</code></p>';
			return;
		}

		$md   = (string) file_get_contents( $path );
		$mt   = filemtime( $path );
		$size = strlen( $md );

		echo '<p style="color:#666;font-size:12px;margin:4px 0 12px">'
			. 'Source: <code>' . esc_html( str_replace( ABSPATH, '', $path ) ) . '</code> · '
			. 'updated ' . esc_html( date_i18n( 'Y-m-d H:i', (int) $mt ) ) . ' · '
			. esc_html( number_format_i18n( $size ) ) . ' bytes · '
			. '<a href="#" onclick="document.getElementById(\'bcpro-pb-raw\').hidden=!document.getElementById(\'bcpro-pb-raw\').hidden;return false">toggle raw</a>'
			. '</p>';

		// Render: lightweight markdown → HTML (headings, lists, bold, code, links).
		$html = self::md_lite( $md );
		echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:18px 24px;line-height:1.6;font-size:13px">'
			. $html
			. '</div>';

		echo '<details id="bcpro-pb-raw" hidden style="margin-top:10px"><summary>Raw markdown</summary>'
			. '<pre style="background:#f6f7f7;padding:12px;border:1px solid #ddd;overflow:auto;max-height:400px;white-space:pre-wrap">'
			. esc_html( $md ) . '</pre></details>';
	}

	/**
	 * Tiny markdown → HTML for the progress board. Supports h1-h3, ul, hr,
	 * inline code, bold, italic, links. Intentionally minimal (no parser dep).
	 */
	private static function md_lite( string $md ): string {
		$lines = preg_split( "/\r?\n/", $md );
		$out   = array();
		$in_ul = false;
		$in_bq = false;

		$flush_ul = function() use ( &$in_ul, &$out ) {
			if ( $in_ul ) { $out[] = '</ul>'; $in_ul = false; }
		};
		$flush_bq = function() use ( &$in_bq, &$out ) {
			if ( $in_bq ) { $out[] = '</blockquote>'; $in_bq = false; }
		};

		foreach ( $lines as $ln ) {
			$raw = $ln;
			$tr  = trim( $ln );

			if ( $tr === '' ) { $flush_ul(); $flush_bq(); continue; }
			if ( preg_match( '/^---+$/', $tr ) ) { $flush_ul(); $flush_bq(); $out[] = '<hr style="border:0;border-top:1px solid #ddd;margin:14px 0">'; continue; }

			if ( preg_match( '/^### (.+)$/', $tr, $m ) ) { $flush_ul(); $flush_bq(); $out[] = '<h4 style="margin:14px 0 6px;color:#1d2327">' . self::md_inline( $m[1] ) . '</h4>'; continue; }
			if ( preg_match( '/^## (.+)$/',  $tr, $m ) ) { $flush_ul(); $flush_bq(); $out[] = '<h3 style="margin:18px 0 8px;color:#1d2327">' . self::md_inline( $m[1] ) . '</h3>'; continue; }
			if ( preg_match( '/^# (.+)$/',   $tr, $m ) ) { $flush_ul(); $flush_bq(); $out[] = '<h2 style="margin:0 0 10px;color:#1d2327">'    . self::md_inline( $m[1] ) . '</h2>'; continue; }

			if ( preg_match( '/^>\s?(.*)$/', $tr, $m ) ) {
				$flush_ul();
				if ( ! $in_bq ) { $out[] = '<blockquote style="margin:8px 0;padding:6px 12px;border-left:3px solid #2271b1;background:#f6f9fc;color:#444;font-size:12px">'; $in_bq = true; }
				$out[] = self::md_inline( $m[1] ) . '<br>';
				continue;
			}

			if ( preg_match( '/^[-*]\s+(.+)$/', $tr, $m ) ) {
				$flush_bq();
				if ( ! $in_ul ) { $out[] = '<ul style="margin:6px 0 6px 22px;padding:0">'; $in_ul = true; }
				$out[] = '<li style="margin:2px 0">' . self::md_inline( $m[1] ) . '</li>';
				continue;
			}

			$flush_ul(); $flush_bq();
			$out[] = '<p style="margin:6px 0">' . self::md_inline( $tr ) . '</p>';
		}
		$flush_ul(); $flush_bq();

		return implode( "\n", $out );
	}

	private static function md_inline( string $s ): string {
		$s = esc_html( $s );
		// inline code
		$s = preg_replace( '/`([^`]+)`/', '<code style="background:#f0f0f1;padding:1px 5px;border-radius:3px;font-size:12px">$1</code>', $s );
		// bold
		$s = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s );
		// italic (single * or _)
		$s = preg_replace( '/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $s );
		// links [text](url)
		$s = preg_replace_callback( '/\[([^\]]+)\]\(([^)]+)\)/', function( $m ) {
			$url = esc_url( $m[2] );
			return '<a href="' . $url . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
		}, $s );
		return $s;
	}

	/* ============================================================
	 * F.1 — Plugin health
	 * ============================================================ */
	public static function compute_f1_tasks(): array {
		$out = array();

		$out[] = self::row( 'T-BCPRO.F1.a',
			defined( 'BCPRO_VERSION' ) ? 'PASS' : 'FAIL',
			'Constant BCPRO_VERSION defined',
			defined( 'BCPRO_VERSION' ) ? 'v' . BCPRO_VERSION : 'undefined'
		);

		$cls_ok = class_exists( 'BizCoach_Pro_Template_Registry' )
			&& class_exists( 'BizCoach_Pro_Template_Loader' )
			&& class_exists( 'BizCoach_Pro_Persona_Provider' )
			&& class_exists( 'BizCoach_Pro_Artifact_Service' )
			&& class_exists( 'BizCoach_Pro_Rest' );
		$out[] = self::row( 'T-BCPRO.F1.b',
			$cls_ok ? 'PASS' : 'FAIL',
			'Core classes loaded (Registry/Loader/Persona/Service/Rest)',
			$cls_ok ? 'all 5 present' : 'missing one or more'
		);

		$rest_ok = false;
		if ( function_exists( 'rest_get_server' ) ) {
			$ns = rest_get_server() ? rest_get_server()->get_namespaces() : array();
			$rest_ok = in_array( 'bizcoach-pro/v1', (array) $ns, true );
		}
		$out[] = self::row( 'T-BCPRO.F1.c',
			$rest_ok ? 'PASS' : 'FAIL',
			'REST namespace bizcoach-pro/v1 registered',
			$rest_ok ? 'OK' : 'rest_get_server unavailable or NS not registered'
		);

		return $out;
	}

	/* ============================================================
	 * F.2 — Legacy bccm_* schema (READ-ONLY probe; bizcoach-map owns it)
	 * ============================================================ */
	public static function compute_f2_tasks(): array {
		$out = array();

		if ( ! class_exists( 'BizCoach_Pro_Installer' ) ) {
			$out[] = self::row( 'T-BCPRO.F2.a', 'FAIL', 'Installer class loaded', 'BizCoach_Pro_Installer missing' );
			return $out;
		}

		$probe = BizCoach_Pro_Installer::probe_legacy_schema();
		$present_count = count( array_filter( $probe['present'] ) );
		$total = count( $probe['present'] );

		$out[] = self::row( 'T-BCPRO.F2.a',
			$probe['ready'] ? 'PASS' : 'FAIL',
			'All 11 bccm_* tables present',
			"present={$present_count}/{$total}" . ( empty( $probe['missing'] ) ? '' : ' · missing: ' . implode( ', ', $probe['missing'] ) )
		);

		$bccm_active = defined( 'BCCM_VERSION' ) || class_exists( 'BCCM_Installer' );
		$out[] = self::row( 'T-BCPRO.F2.b',
			$bccm_active ? 'PASS' : 'WARN',
			'Legacy bizcoach-map plugin active (owns schema until Sprint K)',
			$bccm_active ? ( defined( 'BCCM_VERSION' ) ? 'BCCM_VERSION=' . BCCM_VERSION : 'BCCM_Installer loaded' ) : 'NOT loaded — bcpro must adopt schema'
		);

		// Critical tables for Sprint H reads (artifact + gen_results + astro)
		$critical = array( 'coachees', 'gen_results', 'astro', 'transit_snapshots' );
		$crit_ok  = true; $crit_detail = array();
		foreach ( $critical as $c ) {
			$ok = ! empty( $probe['present'][ $c ] );
			$crit_detail[] = $c . '=' . ( $ok ? '✓' : '✗' );
			if ( ! $ok ) { $crit_ok = false; }
		}
		$out[] = self::row( 'T-BCPRO.F2.c',
			$crit_ok ? 'PASS' : 'FAIL',
			'Critical tables (coachees, gen_results, astro, transit_snapshots)',
			implode( ' · ', $crit_detail )
		);

		// bcpro_db_version option marker
		$ver = get_option( 'bcpro_db_version', '0.0.0' );
		$expected = defined( 'BCPRO_DB_VERSION' ) ? BCPRO_DB_VERSION : '?';
		$out[] = self::row( 'T-BCPRO.F2.d',
			version_compare( $ver, $expected, '>=' ) ? 'PASS' : 'WARN',
			'bcpro_db_version option marker',
			"current={$ver} expected>={$expected}"
		);

		return $out;
	}

	/* ============================================================
	 * F.3 — Template Registry (JSON-backed seed)
	 * ============================================================ */
	public static function compute_f3_tasks(): array {
		$out = array();

		if ( ! class_exists( 'BizCoach_Pro_Template_Registry' ) ) {
			$out[] = self::row( 'T-BCPRO.F3.a', 'FAIL', 'Registry class loaded', 'missing' );
			return $out;
		}

		$count = method_exists( 'BizCoach_Pro_Template_Registry', 'count_active' )
			? (int) BizCoach_Pro_Template_Registry::count_active() : 0;
		$out[] = self::row( 'T-BCPRO.F3.a',
			$count >= 1 ? 'PASS' : 'WARN',
			'>= 1 active template loaded',
			"count={$count}"
		);

		$slugs = method_exists( 'BizCoach_Pro_Template_Registry', 'active_slugs' )
			? (array) BizCoach_Pro_Template_Registry::active_slugs() : array();
		$bad = array();
		foreach ( $slugs as $slug ) {
			$tpl = BizCoach_Pro_Template_Registry::get( $slug );
			if ( ! is_array( $tpl ) ) { continue; }
			$ver = isset( $tpl['schema_version'] ) ? (string) $tpl['schema_version'] : '0';
			if ( version_compare( $ver, '1.0', '>' ) ) { $bad[] = "{$slug}@{$ver}"; }
		}
		$out[] = self::row( 'T-BCPRO.F3.b',
			empty( $bad ) ? 'PASS' : 'WARN',
			'All template schema_version <= 1.0',
			empty( $bad ) ? 'OK' : 'over-versioned: ' . implode( ', ', $bad )
		);

		$dir = defined( 'BCPRO_TEMPLATE_DIR' ) ? BCPRO_TEMPLATE_DIR : '';
		$files = ( $dir && is_dir( $dir ) ) ? glob( $dir . '*.json' ) : array();
		$out[] = self::row( 'T-BCPRO.F3.c',
			( $files && count( $files ) >= 1 ) ? 'PASS' : 'WARN',
			'JSON seed files in BCPRO_TEMPLATE_DIR',
			$dir ? 'dir=' . str_replace( ABSPATH, '/', $dir ) . ' · files=' . ( $files ? count( $files ) : 0 ) : 'BCPRO_TEMPLATE_DIR undefined'
		);

		return $out;
	}

	/* ============================================================
	 * F.4 — Persona / Intent providers
	 * ============================================================ */
	public static function compute_f4_tasks(): array {
		$out = array();

		if ( ! class_exists( 'BizCoach_Pro_Persona_Provider' ) ) {
			$out[] = self::row( 'T-BCPRO.F4.a', 'FAIL', 'Persona class loaded', 'missing' );
			return $out;
		}
		$persona = new BizCoach_Pro_Persona_Provider();

		$out[] = self::row( 'T-BCPRO.F4.a',
			$persona->id() === 'bizcoach_pro' ? 'PASS' : 'FAIL',
			'Persona id === bizcoach_pro',
			'id=' . $persona->id()
		);

		$kinds = $persona->get_source_kinds();
		$out[] = self::row( 'T-BCPRO.F4.b',
			( count( $kinds ) === 1 && $kinds[0] === 'coach_map' ) ? 'PASS' : 'WARN',
			'Source kinds == [coach_map] (single canonical)',
			'kinds=[' . implode( ', ', $kinds ) . ']'
		);

		$tools = $persona->get_tool_definitions();
		$bad   = array();
		foreach ( $tools as $t ) {
			$n = isset( $t['name'] ) ? (string) $t['name'] : '';
			if ( $n !== '' && strpos( $n, 'create_coach_map_' ) !== 0 ) { $bad[] = $n; }
		}
		$out[] = self::row( 'T-BCPRO.F4.c',
			empty( $bad ) ? 'PASS' : 'FAIL',
			'All tool names start with create_coach_map_',
			'count=' . count( $tools ) . ( empty( $bad ) ? '' : ' · violators: ' . implode( ', ', $bad ) )
		);

		// Persona registered via filter
		$reg = false;
		foreach ( (array) apply_filters( 'bizcity_persona_tool_providers', array() ) as $p ) {
			if ( is_object( $p ) && method_exists( $p, 'id' ) && $p->id() === 'bizcoach_pro' ) {
				$reg = true; break;
			}
		}
		$out[] = self::row( 'T-BCPRO.F4.d',
			$reg ? 'PASS' : 'FAIL',
			'Persona registered via bizcity_persona_tool_providers filter',
			$reg ? 'OK' : 'not in filter output'
		);

		return $out;
	}

	/* ============================================================
	 * F.5 — Astro pipeline (legacy lib, freeastrologyapi.com)
	 * Confirms astro path is intact via legacy plugin (R-NO-CONFLICT.5).
	 * ============================================================ */
	public static function compute_f5_tasks(): array {
		$out = array();

		// API key configured (any of 3 sources per legacy lib/astro-api-free.php:28)
		$key_site    = (string) get_option( 'bccm_astro_api_key', '' );
		$key_network = function_exists( 'get_site_option' ) ? (string) get_site_option( 'bccm_network_astro_api_key', '' ) : '';
		$key_const   = defined( 'BCCM_ASTRO_API_KEY' ) ? (string) BCCM_ASTRO_API_KEY : '';
		$src = $key_site !== '' ? 'site_option' : ( $key_network !== '' ? 'network_option' : ( $key_const !== '' ? 'PHP_constant' : '' ) );
		$out[] = self::row( 'T-BCPRO.F5.a',
			$src !== '' ? 'PASS' : 'WARN',
			'BCCM_ASTRO_API_KEY configured (site/network/constant)',
			$src !== '' ? 'source=' . $src : 'no key — astro fetch will fail'
		);

		// Legacy lib functions present (must reach freeastrologyapi.com)
		$fns = array(
			'bccm_astro_fetch_full_chart'    => 'lib/astro-api-free.php',
			'bccm_astro_get_planets'         => 'lib/astro-api-free.php',
			'bccm_astro_get_aspects'         => 'lib/astro-api-free.php',
			'bccm_transit_prefetch_for_coachee' => 'lib/astro-transit.php',
			'bccm_save_gen_result'           => 'lib/helpers.php',
		);
		$missing = array();
		foreach ( $fns as $fn => $_src ) {
			if ( ! function_exists( $fn ) ) { $missing[] = $fn; }
		}
		$out[] = self::row( 'T-BCPRO.F5.b',
			empty( $missing ) ? 'PASS' : 'FAIL',
			'Legacy astro lib functions reachable',
			empty( $missing ) ? count( $fns ) . ' functions present' : 'missing: ' . implode( ', ', $missing )
		);

		// Cache health: count of transit snapshots (warm == astro user activity)
		global $wpdb;
		$tbl = $wpdb->prefix . 'bccm_transit_snapshots';
		$has_tbl = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl;
		$snap = $has_tbl ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" ) : 0;
		$out[] = self::row( 'T-BCPRO.F5.c',
			$has_tbl ? 'PASS' : 'WARN',
			'Transit snapshot cache table exists',
			$has_tbl ? "rows={$snap}" : 'table missing'
		);

		// Astro persona MUST be the legacy bizcoach (NOT bizcoach_pro) — guarantees no double-fetch
		$astro_owner = '';
		foreach ( (array) apply_filters( 'bizcity_persona_tool_providers', array() ) as $p ) {
			if ( ! is_object( $p ) || ! method_exists( $p, 'get_source_kinds' ) ) { continue; }
			$kinds = (array) $p->get_source_kinds();
			if ( in_array( 'astro_natal_chart', $kinds, true ) ) {
				$astro_owner = method_exists( $p, 'id' ) ? (string) $p->id() : get_class( $p );
				break;
			}
		}
		$ok = ( $astro_owner === 'bizcoach' || $astro_owner === '' ); // empty acceptable when legacy not loaded
		$out[] = self::row( 'T-BCPRO.F5.d',
			$ok ? 'PASS' : 'FAIL',
			'astro_natal_chart owned by legacy bizcoach persona (R-NO-CONFLICT.5)',
			$astro_owner !== '' ? "owner={$astro_owner}" : 'no provider claims astro_natal_chart'
		);

		return $out;
	}

	/* ============================================================
	 * F.6 — R-NO-CONFLICT (vs legacy bizcoach-map)
	 * ============================================================ */
	public static function compute_f6_tasks(): array {
		$out = array();
		$bccm_loaded = defined( 'BCCM_VERSION' ) || class_exists( 'BCCM_Installer' );

		if ( ! $bccm_loaded ) {
			$out[] = self::row( 'T-BCPRO.F6.a', 'PASS', 'Persona id disjoint',  'legacy bizcoach-map not loaded' );
			$out[] = self::row( 'T-BCPRO.F6.b', 'PASS', 'Source kinds disjoint', 'legacy bizcoach-map not loaded' );
			$out[] = self::row( 'T-BCPRO.F6.c', 'PASS', 'Tool name prefix disjoint', 'legacy bizcoach-map not loaded' );
			return $out;
		}

		// Persona id collision check
		$pro_id    = class_exists( 'BizCoach_Pro_Persona_Provider' )
			? ( new BizCoach_Pro_Persona_Provider() )->id() : '';
		$legacy_id = class_exists( 'BizCoach_Persona_Provider' )
			? ( new BizCoach_Persona_Provider() )->id() : 'bizcoach';
		$out[] = self::row( 'T-BCPRO.F6.a',
			( $pro_id !== '' && $pro_id !== $legacy_id ) ? 'PASS' : 'FAIL',
			'Persona id disjoint',
			"pro={$pro_id} legacy={$legacy_id}"
		);

		// Source kinds disjoint
		$pro_kinds    = class_exists( 'BizCoach_Pro_Persona_Provider' )
			? ( new BizCoach_Pro_Persona_Provider() )->get_source_kinds() : array();
		$legacy_kinds = class_exists( 'BizCoach_Persona_Provider' )
			? ( new BizCoach_Persona_Provider() )->get_source_kinds()
			: array( 'astro_natal_chart', 'astro_transit_report' );
		$overlap = array_intersect( $pro_kinds, $legacy_kinds );
		$out[] = self::row( 'T-BCPRO.F6.b',
			empty( $overlap ) ? 'PASS' : 'FAIL',
			'Source kinds disjoint',
			empty( $overlap ) ? 'no overlap' : 'overlap=' . implode( ',', $overlap )
		);

		// Tool name prefix
		$legacy_tools = array( 'create_natal_chart', 'create_transit_map', 'bizcoach-consult' );
		$bad = array();
		foreach ( $legacy_tools as $n ) {
			if ( strpos( $n, 'create_coach_map_' ) === 0 ) { $bad[] = $n; }
		}
		$out[] = self::row( 'T-BCPRO.F6.c',
			empty( $bad ) ? 'PASS' : 'FAIL',
			'Legacy tool names do not collide with create_coach_map_ prefix',
			empty( $bad ) ? 'OK' : 'collisions: ' . implode( ', ', $bad )
		);

		return $out;
	}

	/* ============================================================
	 * F.7 — Template Parity Matrix (port progress per template)
	 * ============================================================
	 * Per template slug we surface 6 parity columns:
	 *   1. JSON seed       — file in data/coach-templates/<slug>.v1.json
	 *   2. Registry        — slug present + status='active' in registry
	 *   3. Persona tool    — create_coach_map_<slug> in get_tool_definitions()
	 *   4. Legacy FE       — bizcoach-map/templates/frontend-*.php exists
	 *   5. Coachees count  — # rows in bccm_coachees WHERE coach_type=slug
	 *   6. Sample preview  — public URL resolvable for latest coachee
	 *
	 * The aim per user 2026-05-15: when a guru picks the bizcoach_pro
	 * provider, the dialog must list coachees and HTML previews per
	 * template. Public URL is what twinchat then ingests as MD source.
	 */
	public static function render_parity_matrix(): void {
		echo '<h2>F.7 — Template Parity Matrix '
			. '<small style="font-weight:normal;font-size:13px;color:#666">port progress per template (legacy → bizcoach-pro)</small></h2>';
		echo '<p style="color:#555;margin:6px 0 10px">Mục tiêu: mỗi template phải có public URL → twinchat add as source → KG ingest.</p>';

		$matrix = self::parity_matrix();

		$tools_by_name = array();
		if ( class_exists( 'BizCoach_Pro_Persona_Provider' ) ) {
			$persona = new BizCoach_Pro_Persona_Provider();
			foreach ( (array) $persona->get_tool_definitions() as $t ) {
				if ( ! empty( $t['name'] ) ) { $tools_by_name[ (string) $t['name'] ] = true; }
			}
		}

		$legacy_tpl_dir = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcoach-map/templates/';
		// Defensive: also try the inline mu-plugin layout if user folder differs.
		if ( ! is_dir( $legacy_tpl_dir ) && defined( 'BCCM_DIR' ) ) {
			$legacy_tpl_dir = trailingslashit( BCCM_DIR ) . 'templates/';
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Template</th>';
		echo '<th style="width:90px">JSON seed</th>';
		echo '<th style="width:90px">Registry</th>';
		echo '<th style="width:130px">Persona tool</th>';
		echo '<th style="width:120px">Legacy FE</th>';
		echo '<th style="width:80px">Coachees</th>';
		echo '<th>Sample preview (public URL)</th>';
		echo '<th style="width:140px">Smoke run</th>';
		echo '</tr></thead><tbody>';

		foreach ( $matrix as $slug => $meta ) {
			$json_path = BCPRO_TEMPLATE_DIR . $slug . '.v1.json';
			$json_ok   = file_exists( $json_path );

			$reg_ok = class_exists( 'BizCoach_Pro_Template_Registry' )
				&& BizCoach_Pro_Template_Registry::has( $slug );

			$tool_name = 'create_coach_map_' . $slug;
			$tool_ok   = isset( $tools_by_name[ $tool_name ] );

			$fe_file = (string) $meta['legacy_fe'];
			$fe_ok   = ( $fe_file !== '' && file_exists( $legacy_tpl_dir . $fe_file ) );

			$coach_count = class_exists( 'BizCoach_Pro_Artifact_Service' )
				? BizCoach_Pro_Artifact_Service::count_for_coach_type( $slug ) : 0;

			// Prefer a coachee that already has a public_key (clickable preview).
			$published = class_exists( 'BizCoach_Pro_Artifact_Service' )
				? BizCoach_Pro_Artifact_Service::latest_published_for_coach_type( $slug ) : null;
			$latest = $published ? $published
				: ( class_exists( 'BizCoach_Pro_Artifact_Service' )
					? BizCoach_Pro_Artifact_Service::latest_for_coach_type( $slug ) : null );

			$public_url = '';
			if ( $latest && class_exists( 'BizCoach_Pro_Artifact_Service' ) ) {
				$public_url = BizCoach_Pro_Artifact_Service::get_public_url( (int) $latest['id'] );
			}

			echo '<tr>';
			echo '<td><strong>' . esc_html( $meta['icon'] . ' ' . $meta['label'] ) . '</strong>'
				. '<br><code style="font-size:10px;color:#888">' . esc_html( $slug ) . '</code></td>';
			echo '<td>' . self::badge( $json_ok ? 'PASS' : 'FAIL' ) . '</td>';
			echo '<td>' . self::badge( $reg_ok ? 'PASS' : 'FAIL' ) . '</td>';
			echo '<td>' . self::badge( $tool_ok ? 'PASS' : 'FAIL' )
				. '<br><code style="font-size:10px;color:#888">' . esc_html( $tool_name ) . '</code></td>';
			if ( $fe_file === '' ) {
				echo '<td>' . self::badge( 'WARN' ) . '<br><small style="color:#a00">no legacy FE</small></td>';
			} else {
				echo '<td>' . self::badge( $fe_ok ? 'PASS' : 'FAIL' )
					. '<br><code style="font-size:10px;color:#888">' . esc_html( $fe_file ) . '</code></td>';
			}
			echo '<td><strong>' . (int) $coach_count . '</strong></td>';
			echo '<td>';
			if ( $public_url !== '' ) {
				echo '<a href="' . esc_url( $public_url ) . '" target="_blank" rel="noopener">'
					. esc_html( $public_url ) . '</a>'
					. '<br><small style="color:#666">coachee #' . (int) $latest['id']
					. ' · ' . esc_html( (string) $latest['full_name'] ) . '</small>';
			} elseif ( $latest ) {
				echo self::badge( 'WARN' )
					. ' <small style="color:#a00">latest coachee #' . (int) $latest['id']
					. ' has no public_key — generator chưa chạy xong</small>';
			} else {
				echo '<small style="color:#888">— chưa có coachee nào —</small>';
			}
			echo '</td>';
			echo '<td>';
			if ( $reg_ok && $coach_count > 0 ) {
				$nonce = wp_create_nonce( 'bcpro_diag_smoke_' . $slug );
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0">';
				echo '<input type="hidden" name="action" value="bcpro_diag_smoke">';
				echo '<input type="hidden" name="slug"   value="' . esc_attr( $slug ) . '">';
				echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
				echo '<button class="button button-small" type="submit">▶ Run smoke</button>';
				echo '</form>';
			} else {
				echo '<small style="color:#888">—</small>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Aggregate summary row.
		$total = count( $matrix );
		$ok_json = $ok_reg = $ok_tool = $ok_fe = $ok_url = 0;
		foreach ( $matrix as $slug => $meta ) {
			if ( file_exists( BCPRO_TEMPLATE_DIR . $slug . '.v1.json' ) ) { $ok_json++; }
			if ( class_exists( 'BizCoach_Pro_Template_Registry' ) && BizCoach_Pro_Template_Registry::has( $slug ) ) { $ok_reg++; }
			if ( isset( $tools_by_name[ 'create_coach_map_' . $slug ] ) ) { $ok_tool++; }
			if ( $meta['legacy_fe'] !== '' && file_exists( $legacy_tpl_dir . $meta['legacy_fe'] ) ) { $ok_fe++; }
			$lt = class_exists( 'BizCoach_Pro_Artifact_Service' )
				? BizCoach_Pro_Artifact_Service::latest_published_for_coach_type( $slug ) : null;
			if ( $lt && class_exists( 'BizCoach_Pro_Artifact_Service' )
				&& BizCoach_Pro_Artifact_Service::get_public_url( (int) $lt['id'] ) !== '' ) { $ok_url++; }
		}
		printf(
			'<p style="margin-top:8px"><strong>Parity score:</strong> '
			. 'JSON %d/%d · Registry %d/%d · Tool %d/%d · Legacy FE %d/%d · Public URL %d/%d</p>',
			$ok_json, $total, $ok_reg, $total, $ok_tool, $total, $ok_fe, $total, $ok_url, $total
		);
	}

	/* ============================================================
	 * F.9 — Sprint I (Intent Provider + REST surface)
	 * ============================================================ */
	public static function compute_f9_tasks(): array {
		$out = array();

		$intent_loaded = class_exists( 'BizCoach_Pro_Intent_Provider' );
		$out[] = self::row( 'T-BCPRO.F9.a',
			$intent_loaded ? 'PASS' : 'FAIL',
			'Intent Provider class loaded',
			$intent_loaded ? 'BizCoach_Pro_Intent_Provider OK' : 'missing'
		);

		$pat_count = 0; $tpl_count = 0;
		if ( $intent_loaded ) {
			$intent = new BizCoach_Pro_Intent_Provider();
			$pat_count = count( (array) $intent->get_goal_patterns() );
			$tpl_count = class_exists( 'BizCoach_Pro_Template_Registry' )
				? (int) BizCoach_Pro_Template_Registry::count_active() : 0;
		}
		$out[] = self::row( 'T-BCPRO.F9.b',
			( $pat_count >= $tpl_count && $tpl_count > 0 ) ? 'PASS' : 'WARN',
			'Goal patterns >= active templates',
			"patterns={$pat_count} · templates={$tpl_count}"
		);

		$tool_count = 0;
		if ( $intent_loaded ) {
			$intent = new BizCoach_Pro_Intent_Provider();
			foreach ( (array) $intent->get_tools() as $name => $cfg ) {
				if ( strpos( (string) $name, 'create_coach_map_' ) === 0 ) { $tool_count++; }
			}
		}
		$out[] = self::row( 'T-BCPRO.F9.c',
			$tool_count === $tpl_count ? 'PASS' : 'WARN',
			'Intent tool count == template count',
			"intent_tools={$tool_count} · templates={$tpl_count}"
		);

		$expected = array(
			'/bizcoach-pro/v1/templates',
			'/bizcoach-pro/v1/coach-maps',
			'/bizcoach-pro/v1/coach-map',
		);
		$present = array(); $missing = array();
		if ( function_exists( 'rest_get_server' ) && rest_get_server() ) {
			$routes = (array) rest_get_server()->get_routes();
			foreach ( $expected as $path ) {
				if ( isset( $routes[ $path ] ) ) { $present[] = $path; }
				else { $missing[] = $path; }
			}
		}
		$out[] = self::row( 'T-BCPRO.F9.d',
			empty( $missing ) ? 'PASS' : 'FAIL',
			'REST routes registered (Sprint I surface)',
			'present=' . count( $present ) . '/' . count( $expected )
				. ( empty( $missing ) ? '' : ' · missing: ' . implode( ', ', $missing ) )
		);

		return $out;
	}

	/* ============================================================
	 * F.11 — Legacy admin pages health
	 * ============================================================
	 * Verify that the 3 legacy admin pages user pinned 2026-05-15 still
	 * register and their handler files load. R-NO-CONFLICT: bizcoach-pro
	 * MUST NOT shadow these (legacy bizcoach-map owns the menu items).
	 */
	public static function compute_f11_tasks(): array {
		global $menu, $submenu;
		$out = array();

		// Use shadow dir when adopter is active (legacy plugin moved/deleted).
		$adopting = class_exists( 'BizCoach_Pro_Legacy_Adopter' )
			&& get_option( 'bcpro_adopt_legacy_pages' )
			&& ! file_exists( WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcoach-map/bizcoach-map.php' );
		$base_dir = $adopting
			? BizCoach_Pro_Legacy_Adopter::shadow_dir() . 'includes/'
			: WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcoach-map/includes/';

		$pages = array(
			'bccm_user_profiles'        => array(
				'label' => 'Khách hàng (admin)',
				'file'  => $base_dir . 'admin-user-profiles.php',
			),
			'bccm_my_profile'           => array(
				'label' => 'Bước 1 — My AI Profile (self)',
				'file'  => $base_dir . 'admin-self-profile.php',
			),
			'bccm_step2_coach_template' => array(
				'label' => 'Bước 2 — Coach Template wizard',
				'file'  => $base_dir . 'admin-step2-coach-template.php',
			),
			'bccm_step3_character'      => array(
				'label' => 'Bước 3 — Tạo Character (AI Trợ lý)',
				'file'  => $base_dir . 'admin-step3-character.php',
			),
			'bccm_step4_success_plan'   => array(
				'label' => 'Bước 4 — Success Plan',
				'file'  => $base_dir . 'admin-step4-success-plan.php',
			),
		);

		// Build a flat slug index of all registered admin pages (top + sub).
		$registered = array();
		if ( is_array( $menu ) ) {
			foreach ( $menu as $m ) {
				if ( ! empty( $m[2] ) ) { $registered[ (string) $m[2] ] = true; }
			}
		}
		if ( is_array( $submenu ) ) {
			foreach ( $submenu as $items ) {
				foreach ( (array) $items as $s ) {
					if ( ! empty( $s[2] ) ) { $registered[ (string) $s[2] ] = true; }
				}
			}
		}

		$i = 0;
		foreach ( $pages as $slug => $meta ) {
			$i++;
			$file_ok = file_exists( $meta['file'] );
			$reg_ok  = isset( $registered[ $slug ] );
			$status  = ( $file_ok && $reg_ok ) ? 'PASS' : ( $file_ok ? 'WARN' : 'FAIL' );
			$ev = ( $file_ok ? 'file ✓' : 'file MISSING' )
				. ' · ' . ( $reg_ok ? 'menu ✓' : 'menu NOT registered' )
				. ' · <a href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '" target="_blank">open ↗</a>';
			$out[] = self::row( 'T-BCPRO.F11.' . chr( 96 + $i ), $status,
				$meta['label'] . ' (<code>' . $slug . '</code>)', $ev );
		}

		// R-NO-CONFLICT.6: bizcoach-pro must not register any bccm_* hooks.
		$bcpro_hooks_ok = true;
		$dir = plugin_dir_path( __FILE__ );
		foreach ( (array) glob( $dir . 'class-*.php' ) as $f ) {
			$src = (string) file_get_contents( $f );
			if ( preg_match( '/[\'"](?:admin_post|wp_ajax)_bccm_/', $src ) ) {
				$bcpro_hooks_ok = false; break;
			}
		}
		$out[] = self::row( 'T-BCPRO.F11.f',
			$bcpro_hooks_ok ? 'PASS' : 'FAIL',
			'bizcoach-pro does not register any bccm_* admin/ajax hooks',
			$bcpro_hooks_ok ? 'no shadowing detected' : 'found bccm_* hook in bcpro source — review!'
		);

		return $out;
	}

	/* ============================================================
	 * F.12 — Legacy Adopter (Sprint K, simplified static port).
	 * ============================================================
	 * Just status. No buttons. Shadow files committed under bcpro/legacy/.
	 * Adopter auto-no-ops when bizcoach-map plugin is active.
	 */
	public static function render_legacy_adopter() {
		echo '<h2>F.12 — Legacy Adopter '
			. '<small style="font-weight:normal;font-size:13px;color:#666">Sprint K · static port của bizcoach-map UI</small></h2>';

		if ( ! class_exists( 'BizCoach_Pro_Legacy_Adopter' ) ) {
			echo '<div class="notice notice-error inline"><p>Adopter class chưa load.</p></div>';
			return;
		}
		$st = BizCoach_Pro_Legacy_Adopter::status();
		$mode_label = array(
			'legacy_active'  => array( 'Legacy plugin active — adopter dormant (correct)', 'PASS' ),
			'adopted'        => array( 'ADOPTED — bizcoach-pro đang serve UI từ legacy/ snapshot', 'PASS' ),
			'pending'        => array( 'Pending — shadow ready, will adopt when bizcoach-map deactivates', 'WARN' ),
			'missing_shadow' => array( 'Shadow missing — re-deploy bcpro/legacy/ folder', 'FAIL' ),
		);
		$row = isset( $mode_label[ $st['mode'] ] ) ? $mode_label[ $st['mode'] ] : array( $st['mode'], 'WARN' );

		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		echo '<tr><th style="width:170px">Mode</th><td>' . self::badge( $row[1] ) . ' ' . esc_html( $row[0] ) . '</td></tr>';
		echo '<tr><th>Legacy plugin active</th><td>' . ( $st['legacy_active'] ? '✅ BCCM_VERSION (real)' : '❌ inactive' ) . '</td></tr>';
		echo '<tr><th>Shadow files</th><td>' . ( $st['shadow_ok'] ? '✅ <code>' . esc_html( BizCoach_Pro_Legacy_Adopter::shadow_dir() ) . '</code>' : '❌ missing — check deploy of bcpro/legacy/' ) . '</td></tr>';
		echo '<tr><th>Loaded by adopter</th><td>' . ( $st['loaded'] ? '✅ <code>BCCM_VERSION = 0.0.0-adopted</code>' : '— (legacy plugin handles it)' ) . '</td></tr>';

		// Diagnostic trace — what happened inside boot()?
		$trace = isset( BizCoach_Pro_Legacy_Adopter::$boot_trace ) ? BizCoach_Pro_Legacy_Adopter::$boot_trace : null;
		if ( is_array( $trace ) ) {
			echo '<tr><th>boot() trace</th><td>';
			if ( empty( $trace ) ) {
				echo '<strong style="color:#a00">⚠ boot() NEVER CALLED</strong> — opcache caching old class file. Reset PHP opcache.';
			} else {
				echo '<small><code>' . esc_html( implode( ' → ', $trace ) ) . '</code></small>';
			}
			echo '</td></tr>';
		} else {
			echo '<tr><th>boot() trace</th><td><strong style="color:#a00">⚠ $boot_trace property missing</strong> — class-legacy-adopter.php on disk is OLD version, opcache stale or not deployed.</td></tr>';
		}

		// Show whether 5 expected legacy menus actually got registered.
		global $menu, $submenu;
		$expected = array(
			'bccm_user_profiles'        => 'Khách hàng',
			'bccm_my_profile'           => 'Bước 1 — My Profile',
			'bccm_step2_coach_template' => 'Bước 2 — Coach Template',
			'bccm_step3_character'      => 'Bước 3 — Character',
			'bccm_step4_success_plan'   => 'Bước 4 — Success Plan',
		);
		$found = array();
		if ( is_array( $submenu ) ) {
			foreach ( $submenu as $parent => $items ) {
				foreach ( $items as $it ) {
					$slug = isset( $it[2] ) ? $it[2] : '';
					if ( isset( $expected[ $slug ] ) ) { $found[ $slug ] = $parent; }
				}
			}
		}
		if ( is_array( $menu ) ) {
			foreach ( $menu as $m ) {
				$slug = isset( $m[2] ) ? $m[2] : '';
				if ( isset( $expected[ $slug ] ) ) { $found[ $slug ] = '(top-level)'; }
			}
		}
		echo '<tr><th>Legacy menus</th><td>';
		foreach ( $expected as $slug => $label ) {
			$ok = isset( $found[ $slug ] );
			echo ( $ok ? '✅ ' : '❌ ' ) . esc_html( $label ) . '<br>';
		}
		echo '</td></tr>';
		echo '</tbody></table>';

		// Action buttons.
		echo '<p style="margin-top:10px">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:10px">';
		echo '<input type="hidden" name="action" value="bcpro_legacy_force_flush">';
		wp_nonce_field( 'bcpro_legacy_force_flush' );
		echo '<button class="button button-primary" type="submit">🔄 Flush rewrite rules</button>';
		echo '</form>';
		echo '<small style="color:#666">Dùng nếu <code>/coachee-map/{key}/</code> bị 404/500 sau khi deactivate bizcoach-map.</small>';
		echo '</p>';

		echo '<p style="color:#555;max-width:900px;margin-top:10px"><strong>Cách dùng:</strong> '
			. 'Khi muốn nghỉ <code>bizcoach-map</code> → vào WP Admin → Plugins → Deactivate. '
			. 'bizcoach-pro tự động require <code>legacy/</code> snapshot, 5 menu cũ vẫn render bình thường. '
			. 'Reactivate bất kỳ lúc nào → adopter tự nhường (BCCM_VERSION wins).</p>';
	}

	/* ============================================================
	 * F.8 — End-to-end Smoke Runner
	 * ============================================================
	 * Renders the result of the most recent admin-post smoke run (if any)
	 * stashed in a transient, plus a "run all" form. Each smoke run:
	 *   1) loads template from registry
	 *   2) finds latest coachee for coach_type
	 *   3) calls Artifact_Service::get_artifact()
	 *   4) calls Persona_Provider::render_to_passages('coach_map', ['id'=>X])
	 *   5) resolves public URL
	 * Outputs PASS/FAIL per step + first passage preview (200 chars).
	 */
	public static function render_coachee_browser(): void {
		echo '<h2>F.10 — Coachee Browser '
			. '<small style="font-weight:normal;font-size:13px;color:#666">top 5 latest per template · click public URL → twinchat add as source</small></h2>';
		echo '<p style="color:#555;margin:6px 0 10px">Đây là bản đồ user xin: chọn provider <code>bizcoach_pro</code> → trả ra danh sách coachee per template + bản preview HTML public.</p>';

		// Flash message from backfill action.
		$msg = get_transient( 'bcpro_diag_backfill_msg' );
		if ( is_array( $msg ) && ! empty( $msg['text'] ) ) {
			delete_transient( 'bcpro_diag_backfill_msg' );
			$cls = $msg['type'] === 'error' ? 'notice-error'
				: ( $msg['type'] === 'warning' ? 'notice-warning' : 'notice-success' );
			echo '<div class="notice ' . esc_attr( $cls ) . ' inline"><p>' . esc_html( $msg['text'] ) . '</p></div>';
		}

		// Bulk backfill all.
		$nonce_all = wp_create_nonce( 'bcpro_diag_backfill_all' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" '
			. 'style="margin:6px 0 12px" onsubmit="return confirm(\'Backfill public URLs cho TẤT CẢ coachee thiếu key?\');">';
		echo '<input type="hidden" name="action" value="bcpro_diag_backfill">';
		echo '<input type="hidden" name="slug"   value="all">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce_all ) . '">';
		echo '<button class="button button-primary" type="submit">▶ Backfill ALL missing public URLs</button>';
		echo ' <small style="color:#666">→ gọi <code>bccm_ensure_action_plan()</code>; cần thiết để twinchat ingest</small>';
		echo '</form>';

		if ( ! class_exists( 'BizCoach_Pro_Artifact_Service' ) ) {
			echo '<div class="notice notice-error inline"><p>Artifact_Service chưa load.</p></div>';
			return;
		}

		$matrix = self::parity_matrix();
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:170px">Template</th>';
		echo '<th>Coachees (latest 5)</th>';
		echo '</tr></thead><tbody>';

		foreach ( $matrix as $slug => $meta ) {
			$rows = BizCoach_Pro_Artifact_Service::list_for_coach_type( $slug, 5 );
			echo '<tr><td><strong>' . esc_html( $meta['icon'] . ' ' . $meta['label'] ) . '</strong>'
				. '<br><code style="font-size:10px;color:#888">' . esc_html( $slug ) . '</code></td><td>';
			if ( empty( $rows ) ) {
				echo '<small style="color:#888">— chưa có coachee nào —</small>';
			} else {
				echo '<ol style="margin:0 0 0 18px;padding:0">';
				foreach ( $rows as $r ) {
					$id   = (int) $r['id'];
					$name = (string) ( $r['full_name'] ?? '' );
					$dob  = (string) ( $r['dob'] ?? '' );
					$url  = (string) ( $r['public_url'] ?? '' );
					echo '<li style="margin:2px 0">';
					echo '<strong>#' . $id . '</strong> · ' . esc_html( $name !== '' ? $name : '(no name)' );
					if ( $dob !== '' && $dob !== '0000-00-00' ) {
						echo ' <small style="color:#888">· ' . esc_html( $dob ) . '</small>';
					}
					if ( $url !== '' ) {
						echo ' → <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
							. esc_html( $url ) . '</a>';
					} else {
						echo ' ' . self::badge( 'WARN' )
							. ' <small style="color:#a00">no public_key</small>';
					}

					// Artifact extras: rest_passages + admin_user + (astro: natal/transit/pdf).
					$extras = BizCoach_Pro_Artifact_Service::get_artifact_urls( $id, $slug );
					unset( $extras['public_map'] ); // already shown above
					if ( ! empty( $extras ) ) {
						$labels = array(
							'rest_passages'      => '📄 passages',
							'admin_user'         => '👤 admin user',
							'natal_share'        => '🌟 natal share',
							'natal_full_western' => 'natal western',
							'natal_full_vedic'   => 'natal vedic',
							'transit_week'       => 'transit week',
							'transit_month'      => 'transit month',
							'transit_year'       => 'transit year',
							'natal_pdf'          => '📑 natal pdf',
							'prokerala_pdf'      => '📑 prokerala pdf',
						);
						echo '<div style="margin:3px 0 6px 18px;font-size:11px;line-height:1.7;color:#666">';
						$first = true;
						foreach ( $labels as $k => $lab ) {
							if ( empty( $extras[ $k ] ) ) { continue; }
							if ( ! $first ) { echo ' · '; }
							echo '<a href="' . esc_url( $extras[ $k ] ) . '" target="_blank" rel="noopener">'
								. esc_html( $lab ) . '</a>';
							$first = false;
						}
						echo '</div>';
					}

					echo '</li>';
				}
				echo '</ol>';
				$rest_url = rest_url( 'bizcoach-pro/v1/coach-maps' )
					. '?coach_type=' . rawurlencode( $slug ) . '&limit=20';
				echo '<p style="margin:6px 0 0"><small style="color:#666">REST: <a href="'
					. esc_url( $rest_url ) . '" target="_blank" rel="noopener"><code>'
					. esc_html( $rest_url ) . '</code></a></small></p>';

				// Per-template backfill button.
				$missing = 0;
				foreach ( $rows as $r ) { if ( empty( $r['public_url'] ) ) { $missing++; } }
				if ( $missing > 0 ) {
					$nonce = wp_create_nonce( 'bcpro_diag_backfill_' . $slug );
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:6px 0 0">';
					echo '<input type="hidden" name="action" value="bcpro_diag_backfill">';
					echo '<input type="hidden" name="slug"   value="' . esc_attr( $slug ) . '">';
					echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
					echo '<button class="button button-small" type="submit">▶ Backfill ' . (int) $missing . ' missing key</button>';
					echo '</form>';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function render_smoke_runner(): void {
		echo '<h2>F.8 — End-to-end Smoke Runner '
			. '<small style="font-weight:normal;font-size:13px;color:#666">Persona → Artifact → Passages → Public URL</small></h2>';

		$user_id = get_current_user_id();
		$key     = 'bcpro_diag_smoke_result_' . $user_id;
		$result  = get_transient( $key );

		if ( ! is_array( $result ) ) {
			echo '<p style="color:#666">Bấm <em>▶ Run smoke</em> ở cột phải bảng F.7 để chạy thử end-to-end cho 1 template.</p>';
			return;
		}
		delete_transient( $key );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:140px">Step</th><th style="width:80px">Status</th><th>Detail</th>';
		echo '</tr></thead><tbody>';
		foreach ( (array) $result['steps'] as $step ) {
			echo '<tr><td><code>' . esc_html( $step['id'] ) . '</code></td>';
			echo '<td>' . self::badge( (string) $step['status'] ) . '</td>';
			echo '<td><pre style="white-space:pre-wrap;margin:0;font-size:11px;color:#333">'
				. esc_html( (string) $step['detail'] ) . '</pre></td></tr>';
		}
		echo '</tbody></table>';

		if ( ! empty( $result['preview'] ) ) {
			echo '<h3 style="margin-top:14px">Passage preview (first ~400 chars)</h3>';
			echo '<pre style="background:#f6f7f7;border:1px solid #ddd;padding:10px;white-space:pre-wrap;font-size:12px">'
				. esc_html( (string) $result['preview'] ) . '</pre>';
		}
		if ( ! empty( $result['public_url'] ) ) {
			echo '<p><strong>Public URL:</strong> <a href="' . esc_url( $result['public_url'] )
				. '" target="_blank" rel="noopener">' . esc_html( $result['public_url'] ) . '</a></p>';
		}
	}

	/**
	 * Admin-post handler — backfill missing public_keys for all coachees of
	 * a given template slug (or 'all'). Reuses legacy `bccm_ensure_action_plan()`
	 * so we honor the same UUID format the legacy frontend.php expects.
	 *
	 * Why: most coachees in F.10 show "no public_key" because legacy generator
	 * only writes the plan row for biz/career flows. Twinchat ingest needs the
	 * URL — backfilling unblocks the KG training pipeline immediately without
	 * waiting for full Sprint J template builder.
	 */
	public function handle_backfill_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		check_admin_referer( 'bcpro_diag_backfill_' . $slug );

		// Allow caller (e.g. bccm_user_profiles admin page) to redirect back to
		// itself instead of the diagnostic console — keeps operator in context.
		$redirect_to = isset( $_POST['_redirect_to'] ) ? sanitize_key( wp_unslash( $_POST['_redirect_to'] ) ) : '';
		$redirect_url = ( $redirect_to === 'bccm_user_profiles' )
			? admin_url( 'admin.php?page=bccm_user_profiles' )
			: admin_url( 'tools.php?page=' . self::SLUG );

		if ( ! function_exists( 'bccm_ensure_action_plan' ) ) {
			set_transient( 'bcpro_diag_backfill_msg',
				array( 'type' => 'error', 'text' => 'Legacy helper bccm_ensure_action_plan() not loaded.' ),
				60 );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		global $wpdb;
		$where = '';
		$args  = array();
		if ( $slug !== '' && $slug !== 'all' ) {
			$where = ' WHERE coach_type = %s';
			$args[] = $slug;
		}
		$sql  = "SELECT id FROM {$wpdb->prefix}bccm_coachees{$where} ORDER BY id DESC LIMIT 200";
		$ids  = $args
			? $wpdb->get_col( $wpdb->prepare( $sql, $args ) )
			: $wpdb->get_col( $sql );

		$ok = 0; $skip = 0; $err = 0;
		foreach ( (array) $ids as $cid ) {
			$cid = (int) $cid;
			// Skip if already has key.
			$has = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT public_key FROM {$wpdb->prefix}bccm_action_plans
				 WHERE coachee_id=%d AND status='active' AND public_key<>'' LIMIT 1",
				$cid
			) );
			if ( $has !== '' ) { $skip++; continue; }
			try {
				$key = bccm_ensure_action_plan( $cid );
				if ( $key ) { $ok++; } else { $err++; }
			} catch ( \Throwable $e ) { $err++; }
		}

		set_transient( 'bcpro_diag_backfill_msg', array(
			'type' => $err ? 'warning' : 'success',
			'text' => sprintf(
				'Backfill (%s): %d created · %d already had key · %d errors · %d total scanned.',
				$slug !== '' ? $slug : 'all', $ok, $skip, $err, count( (array) $ids )
			),
		), 60 );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Admin-post handler — runs the smoke pipeline for a given template slug.
	 * Stashes the result in a transient then redirects back to the diag page.
	 */
	public function handle_smoke_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }
		$slug  = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$nonce = isset( $_POST['_wpnonce'] ) ? (string) $_POST['_wpnonce'] : '';
		if ( ! wp_verify_nonce( $nonce, 'bcpro_diag_smoke_' . $slug ) ) { wp_die( 'bad nonce' ); }

		$steps   = array();
		$preview = '';
		$public_url = '';

		// Step 1 — template in registry
		$tpl = class_exists( 'BizCoach_Pro_Template_Registry' )
			? BizCoach_Pro_Template_Registry::get( $slug ) : null;
		$steps[] = array(
			'id'     => 'S1.template',
			'status' => $tpl ? 'PASS' : 'FAIL',
			'detail' => $tpl ? "slug={$slug} · status=" . ( $tpl['status'] ?? '?' ) . " · gens=" . count( (array) ( $tpl['generators'] ?? array() ) )
			                 : "Template not found in registry: {$slug}",
		);

		// Step 2 — latest coachee for this coach_type
		$latest = class_exists( 'BizCoach_Pro_Artifact_Service' )
			? BizCoach_Pro_Artifact_Service::latest_for_coach_type( $slug ) : null;
		$steps[] = array(
			'id'     => 'S2.latest_coachee',
			'status' => $latest ? 'PASS' : 'WARN',
			'detail' => $latest ? "coachee_id=" . (int) $latest['id'] . " · name=" . (string) $latest['full_name']
			                    : 'No coachee row exists yet for coach_type=' . $slug,
		);

		// Step 3 — get_artifact
		$artifact = ( $latest && class_exists( 'BizCoach_Pro_Artifact_Service' ) )
			? BizCoach_Pro_Artifact_Service::get_artifact( (int) $latest['id'] ) : null;
		$steps[] = array(
			'id'     => 'S3.get_artifact',
			'status' => $artifact ? 'PASS' : 'FAIL',
			'detail' => $artifact
				? 'gens_loaded=' . count( (array) $artifact['gens'] ) . ' · title=' . (string) $artifact['title']
				: 'Artifact_Service::get_artifact() returned null',
		);

		// Step 4 — render_to_passages
		$passages = array();
		if ( $artifact && class_exists( 'BizCoach_Pro_Persona_Provider' ) ) {
			$persona  = new BizCoach_Pro_Persona_Provider();
			$passages = $persona->render_to_passages( 'coach_map', array( 'id' => (int) $latest['id'] ) );
		}
		$steps[] = array(
			'id'     => 'S4.render_passages',
			'status' => ! empty( $passages ) ? 'PASS' : 'WARN',
			'detail' => 'passage_count=' . count( $passages )
				. ( empty( $passages ) ? ' — no successful generator results yet' : '' ),
		);
		if ( ! empty( $passages[0]['content'] ) ) {
			$preview = '[' . (string) ( $passages[0]['title'] ?? '' ) . "]\n"
				. mb_substr( (string) $passages[0]['content'], 0, 400 );
		}

		// Step 5 — public URL resolvable
		if ( $latest ) {
			$public_url = class_exists( 'BizCoach_Pro_Artifact_Service' )
				? BizCoach_Pro_Artifact_Service::get_public_url( (int) $latest['id'] ) : '';
		}
		$steps[] = array(
			'id'     => 'S5.public_url',
			'status' => $public_url !== '' ? 'PASS' : 'FAIL',
			'detail' => $public_url !== '' ? $public_url
				: 'No bccm_action_plans.public_key found — twinchat KG ingest sẽ thiếu URL nguồn.',
		);

		set_transient( 'bcpro_diag_smoke_result_' . get_current_user_id(), array(
			'slug'       => $slug,
			'steps'      => $steps,
			'preview'    => $preview,
			'public_url' => $public_url,
			'ts'         => time(),
		), 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG ) . '#smoke' );
		exit;
	}

	/* ============================================================
	 * Render helpers (mirror Phase_CD pattern)
	 * ============================================================ */
	/* ============================================================
	 * F.14 — Astro Persona Dialog (Sprint K, 2026-05-15)
	 *
	 * Diagnoses the click-path: chip "Tạo bản đồ sao" →
	 * `<PersonalArtifactDialog>` → GET /wp-json/bizcity-bizcoach/v1/persona/
	 * profiles → list of `bccm_coachees` for the current user.
	 *
	 * Surfaces every common failure mode in one place so you don't have
	 * to grep the FE console + wp-json index + DB by hand.
	 * ============================================================ */
	public static function compute_f14_tasks(): array {
		global $wpdb, $wp_filter;
		$out = array();

		// a) Provider class loaded + registered via filter at id `bizcoach_astro`.
		$has_class = class_exists( 'BizCoach_Pro_Astro_Provider' );
		$reg_filter = false;
		foreach ( (array) apply_filters( 'bizcity_persona_tool_providers', array() ) as $p ) {
			if ( is_object( $p ) && method_exists( $p, 'id' ) && $p->id() === 'bizcoach_astro' ) {
				$reg_filter = true; break;
			}
		}
		$out[] = self::row( 'T-BCPRO.F14.a',
			( $has_class && $reg_filter ) ? 'PASS' : 'FAIL',
			'BizCoach_Pro_Astro_Provider loaded + registered (id=bizcoach_astro)',
			'class=' . ( $has_class ? 'yes' : 'NO' ) . ' filter=' . ( $reg_filter ? 'yes' : 'NO' )
		);

		// b) REST class loaded.
		$has_rest = class_exists( 'BizCoach_Pro_Astro_Rest' );
		// b2) Bytecode freshness probe — file has `BizCoach_Pro_Astro_Rest::init();`
		// at the very end. If class is loaded but init() never attached the action
		// (see F14.c), the most likely cause is opcache serving stale bytecode of
		// THIS file from before the init() call existed. Surface that explicitly.
		$rest_file = defined( 'BCPRO_DIR' ) ? BCPRO_DIR . 'includes/astro/class-astro-rest.php' : '';
		$disk_has_init = $rest_file && is_readable( $rest_file )
			? ( strpos( (string) file_get_contents( $rest_file ), 'BizCoach_Pro_Astro_Rest::init();' ) !== false )
			: false;
		$opcache_status = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : null;
		$opcache_on = is_array( $opcache_status ) && ! empty( $opcache_status['opcache_enabled'] );
		$out[] = self::row( 'T-BCPRO.F14.b',
			$has_rest ? 'PASS' : 'FAIL',
			'BizCoach_Pro_Astro_Rest class loaded',
			( $has_rest ? 'yes' : 'NO — includes/astro/class-astro-rest.php not required' )
				. ' · disk_file_calls_init=' . ( $disk_has_init ? 'yes' : 'NO' )
				. ' · opcache=' . ( $opcache_on ? 'on' : 'off' )
				. ( $rest_file ? ' · mtime=' . ( @filemtime( $rest_file ) ? date( 'Y-m-d H:i:s', filemtime( $rest_file ) ) : '?' ) : '' )
		);

		// c) REST routes actually registered with the WP REST server.
		//    rest_get_server() forces lazy init so this works even on admin page.
		$server  = function_exists( 'rest_get_server' ) ? rest_get_server() : null;
		$routes  = $server ? $server->get_routes() : array();
		$want    = array( '/bizcity-bizcoach/v1/persona/profiles', '/bizcity-bizcoach/v1/persona/ingest' );
		$missing = array();
		foreach ( $want as $r ) { if ( ! isset( $routes[ $r ] ) ) { $missing[] = $r; } }

		// Self-heal: if the on-disk file calls init() at end (see F14.b2) but the
		// action wasn't attached, opcache is almost certainly serving a stale copy.
		// Force-call init() + register_routes() now so the rest of THIS request
		// (and the live dispatch in F14.g below) sees the routes — proves the
		// diagnosis without requiring an opcache reset to test.
		$forced = false;
		if ( ! empty( $missing ) && $has_rest && method_exists( 'BizCoach_Pro_Astro_Rest', 'register_routes' ) ) {
			BizCoach_Pro_Astro_Rest::register_routes();
			// Re-poll routes after force.
			$routes = $server ? $server->get_routes() : array();
			$still_missing = array();
			foreach ( $want as $r ) { if ( ! isset( $routes[ $r ] ) ) { $still_missing[] = $r; } }
			$forced = empty( $still_missing );
			$missing = $still_missing;
		}

		// Diagnose WHY routes are missing when class IS loaded.
		$action_attached = false;
		if ( isset( $wp_filter['rest_api_init'] ) ) {
			foreach ( $wp_filter['rest_api_init']->callbacks as $prio => $cbs ) {
				foreach ( $cbs as $cb ) {
					$fn = isset( $cb['function'] ) ? $cb['function'] : null;
					if ( is_array( $fn ) && isset( $fn[0], $fn[1] )
						&& ( $fn[0] === 'BizCoach_Pro_Astro_Rest' || ( is_object( $fn[0] ) && get_class( $fn[0] ) === 'BizCoach_Pro_Astro_Rest' ) )
						&& $fn[1] === 'register_routes' ) {
						$action_attached = true; break 2;
					}
				}
			}
		}
		$did_init  = (int) did_action( 'rest_api_init' );

		if ( empty( $missing ) ) {
			$status   = $forced ? 'WARN' : 'PASS';
			$evidence = $forced
				? 'routes registered ONLY after forced register_routes() call → opcache is serving stale bytecode of class-astro-rest.php (init() at end of file not in cache). Reset opcache (php-fpm reload) to fix permanently.'
				: 'profiles + links/{id} + ingest — all present';
		} else {
			$status   = 'FAIL';
			$evidence = 'missing: ' . implode( ', ', $missing )
				. ' · action_attached=' . ( $action_attached ? 'yes' : 'NO' )
				. ' did_action(rest_api_init)=' . $did_init
				. ' · forced_register_failed=yes — class likely incomplete (fatal during load?)';
		}
		$out[] = self::row( 'T-BCPRO.F14.c',
			$status,
			'REST routes registered (namespace bizcity-bizcoach/v1)',
			$evidence
		);

		// d) Helper functions adopted from legacy (build_links_for needs these).
		$fn_hash = function_exists( 'bccm_generate_natal_chart_hash' );
		$fn_url  = function_exists( 'bccm_get_natal_chart_public_url' );
		$out[] = self::row( 'T-BCPRO.F14.d',
			( $fn_hash && $fn_url ) ? 'PASS' : 'WARN',
			'Legacy helper functions present (loaded via legacy adopter)',
			'bccm_generate_natal_chart_hash=' . ( $fn_hash ? 'yes' : 'NO' )
			. ' bccm_get_natal_chart_public_url=' . ( $fn_url ? 'yes' : 'NO' )
		);

		// e) AJAX handlers consumed by the dialog's resolved URLs.
		$has_natal_ajax   = isset( $wp_filter['wp_ajax_bccm_natal_report_full'] );
		$has_transit_ajax = isset( $wp_filter['wp_ajax_bccm_transit_report'] );
		$out[] = self::row( 'T-BCPRO.F14.e',
			( $has_natal_ajax && $has_transit_ajax ) ? 'PASS' : 'WARN',
			'AJAX handlers bccm_natal_report_full + bccm_transit_report registered',
			'natal=' . ( $has_natal_ajax ? 'yes' : 'NO (lib/astro-report-llm.php?)' )
			. ' transit=' . ( $has_transit_ajax ? 'yes' : 'NO (lib/astro-transit-report.php?)' )
		);

		// f) bccm_coachees table exists + row count for current user.
		$tbl   = $wpdb->prefix . 'bccm_coachees';
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl;
		if ( ! $exists ) {
			$out[] = self::row( 'T-BCPRO.F14.f', 'FAIL', 'Table ' . $tbl . ' exists', 'missing' );
		} else {
			$uid       = get_current_user_id();
			$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
			$mine      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE user_id = %d", $uid ) );
			$has_uid   = (string) $wpdb->get_var( "SHOW COLUMNS FROM {$tbl} LIKE 'user_id'" ) !== '';
			$has_legacy = (string) $wpdb->get_var( "SHOW COLUMNS FROM {$tbl} LIKE 'created_by'" ) !== '';
			$mine_legacy = $has_legacy
				? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE created_by = %d", $uid ) )
				: 0;

			$status = ( $mine > 0 || ( $mine === 0 && $total === 0 ) ) ? 'PASS' : 'WARN';
			$out[] = self::row( 'T-BCPRO.F14.f',
				$status,
				'bccm_coachees rows visible to current user (uid=' . $uid . ')',
				'total=' . $total . ' user_id_match=' . $mine
				. ( $has_legacy ? ' created_by_match=' . $mine_legacy : '' )
				. ' cols: user_id=' . ( $has_uid ? 'yes' : 'NO' ) . ' created_by=' . ( $has_legacy ? 'yes' : 'no' )
				. ( $mine === 0 && $mine_legacy > 0 ? "\n⚠ legacy `created_by` rows exist but list_profiles() filters by user_id — backfill needed" : '' )
			);
		}

		// g) Live REST self-test — dispatch the route in-process so we exercise
		//    permission_callback + SQL exactly as the FE would (no HTTP cost).
		if ( $has_rest && ! empty( $routes['/bizcity-bizcoach/v1/persona/profiles'] ) ) {
			$req  = new WP_REST_Request( 'GET', '/bizcity-bizcoach/v1/persona/profiles' );
			$resp = rest_do_request( $req );
			$code = $resp->get_status();
			$data = $resp->get_data();
			$cnt  = is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ? count( $data['data'] ) : -1;
			$out[] = self::row( 'T-BCPRO.F14.g',
				( $code === 200 ) ? 'PASS' : 'FAIL',
				'Live dispatch GET /persona/profiles (in-process)',
				'http=' . $code . ' rows=' . $cnt
				. ( $code !== 200 && is_wp_error( $data ) ? ' err=' . $data->get_error_code() . ' — ' . $data->get_error_message() : '' )
			);
		} else {
			$out[] = self::row( 'T-BCPRO.F14.g', 'SKIP', 'Live dispatch GET /persona/profiles', 'route not registered — see T-BCPRO.F14.c' );
		}

		return $out;
	}

	/* ============================================================
	 * F.15 — PHASE-0.1-ASTRO Multi-provider Astrology Gateway
	 *
	 * Traces Sprint A→E rollout of bizcity-llm-router/includes/astro/*
	 * (replaces direct freeastrologyapi.com calls in legacy bizcoach-map).
	 *
	 * Spec: plugins/bizcoach-pro/PHASE-0.1-ASTRO.md
	 * Live changelog: plugins/bizcoach-pro/CHANGELOG-LIVE.md
	 * ============================================================ */
	public static function compute_f15_tasks(): array {
		$out = array();

		/* ── Sprint A · Foundation ─────────────────────────────────── */
		$schema_ok = class_exists( 'BizCity_Astro_REST' ) || class_exists( 'BizCity_Astrology_REST' );
		$out[] = self::row( 'T-BCPRO.F15.A1',
			$schema_ok ? 'PASS' : 'FAIL',
			'Sprint A1 — REST controller bizcity/v1/astrology/* loaded',
			'BizCity_Astrology_REST=' . ( class_exists( 'BizCity_Astrology_REST' ) ? 'yes' : 'no' )
		);

		global $wpdb;
		$tbl_usage = $wpdb->base_prefix . 'bcr_astro_usage';
		$has_usage = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_usage ) ) === $tbl_usage;
		$rows      = $has_usage ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_usage}" ) : 0;
		$out[] = self::row( 'T-BCPRO.F15.A2',
			$has_usage ? 'PASS' : 'FAIL',
			'Sprint A2 — Usage ledger wp_bcr_astro_usage exists',
			$has_usage ? "rows={$rows}" : 'table missing'
		);

		$has_settings = class_exists( 'BizCity_Astro_Settings' );
		$out[] = self::row( 'T-BCPRO.F15.A3',
			$has_settings ? 'PASS' : 'FAIL',
			'Sprint A3 — Settings class loaded (network admin sub-page)',
			$has_settings ? 'BizCity_Astro_Settings::PAGE_SLUG=' . BizCity_Astro_Settings::PAGE_SLUG : 'class not loaded'
		);

		$has_guard = class_exists( 'BizCity_Astro_Quota_Guard' );
		$out[] = self::row( 'T-BCPRO.F15.A4',
			$has_guard ? 'PASS' : 'FAIL',
			'Sprint A4 — Quota Guard class loaded',
			$has_guard ? 'free=' . BizCity_Astro_Quota_Guard::quota_for_tier( 'free' )
				. ' / paid=' . BizCity_Astro_Quota_Guard::quota_for_tier( 'paid' )
				. ' / enterprise=' . BizCity_Astro_Quota_Guard::quota_for_tier( 'enterprise' )
				: 'class not loaded'
		);

		/* ── Sprint B · Provider adapters + Router ─────────────────── */
		$has_iface     = interface_exists( 'BizCity_Astro_Provider' );
		$has_normalize = class_exists( 'BizCity_Astro_Normalizer' );
		$out[] = self::row( 'T-BCPRO.F15.B1',
			( $has_iface && $has_normalize ) ? 'PASS' : 'FAIL',
			'Sprint B1 — Provider interface + Normalizer',
			'iface=' . ( $has_iface ? 'yes' : 'no' ) . ', normalizer=' . ( $has_normalize ? 'yes' : 'no' )
		);

		$adapters = array(
			'B2' => 'Astro_Provider_Freeastrology',
			'B3' => 'Astro_Provider_Freeastroapi',
			'B4' => 'Astro_Provider_Local',
		);
		foreach ( $adapters as $sub => $cls ) {
			$exists = class_exists( $cls );
			$ready  = $exists ? ( new $cls() )->is_ready() : false;
			$out[] = self::row( 'T-BCPRO.F15.' . $sub,
				$exists ? 'PASS' : 'FAIL',
				"Sprint {$sub} — {$cls} adapter loaded",
				'class=' . ( $exists ? 'yes' : 'no' ) . ', is_ready=' . ( $ready ? 'yes' : 'no' )
			);
		}

		$has_router = class_exists( 'BizCity_Astro_Router' );
		$status     = $has_router ? BizCity_Astro_Router::provider_status() : array();
		$out[] = self::row( 'T-BCPRO.F15.B5',
			$has_router ? 'PASS' : 'FAIL',
			'Sprint B5 — Router with fallback chain',
			$has_router
				? 'providers=' . implode( ',', array_map( function( $id, $s ) { return $id . '(' . ( $s['ready'] ? 'ready' : 'down' ) . ')'; }, array_keys( $status ), $status ) )
				: 'class not loaded'
		);

		// B6 wiring: REST handler delegates to router (no longer 501 stub).
		$rest_live = false;
		if ( class_exists( 'BizCity_Astrology_REST' ) ) {
			$rc = new ReflectionClass( 'BizCity_Astrology_REST' );
			if ( $rc->hasMethod( 'live_handler' ) ) { $rest_live = true; }
		}
		$out[] = self::row( 'T-BCPRO.F15.B6',
			$rest_live ? 'PASS' : 'FAIL',
			'Sprint B6 — REST /natal & /transit live (live_handler present)',
			$rest_live ? 'method live_handler() registered' : 'still using stub_handler()'
		);

		/* ── Sprint C · Client migration ───────────────────────────── */
		$has_client = class_exists( 'BizCity_Astro_Client' );
		$out[] = self::row( 'T-BCPRO.F15.C1',
			$has_client ? 'PASS' : 'FAIL',
			'Sprint C1 — BizCity_Astro_Client adapter (mu-plugins/bizcity-openrouter)',
			$has_client ? 'loaded' : 'missing — caller will bypass gateway'
		);

		// C3: hub-rest handler routes through Client.
		$hub_uses_client = false;
		$hub_file = WPMU_PLUGIN_DIR . '/bizcity-openrouter/includes/class-hub-rest.php';
		if ( is_readable( $hub_file ) ) {
			$src = (string) file_get_contents( $hub_file );
			$hub_uses_client = ( strpos( $src, 'BizCity_Astro_Client::natal' ) !== false );
		}
		$out[] = self::row( 'T-BCPRO.F15.C3',
			$hub_uses_client ? 'PASS' : 'WARN',
			'Sprint C3 — class-hub-rest.php delegates to BizCity_Astro_Client',
			$hub_uses_client ? 'handle_astrology_chart routes through gateway' : 'still calls BizCity_Astrology_API directly'
		);

		/* ── Sprint D · Polish & telemetry (not yet started) ───────── */
		$out[] = self::row( 'T-BCPRO.F15.D',  'PENDING',
			'Sprint D — telemetry / healthcheck endpoint / WP-CLI / cache',
			'not started'
		);

		/* ── Sprint E.1 · Choke-point refactor (2026-05-16) ─────────
		 * `bccm_astro_api_call()` in bcpro/legacy/lib/astro-api-free.php
		 * now routes through BizCity_Astro_Quota_Guard + records every
		 * call into wp_bcr_astro_usage. Verify by reflecting on the
		 * function source: must contain the gateway marker symbol. */
		$e1_wired = false;
		$e1_file  = '';
		if ( function_exists( 'bccm_astro_api_call' ) ) {
			try {
				$rf = new ReflectionFunction( 'bccm_astro_api_call' );
				$e1_file = (string) $rf->getFileName();
				$src     = is_readable( $e1_file ) ? (string) file_get_contents( $e1_file ) : '';
				$e1_wired = ( strpos( $src, '_bccm_astro_api_call_via_gateway' ) !== false );
			} catch ( Throwable $e ) { /* ignore */ }
		}
		$out[] = self::row( 'T-BCPRO.F15.E1',
			$e1_wired ? 'PASS' : 'FAIL',
			'Sprint E.1 — bccm_astro_api_call() refactored to gateway choke-point',
			$e1_wired
				? 'gateway dispatch wired (file=' . basename( $e1_file ) . ')'
				: ( $e1_file ? 'gateway marker missing in ' . basename( $e1_file ) : 'function not loaded' )
		);

		/* ── Sprint E.2+ · Remaining cleanup ───────────────────────── */
		$out[] = self::row( 'T-BCPRO.F15.E',  'PENDING',
			'Sprint E.2+ — refactor fetch_full_chart → BizCity_Astro_Client, remove legacy lib/astro-api-*.php',
			'E.1 done — E.2 will switch to envelope shape + drop snapshot once all callers verified'
		);

		/* ── Wiring sanity: link to canonical R-1API-9 settings page ─
		 * 2026-05-17 — replaced the legacy `bizcity-astro-gateway` slug
		 * with the unified TwinChat settings page (see PHASE-0-RULE-1-API.md
		 * §3 R-1API-9). One canonical settings page for the whole network.
		 */
		$page_url = admin_url( 'admin.php?page=bizcity-twinchat-settings' );
		$out[] = self::row( 'T-BCPRO.F15.UI',
			$has_settings ? 'PASS' : 'FAIL',
			'Settings page reachable',
			$has_settings ? 'URL: ' . $page_url : 'class not loaded'
		);

		/* ── Live traffic: gateway vs legacy direct ────────────────
		 * Gateway calls are recorded in wp_bcr_astro_usage (Sprint A2).
		 * Legacy direct hits to freeastrologyapi.com are counted in
		 * site_option bcr_astro_legacy_call_count (Sprint C3-bis hook).
		 * Hook is installed in BOTH copies of bccm_astro_api_call:
		 *   1. bizcoach-map/lib/astro-api-free.php (legacy plugin, retired)
		 *   2. bcpro/legacy/lib/astro-api-free.php (snapshot loaded by
		 *      BizCoach_Pro_Legacy_Adopter when bizcoach-map is OFF — the
		 *      live path on production after 2026-05-16 deprecation).
		 * Source of last hit is recorded in bcr_astro_legacy_last_source.
		 * If LEGACY > 0 while GATEWAY = 0 → adopter shadow is BYPASSING
		 * the gateway. Sprint E will route bccm_astro_api_call → Client. */
		global $wpdb;
		$tbl_u = $wpdb->base_prefix . 'bcr_astro_usage';
		$gw_24h = 0; $gw_total = 0;
		if ( $has_usage ) {
			$gw_24h   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_u} WHERE called_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)" );
			$gw_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_u}" );
		}
		$out[] = self::row( 'T-BCPRO.F15.GW',
			$gw_total > 0 ? 'PASS' : 'PENDING',
			'Gateway traffic (wp_bcr_astro_usage rows)',
			"24h={$gw_24h} / total={$gw_total}"
		);

		$legacy_count  = (int) get_site_option( 'bcr_astro_legacy_call_count', 0 );
		$legacy_at     = (int) get_site_option( 'bcr_astro_legacy_last_at', 0 );
		$legacy_ep     = (string) get_site_option( 'bcr_astro_legacy_last_endpoint', '' );
		$legacy_src    = (string) get_site_option( 'bcr_astro_legacy_last_source', 'unknown' );
		$legacy_when   = $legacy_at > 0 ? human_time_diff( $legacy_at, time() ) . ' ago' : 'never';

		/* Auto-baseline (Sprint E.1, 2026-05-16): the LEG counter is
		 * monotonic across deploys; existing hits are PRE-refactor
		 * history, not active bypasses. The first time we see E.1
		 * statically wired AND no baseline yet, snapshot the current
		 * count + timestamp. Subsequent LEG growth = post-deploy active
		 * bypass (real FAIL). */
		$baseline_count = (int) get_site_option( 'bcr_astro_legacy_baseline_count', -1 );
		$baseline_at    = (int) get_site_option( 'bcr_astro_legacy_baseline_at', 0 );
		if ( $e1_wired && $baseline_count < 0 ) {
			update_site_option( 'bcr_astro_legacy_baseline_count', $legacy_count );
			update_site_option( 'bcr_astro_legacy_baseline_at', time() );
			$baseline_count = $legacy_count;
			$baseline_at    = time();
		}
		$post_deploy_leg = $baseline_count >= 0 ? max( 0, $legacy_count - $baseline_count ) : $legacy_count;

		// Status logic (post-E.1):
		//  PASS  → 0 post-deploy legacy hits (history may exist).
		//  WARN  → some post-deploy legacy hits BUT gateway is also seeing traffic
		//          (mixed — usually a non-bccm caller or cron race).
		//  FAIL  → post-deploy legacy hits exist AND gateway sees nothing
		//          (refactor not effective — gateway classes likely unavailable).
		if ( $post_deploy_leg === 0 ) {
			$status = 'PASS';
		} elseif ( $gw_total > 0 ) {
			$status = 'WARN';
		} else {
			$status = 'FAIL';
		}
		$baseline_note = $baseline_count >= 0
			? " baseline={$baseline_count}@" . ( $baseline_at > 0 ? human_time_diff( $baseline_at, time() ) . ' ago' : '?' )
			: '';
		$out[] = self::row( 'T-BCPRO.F15.LEG',
			$status,
			'Legacy direct freeastrologyapi.com hits (post-E.1 fallback path)',
			"post_deploy={$post_deploy_leg} (total={$legacy_count}{$baseline_note}) last={$legacy_when} endpoint={$legacy_ep} source={$legacy_src}"
				. ( $status === 'FAIL' ? ' — active bypass, gateway classes unavailable when called' : '' )
		);

		/* ── E.1 telemetry: gateway-routed legacy calls ──────────────
		 * Bumped by _bccm_astro_bump_e1_counter() inside the new gateway
		 * dispatch in bcpro/legacy/lib/astro-api-free.php. Proves the
		 * choke-point refactor is exercising actual traffic. */
		$e1_count = (int) get_site_option( 'bcr_astro_e1_via_gateway_count', 0 );
		$e1_at    = (int) get_site_option( 'bcr_astro_e1_via_gateway_last_at', 0 );
		$e1_ep    = (string) get_site_option( 'bcr_astro_e1_via_gateway_last_endpoint', '' );
		$e1_res   = (string) get_site_option( 'bcr_astro_e1_via_gateway_last_result', '' );
		$e1_when  = $e1_at > 0 ? human_time_diff( $e1_at, time() ) . ' ago' : 'never';
		if ( $e1_count > 0 && $legacy_count === 0 ) {
			$e1_status = 'PASS';
		} elseif ( $e1_count > 0 ) {
			$e1_status = 'WARN';
		} else {
			$e1_status = 'PENDING';
		}
		$out[] = self::row( 'T-BCPRO.F15.E1.RUN',
			$e1_status,
			'E.1 live traffic — legacy callers routed through gateway',
			"count={$e1_count} last={$e1_when} endpoint={$e1_ep} result={$e1_res}"
				. ( $e1_status === 'WARN' ? ' — some calls still falling back direct, see F.15.LEG' : '' )
		);

		return $out;
	}

	/* ============================================================
	 * F.16 — Cache health (CACHE-STRATEGY.md, 2026-05-16)
	 *
	 * Verifies the wp_cache_* layer + bcpro/cache/invalidate listener
	 * are wired correctly. See CACHE-STRATEGY.md §6.
	 * ============================================================ */
	public static function compute_f16_tasks(): array {
		$out = array();

		// a) Cache helper class loaded.
		$has = class_exists( 'BizCoach_Pro_Cache' );
		$out[] = self::row( 'T-BCPRO.F16.a',
			$has ? 'PASS' : 'FAIL',
			'BizCoach_Pro_Cache helper loaded',
			$has ? 'yes' : 'NO — includes/class-cache.php not required'
		);
		if ( ! $has ) { return $out; }

		// b) Persistent object cache backend (Redis/Memcached) detected.
		$persistent = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		$out[] = self::row( 'T-BCPRO.F16.b',
			$persistent ? 'PASS' : 'WARN',
			'Persistent object cache backend active',
			$persistent
				? 'yes — wp_using_ext_object_cache() reports external backend'
				: 'no — falling back to per-request memo only (TTL ineffective across requests)'
		);

		// c) bcpro/cache/invalidate listener registered.
		global $wp_filter;
		$listener_count = 0;
		if ( isset( $wp_filter['bcpro/cache/invalidate'] ) ) {
			foreach ( $wp_filter['bcpro/cache/invalidate']->callbacks as $cbs ) {
				$listener_count += is_array( $cbs ) ? count( $cbs ) : 0;
			}
		}
		$out[] = self::row( 'T-BCPRO.F16.c',
			$listener_count > 0 ? 'PASS' : 'FAIL',
			'bcpro/cache/invalidate action has listeners',
			'count=' . $listener_count . ( $listener_count === 0 ? ' — BizCoach_Pro_Cache::init() never ran' : '' )
		);

		// d) Round-trip probe: set + get against bcpro_templates group.
		$probe_key = 'diag:probe:' . wp_generate_password( 6, false, false );
		$probe_val = array( 'ts' => time(), 'rand' => mt_rand( 1, 9999 ) );
		wp_cache_set( $probe_key, $probe_val, 'bcpro_templates', 30 );
		$got    = wp_cache_get( $probe_key, 'bcpro_templates' );
		$rt_ok  = is_array( $got ) && isset( $got['rand'] ) && $got['rand'] === $probe_val['rand'];
		wp_cache_delete( $probe_key, 'bcpro_templates' );
		$out[] = self::row( 'T-BCPRO.F16.d',
			$rt_ok ? 'PASS' : 'FAIL',
			'Round-trip probe (set→get→delete) on bcpro_templates group',
			$rt_ok ? 'set+get returned identical payload' : 'set+get mismatch — object cache broken?'
		);

		// e) Invalidation listener fires correctly. Bump user version,
		//    confirm it changes; this exercises the bcpro_coachee_idx wildcard
		//    pattern documented in CACHE-STRATEGY.md §3 / §5.
		$probe_uid = 999999; // synthetic, won't collide with real users
		$ver_before = BizCoach_Pro_Cache::get_user_version( $probe_uid );
		do_action( 'bcpro/cache/invalidate', 'coachee', array( 'id' => 999999, 'user_id' => $probe_uid ) );
		$ver_after = BizCoach_Pro_Cache::get_user_version( $probe_uid );
		$bump_ok = ( $ver_after > $ver_before );
		$out[] = self::row( 'T-BCPRO.F16.e',
			$bump_ok ? 'PASS' : 'FAIL',
			'Invalidation action bumps user version stamp',
			"before={$ver_before} after={$ver_after}"
				. ( $bump_ok ? '' : ' — listener fired but version unchanged?' )
		);

		// f) Recent invalidation log (last 10 events, ring buffer).
		$log = BizCoach_Pro_Cache::get_log();
		$recent = array_slice( $log, -5 );
		$summary = array();
		foreach ( $recent as $entry ) {
			$summary[] = sprintf(
				'%s/%s(id=%s)',
				date( 'H:i:s', (int) $entry['t'] ),
				(string) $entry['entity'],
				isset( $entry['ctx']['id'] ) ? (int) $entry['ctx']['id'] : '-'
			);
		}
		$out[] = self::row( 'T-BCPRO.F16.f',
			count( $log ) > 0 ? 'PASS' : 'WARN',
			'Recent invalidation events (ring buffer, last 10)',
			count( $log ) > 0
				? ( 'total=' . count( $log ) . ' · last5=' . implode( ', ', $summary ) )
				: 'no events recorded yet — write a coachee or ingest a link to populate'
		);

		return $out;
	}

	/* ============================================================
	 * G.6 — PHASE-0.2-ASTRO gateway probes (2026-05-16)
	 *
	 * Verifies the new BizCoach_Pro_Astro_Client surface and the
	 * freeastroapi gateway end-to-end. Live POST probes consume real
	 * quota and are opt-in via `?bcpro_diag_g6=1` query param.
	 * ============================================================ */
	public static function render_g6_header( bool $run_live ): void {
		$page_url = admin_url( 'tools.php?page=' . self::SLUG );
		echo '<h2 style="margin-top:24px">G.6 — PHASE-0.2-ASTRO gateway probes <small style="font-weight:normal;font-size:12px;color:#666">opt-in live probes</small></h2>';
		echo '<p style="color:#555;margin:6px 0 10px">';
		echo 'Spec: <code>plugins/bizcoach-pro/PHASE-0.2-ASTRO.md §G.6</code>. ';
		if ( $run_live ) {
			echo '<strong style="color:#dc3232">Live probes ENABLED</strong> — each gateway POST consumes quota. ';
			echo '<a class="button" href="' . esc_url( $page_url ) . '">Disable live probes</a>';
		} else {
			echo 'Live POST probes are <strong>SKIPPED by default</strong> to preserve quota. ';
			echo '<a class="button button-primary" href="' . esc_url( add_query_arg( 'bcpro_diag_g6', '1', $page_url ) ) . '">Run live probes once</a>';
		}
		echo '</p>';
	}

	public static function compute_g6_tasks( bool $run_live = false ): array {
		$out = array();

		/* ── G.6.0 · Client surface loaded ─────────────────────────── */
		$has_client = class_exists( 'BizCoach_Pro_Astro_Client' );
		$out[] = self::row( 'T-BCPRO.G6.0',
			$has_client ? 'PASS' : 'FAIL',
			'BizCoach_Pro_Astro_Client surface loaded',
			$has_client ? 'class available' : 'class missing — includes/astro/class-astro-client.php not required'
		);
		if ( ! $has_client ) { return $out; }

		/* ── G.6.api · Gateway API key configured ──────────────────── */
		$masked = (string) BizCoach_Pro_Astro_Client::get_masked_api_key();
		$key_ok = $masked !== '' && $masked !== '(not set)';
		$out[] = self::row( 'T-BCPRO.G6.api',
			$key_ok ? 'PASS' : 'WARN',
			'Gateway API key (bcpro_gateway_api_key) configured',
			$key_ok ? 'key=' . $masked : 'no key — configure at Settings → BCPro Astro Gateway'
		);

		/* ── G.6.inproc · In-process gateway preferred path ────────── */
		$in_proc = BizCoach_Pro_Astro_Client::is_in_process_ready();
		$out[] = self::row( 'T-BCPRO.G6.inproc',
			$in_proc ? 'PASS' : 'WARN',
			'In-process gateway (REST + Auth classes loaded)',
			$in_proc ? 'rest_do_request() will be used (no HTTP)' : 'remote fallback — wp_remote_post will be used'
		);

		/* ── G.6.reach · Cheap quota call (always runs) ────────────── */
		$reach = BizCoach_Pro_Astro_Client::quota();
		$reach_ok = ! empty( $reach['success'] );
		$out[] = self::row( 'T-BCPRO.G6.reach',
			$reach_ok ? 'PASS' : 'FAIL',
			'gateway_reach — GET /astrology/quota',
			$reach_ok
				? sprintf( 'http=%d transport=%s latency=%dms',
					(int) ( $reach['http']['status'] ?? 0 ),
					(string) ( $reach['http']['transport'] ?? '?' ),
					(int) ( $reach['http']['latency_ms'] ?? 0 ) )
				: 'error=' . (string) ( $reach['error'] ?? 'unknown' )
		);

		/* ── G.6.quota · Snapshot remaining quota ──────────────────── */
		$env = isset( $reach['envelope'] ) && is_array( $reach['envelope'] ) ? $reach['envelope'] : array();
		$remaining = null; $limit = null;
		foreach ( array( 'remaining', 'quota_remaining', 'x-ratelimit-remaining' ) as $k ) {
			if ( isset( $env[ $k ] ) ) { $remaining = (int) $env[ $k ]; break; }
		}
		foreach ( array( 'limit', 'quota_limit', 'x-ratelimit-limit' ) as $k ) {
			if ( isset( $env[ $k ] ) ) { $limit = (int) $env[ $k ]; break; }
		}
		$ratio = ( $limit && $limit > 0 && $remaining !== null ) ? ( $remaining / $limit ) : null;
		$qstatus = 'PENDING';
		if ( $remaining !== null ) {
			if ( $ratio !== null && $ratio < 0.10 ) { $qstatus = 'WARN'; }
			else { $qstatus = 'PASS'; }
		}
		$out[] = self::row( 'T-BCPRO.G6.quota',
			$qstatus,
			'gateway_quota_remaining — envelope quota fields present',
			$remaining === null
				? 'no quota field in envelope (keys=' . implode( ',', array_keys( $env ) ) . ')'
				: sprintf( 'remaining=%d limit=%s ratio=%s',
					$remaining,
					$limit === null ? '?' : (string) $limit,
					$ratio === null ? '?' : sprintf( '%.0f%%', $ratio * 100 ) )
		);

		/* ── G.6.audit · Legacy direct-caller audit (read-only) ────── */
		$legacy_total    = (int) get_site_option( 'bcr_astro_legacy_call_count', 0 );
		$legacy_baseline = (int) get_site_option( 'bcr_astro_legacy_baseline_count', -1 );
		$legacy_last_at  = (int) get_site_option( 'bcr_astro_legacy_last_at', 0 );
		$legacy_last_ep  = (string) get_site_option( 'bcr_astro_legacy_last_endpoint', '' );
		$legacy_last_src = (string) get_site_option( 'bcr_astro_legacy_last_source', '' );
		$post_deploy     = $legacy_baseline >= 0 ? max( 0, $legacy_total - $legacy_baseline ) : $legacy_total;
		$audit_status    = 'PASS';
		if ( $post_deploy > 0 ) {
			$audit_status = 'WARN'; // some calls still went direct — gateway filter may be off
		}
		$out[] = self::row( 'T-BCPRO.G6.audit',
			$audit_status,
			'legacy_caller_audit — post-deploy direct freeastrologyapi.com hits',
			sprintf( 'post_deploy=%d total=%d baseline=%d last=%s endpoint=%s source=%s',
				$post_deploy,
				$legacy_total,
				$legacy_baseline,
				$legacy_last_at > 0 ? human_time_diff( $legacy_last_at, time() ) . ' ago' : 'never',
				$legacy_last_ep,
				$legacy_last_src )
				. ( $post_deploy > 0 ? ' — check bccm_astro_use_gateway_v2 filter' : '' )
		);

		/* ── Live POST probes (opt-in) ─────────────────────────────── */
		if ( ! $run_live ) {
			$skip_msg = 'skipped — append ?bcpro_diag_g6=1 to URL to run (consumes quota)';
			foreach ( array(
				'natal' => 'gateway_natal_western — POST /astrology/western/natal',
				'vedic' => 'gateway_vedic_calculate — POST /astrology/vedic/calculate',
				'bazi'  => 'gateway_chinese_bazi — POST /astrology/chinese/bazi',
				'geo'   => 'gateway_geo_search — GET /astrology/utilities/geo-search?q=Hanoi',
			) as $k => $label ) {
				$out[] = self::row( 'T-BCPRO.G6.' . $k, 'SKIP', $label, $skip_msg );
			}
			return $out;
		}

		// Canonical sample birth (Hanoi, 1990-01-15 10:30 +07).
		$sample = array(
			'datetime_utc'  => '1990-01-15T03:30:00Z',
			'lat'           => 21.0285,
			'lng'           => 105.8542,
			'tz_str'        => 'Asia/Ho_Chi_Minh',
			'house_system'  => 'P',
			'zodiac_type'   => 'tropical',
			'include_speed' => true,
		);

		// G.6.natal — Western natal.
		$r = BizCoach_Pro_Astro_Client::natal_western( $sample );
		$env_n = isset( $r['envelope'] ) && is_array( $r['envelope'] ) ? $r['envelope'] : array();
		$pc = ( isset( $env_n['planets'] ) && is_array( $env_n['planets'] ) ) ? count( $env_n['planets'] ) : 0;
		$status_n = ! empty( $r['success'] ) ? ( $pc >= 10 ? 'PASS' : 'WARN' ) : 'FAIL';
		$out[] = self::row( 'T-BCPRO.G6.natal',
			$status_n,
			'gateway_natal_western — planets_count >= 10 (expected 13)',
			! empty( $r['success'] )
				? sprintf( 'planets=%d latency=%dms transport=%s',
					$pc, (int) ( $r['http']['latency_ms'] ?? 0 ), (string) ( $r['http']['transport'] ?? '?' ) )
				: 'error=' . (string) ( $r['error'] ?? 'unknown' )
		);

		// G.6.vedic — Vedic calculate (lahiri ayanamsa).
		$sample_v = $sample + array( 'ayanamsa' => 'lahiri', 'include_vargas' => true );
		$r = BizCoach_Pro_Astro_Client::calculate_vedic( $sample_v );
		$env_v = isset( $r['envelope'] ) && is_array( $r['envelope'] ) ? $r['envelope'] : array();
		$pcv = ( isset( $env_v['planets'] ) && is_array( $env_v['planets'] ) ) ? count( $env_v['planets'] ) : 0;
		$has_lagna = isset( $env_v['lagna'] ) || isset( $env_v['ascendant'] );
		$status_v = ! empty( $r['success'] ) ? ( ( $pcv >= 9 && $has_lagna ) ? 'PASS' : 'WARN' ) : 'FAIL';
		$out[] = self::row( 'T-BCPRO.G6.vedic',
			$status_v,
			'gateway_vedic_calculate — planets>=9 + lagna present',
			! empty( $r['success'] )
				? sprintf( 'planets=%d lagna=%s latency=%dms',
					$pcv, $has_lagna ? 'yes' : 'no', (int) ( $r['http']['latency_ms'] ?? 0 ) )
				: 'error=' . (string) ( $r['error'] ?? 'unknown' )
		);

		// G.6.bazi — Chinese BaZi (4 pillars).
		$sample_b = $sample + array( 'gender' => 'male' );
		$r = BizCoach_Pro_Astro_Client::bazi_chinese( $sample_b );
		$env_b = isset( $r['envelope'] ) && is_array( $r['envelope'] ) ? $r['envelope'] : array();
		$pillars = 0;
		if ( isset( $env_b['pillars'] ) && is_array( $env_b['pillars'] ) ) { $pillars = count( $env_b['pillars'] ); }
		elseif ( isset( $env_b['bazi']['pillars'] ) && is_array( $env_b['bazi']['pillars'] ) ) { $pillars = count( $env_b['bazi']['pillars'] ); }
		$status_b = ! empty( $r['success'] ) ? ( $pillars === 4 ? 'PASS' : 'WARN' ) : 'FAIL';
		$out[] = self::row( 'T-BCPRO.G6.bazi',
			$status_b,
			'gateway_chinese_bazi — pillars_count == 4',
			! empty( $r['success'] )
				? sprintf( 'pillars=%d latency=%dms', $pillars, (int) ( $r['http']['latency_ms'] ?? 0 ) )
				: 'error=' . (string) ( $r['error'] ?? 'unknown' )
		);

		// G.6.geo — Geo search.
		$r = BizCoach_Pro_Astro_Client::geo_search( array( 'q' => 'Hanoi', 'country' => 'VN', 'limit' => 1 ) );
		$env_g = isset( $r['envelope'] ) && is_array( $r['envelope'] ) ? $r['envelope'] : array();
		$results = 0;
		if ( isset( $env_g['results'] ) && is_array( $env_g['results'] ) ) { $results = count( $env_g['results'] ); }
		elseif ( isset( $env_g['data'] ) && is_array( $env_g['data'] ) ) { $results = count( $env_g['data'] ); }
		$status_g = ! empty( $r['success'] ) ? ( $results >= 1 ? 'PASS' : 'WARN' ) : 'FAIL';
		$out[] = self::row( 'T-BCPRO.G6.geo',
			$status_g,
			'gateway_geo_search — q=Hanoi country=VN → results_count >= 1',
			! empty( $r['success'] )
				? sprintf( 'results=%d latency=%dms', $results, (int) ( $r['http']['latency_ms'] ?? 0 ) )
				: 'error=' . (string) ( $r['error'] ?? 'unknown' )
		);

		return $out;
	}

	public static function render_section( string $title, array $tasks ): void {
		echo '<h2>' . esc_html( $title ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:140px">Task</th><th style="width:80px">Status</th><th>Check</th><th>Evidence</th>';
		echo '</tr></thead><tbody>';
		foreach ( $tasks as $t ) {
			echo '<tr><td><code>' . esc_html( $t['id'] ) . '</code></td>';
			echo '<td>' . self::badge( (string) $t['status'] ) . '</td>';
			echo '<td>' . esc_html( $t['check'] ) . '</td>';
			echo '<td><pre style="white-space:pre-wrap;margin:0;font-size:11px;color:#333">'
				. esc_html( $t['evidence'] ) . '</pre></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function row( string $id, string $status, string $check, string $evidence ): array {
		return array( 'id' => $id, 'status' => $status, 'check' => $check, 'evidence' => $evidence );
	}

	private static function badge( string $status ): string {
		$status = strtoupper( $status );
		$colors = array(
			'PASS'    => '#46b450',
			'FAIL'    => '#dc3232',
			'WARN'    => '#ffb900',
			'PENDING' => '#999',
			'SKIP'    => '#999',
		);
		$bg = isset( $colors[ $status ] ) ? $colors[ $status ] : '#999';
		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;background:%s;color:#fff;border-radius:3px;font-size:11px;font-weight:600">%s</span>',
			esc_attr( $bg ), esc_html( $status )
		);
	}
}
