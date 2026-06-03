<?php
/**
 * Pro-Learning Diagnostic (PHASE 0.7)
 *
 * Operator-facing health-check for the unified PHASE-0.7-MASTER roadmap.
 * One row per roadmap task → PASS / WARN / FAIL / SKIP + evidence + a copy-
 * paste fix hint when failing.
 *
 * URL:   /wp-admin/tools.php?page=bizcity-pro-learning-diag
 * Cap:   manage_options
 * Query: ?run=1 to force recheck (bypass 5-min transient cache)
 *        ?format=json to JSON dump (for CI / curl)
 *
 * Verification layers (per PHASE-0.31 lesson — "file_exists alone is PASS giả"):
 *   L1  file/class exists
 *   L2  symbol callable + dependent classes resolvable
 *   L3  live HTTP probe (REST or upload sandbox) where applicable
 *
 * @package    Bizcity_Twin_AI
 * @subpackage TwinChat\Diagnostics
 * @since      2026-05-09
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Pro_Learning_Diagnostic {

	const PAGE_SLUG       = 'bizcity-pro-learning-diag';
	const TRANSIENT_CACHE = 'bizcity_prolearning_diag_v1';
	const CACHE_TTL       = 300; // 5 min

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Admin submenu removed (Consolidation M2, 2026-06-02). Smoke moved
		// to BizCity_Probe_Twinchat_Pro_Learning. Class kept for run_all()
		// + JSON dump (still callable from CLI / cron).
	}

	/* =====================================================================
	 * RENDER
	 * ===================================================================*/

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}

		$force = isset( $_GET['run'] ) && $_GET['run'] == '1';
		$json  = isset( $_GET['format'] ) && $_GET['format'] === 'json';

		$results = $this->run_all( $force );

		if ( $json ) {
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
			exit;
		}

		echo '<div class="wrap">';
		echo '<h1>BizCity Pro Learning — Sprint Diagnostic</h1>';
		echo '<p><strong>Roadmap:</strong> <code>PHASE-0.7-MASTER.md</code> &nbsp;|&nbsp; '
			. '<strong>Companions:</strong> <code>PHASE-0.7-LEARNING-EXTEND.md</code>, '
			. '<code>PHASE-0.7-PLAN-PRO-LEARNING.md</code></p>';
		echo '<p>Three-layer verification: <strong>L1</strong> file/class exists · '
			. '<strong>L2</strong> dependents loadable · <strong>L3</strong> live probe. '
			. 'Status legend: '
			. '<span style="background:#46b450;color:#fff;padding:2px 6px;border-radius:3px">PASS</span> '
			. '<span style="background:#ffb900;color:#000;padding:2px 6px;border-radius:3px">WARN</span> '
			. '<span style="background:#dc3232;color:#fff;padding:2px 6px;border-radius:3px">FAIL</span> '
			. '<span style="background:#888;color:#fff;padding:2px 6px;border-radius:3px">SKIP</span></p>';

		$cache_age = $force ? 0 : (int) get_transient( self::TRANSIENT_CACHE . '_at' );
		$age_text  = $cache_age ? human_time_diff( $cache_age ) . ' ago' : 'just now';

		echo '<p>'
			. '<a class="button button-primary" href="' . esc_url( add_query_arg( 'run', '1' ) ) . '">↻ Re-run</a> '
			. '&nbsp;<a class="button" href="' . esc_url( add_query_arg( 'format', 'json' ) ) . '" target="_blank">⇣ JSON</a> '
			. '&nbsp;<span style="color:#666">Cached: ' . esc_html( $age_text ) . '</span>'
			. '</p>';

		// Summary
		$summary = $this->summarize( $results );
		echo '<div style="margin:14px 0;padding:12px;background:#f6f7f7;border-left:4px solid '
			. ( $summary['fail'] > 0 ? '#dc3232' : ( $summary['warn'] > 0 ? '#ffb900' : '#46b450' ) )
			. '">'
			. '<strong>Overall:</strong> '
			. $summary['pass'] . ' PASS · '
			. $summary['warn'] . ' WARN · '
			. $summary['fail'] . ' FAIL · '
			. $summary['skip'] . ' SKIP &nbsp; '
			. '<em>(' . $summary['total'] . ' checks)</em>'
			. '</div>';

		// Sections
		foreach ( $results['sections'] as $section ) {
			echo '<h2 style="margin-top:30px">' . esc_html( $section['title'] ) . '</h2>';
			if ( ! empty( $section['note'] ) ) {
				echo '<p>' . wp_kses_post( $section['note'] ) . '</p>';
			}
			echo '<table class="widefat striped"><thead><tr>'
				. '<th style="width:90px">Task</th>'
				. '<th style="width:80px">Layer</th>'
				. '<th style="width:80px">Status</th>'
				. '<th>Check</th>'
				. '<th>Evidence / Fix Hint</th>'
				. '</tr></thead><tbody>';
			foreach ( $section['rows'] as $r ) {
				$this->render_row( $r );
			}
			echo '</tbody></table>';
		}

		// Live PDF upload sandbox
		echo '<h2 style="margin-top:30px">L3 Live Probe — PDF Adapter Sandbox</h2>';
		echo '<p>Upload a PDF (≤10 MB) to verify the full PDF text-extraction pipeline end-to-end without going through Sources REST.</p>';
		$this->render_pdf_probe();

		echo '</div>';
	}

	private function render_row( $r ) {
		$colors = [
			'PASS' => 'background:#46b450;color:#fff',
			'WARN' => 'background:#ffb900;color:#000',
			'FAIL' => 'background:#dc3232;color:#fff',
			'SKIP' => 'background:#888;color:#fff',
		];
		$bg = isset( $colors[ $r['status'] ] ) ? $colors[ $r['status'] ] : '';
		echo '<tr>';
		echo '<td><code>' . esc_html( $r['task'] ) . '</code></td>';
		echo '<td><code>' . esc_html( $r['layer'] ) . '</code></td>';
		echo '<td><span style="' . esc_attr( $bg ) . ';padding:3px 8px;border-radius:3px;font-weight:600">' . esc_html( $r['status'] ) . '</span></td>';
		echo '<td>' . esc_html( $r['check'] ) . '</td>';
		echo '<td>';
		if ( ! empty( $r['evidence'] ) ) {
			echo '<div style="font-family:Consolas,monospace;font-size:12px;color:#333;white-space:pre-wrap">'
				. esc_html( $r['evidence'] ) . '</div>';
		}
		if ( ! empty( $r['fix_hint'] ) && $r['status'] !== 'PASS' ) {
			echo '<div style="margin-top:6px;padding:6px;background:#fff3cd;border-left:3px solid #ffb900;font-size:12px">'
				. '<strong>Fix:</strong> ' . wp_kses_post( $r['fix_hint'] )
				. '</div>';
		}
		echo '</td>';
		echo '</tr>';
	}

	private function render_pdf_probe() {
		$action = isset( $_POST['bizcity_diag_action'] ) ? sanitize_key( $_POST['bizcity_diag_action'] ) : '';
		$nonce_ok = isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'bizcity_prolearning_diag' );

		if ( $nonce_ok && $action === 'probe_pdf' && ! empty( $_FILES['pdf']['tmp_name'] ) ) {
			$this->action_probe_pdf();
		}

		echo '<form method="post" enctype="multipart/form-data" style="padding:12px;background:#f6f7f7;border:1px solid #ddd">';
		wp_nonce_field( 'bizcity_prolearning_diag' );
		echo '<input type="hidden" name="bizcity_diag_action" value="probe_pdf" />';
		echo '<input type="file" name="pdf" accept="application/pdf,.pdf" required />';
		echo ' <button type="submit" class="button button-primary">Run PDF Adapter</button>';
		echo '</form>';
	}

	private function action_probe_pdf() {
		$f = $_FILES['pdf'];
		if ( $f['error'] !== UPLOAD_ERR_OK ) {
			$this->notice( 'error', 'Upload failed: error code ' . (int) $f['error'] );
			return;
		}
		if ( $f['size'] > 10 * 1024 * 1024 ) {
			$this->notice( 'error', 'File >10 MB — please use a smaller sample.' );
			return;
		}
		if ( ! class_exists( 'BizCity_KG_Adapter_Registry' ) ) {
			$this->notice( 'error', 'Adapter Registry not loaded — check kg-hub bootstrap require_once.' );
			return;
		}
		$mime    = function_exists( 'mime_content_type' ) ? @mime_content_type( $f['tmp_name'] ) : '';
		$adapter = BizCity_KG_Adapter_Registry::instance()->resolve( 'pdf', (string) $mime );
		if ( ! $adapter ) {
			$this->notice( 'error', 'No adapter resolved for ext=pdf mime=' . esc_html( $mime ) );
			return;
		}
		$t0     = microtime( true );
		$result = $adapter->extract( $f['tmp_name'], [] );
		$ms     = (int) ( ( microtime( true ) - $t0 ) * 1000 );

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error',
				'<strong>Adapter returned WP_Error:</strong> '
				. '<code>' . esc_html( $result->get_error_code() ) . '</code> — '
				. esc_html( $result->get_error_message() )
				. ' &nbsp;<em>(' . $ms . ' ms)</em>'
			);
			return;
		}
		$preview = mb_substr( (string) $result['text'], 0, 600 );
		$this->notice( 'success',
			'<strong>PASS</strong> · strategy=<code>' . esc_html( $result['meta']['strategy'] ?? '?' )
			. '</code> · pages=' . (int) ( $result['meta']['page_count'] ?? 0 )
			. ' · chars=' . (int) ( $result['meta']['total_chars'] ?? 0 )
			. ' · ' . $ms . ' ms'
			. '<details style="margin-top:6px"><summary>First 600 chars</summary>'
			. '<pre style="white-space:pre-wrap;background:#fff;padding:8px;border:1px solid #ccc">'
			. esc_html( $preview ) . '</pre></details>'
		);
	}

	/* =====================================================================
	 * CHECK ORCHESTRATION
	 * ===================================================================*/

	public function run_all( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_CACHE );
			if ( is_array( $cached ) ) return $cached;
		}

		$results = [
			'generated_at' => gmdate( 'c' ),
			'sections'     => [
				// D0 (diagnostic surface) removed in Consolidation M2 (2026-06-02):
				// section was self-referencing admin_menu hook which no longer
				// exists; the canonical surface is BizCity Diagnostics page now.
				$this->section_e1_adapters(),
				$this->section_e2_pdf(),
				$this->section_e0_router(),
				$this->section_t0_entitlement(),
				$this->section_t1_ui(),
				$this->section_t2_gates(),
				$this->section_ui_err(),
			],
		];

		set_transient( self::TRANSIENT_CACHE, $results, self::CACHE_TTL );
		set_transient( self::TRANSIENT_CACHE . '_at', time(), self::CACHE_TTL );
		return $results;
	}

	private function summarize( $results ) {
		$out = [ 'pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0, 'total' => 0 ];
		foreach ( $results['sections'] as $s ) {
			foreach ( $s['rows'] as $r ) {
				$key = strtolower( $r['status'] );
				if ( isset( $out[ $key ] ) ) $out[ $key ]++;
				$out['total']++;
			}
		}
		return $out;
	}

	/* =====================================================================
	 * SECTIONS
	 * ===================================================================*/

	private function section_d0_diag() {
		return [
			'title' => 'D0 — Diagnostic Surface',
			'rows'  => [
				$this->row( 'D0.1', 'L1', class_exists( __CLASS__ ) ? 'PASS' : 'FAIL',
					'Diagnostic class loaded',
					__CLASS__ . ' is ' . ( class_exists( __CLASS__ ) ? 'loaded' : 'MISSING' ),
					'Add <code>require_once</code> to <code>modules/twinchat/bootstrap.php</code>' ),
				$this->row( 'D0.2', 'L2', has_action( 'admin_menu', [ $this, 'register_menu' ] ) ? 'PASS' : 'FAIL',
					'admin_menu hook registered',
					'has_action() = ' . (string) has_action( 'admin_menu', [ $this, 'register_menu' ] ),
					'Make sure ::instance() is called at plugin bootstrap.' ),
			],
		];
	}

	private function section_e1_adapters() {
		$rows   = [];
		$rows[] = $this->row( 'E1.1', 'L1',
			interface_exists( 'BizCity_KG_Source_Adapter' ) ? 'PASS' : 'FAIL',
			'Interface BizCity_KG_Source_Adapter exists',
			interface_exists( 'BizCity_KG_Source_Adapter' )
				? 'interface_exists=true'
				: 'NOT loaded',
			'<code>require_once BIZCITY_KG_HUB_INCLUDES . \'adapters/interface-source-adapter.php\'</code>' );

		$rows[] = $this->row( 'E1.2', 'L1',
			class_exists( 'BizCity_KG_Adapter_Registry' ) ? 'PASS' : 'FAIL',
			'Adapter Registry class exists',
			class_exists( 'BizCity_KG_Adapter_Registry' ) ? 'class_exists=true' : 'NOT loaded',
			'<code>require_once BIZCITY_KG_HUB_INCLUDES . \'adapters/class-adapter-registry.php\'</code>' );

		if ( class_exists( 'BizCity_KG_Adapter_Registry' ) ) {
			$reg     = BizCity_KG_Adapter_Registry::instance();
			$all     = $reg->all();
			$ids     = array_map( function ( $a ) { return $a::id(); }, $all );
			$rows[]  = $this->row( 'E1.3', 'L2',
				count( $all ) > 0 ? 'PASS' : 'FAIL',
				'At least one adapter registered',
				'count=' . count( $all ) . ' · ids=[' . implode( ',', $ids ) . ']',
				'Check <code>register_defaults()</code> in <code>class-adapter-registry.php</code>' );

			$pdf = $reg->resolve( 'pdf', 'application/pdf' );
			$rows[] = $this->row( 'E1.4', 'L2',
				$pdf ? 'PASS' : 'FAIL',
				'PDF adapter resolves for ext=pdf',
				$pdf ? get_class( $pdf ) : 'NULL',
				'Ensure PDF adapter file exists and class is named <code>BizCity_KG_Pdf_Adapter</code>' );

			$office = $reg->resolve( 'docx', '' );
			$rows[] = $this->row( 'E1.5', 'L2',
				$office ? 'PASS' : 'FAIL',
				'Office adapter resolves for ext=docx',
				$office ? get_class( $office ) : 'NULL',
				'Ensure <code>class-office-adapter.php</code> is loaded and registered.' );

			// E1.6 — verify all 4 office formats route through the adapter
			$cov = [];
			foreach ( [ 'docx', 'xlsx', 'pptx', 'rtf' ] as $ext ) {
				$a = $reg->resolve( $ext, '' );
				$cov[] = $ext . '=' . ( $a ? $a::id() : 'MISS' );
			}
			$missing = array_filter( $cov, function ( $c ) { return strpos( $c, 'MISS' ) !== false; } );
			$rows[] = $this->row( 'E1.6', 'L2',
				empty( $missing ) ? 'PASS' : 'FAIL',
				'All office extensions covered',
				implode( ' · ', $cov ),
				'Ensure SUPPORTED_EXTS includes docx/xlsx/pptx/rtf.' );

			// E1.7 — required PHP extension for office extraction
			$has_zip = class_exists( 'ZipArchive' );
			$rows[] = $this->row( 'E1.7', 'L1',
				$has_zip ? 'PASS' : 'FAIL',
				'ZipArchive PHP extension present',
				$has_zip ? 'available' : 'MISSING — install php-zip',
				'DOCX/XLSX/PPTX extraction requires the <code>zip</code> PHP extension.' );
		}
		return [
			'title' => 'E1 — Adapter Framework',
			'note'  => 'Per <code>PHASE-0.7-LEARNING-EXTEND.md §2.2</code>.',
			'rows'  => $rows,
		];
	}

	private function section_e2_pdf() {
		$rows = [];

		$smalot = class_exists( '\\Smalot\\PdfParser\\Parser' );
		$rows[] = $this->row( 'E2.A', 'L1',
			$smalot ? 'PASS' : 'WARN',
			'Smalot\\PdfParser available (Tier-1 strategy A)',
			$smalot ? 'class_exists=true' : 'NOT installed (will fall back to pdftotext / built-in)',
			'<code>composer require smalot/pdfparser:^2.0</code> in plugin root for best quality.' );

		$has_pdftotext = $this->check_pdftotext();
		$rows[] = $this->row( 'E2.B', 'L2',
			$has_pdftotext ? 'PASS' : 'WARN',
			'pdftotext shell binary available (Tier-1 strategy B)',
			$has_pdftotext ? 'found in PATH' : 'not on PATH (or shell_exec disabled)',
			'Install poppler-utils on host: <code>apt-get install poppler-utils</code>' );

		$built_in_ok = function_exists( 'gzuncompress' ) && function_exists( 'gzinflate' );
		$rows[] = $this->row( 'E2.C', 'L2',
			$built_in_ok ? 'PASS' : 'WARN',
			'Built-in regex extractor (Tier-1 strategy C) available',
			'gzinflate/gzuncompress=' . ( $built_in_ok ? 'yes' : 'no' ),
			'Enable PHP zlib extension to allow built-in fallback for compressed streams.' );

		$any_strategy = $smalot || $has_pdftotext || $built_in_ok;
		$rows[] = $this->row( 'E2.D', 'L3',
			$any_strategy ? 'PASS' : 'FAIL',
			'At least one Tier-1 strategy available',
			$any_strategy ? 'OK — adapter will try in order A→B→C' : 'No strategy — uploads will WP_Error',
			'See E2.A/B/C above for fix steps.' );

		// E2.E — OCR fallback status (Wave E2.SCAN shipped)
		$ocr_built      = class_exists( 'BizCity_OCR_Client' );
		$ocr_configured = $ocr_built && BizCity_OCR_Client::instance()->is_configured();
		$rows[] = $this->row( 'E2.E', 'L3',
			$ocr_built ? ( $ocr_configured ? 'PASS' : 'WARN' ) : 'SKIP',
			'OCR fallback Tier-2 wired (BizCity_OCR_Client + PDF adapter)',
			$ocr_built
				? ( $ocr_configured ? 'built + configured (Wave E2.SCAN ✓)' : 'built — gateway not configured yet' )
				: 'class not loaded — check kg-hub bootstrap.php',
			$ocr_built
				? ( $ocr_configured ? '' : 'Set <code>bizcity_llm_gateway_url</code> + <code>bizcity_llm_api_key</code> in LLM settings.' )
				: 'Add require_once for class-ocr-client.php in kg-hub bootstrap.' );

		return [
			'title' => 'E2 — PDF Adapter (PRIORITY)',
			'note'  => '3-tier strategy. <strong>Use the live probe at the bottom of this page</strong> to confirm L3.',
			'rows'  => $rows,
		];
	}

	private function section_e0_router() {
		$rows = [];

		// E0.OCR — multi-layer probe
		// L1: client class loadable
		$client_loaded = class_exists( 'BizCity_OCR_Client' );
		$rows[] = $this->row( 'E0.OCR.1', 'L1',
			$client_loaded ? 'PASS' : 'FAIL',
			'BizCity_OCR_Client class loadable',
			'class_exists',
			$client_loaded ? '' : 'kg-hub bootstrap missing clients/class-ocr-client.php require_once.'
		);

		// L2: client configured (gateway URL + API key)
		if ( $client_loaded ) {
			$configured = BizCity_OCR_Client::instance()->is_configured();
			$rows[] = $this->row( 'E0.OCR.2', 'L2',
				$configured ? 'PASS' : 'WARN',
				'OCR client configured (gateway URL + API key)',
				$configured ? 'configured' : 'missing',
				$configured ? '' : 'Set <code>bizcity_llm_gateway_url</code> + <code>bizcity_llm_api_key</code> in LLM settings.'
			);
		} else {
			$rows[] = $this->row( 'E0.OCR.2', 'L2', 'SKIP', 'OCR client configured', '—', 'Class not loaded.' );
		}

		// L3: live health probe
		if ( $client_loaded && BizCity_OCR_Client::instance()->is_configured() ) {
			$health = BizCity_OCR_Client::instance()->health();
			$ok = ! empty( $health['success'] );
			$status = isset( $health['http_status'] ) ? intval( $health['http_status'] ) : 0;
			$provider_ready = ! empty( $health['data']['provider_ready'] );
			// Severity: FAIL only for 5xx (server crash). 404 = not-deployed (WARN). 0 = loopback blocked (WARN).
			if ( $ok ) {
				$ocr3_status = 'PASS';
				$ocr3_hint   = '';
			} elseif ( $status >= 500 ) {
				$ocr3_status = 'FAIL';
				$ocr3_hint   = 'Server error (' . $status . '): ' . ( $health['error'] ?? 'check PHP error log' );
			} elseif ( $status === 404 ) {
				$ocr3_status = 'WARN';
				$ocr3_hint   = 'Endpoint returned 404 — <code>class-router-tools-rest.php</code> not yet deployed/loaded on live server. Deploy bizcity-llm-router plugin update.';
			} else {
				$ocr3_status = 'WARN';
				$ocr3_hint   = 'Connection failed (HTTP ' . $status . '): loopback requests may be blocked on this host. Error: ' . ( $health['error'] ?? 'unknown' );
			}
			$rows[] = $this->row( 'E0.OCR.3', 'L3',
				$ocr3_status,
				'/tools/ocr/health live probe',
				'HTTP ' . $status,
				$ocr3_hint
			);
			$rows[] = $this->row( 'E0.OCR.4', 'L3',
				$provider_ready ? 'PASS' : 'WARN',
				'OpenRouter API key present on gateway',
				$provider_ready ? 'ready' : 'missing',
				$provider_ready ? '' : 'Gateway has no <code>bizcity_openrouter_api_key</code> set — OCR calls will fail.'
			);
		} else {
			$rows[] = $this->row( 'E0.OCR.3', 'L3', 'SKIP', '/tools/ocr/health live probe', '—', 'Client not configured.' );
		}

		// E2.SCAN — PDF rasterizer presence
		$has_imagick = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
		$has_gs      = self::probe_ghostscript();
		$any_raster  = $has_imagick || $has_gs;
		$which       = $has_imagick ? 'Imagick' : ( $has_gs ? 'Ghostscript' : 'none' );
		$rows[] = $this->row( 'E2.SCAN.1', 'L1',
			$any_raster ? 'PASS' : 'WARN',
			'PDF rasterizer (Imagick or Ghostscript)',
			$which,
			$any_raster ? '' : 'Install Imagick PHP ext OR Ghostscript binary to enable scan-PDF OCR fallback.'
		);

		// E0.STT/VISION placeholders (future waves)
		$rows[] = $this->row( 'E0.STT',    'L1', 'SKIP', '/tools/stt endpoint',     '—', 'Pending future wave.' );
		$rows[] = $this->row( 'E0.VISION', 'L1', 'SKIP', '/tools/vision endpoint',  '—', 'Pending future wave.' );

		// E0.YT — YouTube transcriber (caption-only mode, no router endpoint required)
		$yt_loaded = class_exists( 'BizCity_Youtube_Transcriber' );
		$rows[] = $this->row( 'E0.YT', 'L1',
			$yt_loaded ? 'PASS' : 'FAIL',
			'BizCity_Youtube_Transcriber loaded (caption mode)',
			$yt_loaded ? 'class_exists=true — YouTube URL ingest enabled' : 'class missing — check kg-hub bootstrap',
			$yt_loaded ? '' : 'Add require_once for clients/class-youtube-transcriber.php in kg-hub bootstrap.' );

		return [
			'title' => 'E0 — Router /tools/* Endpoints',
			'note'  => '<code>/tools/ocr</code> shipped (Wave E0). PDF Tier-2 OCR fallback active when rasterizer present.',
			'rows'  => $rows,
		];
	}

	/** Cheap shell probe for ghostscript binary. */
	private static function probe_ghostscript() {
		if ( ! function_exists( 'shell_exec' ) ) return false;
		$disabled = explode( ',', (string) ini_get( 'disable_functions' ) );
		$disabled = array_map( 'trim', $disabled );
		if ( in_array( 'shell_exec', $disabled, true ) ) return false;
		$bin = ( PHP_OS_FAMILY === 'Windows' ) ? 'gswin64c' : 'gs';
		$cmd = ( PHP_OS_FAMILY === 'Windows' ) ? "where {$bin} 2>NUL" : "command -v {$bin} 2>/dev/null";
		$out = @shell_exec( $cmd );
		return ! empty( trim( (string) $out ) );
	}

	private function section_t0_entitlement() {
		$rows = [];

		// T0.1 — Auth class loadable
		$rows[] = $this->row( 'T0.1', 'L1',
			class_exists( 'BizCity_Router_Auth' ) ? 'PASS' : 'SKIP',
			'BizCity_Router_Auth::get_user_tier loadable',
			class_exists( 'BizCity_Router_Auth' ) ? 'class_exists=true' : 'router plugin not active here',
			'Activate <code>bizcity-llm-router</code> if Pro tier should be enforced.' );

		// T0.2 — Unified Entitlement service loadable
		$ent_loaded = class_exists( 'BizCity_Entitlement' );
		$rows[] = $this->row( 'T0.2', 'L1',
			$ent_loaded ? 'PASS' : 'FAIL',
			'BizCity_Entitlement service loadable',
			$ent_loaded ? 'class_exists=true' : 'class missing — check router includes',
			$ent_loaded ? '' : 'Add <code>require_once</code> for class-router-entitlement.php in main plugin file.' );

		// T0.3 — Plan matrix has expected tiers
		if ( $ent_loaded ) {
			$matrix = BizCity_Entitlement::plan_matrix();
			$tiers  = is_array( $matrix ) ? array_keys( $matrix ) : [];
			$has_paid = in_array( 'paid', $tiers, true );
			$rows[] = $this->row( 'T0.3', 'L1',
				$has_paid ? 'PASS' : 'WARN',
				'Plan matrix exposes paid tier with learning.* features',
				'tiers=' . implode( ',', $tiers ),
				$has_paid ? '' : 'Set site_option <code>bizcity_router_plan_matrix</code> or use defaults.' );
		} else {
			$rows[] = $this->row( 'T0.3', 'L1', 'SKIP', 'Plan matrix shape', '—', 'Entitlement class not loaded.' );
		}

		// T0.4 — Entitlement REST endpoint reachable (live probe)
		if ( $ent_loaded ) {
			$url = rest_url( 'bizcity/v1/account/entitlement/health' );
			$res = wp_remote_get( $url, [ 'timeout' => 5 ] );
			$status = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			$ok = $status === 200;
			$rows[] = $this->row( 'T0.4', 'L3',
				$ok ? 'PASS' : ( $status === 0 ? 'WARN' : 'WARN' ),
				'GET /bizcity/v1/account/entitlement/health',
				'HTTP ' . $status,
				$ok ? '' : 'Endpoint not reachable yet — likely opcache / not deployed. Reload bizcity-llm-router.' );
		} else {
			$rows[] = $this->row( 'T0.4', 'L3', 'SKIP', '/account/entitlement/health probe', '—', 'Entitlement class not loaded.' );
		}

		// T0.7 — Usage table exists
		global $wpdb;
		$table  = $wpdb->base_prefix . 'bizcity_entitlement_usage';
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		$rows[] = $this->row( 'T0.7', 'L2',
			$exists ? 'PASS' : 'WARN',
			'Usage counter table exists (' . $table . ')',
			$exists ? 'present' : 'not yet created — schema upgrade pending',
			$exists ? '' : 'Run <code>BizCity_Router_Schema::install()</code> (auto-runs on plugins_loaded after version bump to 1.5.0).' );

		return [
			'title' => 'T0 — Entitlement & Usage Log',
			'note'  => 'Per <code>PHASE-0.7-PLAN-PRO-LEARNING.md</code>.',
			'rows'  => $rows,
		];
	}

	private function section_t1_ui() {
		$base = WP_PLUGIN_DIR . '/bizcity-twin-ai/modules/twinchat/ui/src';
		$files = [
			'T1.PB' => [ '/components/PlanBadge.tsx', 'PlanBadge component' ],
			'T1.UM' => [ '/components/UpgradeModal.tsx', 'UpgradeModal component' ],
			'T1.AP' => [ '/pages/AccountPage.tsx', '3-tab AccountPage (Plan/Wallet/Usage)' ],
		];
		$rows = [];
		foreach ( $files as $task => $info ) {
			$path = $base . $info[0];
			$ok   = file_exists( $path );
			$rows[] = $this->row( $task, 'L1', $ok ? 'PASS' : 'SKIP',
				$info[1],
				$ok ? 'file present' : 'not yet — pending T1',
				'Create <code>' . esc_html( $info[0] ) . '</code> per PRO-LEARNING plan §T1.' );
		}
		return [ 'title' => 'T1 — UI Surfaces (PlanBadge, AccountPage)', 'rows' => $rows ];
	}

	private function section_t2_gates() {
		$rows = [];

		// T2.1 — verify OCR tier gate is wired in the PDF adapter source
		$adapter_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/knowledge/kg-hub/includes/adapters/class-pdf-adapter.php';
		$adapter_src  = file_exists( $adapter_file ) ? (string) file_get_contents( $adapter_file ) : '';
		$has_gate     = strpos( $adapter_src, 'tier_required' ) !== false && strpos( $adapter_src, 'pdf_ocr' ) !== false;
		$rows[] = $this->row( 'T2.1', 'L2',
			$has_gate ? 'PASS' : 'FAIL',
			'PDF adapter: scan-OCR gated behind paid tier (T2)',
			$has_gate ? 'BizCity_Router_Auth::get_user_tier() gate found' : 'Gate code missing in class-pdf-adapter.php',
			$has_gate ? '' : 'Add tier check in <code>try_ocr_fallback()</code> before OCR call.' );

		// T2.2 — verify normalize_ingest_error maps tier_required → 402
		$ctrl_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/knowledge/kg-hub/includes/class-kg-scoped-rest-controller.php';
		$ctrl_src  = file_exists( $ctrl_file ) ? (string) file_get_contents( $ctrl_file ) : '';
		$has_map   = strpos( $ctrl_src, "'tier_required'" ) !== false && strpos( $ctrl_src, '402' ) !== false;
		$rows[] = $this->row( 'T2.2', 'L3',
			$has_map ? 'PASS' : 'FAIL',
			'normalize_ingest_error maps tier_required → HTTP 402',
			$has_map ? 'code found in class-kg-scoped-rest-controller.php' : 'mapping missing',
			$has_map ? '' : 'Add <code>\'tier_required\' => 402</code> in normalize_ingest_error().' );

		return [ 'title' => 'T2 — Ingest Gates', 'rows' => $rows ];
	}

	private function section_ui_err() {
		// [2026-06-02 Johnny Chu] HOTFIX — dùng constant BIZCITY_TWINCHAT_DIR thay vì
		// WP_PLUGIN_DIR + tên folder cứng. Tên folder plugin có thể khác (symlink,
		// rename, mu-plugins) → WP_PLUGIN_DIR path không tồn tại → FAIL giả.
		$base = defined( 'BIZCITY_TWINCHAT_DIR' )
			? rtrim( BIZCITY_TWINCHAT_DIR, '/\\' )
			: WP_PLUGIN_DIR . '/bizcity-twin-ai/modules/twinchat';
		$path = $base . '/ui/src/utils/humanizeIngestError.ts';
		$ok   = file_exists( $path );
		return [
			'title' => 'UI-ERR — Human-readable error mapping',
			'rows'  => [
				$this->row( 'UI-ERR.1', 'L1', $ok ? 'PASS' : 'FAIL',
					'humanizeIngestError.ts exists',
					$ok ? $path : ( 'MISSING — checked: ' . $path ),
					'Create the file (Wave UI-ERR.1).' ),
			],
		];
	}

	/* =====================================================================
	 * HELPERS
	 * ===================================================================*/

	private function row( $task, $layer, $status, $check, $evidence = '', $fix_hint = '' ) {
		return compact( 'task', 'layer', 'status', 'check', 'evidence', 'fix_hint' );
	}

	private function notice( $type, $msg_html ) {
		$cls = $type === 'success' ? 'notice-success' : ( $type === 'error' ? 'notice-error' : 'notice-warning' );
		echo '<div class="notice ' . esc_attr( $cls ) . '" style="margin:12px 0;padding:8px 12px">' . wp_kses_post( $msg_html ) . '</div>';
	}

	private function check_pdftotext() {
		if ( ! function_exists( 'shell_exec' ) ) return false;
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'shell_exec', $disabled, true ) ) return false;
		$cmd = ( PHP_OS_FAMILY === 'Windows' ) ? 'where pdftotext 2>NUL' : 'command -v pdftotext 2>/dev/null';
		$out = @shell_exec( $cmd );
		return ! empty( trim( (string) $out ) );
	}
}

// Bootstrap
BizCity_Pro_Learning_Diagnostic::instance();
