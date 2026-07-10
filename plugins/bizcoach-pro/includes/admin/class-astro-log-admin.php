<?php
/**
 * BizCoach Pro — Astro Log Reader (Admin Page)
 *
 * Đọc daily JSONL logs tại:
 *   {upload_basedir}/bizcity-channel-logs/astro/YYYY-MM-DD.jsonl
 *
 * Thêm submenu dưới "BizCoach Pro" → "📋 Astro Logs".
 * URL: /wp-admin/admin.php?page=bcpro-astro-logs
 *
 * Features:
 *   - Chọn ngày (dropdown các ngày có file)
 *   - Filter theo event / level / free text search
 *   - Bảng hiển thị rows với color-coded level badges
 *   - AJAX live refresh + auto-scroll to bottom
 *   - Copy log (JSON raw) to clipboard
 *
 * PHP 7.4 compatible.
 *
 * [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — Astro Log Reader admin page
 *
 * @package BizCoach_Pro
 * @since   0.4.3
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Astro_Log_Admin', false ) ) { return; }

class BizCoach_Pro_Astro_Log_Admin {

	const PAGE_SLUG      = 'bcpro-astro-logs';
	const PARENT_SLUG    = 'bccm_user_profiles';
	const AJAX_ACTION    = 'bcpro_astro_logs_fetch';
	const NONCE_KEY      = 'bcpro_astro_logs_nonce';

	// ──────────────────────────────────────────────────────────────────
	// Boot
	// ──────────────────────────────────────────────────────────────────

	public static function init() {
		// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — register menu + AJAX
		add_action( 'admin_menu',                          array( __CLASS__, 'register_menu' ), 35 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION,        array( __CLASS__, 'ajax_fetch' ) );
	}

	// ──────────────────────────────────────────────────────────────────
	// Menu registration
	// ──────────────────────────────────────────────────────────────────

	public static function register_menu() {
		add_submenu_page(
			self::PARENT_SLUG,
			'📋 Astro Logs',
			'📋 Astro Logs',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			38
		);
	}

	// ──────────────────────────────────────────────────────────────────
	// AJAX handler — returns rows JSON
	// ──────────────────────────────────────────────────────────────────

	public static function ajax_fetch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'msg' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE_KEY, '_nonce' );

		$date    = sanitize_text_field( wp_unslash( $_POST['date']   ?? '' ) );
		$level   = sanitize_text_field( wp_unslash( $_POST['level']  ?? '' ) );
		$event   = sanitize_text_field( wp_unslash( $_POST['event']  ?? '' ) );
		$q       = sanitize_text_field( wp_unslash( $_POST['q']      ?? '' ) );
		$limit   = min( 2000, max( 10, (int) ( $_POST['limit'] ?? 500 ) ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		}

		$rows  = self::read_rows( $date, $level, $event, $q, $limit );
		$dates = self::available_dates();

		wp_send_json_success( array(
			'date'  => $date,
			'dates' => $dates,
			'count' => count( $rows ),
			'rows'  => $rows,
		) );
	}

	// ──────────────────────────────────────────────────────────────────
	// Admin page render
	// ──────────────────────────────────────────────────────────────────

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden' );
		}

		$nonce   = wp_create_nonce( self::NONCE_KEY );
		$ajax    = admin_url( 'admin-ajax.php' );
		$today   = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$dates   = self::available_dates();
		$log_dir = self::log_dir_display();
		?>
		<div class="wrap">
			<h1>📋 BizCoach Pro — Astro API Logs</h1>
			<p class="description">
				Daily JSONL logs cho mọi request từ BizCoach Pro gọi về hub LLM Router (FAA2 natal, transit_range, compare).<br>
				File: <code><?php echo esc_html( $log_dir ); ?></code>
			</p>

			<!-- Toolbar -->
			<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:16px 0 12px">
				<label>
					<strong>Ngày:</strong>
					<select id="bcpro-alog-date" style="margin-left:4px">
						<?php if ( empty( $dates ) ): ?>
							<option value="<?php echo esc_attr( $today ); ?>"><?php echo esc_html( $today ); ?> (hôm nay)</option>
						<?php else: ?>
							<?php foreach ( $dates as $d ): ?>
								<option value="<?php echo esc_attr( $d ); ?>"<?php selected( $d, $today ); ?>>
									<?php echo esc_html( $d ); ?><?php echo ( $d === $today ) ? ' (hôm nay)' : ''; ?>
								</option>
							<?php endforeach; ?>
						<?php endif; ?>
					</select>
				</label>

				<label>
					Level:
					<select id="bcpro-alog-level" style="margin-left:4px;width:90px">
						<option value="">— tất cả —</option>
						<option value="info">info</option>
						<option value="warn">warn</option>
						<option value="error">error</option>
						<option value="debug">debug</option>
					</select>
				</label>

				<label>
					Event:
					<select id="bcpro-alog-event" style="margin-left:4px;width:190px">
						<option value="">— tất cả —</option>
						<option value="transit_range_request">transit_range_request</option>
						<option value="transit_range_ok">transit_range_ok</option>
						<option value="transit_range_failed">transit_range_failed</option>
						<option value="natal_ok">natal_ok</option>
						<option value="natal_failed">natal_failed</option>
						<option value="transit_snap_ok">transit_snap_ok</option>
						<option value="compare_natal">compare_natal</option>
					</select>
				</label>

				<label>
					Search:
					<input type="text" id="bcpro-alog-q" placeholder="free text…" style="width:150px;margin-left:4px" />
				</label>

				<label>
					Limit:
					<select id="bcpro-alog-limit" style="margin-left:4px;width:80px">
						<option value="100">100</option>
						<option value="500" selected>500</option>
						<option value="1000">1000</option>
						<option value="2000">2000</option>
					</select>
				</label>

				<button type="button" class="button button-primary" id="bcpro-alog-run">🔄 Tải logs</button>
				<button type="button" class="button" id="bcpro-alog-copy">📋 Copy JSON</button>
				<span id="bcpro-alog-status" style="color:#666;font-size:12px"></span>
			</div>

			<!-- Stats bar -->
			<div id="bcpro-alog-stats" style="font-size:12px;color:#555;margin-bottom:6px;display:none"></div>

			<!-- Table -->
			<div style="overflow-x:auto">
				<table class="widefat striped" id="bcpro-alog-table" style="font-size:12px;min-width:900px">
					<thead>
						<tr>
							<th style="width:140px">Timestamp</th>
							<th style="width:55px">Level</th>
							<th style="width:190px">Event</th>
							<th style="width:80px">Source</th>
							<th style="width:70px">Days</th>
							<th style="width:75px">Days ret.</th>
							<th style="width:75px">Latency</th>
							<th style="width:65px">API calls</th>
							<th style="width:65px">Cache hits</th>
							<th>Details</th>
						</tr>
					</thead>
					<tbody id="bcpro-alog-tbody">
						<tr><td colspan="10" style="color:#999;text-align:center;padding:20px">Nhấn "Tải logs" để xem dữ liệu.</td></tr>
					</tbody>
				</table>
			</div>

			<!-- Raw JSON viewer (hidden) -->
			<div id="bcpro-alog-raw-wrap" style="display:none;margin-top:16px">
				<h3>Raw JSON row</h3>
				<pre id="bcpro-alog-raw" style="background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:4px;font-size:11px;max-height:400px;overflow:auto;white-space:pre-wrap;word-break:break-all"></pre>
				<button type="button" class="button" id="bcpro-alog-raw-close">✕ Đóng</button>
			</div>
		</div>

		<script>
		(function () {
			var ajax    = <?php echo wp_json_encode( $ajax ); ?>;
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
			var action  = <?php echo wp_json_encode( self::AJAX_ACTION ); ?>;
			var allRows = [];

			function $ ( id ) { return document.getElementById( id ); }

			function levelBadge( level ) {
				var color = level === 'error' ? '#d63638'
				          : level === 'warn'  ? '#996800'
				          : level === 'debug' ? '#6c757d'
				          : '#0073aa';
				return '<span style="background:' + color + ';color:#fff;padding:1px 5px;border-radius:3px;font-size:10px;font-weight:bold">'
					+ level.toUpperCase() + '</span>';
			}

			function esc( s ) {
				return String( s == null ? '' : s )
					.replace( /&/g,'&amp;' ).replace( /</g,'&lt;' ).replace( />/g,'&gt;' );
			}

			function renderRows( rows ) {
				var tbody = $('bcpro-alog-tbody');
				if ( !rows || rows.length === 0 ) {
					tbody.innerHTML = '<tr><td colspan="10" style="color:#999;text-align:center;padding:20px">Không có rows nào khớp.</td></tr>';
					return;
				}
				var html = '';
				rows.forEach( function( r, idx ) {
					var ctx = r.ctx || {};
					var bg  = r.level === 'error' ? '#fff5f5' : ( r.level === 'warn' ? '#fffbe6' : '' );
					html += '<tr style="' + ( bg ? 'background:' + bg : '' ) + '" data-idx="' + idx + '">';
					html += '<td style="white-space:nowrap;font-family:monospace;font-size:11px">' + esc( r.ts ) + '</td>';
					html += '<td>' + levelBadge( r.level || 'info' ) + '</td>';
					html += '<td style="font-family:monospace;font-size:11px">' + esc( r.event ) + '</td>';
					html += '<td>' + esc( ctx.source || ctx.provider || '' ) + '</td>';
					html += '<td style="text-align:center">' + ( ctx.num_days != null ? ctx.num_days : '—' ) + '</td>';
					html += '<td style="text-align:center">' + ( ctx.days_returned != null ? ctx.days_returned : '—' ) + '</td>';
					html += '<td style="text-align:right">' + ( ctx.latency_ms != null ? ctx.latency_ms + 'ms' : '—' ) + '</td>';
					html += '<td style="text-align:center">' + ( ctx.api_calls != null ? ctx.api_calls : '—' ) + '</td>';
					html += '<td style="text-align:center">' + ( ctx.cache_hits != null ? ctx.cache_hits : '—' ) + '</td>';
					// Details: error / planet count / start
					var det = '';
					if ( ctx.error )         det += '<span style="color:#d63638">❌ ' + esc( ctx.error ) + '</span> ';
					if ( ctx.natal_planets )  det += '🌟' + ctx.natal_planets + ' natal  ';
					if ( ctx.start_date )     det += '📅' + esc( ctx.start_date ) + '  ';
					if ( ctx.planet_count )   det += '🪐' + ctx.planet_count + ' planets  ';
					if ( ctx.sign_mismatches != null ) det += 'Δ signs:' + ctx.sign_mismatches + '  ';
					html += '<td>' + det + '<button type="button" class="button button-small bcpro-alog-detail-btn" data-idx="' + idx + '">JSON</button></td>';
					html += '</tr>';
				} );
				tbody.innerHTML = html;

				// Bind detail buttons
				Array.prototype.forEach.call(
					document.querySelectorAll('.bcpro-alog-detail-btn'),
					function( btn ) {
						btn.addEventListener( 'click', function() {
							var i = parseInt( btn.getAttribute('data-idx'), 10 );
							$('bcpro-alog-raw').textContent = JSON.stringify( rows[i], null, 2 );
							$('bcpro-alog-raw-wrap').style.display = '';
							$('bcpro-alog-raw-wrap').scrollIntoView({ behavior: 'smooth' });
						} );
					}
				);
			}

			function buildStats( rows ) {
				var counts = {};
				rows.forEach( function(r) {
					var ev = r.event || 'unknown';
					counts[ev] = (counts[ev] || 0) + 1;
				} );
				var parts = Object.keys(counts).map( function(k) { return k + ':' + counts[k]; } );
				return '📊 ' + rows.length + ' rows  |  ' + parts.join('  ·  ');
			}

			function fetch() {
				var st = $('bcpro-alog-status');
				st.textContent = '⏳ đang tải…';
				$('bcpro-alog-stats').style.display = 'none';

				var body = new URLSearchParams();
				body.append( 'action', action );
				body.append( '_nonce', nonce );
				body.append( 'date',   $('bcpro-alog-date').value );
				body.append( 'level',  $('bcpro-alog-level').value );
				body.append( 'event',  $('bcpro-alog-event').value );
				body.append( 'q',      $('bcpro-alog-q').value );
				body.append( 'limit',  $('bcpro-alog-limit').value );

				window.fetch( ajax, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function(r) { return r.json(); } )
					.then( function(j) {
						if ( !j || !j.success ) {
							st.textContent = '❌ AJAX error: ' + JSON.stringify(j);
							return;
						}
						allRows = j.data.rows || [];
						// Refresh date dropdown if new dates available
						var dateEl = $('bcpro-alog-date');
						var curDate = dateEl.value;
						if ( j.data.dates && j.data.dates.length > 0 ) {
							var today = <?php echo wp_json_encode( $today ); ?>;
							dateEl.innerHTML = j.data.dates.map( function(d) {
								return '<option value="' + d + '"' + (d===curDate?' selected':'') + '>' + d + (d===today?' (hôm nay)':'') + '</option>';
							}).join('');
						}
						renderRows( allRows );
						var stats = buildStats( allRows );
						$('bcpro-alog-stats').textContent = stats;
						$('bcpro-alog-stats').style.display = '';
						st.textContent = 'done · ' + j.data.date;
					} )
					.catch( function(e) {
						st.textContent = '❌ network: ' + e.message;
					} );
			}

			$('bcpro-alog-run').addEventListener( 'click', fetch );

			// Auto-load today on page open
			fetch();

			// Copy raw JSON of all rows
			$('bcpro-alog-copy').addEventListener( 'click', function() {
				var json = JSON.stringify( allRows, null, 2 );
				navigator.clipboard.writeText( json )
					.then( function() { $('bcpro-alog-status').textContent = '📋 Đã copy JSON vào clipboard'; } )
					.catch( function() {
						var ta = document.createElement('textarea');
						ta.value = json;
						document.body.appendChild(ta);
						ta.select();
						document.execCommand('copy');
						document.body.removeChild(ta);
						$('bcpro-alog-status').textContent = '📋 Đã copy';
					} );
			} );

			$('bcpro-alog-raw-close').addEventListener( 'click', function() {
				$('bcpro-alog-raw-wrap').style.display = 'none';
			} );
		})();
		</script>
		<?php
	}

	// ──────────────────────────────────────────────────────────────────
	// Internal helpers
	// ──────────────────────────────────────────────────────────────────

	/**
	 * Read log rows for a given date with optional filters (newest-first).
	 */
	private static function read_rows( $date, $level = '', $event = '', $q = '', $limit = 500 ) {
		if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
			$all = BizCity_Channel_File_Logger::read( 'astro', $date, 0, $level );
		} else {
			$all = self::fallback_read( $date );
		}

		$out = array();
		foreach ( $all as $row ) {
			if ( $level !== '' && ( $row['level'] ?? '' ) !== $level ) { continue; }
			if ( $event !== '' && ( $row['event'] ?? '' ) !== $event ) { continue; }
			if ( $q !== '' && stripos( wp_json_encode( $row ), $q ) === false ) { continue; }
			$out[] = $row;
			if ( $limit > 0 && count( $out ) >= $limit ) { break; }
		}
		return $out;
	}

	/**
	 * List available log dates (most recent first).
	 */
	private static function available_dates() {
		if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
			return BizCity_Channel_File_Logger::list_dates( 'astro', 60 );
		}
		return self::fallback_list_dates();
	}

	/** Fallback: read directly from disk without BizCity_Channel_File_Logger. */
	private static function fallback_read( $date ) {
		$dir = self::raw_log_dir();
		if ( $dir === '' ) { return array(); }
		$file = $dir . DIRECTORY_SEPARATOR . $date . '.jsonl';
		if ( ! file_exists( $file ) ) { return array(); }
		$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! is_array( $lines ) ) { return array(); }
		$rows = array();
		foreach ( array_reverse( $lines ) as $raw ) {
			$obj = json_decode( $raw, true );
			if ( is_array( $obj ) ) { $rows[] = $obj; }
		}
		return $rows;
	}

	private static function fallback_list_dates() {
		$dir = self::raw_log_dir();
		if ( $dir === '' ) { return array(); }
		$files = glob( $dir . DIRECTORY_SEPARATOR . '*.jsonl' );
		if ( ! is_array( $files ) ) { return array(); }
		$dates = array();
		foreach ( $files as $f ) { $dates[] = basename( $f, '.jsonl' ); }
		rsort( $dates );
		return array_slice( $dates, 0, 60 );
	}

	private static function raw_log_dir() {
		$upload = wp_upload_dir();
		$base   = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
		if ( $base === '' ) { return ''; }
		return $base . DIRECTORY_SEPARATOR . 'bizcity-channel-logs' . DIRECTORY_SEPARATOR . 'astro';
	}

	/** Display path for admin (relative from WP root). */
	private static function log_dir_display() {
		$dir = self::raw_log_dir();
		if ( $dir === '' ) { return 'n/a'; }
		$abspath = defined( 'ABSPATH' ) ? rtrim( ABSPATH, '/\\' ) : '';
		if ( $abspath !== '' && strpos( $dir, $abspath ) === 0 ) {
			return ltrim( str_replace( $abspath, '', $dir ), '/\\' ) . '/YYYY-MM-DD.jsonl';
		}
		return $dir . '/YYYY-MM-DD.jsonl';
	}
}
