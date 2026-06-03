<?php
/**
 * Diagnostics Admin Page — Tools → BizCity Diagnostics.
 *
 * Renders a table inventory grouped by owner, highlighting missing rows and
 * showing approximate row count + on-disk size per registered table.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-05-20
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Diagnostics_Admin_Page {

	private static $instance = null;

	/** @var array|null Set by render() when the Auto-Fix-All handler runs (Phase 0.41 L9.b+). */
	private $auto_fix_all_result = null;

	public static function instance(): self {
		return self::$instance ?: ( self::$instance = new self() );
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register' ] );
	}

	public function register(): void {
		add_management_page(
			__( 'BizCity Diagnostics', 'bizcity-twin-ai' ),
			__( 'BizCity Diagnostics', 'bizcity-twin-ai' ),
			'manage_options',
			'bizcity-diagnostics',
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bizcity-twin-ai' ) );
		}

		// ── Per-row "🔧 Fix" / "🔧 Repair" action handler ───────────────
		// URL shape: ?page=bizcity-diagnostics&bizcity_run_installer=<id>&_wpnonce=...
		$run_one_result = null;
		if ( isset( $_GET['bizcity_run_installer'] ) ) {
			$req_id   = sanitize_key( (string) $_GET['bizcity_run_installer'] );
			$nonce_ok = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'bizcity_run_installer_' . $req_id );
			if ( $nonce_ok && class_exists( 'BizCity_Site_Provisioner' ) ) {
				$run_one_result = BizCity_Site_Provisioner::run_one( $req_id, true );
				// Flush memos so the freshly created/altered tables show up immediately.
				BizCity_Diagnostics_Table_Registry::flush();
				if ( class_exists( 'BizCity_Diagnostics_Column_Inspector' ) ) {
					BizCity_Diagnostics_Column_Inspector::flush();
				}
				if ( class_exists( 'BizCity_Diagnostics_Installer_Resolver' ) ) {
					BizCity_Diagnostics_Installer_Resolver::flush();
				}
			}
		}

		// ── Phase 0.41 L9.b T10 — Auto-create from JSON changelog ───────
		// URL shape: ?page=bizcity-diagnostics&bizcity_auto_create=<suffix>&_wpnonce=...
		$auto_create_result = null;
		if ( isset( $_GET['bizcity_auto_create'] ) && class_exists( 'BizCity_Diagnostics_Auto_Create' ) ) {
			$suffix   = sanitize_key( (string) $_GET['bizcity_auto_create'] );
			$nonce_ok = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'bizcity_auto_create_' . $suffix );
			if ( $nonce_ok && $suffix ) {
				$auto_create_result = BizCity_Diagnostics_Auto_Create::run( $suffix );
				BizCity_Diagnostics_Table_Registry::flush();
				if ( class_exists( 'BizCity_Diagnostics_Column_Inspector' ) ) {
					BizCity_Diagnostics_Column_Inspector::flush();
				}
			}
		}

		// ── Auto-drop orphan tables (throttled to 1×/hour per blog) ──────
		// Force=true when ?bzdiag_force_clean=1 to bypass throttle on demand.
		$force = isset( $_GET['bzdiag_force_clean'] ) && $_GET['bzdiag_force_clean'] === '1';
		$cleanup_result = BizCity_Diagnostics_Orphan_Cleaner::auto_drop( $force );

		// ── Phase 0.41 L9.a T4 — Smoke probe runner (server-rendered) ────
		// URL shapes:
		//   ?page=bizcity-diagnostics&bizcity_run_probe=<id>&_wpnonce=...
		//   ?page=bizcity-diagnostics&bizcity_run_all_probes=1&_wpnonce=...
		$smoke_results = [];
		$smoke_aggregate = null;
		if ( class_exists( 'BizCity_Diagnostics_Smoke_Runner' ) ) {
			if ( isset( $_GET['bizcity_run_probe'] ) ) {
				// sanitize_key() strips dots — use preg-whitelist instead so probe
				// ids like 'channel-gateway.rest' / 'web.search.ping' work correctly.
				$probe_id = preg_replace( '/[^a-z0-9_\-\.]/', '', strtolower( (string) $_GET['bizcity_run_probe'] ) );
				$probe_id = substr( $probe_id, 0, 64 ); // length-cap
				$nonce_ok = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'bizcity_run_probe_' . $probe_id );
				if ( $nonce_ok && $probe_id ) {
					$smoke_results[ $probe_id ] = BizCity_Diagnostics_Smoke_Runner::run_probe( $probe_id );
				}
			}
			if ( isset( $_GET['bizcity_run_all_probes'] ) ) {
				$nonce_ok = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'bizcity_run_all_probes' );
				if ( $nonce_ok ) {
					$smoke_aggregate = BizCity_Diagnostics_Smoke_Runner::run_all();
					foreach ( $smoke_aggregate['results'] as $r ) {
						if ( ! empty( $r['id'] ) ) {
							$smoke_results[ $r['id'] ] = $r;
						}
					}
				}
			}
			// Phase 0.41 L9.b+ — Auto-Fix-All sweep.
			if ( isset( $_GET['bizcity_auto_fix_all'] ) ) {
				$nonce_ok = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'bizcity_auto_fix_all' );
				if ( $nonce_ok ) {
					$this->auto_fix_all_result = BizCity_Diagnostics_Smoke_Runner::auto_fix_all();
				}
			}
		}

		$summary = BizCity_Diagnostics_Table_Inspector::summary();
		$rows    = BizCity_Diagnostics_Table_Inspector::inspect_all();

		// Phase 0.41 L9.b — map suffix => JSON-declared table (for "Since" + Auto-create button).
		$json_tables = class_exists( 'BizCity_Diagnostics_Changelog_Loader' )
			? BizCity_Diagnostics_Changelog_Loader::tables()
			: [];

		// Group rows for display.
		$by_group = [];
		foreach ( $rows as $r ) {
			$by_group[ $r['group'] ][] = $r;
		}
		ksort( $by_group );

		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		// Build smoke-test action URLs once — used both in the sticky bar and the smoke section.
		$smoke_page     = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'bizcity-diagnostics';
		$smoke_run_all_url = class_exists( 'BizCity_Diagnostics_Smoke_Runner' ) ? wp_nonce_url(
			add_query_arg( [ 'page' => $smoke_page, 'bizcity_run_all_probes' => '1' ], admin_url( 'tools.php' ) ) . '#smoke-test',
			'bizcity_run_all_probes'
		) : '#';
		$smoke_auto_fix_url = class_exists( 'BizCity_Diagnostics_Smoke_Runner' ) ? wp_nonce_url(
			add_query_arg( [ 'page' => $smoke_page, 'bizcity_auto_fix_all' => '1' ], admin_url( 'tools.php' ) ) . '#smoke-test',
			'bizcity_auto_fix_all'
		) : '#';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BizCity Diagnostics — Table Inventory', 'bizcity-twin-ai' ); ?></h1>

			<?php if ( class_exists( 'BizCity_Diagnostics_Smoke_Runner' ) ) :
				// Aggregate counts for badge display in the sticky bar.
				$bar_pass = 0; $bar_fail = 0; $bar_skip = 0;
				foreach ( $smoke_results as $sr ) {
					$ss = $sr['status'] ?? '';
					if ( $ss === 'pass' ) { $bar_pass++; }
					elseif ( $ss === 'skipped' ) { $bar_skip++; }
					else { $bar_fail++; }
				}
			?>
			<div style="position:sticky;top:32px;z-index:99;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:10px 14px;margin:8px 0 16px;display:flex;align-items:center;gap:10px;box-shadow:0 1px 4px rgba(0,0,0,.08)">
				<strong style="margin-right:4px;font-size:13px">⚡ Smoke Test</strong>
				<a class="button button-primary button-small" href="<?php echo esc_url( $smoke_run_all_url ); ?>"
				   onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Chạy tất cả probes trong 30s budget?', 'bizcity-twin-ai' ) ) ); ?>);">
					▶ <?php esc_html_e( 'Run all probes', 'bizcity-twin-ai' ); ?>
				</a>
				<a class="button button-small" href="<?php echo esc_url( $smoke_auto_fix_url ); ?>"
				   style="background:#fff4e5;border-color:#b26a00;color:#b26a00"
				   onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Auto-Fix-All: chạy tất cả installer + Auto-Create cho mọi bảng missing/drift. Chỉ thao tác additive (CREATE TABLE / ADD COLUMN). Tiếp tục?', 'bizcity-twin-ai' ) ) ); ?>);">
					🔧 <?php esc_html_e( 'Auto-fix all', 'bizcity-twin-ai' ); ?>
				</a>
				<?php if ( ! empty( $this->auto_fix_all_result ) ) :
					$afa = $this->auto_fix_all_result;
				?>
					<span style="color:#00674e;font-size:12px">
						✓ <?php echo esc_html( sprintf( __( 'missing %d → %d · %dms', 'bizcity-twin-ai' ), (int) $afa['before'], (int) $afa['after'], (int) $afa['took_ms'] ) ); ?>
					</span>
				<?php endif; ?>
				<?php if ( $smoke_aggregate ) : ?>
					<span style="font-size:12px;color:#666;margin-left:4px">
						<span style="color:#00674e">✓ <?php echo (int) $bar_pass; ?></span> ·
						<span style="color:#b32d2e">✗ <?php echo (int) $bar_fail; ?></span> ·
						<span><?php echo (int) $bar_skip; ?> skipped</span>
						· <?php echo (int) $smoke_aggregate['duration_ms']; ?>ms
					</span>
				<?php endif; ?>
				<a href="#smoke-test" style="margin-left:auto;font-size:12px;color:#666">↓ <?php esc_html_e( 'xem kết quả', 'bizcity-twin-ai' ); ?></a>
			</div>
			<?php endif; ?>

			<p class="description">
				<?php
				printf(
					/* translators: 1: version, 2: blog id */
					esc_html__( 'Phase 0.41 · v%1$s · blog_id=%2$d · prefix=%3$s', 'bizcity-twin-ai' ),
					esc_html( BIZCITY_DIAGNOSTICS_VERSION ),
					$blog_id,
					'<code>' . esc_html( $GLOBALS['wpdb']->prefix ) . '</code>'
				);
				?>
			</p>

			<div style="display:flex;gap:16px;margin:12px 0">
				<div class="card" style="padding:12px 16px;flex:1">
					<strong><?php esc_html_e( 'Tổng quan', 'bizcity-twin-ai' ); ?></strong>
					<p style="margin:4px 0">
						<?php echo (int) $summary['present']; ?> / <?php echo (int) $summary['total']; ?> <?php esc_html_e( 'bảng tồn tại', 'bizcity-twin-ai' ); ?>.
						<br><?php esc_html_e( 'Thiếu (critical):', 'bizcity-twin-ai' ); ?>
						<span style="color:<?php echo $summary['critical_missing'] ? '#b32d2e' : '#00674e'; ?>;font-weight:bold">
							<?php echo (int) $summary['critical_missing']; ?>
						</span>
					</p>
					<p style="margin:4px 0">
						<?php esc_html_e( 'Tổng số bản ghi (xấp xỉ):', 'bizcity-twin-ai' ); ?>
						<strong><?php echo number_format_i18n( $summary['rows_total'] ); ?></strong>
						· <?php esc_html_e( 'Dung lượng:', 'bizcity-twin-ai' ); ?>
						<strong><?php echo esc_html( size_format( $summary['size_total'], 2 ) ); ?></strong>
					</p>
				</div>
			</div>

			<?php if ( $run_one_result ) :
				$ok = ( $run_one_result['action'] ?? '' ) !== 'error';
			?>
				<div class="notice notice-<?php echo $ok ? 'success' : 'error'; ?> inline" style="margin:12px 0">
					<p style="margin:8px 0">
						<strong><?php echo $ok ? '✓' : '✗'; ?> Installer <code><?php echo esc_html( $run_one_result['id'] ); ?></code> (<?php echo esc_html( $run_one_result['label'] ); ?>) — <?php echo esc_html( $run_one_result['action'] ); ?></strong>
						· <?php echo (int) $run_one_result['took_ms']; ?>ms
						<?php if ( ! empty( $run_one_result['ver_before'] ) || ! empty( $run_one_result['ver_after'] ) ) : ?>
							· ver <code><?php echo esc_html( (string) $run_one_result['ver_before'] ); ?></code> → <code><?php echo esc_html( (string) $run_one_result['ver_after'] ); ?></code>
						<?php endif; ?>
						<?php if ( ! empty( $run_one_result['detail'] ) ) : ?>
							<br><span style="color:#b32d2e"><?php echo esc_html( $run_one_result['detail'] ); ?></span>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $auto_create_result ) :
				$ok = ! empty( $auto_create_result['ok'] );
			?>
				<div class="notice notice-<?php echo $ok ? 'success' : 'error'; ?> inline" style="margin:12px 0">
					<p style="margin:8px 0">
						<strong><?php echo $ok ? '✓' : '✗'; ?> Auto-create <code><?php echo esc_html( $auto_create_result['table'] ); ?></code> — <?php echo esc_html( $auto_create_result['action'] ); ?></strong>
						· <?php echo (int) $auto_create_result['took_ms']; ?>ms
						· <?php echo count( $auto_create_result['statements'] ); ?> statement(s)
					</p>
					<?php if ( ! empty( $auto_create_result['statements'] ) ) : ?>
						<details style="margin:0 0 8px 8px"><summary style="cursor:pointer;color:#666">SQL</summary>
							<pre style="font-size:11px;background:#f6f7f7;padding:6px;overflow:auto;max-height:200px"><?php echo esc_html( implode( ";\n", $auto_create_result['statements'] ) ); ?>;</pre>
						</details>
					<?php endif; ?>
					<?php if ( ! empty( $auto_create_result['errors'] ) ) : ?>
						<p style="color:#b32d2e;margin:4px 0 8px 8px"><?php echo esc_html( implode( ' | ', $auto_create_result['errors'] ) ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php foreach ( $by_group as $group => $items ) : ?>
				<h2 style="margin-top:24px">
					<?php echo esc_html( $group ); ?>
					<span style="font-size:12px;font-weight:normal;color:#666">
						(<?php echo count( $items ); ?>)
					</span>
				</h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:22%"><?php esc_html_e( 'Table', 'bizcity-twin-ai' ); ?></th>
							<th><?php esc_html_e( 'Owner', 'bizcity-twin-ai' ); ?></th>
							<th><?php esc_html_e( 'Class', 'bizcity-twin-ai' ); ?></th>
							<th><?php esc_html_e( 'Status', 'bizcity-twin-ai' ); ?></th>
							<th><?php esc_html_e( 'Columns', 'bizcity-twin-ai' ); ?></th>
							<th style="text-align:right"><?php esc_html_e( 'Rows', 'bizcity-twin-ai' ); ?></th>
							<th style="text-align:right"><?php esc_html_e( 'Size', 'bizcity-twin-ai' ); ?></th>
							<th><?php esc_html_e( 'Engine', 'bizcity-twin-ai' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'bizcity-twin-ai' ); ?></th>
							<th><?php esc_html_e( 'Notes', 'bizcity-twin-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $items as $r ) :
						$is_orphan_hint = ! empty( $r['notes'] ) && stripos( $r['notes'], 'ORPHAN' ) !== false;
						$col_diff       = class_exists( 'BizCity_Diagnostics_Column_Inspector' )
							? BizCity_Diagnostics_Column_Inspector::diff( $r )
							: [ 'status' => 'no_schema', 'actual' => [], 'expected' => [], 'missing' => [], 'extra' => [] ];
						$installer_id   = class_exists( 'BizCity_Diagnostics_Installer_Resolver' )
							? BizCity_Diagnostics_Installer_Resolver::for_row( $r )
							: null;
						$needs_fix      = ! $r['exists'];
						$needs_repair   = $r['exists'] && $col_diff['status'] === 'drift';
						$row_bg         = '';
						if ( $is_orphan_hint ) {
							$row_bg = 'background:#fff8e1';
						} elseif ( $needs_repair ) {
							$row_bg = 'background:#fff3e0';
						}
					?>
						<tr<?php echo $row_bg ? ' style="' . esc_attr( $row_bg ) . '"' : ''; ?>>
							<td>
								<code><?php echo esc_html( $r['physical'] ); ?></code>
								<?php if ( $r['critical'] ) : ?>
									<span title="critical" style="color:#b32d2e">●</span>
								<?php endif; ?>
								<?php if ( isset( $json_tables[ $r['name'] ] ) && ! empty( $json_tables[ $r['name'] ]['since'] ) ) : ?>
									<br><span style="font-size:10px;color:#0a4b78" title="JSON changelog">since v<?php echo esc_html( $json_tables[ $r['name'] ]['since'] ); ?></span>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $r['owner'] ); ?></code></td>
							<td>
								<?php if ( ! empty( $r['class'] ) ) : ?>
									<code style="font-size:11px"><?php echo esc_html( $r['class'] ); ?></code>
								<?php else : ?>
									<span style="color:#999">—</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $r['exists'] ) : ?>
									<span style="color:#00674e">✓ OK</span>
								<?php else : ?>
									<span style="color:#b32d2e;font-weight:bold">✗ MISSING</span>
								<?php endif; ?>
							</td>
							<td style="font-size:11px">
								<?php
								switch ( $col_diff['status'] ) {
									case 'no_table':
										echo '<span style="color:#999">—</span>';
										break;
									case 'no_schema':
										if ( $col_diff['actual'] ) {
											echo '<span style="color:#666" title="' . esc_attr( implode( ', ', $col_diff['actual'] ) ) . '">' . count( $col_diff['actual'] ) . ' cols</span>';
										} else {
											echo '<span style="color:#999">—</span>';
										}
										break;
									case 'ok':
										echo '<span style="color:#00674e" title="' . esc_attr( implode( ', ', $col_diff['actual'] ) ) . '">✓ ' . count( $col_diff['expected'] ) . ' cols</span>';
										if ( $col_diff['extra'] ) {
											echo ' <span style="color:#888" title="' . esc_attr( implode( ', ', $col_diff['extra'] ) ) . '">+' . count( $col_diff['extra'] ) . ' extra</span>';
										}
										break;
									case 'drift':
										echo '<span style="color:#b26a00;font-weight:bold" title="missing: ' . esc_attr( implode( ', ', $col_diff['missing'] ) ) . '">⚠ drift ' . count( $col_diff['missing'] ) . '</span>';
										echo '<br><span style="color:#b26a00">missing: ' . esc_html( implode( ', ', $col_diff['missing'] ) ) . '</span>';
										break;
								}
								?>
							</td>
							<td style="text-align:right"><?php echo $r['exists'] ? number_format_i18n( $r['rows'] ) : '—'; ?></td>
							<td style="text-align:right"><?php echo esc_html( $r['size_human'] ); ?></td>
							<td><?php echo esc_html( $r['engine'] ); ?></td>
							<td style="font-size:11px">
								<?php if ( $needs_fix || $needs_repair ) :
									if ( $installer_id ) :
										$action_url = wp_nonce_url(
											add_query_arg(
												[ 'page' => 'bizcity-diagnostics', 'bizcity_run_installer' => $installer_id ],
												admin_url( 'tools.php' )
											),
											'bizcity_run_installer_' . $installer_id
										);
										$label = $needs_fix ? '🔧 Fix' : '🔧 Repair';
										$conf  = $needs_fix
											? sprintf( 'Tạo bảng thiếu qua installer "%s"?', $installer_id )
											: sprintf( 'Chạy lại installer "%s" để bổ sung cột thiếu (dbDelta)?', $installer_id );
										?>
										<a class="button button-small <?php echo $needs_fix ? 'button-primary' : ''; ?>"
										   href="<?php echo esc_url( $action_url ); ?>"
										   onclick="return confirm(<?php echo esc_attr( wp_json_encode( $conf ) ); ?>);"
										   title="installer id: <?php echo esc_attr( $installer_id ); ?>">
											<?php echo esc_html( $label ); ?>
										</a>
									<?php else : ?>
										<span style="color:#999" title="No installer mapped for this owner/class">— no installer</span>
									<?php endif; ?>
								<?php else : ?>
									<span style="color:#ccc">—</span>
								<?php endif; ?>
								<?php
								// Phase 0.41 L9.b T10 — Auto-create button (JSON-driven, additive only).
								if ( isset( $json_tables[ $r['name'] ] ) && ( $needs_fix || $needs_repair ) ) :
									$ac_url = wp_nonce_url(
										add_query_arg(
											[ 'page' => 'bizcity-diagnostics', 'bizcity_auto_create' => $r['name'] ],
											admin_url( 'tools.php' )
										),
										'bizcity_auto_create_' . $r['name']
									);
									$ac_conf = $needs_fix
										? sprintf( 'Auto-create bảng "%s" từ JSON changelog (additive only)?', $r['name'] )
										: sprintf( 'Auto-add các cột/index thiếu cho "%s" từ JSON changelog (KHÔNG drop, KHÔNG modify)?', $r['name'] );
									?>
									<br>
									<a class="button button-small"
									   href="<?php echo esc_url( $ac_url ); ?>"
									   onclick="return confirm(<?php echo esc_attr( wp_json_encode( $ac_conf ) ); ?>);"
									   title="JSON-declared schema · additive only"
									   style="margin-top:4px;color:#0a4b78;border-color:#0a4b78">
										🔧 Auto-create
									</a>
								<?php endif; ?>
							</td>
							<td style="font-size:11px;color:<?php echo $is_orphan_hint ? '#b26a00' : '#666'; ?>">
								<?php echo esc_html( $r['notes'] ?? '' ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>

			<p style="margin-top:24px;color:#666">
				<?php esc_html_e( 'Modules có thể đăng ký thêm bảng vào registry qua filter', 'bizcity-twin-ai' ); ?>
				<code>bizcity_diagnostics_register_tables</code>
				<?php esc_html_e( 'và khai báo cột mong đợi qua', 'bizcity-twin-ai' ); ?>
				<code>bizcity_diagnostics_expected_columns</code>
				(<?php esc_html_e( 'key = table suffix; value = array of column names', 'bizcity-twin-ai' ); ?>).
				<br><?php esc_html_e( 'REST snapshot:', 'bizcity-twin-ai' ); ?>
				<code>GET /wp-json/<?php echo esc_html( BIZCITY_DIAGNOSTICS_REST_NS ); ?>/tables</code>
				· <?php esc_html_e( 'Buttons:', 'bizcity-twin-ai' ); ?>
				<strong>🔧 Fix</strong> = <?php esc_html_e( 'tạo bảng thiếu', 'bizcity-twin-ai' ); ?> ·
				<strong>🔧 Repair</strong> = <?php esc_html_e( 'chạy lại installer để dbDelta bổ sung cột', 'bizcity-twin-ai' ); ?>.
			</p>

			<?php $this->render_orphan_section( $cleanup_result ); ?>
			<?php $this->render_smoke_section( $smoke_results, $smoke_aggregate ); ?>
			<?php $this->render_provisioner_section(); ?>
			<?php $this->render_error_reports_section(); ?>
		</div>
		<?php
	}

	/**
	 * Smoke-Test section — Phase 0.41 L9.a T4.
	 *
	 * Server-rendered companion to the FE Health-Check Wizard. Lists every
	 * registered probe, lets ops run one probe (or run-all) in-place, and
	 * surfaces the result envelope (status, duration, steps, fix_hint).
	 *
	 * @param array<string,array<string,mixed>> $smoke_results keyed by probe id
	 * @param array<string,mixed>|null          $smoke_aggregate run-all envelope
	 */
	private function render_smoke_section( array $smoke_results, $smoke_aggregate ): void {
		if ( ! class_exists( 'BizCity_Diagnostics_Smoke_Runner' ) ) {
			return;
		}
		$catalog       = BizCity_Diagnostics_Smoke_Runner::describe_catalog();
		$last_results  = BizCity_Diagnostics_Smoke_Runner::get_last_results();
		$page          = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'bizcity-diagnostics';

		$run_all_url = wp_nonce_url(
			add_query_arg(
				[ 'page' => $page, 'bizcity_run_all_probes' => '1' ],
				admin_url( 'tools.php' )
			) . '#smoke-test',
			'bizcity_run_all_probes'
		);
		$auto_fix_url = wp_nonce_url(
			add_query_arg(
				[ 'page' => $page, 'bizcity_auto_fix_all' => '1' ],
				admin_url( 'tools.php' )
			) . '#smoke-test',
			'bizcity_auto_fix_all'
		);

		// Aggregate counts.
		$pass = 0; $fail = 0; $skip = 0;
		foreach ( $smoke_results as $r ) {
			$s = $r['status'] ?? '';
			if ( $s === 'pass' ) { $pass++; }
			elseif ( $s === 'skipped' ) { $skip++; }
			else { $fail++; }
		}
		?>
		<hr style="margin:32px 0">
		<h2 id="smoke-test"><?php esc_html_e( 'Smoke Test — Health Check Probes', 'bizcity-twin-ai' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Phase 0.41 L9.a · Mỗi probe tự seed dữ liệu __healthtest__ rồi cleanup. Dùng cho onboarding lần đầu + critical-regression. FE Wizard (nút 🩺 trên Brain header) dùng chung catalog này qua REST.', 'bizcity-twin-ai' ); ?>
			<br>
			<a class="button button-primary" href="<?php echo esc_url( $run_all_url ); ?>"
			   onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Chạy tất cả probes trong 30s budget?', 'bizcity-twin-ai' ) ) ); ?>);">
				▶ <?php esc_html_e( 'Run all probes', 'bizcity-twin-ai' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( $auto_fix_url ); ?>"
			   style="background:#fff4e5;border-color:#b26a00;color:#b26a00"
			   onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Auto-Fix-All: chạy tất cả installer + Auto-Create cho mọi bảng missing/drift. Chỉ thao tác additive (CREATE TABLE / ADD COLUMN). Tiếp tục?', 'bizcity-twin-ai' ) ) ); ?>);">
				🔧 <?php esc_html_e( 'Auto-fix all', 'bizcity-twin-ai' ); ?>
			</a>
			<?php if ( ! empty( $this->auto_fix_all_result ) ) :
				$afa       = $this->auto_fix_all_result;
				$ac_count  = is_array( $afa['auto_create_results'] ?? null ) ? count( $afa['auto_create_results'] ) : 0;
				$cf_count  = is_array( $afa['class_fallback_results'] ?? null ) ? count( $afa['class_fallback_results'] ) : 0;
				$uf_list   = is_array( $afa['unfixable'] ?? null ) ? $afa['unfixable'] : [];
			?>
				<span style="margin-left:12px;color:#00674e">
					<strong>🔧 <?php esc_html_e( 'Auto-Fix-All result:', 'bizcity-twin-ai' ); ?></strong>
					<?php echo esc_html( sprintf( __( 'missing %d → %d · JSON %d · class %d · %dms', 'bizcity-twin-ai' ), (int) $afa['before'], (int) $afa['after'], $ac_count, $cf_count, (int) $afa['took_ms'] ) ); ?>
				</span>
				<?php if ( ! empty( $uf_list ) ) : ?>
					<div class="notice notice-warning inline" style="margin-top:8px">
						<p><strong>⚠️ <?php echo esc_html( sprintf( __( '%d bảng chưa thể auto-fix:', 'bizcity-twin-ai' ), count( $uf_list ) ) ); ?></strong></p>
						<ul style="margin:4px 0 4px 20px;list-style:disc">
							<?php foreach ( array_slice( $uf_list, 0, 30 ) as $u ) : ?>
								<li>
									<code><?php echo esc_html( $u['physical'] ); ?></code>
									<span style="color:#666"> — owner: <code><?php echo esc_html( $u['owner'] ?: '—' ); ?></code><?php if ( ! empty( $u['class'] ) ) : ?>, class: <code><?php echo esc_html( $u['class'] ); ?></code><?php endif; ?></span>
									<em style="color:#b26a00"> · <?php echo esc_html( $u['hint'] ); ?></em>
								</li>
							<?php endforeach; ?>
							<?php if ( count( $uf_list ) > 30 ) : ?>
								<li><em>… và <?php echo (int) ( count( $uf_list ) - 30 ); ?> dòng nữa</em></li>
							<?php endif; ?>
						</ul>
					</div>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $smoke_aggregate ) : ?>
				<span style="margin-left:12px">
					<strong><?php esc_html_e( 'Aggregate:', 'bizcity-twin-ai' ); ?></strong>
					<span style="color:#00674e">✓ <?php echo (int) $pass; ?> pass</span> ·
					<span style="color:#b32d2e">✗ <?php echo (int) $fail; ?> fail</span> ·
					<span style="color:#666"><?php echo (int) $skip; ?> skipped</span>
					· <?php echo (int) $smoke_aggregate['duration_ms']; ?>ms
				</span>
			<?php endif; ?>
		</p>

		<?php if ( empty( $catalog ) ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Chưa có probe nào được đăng ký. Module có thể đăng ký qua filter', 'bizcity-twin-ai' ); ?> <code>bizcity_diagnostics_register_probes</code>.</p></div>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:18%"><?php esc_html_e( 'Probe', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Description', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Severity', 'bizcity-twin-ai' ); ?></th>
					<th style="text-align:right"><?php esc_html_e( 'Est.', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Last result', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Action', 'bizcity-twin-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $catalog as $p ) :
				$pid = (string) $p['id'];
				// Prefer fresh in-session result; fall back to persisted last run.
				$res          = $smoke_results[ $pid ] ?? null;
				$last         = $last_results[ $pid ] ?? null;
				$res_for_view = $res ?: $last; // may still be null
				$is_persisted = ! $res && $last;
				$status       = $res_for_view['status'] ?? '';
				$row_bg = '';
				if ( $status === 'pass' ) { $row_bg = 'background:#e8f5e9'; }
				elseif ( $status === 'fail' ) { $row_bg = 'background:#fdecea'; }
				elseif ( $status === 'precheck-fail' ) { $row_bg = 'background:#fff3e0'; }
				elseif ( $status === 'skipped' ) { $row_bg = 'background:#f5f5f5'; }

				$run_url = wp_nonce_url(
					add_query_arg(
						[ 'page' => $page, 'bizcity_run_probe' => $pid ],
						admin_url( 'tools.php' )
					) . '#smoke-test',
					'bizcity_run_probe_' . $pid
				);
				$sev_color = [ 'critical' => '#b32d2e', 'warning' => '#b26a00', 'info' => '#666' ][ $p['severity'] ] ?? '#666';
			?>
				<tr<?php echo $row_bg ? ' style="' . esc_attr( $row_bg ) . '"' : ''; ?>>
					<td>
						<code><?php echo esc_html( $pid ); ?></code><br>
						<strong style="font-size:12px"><?php echo esc_html( $p['label'] ); ?></strong>
					</td>
					<td style="font-size:12px;color:#555"><?php echo esc_html( $p['description'] ); ?></td>
					<td style="font-size:11px;color:<?php echo esc_attr( $sev_color ); ?>;font-weight:bold"><?php echo esc_html( strtoupper( (string) $p['severity'] ) ); ?></td>
					<td style="text-align:right;font-size:11px;color:#666">~<?php echo (int) $p['estimate_ms']; ?>ms</td>
					<td style="font-size:12px">
						<?php if ( ! $res_for_view ) : ?>
							<span style="color:#999">—</span>
						<?php else :
							$icon = [ 'pass' => '✓', 'fail' => '✗', 'precheck-fail' => '⚠', 'skipped' => '⏭' ][ $status ] ?? '?';
							$color = [ 'pass' => '#00674e', 'fail' => '#b32d2e', 'precheck-fail' => '#b26a00', 'skipped' => '#666' ][ $status ] ?? '#666';
						?>
							<span style="color:<?php echo esc_attr( $color ); ?>;font-weight:bold"><?php echo esc_html( $icon . ' ' . strtoupper( $status ) ); ?></span>
							· <?php echo (int) ( $res_for_view['duration_ms'] ?? 0 ); ?>ms
							<?php if ( $is_persisted && ! empty( $last['ts'] ) ) :
								$age = human_time_diff( (int) $last['ts'], time() );
							?>
								<span style="color:#888;font-size:11px" title="<?php echo esc_attr( gmdate( 'Y-m-d H:i:s', (int) $last['ts'] ) . ' UTC' ); ?>">
									· last run <?php echo esc_html( $age ); ?> ago
								</span>
							<?php endif; ?>
							<?php if ( ! empty( $res_for_view['summary'] ) ) : ?>
								<br><span style="color:#444"><?php echo esc_html( $res_for_view['summary'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $res_for_view['error'] ) ) : ?>
								<br><span style="color:#b32d2e">error: <?php echo esc_html( $res_for_view['error'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $res_for_view['fix_hint'] ) ) : ?>
								<br><span style="color:#b26a00">→ <?php echo esc_html( $res_for_view['fix_hint'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! $is_persisted && ! empty( $res_for_view['steps'] ) && is_array( $res_for_view['steps'] ) ) : ?>
								<details style="margin-top:4px">
									<summary style="cursor:pointer;color:#666;font-size:11px"><?php echo count( $res_for_view['steps'] ); ?> steps</summary>
									<ol style="margin:4px 0 0 18px;padding:0;font-size:11px;color:#666">
										<?php foreach ( $res_for_view['steps'] as $s ) : ?>
											<li><?php echo esc_html( is_array( $s ) ? ( $s['label'] ?? wp_json_encode( $s ) ) : (string) $s ); ?></li>
										<?php endforeach; ?>
									</ol>
								</details>
							<?php elseif ( $is_persisted && ! empty( $last['steps_count'] ) ) : ?>
								<div style="margin-top:2px;color:#888;font-size:11px"><?php echo (int) $last['steps_count']; ?> steps (chạy Run để xem chi tiết)</div>
							<?php endif; ?>
						<?php endif; ?>
					</td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( $run_url ); ?>">▶ <?php esc_html_e( 'Run', 'bizcity-twin-ai' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p style="margin-top:8px;color:#666;font-size:12px">
			<?php esc_html_e( 'REST endpoints:', 'bizcity-twin-ai' ); ?>
			<code>GET /wp-json/<?php echo esc_html( BIZCITY_DIAGNOSTICS_REST_NS ); ?>/smoke/probes</code> ·
			<code>POST /smoke/run</code> ·
			<code>POST /smoke/run-all</code> ·
			<code>GET /wizard/eligibility</code>
		</p>
		<?php
	}

	/**
	 * Orphan cleanup section — AUTO-DROP empty deprecated tables.
	 * Runs on page render (throttled 1×/hour). Tables with rows are skipped.
	 */
	private function render_orphan_section( $cleanup_result ): void {
		$preview = BizCity_Diagnostics_Orphan_Cleaner::preview();
		$page    = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'bizcity-diagnostics';
		$force_url = add_query_arg( [ 'page' => $page, 'bzdiag_force_clean' => '1' ], admin_url( 'tools.php' ) );
		?>
		<hr style="margin:32px 0">
		<h2><?php esc_html_e( 'Orphan Cleanup (auto)', 'bizcity-twin-ai' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Tự động DROP các bảng deprecated (audit-verified zero consumer) khi mở trang. Bảng có dữ liệu sẽ bị skip. Chạy 1×/giờ mỗi blog.', 'bizcity-twin-ai' ); ?>
			· <a href="<?php echo esc_url( $force_url ); ?>"><?php esc_html_e( 'Force re-run now', 'bizcity-twin-ai' ); ?></a>
		</p>

		<?php if ( $cleanup_result ) :
			$counts = [ 'dropped' => 0, 'skipped' => 0, 'noop' => 0, 'error' => 0 ];
			foreach ( $cleanup_result['actions'] as $a ) {
				$k = $a['action'] ?? 'skipped';
				if ( isset( $counts[ $k ] ) ) {
					$counts[ $k ]++;
				}
			}
		?>
			<div class="notice notice-<?php echo $counts['error'] ? 'error' : ( $counts['dropped'] ? 'success' : 'info' ); ?> inline" style="margin:12px 0">
				<p style="margin:8px 0">
					<?php if ( ! empty( $cleanup_result['throttled'] ) ) : ?>
						<strong>⏱ THROTTLED</strong> — <?php esc_html_e( 'Đã chạy trong giờ qua. Dùng "Force re-run now" để bỏ qua throttle.', 'bizcity-twin-ai' ); ?>
					<?php else : ?>
						<strong><?php esc_html_e( 'Cleanup result', 'bizcity-twin-ai' ); ?></strong>
						· blog_id=<?php echo (int) $cleanup_result['blog_id']; ?>
						· prefix=<code><?php echo esc_html( $cleanup_result['prefix'] ); ?></code>
						· <span style="color:#00674e">✓ <?php echo (int) $counts['dropped']; ?> dropped</span>
						· <span style="color:#b26a00">⚠ <?php echo (int) $counts['skipped']; ?> skipped</span>
						· <span style="color:#666"><?php echo (int) $counts['noop']; ?> absent</span>
						<?php if ( $counts['error'] ) : ?>
							· <span style="color:#b32d2e">✗ <?php echo (int) $counts['error']; ?> error</span>
						<?php endif; ?>
					<?php endif; ?>
				</p>
				<?php if ( ! empty( $cleanup_result['actions'] ) ) : ?>
				<table class="widefat striped" style="margin:8px 0">
					<thead><tr><th><?php esc_html_e( 'Table', 'bizcity-twin-ai' ); ?></th><th><?php esc_html_e( 'Action', 'bizcity-twin-ai' ); ?></th><th><?php esc_html_e( 'Detail', 'bizcity-twin-ai' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $cleanup_result['actions'] as $a ) :
						$color = [ 'dropped' => '#00674e', 'skipped' => '#b26a00', 'noop' => '#666', 'error' => '#b32d2e' ][ $a['action'] ] ?? '#666';
					?>
						<tr>
							<td><code><?php echo esc_html( $a['physical'] ); ?></code></td>
							<td style="color:<?php echo esc_attr( $color ); ?>;font-weight:bold"><?php echo esc_html( $a['action'] ); ?></td>
							<td><?php echo esc_html( $a['detail'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<h3 style="margin-top:24px"><?php esc_html_e( 'Deprecated table catalog', 'bizcity-twin-ai' ); ?></h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Table (physical)', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Status', 'bizcity-twin-ai' ); ?></th>
					<th style="text-align:right"><?php esc_html_e( 'Rows', 'bizcity-twin-ai' ); ?></th>
					<th style="text-align:right"><?php esc_html_e( 'Size', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Reason', 'bizcity-twin-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $preview as $r ) : ?>
				<tr style="background:<?php echo $r['safe_to_drop'] ? '#e8f5e9' : ( $r['exists'] ? '#fff3e0' : '#f5f5f5' ); ?>">
					<td><code><?php echo esc_html( $r['physical'] ); ?></code></td>
					<td>
						<?php if ( ! $r['exists'] ) : ?>
							<span style="color:#666">— absent</span>
						<?php elseif ( $r['safe_to_drop'] ) : ?>
							<span style="color:#00674e;font-weight:bold">✓ will auto-drop</span>
						<?php else : ?>
							<span style="color:#b26a00;font-weight:bold">⚠ has data (kept)</span>
						<?php endif; ?>
					</td>
					<td style="text-align:right"><?php echo $r['exists'] ? number_format_i18n( $r['rows'] ) : '—'; ?></td>
					<td style="text-align:right"><?php echo esc_html( $r['size_human'] ); ?></td>
					<td style="font-size:12px;color:#666"><?php echo esc_html( $r['reason'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$log = BizCity_Diagnostics_Orphan_Cleaner::get_log();
		if ( ! empty( $log ) ) {
			$recent = array_slice( $log, -10 );
			echo '<h3 style="margin-top:24px">' . esc_html__( 'Recent cleanup actions (last 10)', 'bizcity-twin-ai' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>User</th><th>Blog</th><th>Summary</th></tr></thead><tbody>';
			foreach ( array_reverse( $recent ) as $entry ) {
				echo '<tr>';
				echo '<td>' . esc_html( $entry['ts'] ?? '' ) . '</td>';
				echo '<td>' . esc_html( (string) ( $entry['user'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $entry['blog_id'] ?? '' ) ) . ' (' . esc_html( (string) ( $entry['prefix'] ?? '' ) ) . ')</td>';
				echo '<td><code>' . esc_html( wp_json_encode( $entry['summary'] ?? [] ) ) . '</code></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Site Provisioner section — unified table-installer orchestrator.
	 *
	 * Shows registered installers, current vs expected db_version, and the
	 * recent run log. Force re-run via ?bizcity_provision=1.
	 */
	private function render_provisioner_section(): void {
		if ( ! class_exists( 'BizCity_Site_Provisioner' ) ) {
			return;
		}

		$installers = BizCity_Site_Provisioner::get_installers();
		$log        = BizCity_Site_Provisioner::get_log();
		$page       = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'bizcity-diagnostics';
		$force_url  = add_query_arg(
			[
				'page' => $page,
				BizCity_Site_Provisioner::FORCE_QUERY_ARG => '1',
			],
			admin_url( 'tools.php' )
		);
		$ran_now = ! empty( $_GET[ BizCity_Site_Provisioner::FORCE_QUERY_ARG ] );
		?>
		<hr style="margin:32px 0">
		<h2><?php esc_html_e( 'Site Provisioner — Auto-install tables', 'bizcity-twin-ai' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Mỗi module đăng ký installer của mình qua filter bizcity_register_installers. Provisioner chạy tất cả khi blog mới được tạo (wp_initialize_site) và self-heal khi admin mở trang (throttle 5 phút).', 'bizcity-twin-ai' ); ?>
			· <a class="button button-primary" href="<?php echo esc_url( $force_url ); ?>"><?php esc_html_e( '🔧 Repair all tables now', 'bizcity-twin-ai' ); ?></a>
		</p>

		<?php if ( $ran_now ) : ?>
			<div class="notice notice-success inline" style="margin:12px 0">
				<p><strong><?php esc_html_e( '✓ Provisioner forced re-run for this blog.', 'bizcity-twin-ai' ); ?></strong> <?php esc_html_e( 'See latest log entry below.', 'bizcity-twin-ai' ); ?></p>
			</div>
		<?php endif; ?>

		<h3 style="margin-top:16px"><?php esc_html_e( 'Registered installers', 'bizcity-twin-ai' ); ?> (<?php echo count( $installers ); ?>)</h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ID', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Label', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Version option', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Current ver', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Expected ver', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Status', 'bizcity-twin-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $installers as $i ) :
				$opt     = $i['version_opt'];
				$cur     = $opt ? (string) get_option( $opt, '' ) : '';
				$exp     = $i['expected_ver'];
				if ( ! $opt ) {
					$status = '<span style="color:#666">— no version gate</span>';
				} elseif ( $exp && $cur === $exp ) {
					$status = '<span style="color:#00674e">✓ up-to-date</span>';
				} elseif ( $cur === '' ) {
					$status = '<span style="color:#b32d2e;font-weight:bold">✗ NOT INSTALLED</span>';
				} elseif ( $exp && $cur !== $exp ) {
					$status = '<span style="color:#b26a00;font-weight:bold">⚠ needs migrate</span>';
				} else {
					$status = '<span style="color:#00674e">✓ installed</span>';
				}
			?>
				<tr>
					<td><code><?php echo esc_html( $i['id'] ); ?></code></td>
					<td><?php echo esc_html( $i['label'] ); ?></td>
					<td style="font-size:11px"><?php echo $opt ? '<code>' . esc_html( $opt ) . '</code>' : '<span style="color:#999">—</span>'; ?></td>
					<td><?php echo $cur !== '' ? '<code>' . esc_html( $cur ) . '</code>' : '<span style="color:#999">—</span>'; ?></td>
					<td><?php echo $exp !== '' ? '<code>' . esc_html( $exp ) . '</code>' : '<span style="color:#999">—</span>'; ?></td>
					<td><?php echo $status; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $installers ) ) : ?>
				<tr><td colspan="6"><em><?php esc_html_e( 'No installers registered. Modules should add filter bizcity_register_installers.', 'bizcity-twin-ai' ); ?></em></td></tr>
			<?php endif; ?>
			</tbody>
		</table>

		<?php if ( ! empty( $log ) ) :
			$recent = array_slice( $log, -10 );
		?>
			<h3 style="margin-top:24px"><?php esc_html_e( 'Recent provisioner runs (last 10)', 'bizcity-twin-ai' ); ?></h3>
			<table class="widefat striped">
				<thead><tr><th>Time (UTC)</th><th>Blog</th><th>User</th><th>Force</th><th>Summary</th></tr></thead>
				<tbody>
				<?php foreach ( array_reverse( $recent ) as $entry ) :
					$results = $entry['results'] ?? [];
					$ok      = 0;
					$err     = 0;
					foreach ( $results as $r ) {
						if ( ( $r['action'] ?? '' ) === 'error' ) {
							$err++;
						} else {
							$ok++;
						}
					}
				?>
					<tr>
						<td><?php echo esc_html( $entry['ts'] ?? '' ); ?></td>
						<td><?php echo (int) ( $entry['blog_id'] ?? 0 ); ?> (<code><?php echo esc_html( (string) ( $entry['prefix'] ?? '' ) ); ?></code>)</td>
						<td><?php echo (int) ( $entry['user'] ?? 0 ); ?></td>
						<td><?php echo ! empty( $entry['force'] ) ? '<strong style="color:#b26a00">forced</strong>' : '—'; ?></td>
						<td>
							<span style="color:#00674e">✓ <?php echo (int) $ok; ?> ran</span>
							<?php if ( $err ) : ?>
								· <span style="color:#b32d2e;font-weight:bold">✗ <?php echo (int) $err; ?> error</span>
							<?php endif; ?>
							<details style="margin-top:4px"><summary style="cursor:pointer;font-size:11px;color:#666"><?php esc_html_e( 'details', 'bizcity-twin-ai' ); ?></summary>
								<pre style="font-size:10px;max-height:200px;overflow:auto;background:#f8f8f8;padding:6px;margin:4px 0"><?php echo esc_html( wp_json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
							</details>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Error Reports section — viewer for `bizcity_error_reports` option.
	 *
	 * Shows recent reports (newest first), highlights critical codes, and
	 * surfaces the suggested fix CTA per row. Supports clearing via
	 * ?bzdiag_clear_errors=1 (requires nonce).
	 */
	private function render_error_reports_section(): void {
		if ( ! class_exists( 'BizCity_Error_Reporter' ) ) {
			return;
		}

		// Handle clear action (nonce-protected).
		$cleared = 0;
		if ( isset( $_GET['bzdiag_clear_errors'] ) && $_GET['bzdiag_clear_errors'] === '1' ) {
			$nonce_ok = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'bizcity_clear_errors' );
			if ( $nonce_ok && current_user_can( 'manage_options' ) ) {
				$cleared = BizCity_Error_Reporter::clear_reports();
			}
		}

		$reports   = BizCity_Error_Reporter::get_reports();
		$total     = count( $reports );
		$recent    = array_slice( array_reverse( $reports ), 0, 50 );
		$page      = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'bizcity-diagnostics';
		$clear_url = wp_nonce_url(
			add_query_arg( [ 'page' => $page, 'bzdiag_clear_errors' => '1' ], admin_url( 'tools.php' ) ),
			'bizcity_clear_errors'
		);
		?>
		<hr style="margin:32px 0">
		<h2 id="error-reports"><?php esc_html_e( 'Error Reports — User-facing errors', 'bizcity-twin-ai' ); ?> <span style="font-size:13px;color:#666">(<?php echo (int) $total; ?> stored)</span></h2>
		<p class="description">
			<?php esc_html_e( 'Telemetry từ frontend/REST gửi qua POST /bizcity-diagnostics/v1/error-report. Mỗi row có suggested-fix link nếu code được mapper hỗ trợ.', 'bizcity-twin-ai' ); ?>
			<?php if ( $total > 0 ) : ?>
				· <a href="<?php echo esc_url( $clear_url ); ?>" class="button" onclick="return confirm('Xoá toàn bộ <?php echo (int) $total; ?> báo cáo?');"><?php esc_html_e( '🗑 Clear all', 'bizcity-twin-ai' ); ?></a>
			<?php endif; ?>
		</p>

		<?php if ( $cleared > 0 ) : ?>
			<div class="notice notice-success inline" style="margin:12px 0"><p><strong>✓ Cleared <?php echo (int) $cleared; ?> report(s).</strong></p></div>
		<?php endif; ?>

		<?php if ( empty( $recent ) ) : ?>
			<p><em><?php esc_html_e( 'No errors recorded yet.', 'bizcity-twin-ai' ); ?></em></p>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:140px"><?php esc_html_e( 'Time (UTC)', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Code', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Module', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Title / Detail', 'bizcity-twin-ai' ); ?></th>
					<th style="width:80px"><?php esc_html_e( 'Blog', 'bizcity-twin-ai' ); ?></th>
					<th style="width:60px"><?php esc_html_e( 'User', 'bizcity-twin-ai' ); ?></th>
					<th style="width:180px"><?php esc_html_e( 'Suggested fix', 'bizcity-twin-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $recent as $r ) :
				$code  = (string) ( $r['code'] ?? '' );
				$crit  = in_array( $code, BizCity_Error_Reporter::CRITICAL_CODES, true );
				$fix   = $r['fix'] ?? [];
				$badge = $crit ? 'background:#b32d2e;color:#fff' : 'background:#eef;color:#334';
			?>
				<tr>
					<td style="font-size:11px"><?php echo esc_html( $r['ts'] ?? '' ); ?></td>
					<td>
						<code style="padding:2px 6px;border-radius:3px;<?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $code ); ?></code>
						<?php if ( ! empty( $r['http_status'] ) ) : ?>
							<div style="font-size:10px;color:#666;margin-top:2px">HTTP <?php echo (int) $r['http_status']; ?></div>
						<?php endif; ?>
					</td>
					<td style="font-size:12px"><?php echo esc_html( (string) ( $r['module'] ?? '—' ) ); ?></td>
					<td>
						<?php if ( ! empty( $r['title'] ) ) : ?>
							<strong><?php echo esc_html( $r['title'] ); ?></strong><br>
						<?php endif; ?>
						<?php if ( ! empty( $r['detail'] ) ) : ?>
							<span style="font-size:11px;color:#444"><?php echo esc_html( wp_trim_words( $r['detail'], 30 ) ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $r['url'] ) || ! empty( $r['context'] ) ) : ?>
							<details style="margin-top:4px"><summary style="cursor:pointer;font-size:10px;color:#666">details</summary>
								<?php if ( ! empty( $r['url'] ) ) : ?>
									<div style="font-size:10px;color:#555;word-break:break-all"><?php echo esc_html( $r['url'] ); ?></div>
								<?php endif; ?>
								<?php if ( ! empty( $r['context'] ) ) : ?>
									<pre style="font-size:10px;max-height:140px;overflow:auto;background:#f8f8f8;padding:6px;margin:4px 0"><?php echo esc_html( wp_json_encode( $r['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
								<?php endif; ?>
								<?php if ( ! empty( $r['user_agent'] ) ) : ?>
									<div style="font-size:10px;color:#888"><?php echo esc_html( $r['user_agent'] ); ?></div>
								<?php endif; ?>
							</details>
						<?php endif; ?>
					</td>
					<td><?php echo (int) ( $r['blog_id'] ?? 0 ); ?></td>
					<td><?php echo (int) ( $r['user_id'] ?? 0 ); ?></td>
					<td>
						<?php if ( ! empty( $fix['url'] ) ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $fix['url'] ); ?>"><?php echo esc_html( (string) ( $fix['label'] ?? 'Open fix' ) ); ?></a>
						<?php else : ?>
							<span style="color:#999;font-size:11px">— no mapper</span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p style="margin-top:8px;font-size:11px;color:#666">
			<?php esc_html_e( 'Critical codes (highlighted red) trigger an email to admin via core/smtp, throttled 1h per code/blog.', 'bizcity-twin-ai' ); ?>
		</p>
		<?php
	}
}
