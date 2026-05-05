<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent\Shell
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.16 / Vòng 4 — Sprint 3
 * Settings & monitoring UI for the Intent Shell rollout.
 *
 * Adds two screens under wp-admin → Tools:
 *   • bizcity-intent-shell           — toggle / rollout slider / allow-deny lists
 *   • bizcity-intent-shadow-diff     — last 50 shadow diff rows
 *
 * @since 4.0.0
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Intent_Shell_Admin {

	const PAGE_SETTINGS = 'bizcity-intent-shell';
	const PAGE_SHADOW   = 'bizcity-intent-shadow-diff';
	const NONCE         = 'bizcity_intent_shell_save';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_pages' ], 12 );
		add_action( 'admin_post_bizcity_intent_shell_save', [ __CLASS__, 'handle_save' ] );
	}

	public static function register_pages(): void {
		// Sit under Intent Monitor when present, fallback to Tools menu.
		$parent = class_exists( 'BizCity_Intent_Monitor', false ) ? 'bizcity-intent-monitor' : null;
		if ( $parent ) {
			add_submenu_page( $parent,
				'BizCity Intent Shell',
				'Intent Shell — Settings',
				'manage_options',
				self::PAGE_SETTINGS,
				[ __CLASS__, 'render_settings' ]
			);
			add_submenu_page( $parent,
				'BizCity Shadow Diff',
				'Intent Shell — Shadow Diff',
				'manage_options',
				self::PAGE_SHADOW,
				[ __CLASS__, 'render_shadow' ]
			);
		} else {
			add_management_page(
				'BizCity Intent Shell',
				'BizCity Intent Shell',
				'manage_options',
				self::PAGE_SETTINGS,
				[ __CLASS__, 'render_settings' ]
			);
			add_management_page(
				'BizCity Shadow Diff',
				'BizCity Shadow Diff',
				'manage_options',
				self::PAGE_SHADOW,
				[ __CLASS__, 'render_shadow' ]
			);
		}
	}

	/** Resolve admin URL regardless of whether page sits under Intent Monitor or Tools. */
	private static function page_url( string $slug ): string {
		$url = menu_page_url( $slug, false );
		return $url ?: admin_url( 'admin.php?page=' . $slug );
	}

	/* ---------------------------- SETTINGS ---------------------------- */

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Admin only' ); }
		$cfg = BizCity_Intent_Shell_Config::get();
		?>
		<div class="wrap">
			<h1>BizCity Intent Shell — Rollout Settings</h1>
			<p>Tắt mặc định. Bật cẩn thận: traffic được bucket deterministic theo <code>user_id + message hash</code>.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bizcity_intent_shell_save" />
				<?php wp_nonce_field( self::NONCE ); ?>

				<table class="form-table">
					<tr>
						<th><label for="bcs_enabled">Bật shell</label></th>
						<td>
							<label><input type="checkbox" id="bcs_enabled" name="enabled" value="1" <?php checked( ! empty( $cfg['enabled'] ) ); ?> />
								Cho phép `Intent_Engine::process()` chuyển sang shell mới khi user nằm trong rollout bucket.</label>
						</td>
					</tr>
					<tr>
						<th><label for="bcs_shadow">Shadow mode</label></th>
						<td>
							<label><input type="checkbox" id="bcs_shadow" name="shadow_mode" value="1" <?php checked( ! empty( $cfg['shadow_mode'] ) ); ?> />
								Chạy shell SONG SONG (không trả response) và log diff vào bảng so sánh. An toàn để bật trên production.</label>
						</td>
					</tr>
					<tr>
						<th><label for="bcs_pct">Rollout %</label></th>
						<td>
							<input type="number" min="0" max="100" id="bcs_pct" name="rollout_pct" value="<?php echo esc_attr( (int) ( $cfg['rollout_pct'] ?? 0 ) ); ?>" /> %
							<p class="description">Phần trăm user (deterministic bucket) được route qua shell khi `enabled=ON`.</p>
						</td>
					</tr>
					<tr>
						<th><label for="bcs_allow">Allow user IDs</label></th>
						<td>
							<input type="text" id="bcs_allow" name="allow_users" class="regular-text"
								value="<?php echo esc_attr( implode( ',', (array) ( $cfg['allow_users'] ?? [] ) ) ); ?>" />
							<p class="description">User được route qua shell BẤT KỂ rollout %. CSV.</p>
						</td>
					</tr>
					<tr>
						<th><label for="bcs_deny">Deny user IDs</label></th>
						<td>
							<input type="text" id="bcs_deny" name="deny_users" class="regular-text"
								value="<?php echo esc_attr( implode( ',', (array) ( $cfg['deny_users'] ?? [] ) ) ); ?>" />
							<p class="description">User KHÔNG BAO GIỜ route qua shell. CSV.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>
			</form>

			<hr />
			<h2>Quick links</h2>
			<ul>
				<li><a href="<?php echo esc_url( self::page_url( self::PAGE_SHADOW ) ); ?>">→ Xem Shadow Diff</a></li>
			</ul>
		</div>
		<?php
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Admin only' ); }
		check_admin_referer( self::NONCE );

		$patch = [
			'enabled'     => ! empty( $_POST['enabled'] ),
			'shadow_mode' => ! empty( $_POST['shadow_mode'] ),
			'rollout_pct' => max( 0, min( 100, (int) ( $_POST['rollout_pct'] ?? 0 ) ) ),
			'allow_users' => self::parse_ids( $_POST['allow_users'] ?? '' ),
			'deny_users'  => self::parse_ids( $_POST['deny_users']  ?? '' ),
		];
		BizCity_Intent_Shell_Config::update( $patch );

		wp_safe_redirect( add_query_arg( 'updated', '1', self::page_url( self::PAGE_SETTINGS ) ) );
		exit;
	}

	private static function parse_ids( $raw ): array {
		$raw = wp_unslash( (string) $raw );
		$ids = array_filter( array_map( 'intval', preg_split( '/[\s,]+/', $raw ) ?: [] ) );
		return array_values( array_unique( $ids ) );
	}

	/* ---------------------------- SHADOW VIEWER ---------------------------- */

	public static function render_shadow(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Admin only' ); }
		global $wpdb;
		$tbl  = BizCity_Intent_Shadow_Diff_Installer::table_name();

		// ── Filters (Sprint 9 — B1) ───────────────────────────────────────
		$score_filter = isset( $_GET['score'] ) ? sanitize_key( wp_unslash( $_GET['score'] ) ) : 'all';
		if ( ! in_array( $score_filter, [ 'all', 'low', 'mid', 'high' ], true ) ) {
			$score_filter = 'all';
		}
		$days_filter = isset( $_GET['days'] ) ? (int) $_GET['days'] : 7;
		if ( ! in_array( $days_filter, [ 1, 7, 30, 0 ], true ) ) { // 0 = all
			$days_filter = 7;
		}
		$action_pair = isset( $_GET['pair'] ) ? sanitize_key( wp_unslash( $_GET['pair'] ) ) : 'all';
		if ( ! in_array( $action_pair, [ 'all', 'same', 'diff' ], true ) ) {
			$action_pair = 'all';
		}

		$where  = [];
		$params = [];
		if ( $score_filter === 'low'  ) { $where[] = 'match_score < 50'; }
		if ( $score_filter === 'mid'  ) { $where[] = 'match_score BETWEEN 50 AND 69'; }
		if ( $score_filter === 'high' ) { $where[] = 'match_score >= 70'; }
		if ( $days_filter > 0 ) {
			$where[]  = 'created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', time() - ( $days_filter * DAY_IN_SECONDS ) );
		}
		if ( $action_pair === 'same' ) { $where[] = 'legacy_action = shell_action'; }
		if ( $action_pair === 'diff' ) { $where[] = 'legacy_action <> shell_action'; }
		$where_sql = $where ? ( ' WHERE ' . implode( ' AND ', $where ) ) : '';

		$prep = static function ( $sql ) use ( $wpdb, $params ) {
			return $params ? $wpdb->prepare( $sql, $params ) : $sql;
		};

		$rows = $wpdb->get_results( $prep( "SELECT id, user_id, channel, message, legacy_action, shell_action, legacy_resp, shell_resp, match_score, legacy_ms, shell_ms, diff_summary, created_at FROM {$tbl}{$where_sql} ORDER BY id DESC LIMIT 50" ) );

		$total = (int)   $wpdb->get_var( $prep( "SELECT COUNT(*) FROM {$tbl}{$where_sql}" ) );
		$avg   = (float) $wpdb->get_var( $prep( "SELECT AVG(match_score) FROM {$tbl}{$where_sql}" ) );
		$lowm  = (int)   $wpdb->get_var( $prep( "SELECT COUNT(*) FROM {$tbl}{$where_sql}" . ( $where_sql ? ' AND' : ' WHERE' ) . ' match_score < 50' ) );
		$avg_legacy_ms = (float) $wpdb->get_var( $prep( "SELECT AVG(legacy_ms) FROM {$tbl}{$where_sql}" . ( $where_sql ? ' AND' : ' WHERE' ) . ' legacy_ms > 0' ) );
		$avg_shell_ms  = (float) $wpdb->get_var( $prep( "SELECT AVG(shell_ms)  FROM {$tbl}{$where_sql}" . ( $where_sql ? ' AND' : ' WHERE' ) . ' shell_ms  > 0' ) );

		// ── Rollout Health card (Sprint 9 — B2): 7-day daily series ───────
		$daily = $wpdb->get_results(
			"SELECT DATE(created_at) AS d, COUNT(*) AS n, AVG(match_score) AS avg_match
			   FROM {$tbl}
			  WHERE created_at >= " . "'" . gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) . "'
			  GROUP BY DATE(created_at)
			  ORDER BY d ASC"
		);
		$cfg              = BizCity_Intent_Shell_Config::get();
		$current_pct      = (int) ( $cfg['rollout_pct'] ?? 0 );
		$shadow_on        = ! empty( $cfg['shadow_mode'] );
		$rollout_health   = self::compute_rollout_health( $daily, $current_pct );

		$extract_reply = static function ( $json_str ) {
			if ( $json_str === null || $json_str === '' ) { return ''; }
			$d = json_decode( (string) $json_str, true );
			if ( ! is_array( $d ) ) { return ''; }
			$r = $d['reply'] ?? $d['content'] ?? '';
			return is_string( $r ) ? $r : wp_json_encode( $r );
		};

		// Sprint 5 — extract perf breakdown from shell_resp.meta
		$extract_perf = static function ( $json_str ) {
			if ( $json_str === null || $json_str === '' ) { return null; }
			$d = json_decode( (string) $json_str, true );
			if ( ! is_array( $d ) ) { return null; }
			$meta = $d['meta'] ?? [];
			if ( ! is_array( $meta ) ) { return null; }
			$out = [];
			if ( ! empty( $meta['phase_timings'] ) && is_array( $meta['phase_timings'] ) ) {
				$out['phases'] = $meta['phase_timings'];
			}
			if ( ! empty( $meta['runner_perf'] ) && is_array( $meta['runner_perf'] ) ) {
				$out['runner'] = $meta['runner_perf'];
			}
			return $out ?: null;
		};
		?>
		<div class="wrap">
			<h1>BizCity Intent Shell — Shadow Diff</h1>

			<?php // ── Rollout Health card (Sprint 9 — B2) ──────────────────── ?>
			<div style="background:#fff;border-left:4px solid <?php echo esc_attr( $rollout_health['color'] ); ?>;padding:12px 16px;margin:12px 0;box-shadow:0 1px 1px rgba(0,0,0,.04);">
				<h2 style="margin-top:0;">Rollout Health</h2>
				<p>
					<strong>Trạng thái:</strong>
					shadow_mode = <code><?php echo $shadow_on ? 'ON' : 'OFF'; ?></code> ·
					rollout_pct = <code><?php echo esc_html( (string) $current_pct ); ?>%</code> ·
					Khuyến nghị: <strong style="color:<?php echo esc_attr( $rollout_health['color'] ); ?>;"><?php echo esc_html( $rollout_health['recommendation'] ); ?></strong>
				</p>
				<table class="widefat" style="max-width:720px;">
					<thead><tr><th>Ngày</th><th>Số mẫu</th><th>Avg match</th><th>Đạt ngưỡng?</th></tr></thead>
					<tbody>
						<?php if ( empty( $daily ) ) : ?>
							<tr><td colspan="4"><em>Chưa có dữ liệu trong 7 ngày qua.</em></td></tr>
						<?php else : foreach ( $daily as $d ) :
							$avg_d = (float) $d->avg_match;
							$ok_d  = $avg_d >= $rollout_health['threshold'];
						?>
							<tr>
								<td><?php echo esc_html( $d->d ); ?></td>
								<td><?php echo (int) $d->n; ?></td>
								<td><strong style="color:<?php echo $ok_d ? 'green' : 'crimson'; ?>;"><?php echo esc_html( number_format( $avg_d, 1 ) ); ?></strong></td>
								<td><?php echo $ok_d ? '✅' : '⚠️'; ?></td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
				<p style="margin-top:8px;color:#646970;">
					Ngưỡng tự động: 5% → 25% cần ≥3 ngày liên tiếp avg ≥70 ·
					25% → 50% cần ≥4 ngày avg ≥75 ·
					50% → 100% cần ≥5 ngày avg ≥80.
					<a href="<?php echo esc_url( self::page_url( self::PAGE_SETTINGS ) ); ?>">→ Chỉnh rollout settings</a>
				</p>
			</div>

			<?php // ── Filters (Sprint 9 — B1) ───────────────────────────────── ?>
			<form method="get" action="" style="margin:12px 0;padding:8px 12px;background:#f6f7f7;border:1px solid #dcdcde;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SHADOW ); ?>" />
				<label>Match score:
					<select name="score">
						<option value="all"  <?php selected( $score_filter, 'all'  ); ?>>Tất cả</option>
						<option value="low"  <?php selected( $score_filter, 'low'  ); ?>>Thấp (&lt;50)</option>
						<option value="mid"  <?php selected( $score_filter, 'mid'  ); ?>>Trung (50–69)</option>
						<option value="high" <?php selected( $score_filter, 'high' ); ?>>Cao (≥70)</option>
					</select>
				</label>
				&nbsp;
				<label>Khoảng thời gian:
					<select name="days">
						<option value="1"  <?php selected( $days_filter, 1  ); ?>>24 giờ</option>
						<option value="7"  <?php selected( $days_filter, 7  ); ?>>7 ngày</option>
						<option value="30" <?php selected( $days_filter, 30 ); ?>>30 ngày</option>
						<option value="0"  <?php selected( $days_filter, 0  ); ?>>Tất cả</option>
					</select>
				</label>
				&nbsp;
				<label>Action pair:
					<select name="pair">
						<option value="all"  <?php selected( $action_pair, 'all'  ); ?>>Tất cả</option>
						<option value="same" <?php selected( $action_pair, 'same' ); ?>>Trùng</option>
						<option value="diff" <?php selected( $action_pair, 'diff' ); ?>>Khác</option>
					</select>
				</label>
				&nbsp;
				<button class="button button-primary">Lọc</button>
				<a class="button" href="<?php echo esc_url( self::page_url( self::PAGE_SHADOW ) ); ?>">Reset</a>
			</form>

			<p>
				<strong>Tổng (đã lọc):</strong> <?php echo esc_html( $total ); ?> rows ·
				<strong>Match TB:</strong> <?php echo esc_html( number_format( $avg, 1 ) ); ?> /100 ·
				<strong>Low-match (&lt;50):</strong> <?php echo esc_html( $lowm ); ?> ·
				<strong>Latency TB:</strong> legacy <?php echo esc_html( number_format( $avg_legacy_ms, 0 ) ); ?> ms vs shell <?php echo esc_html( number_format( $avg_shell_ms, 0 ) ); ?> ms
			</p>
			<p><em>Click vào hàng để xem reply đầy đủ. Mục tiêu: avg match ≥ 70 và shell_ms ≤ 1.5× legacy_ms trước khi cutover.</em></p>
			<style>
				.bizcity-diff tr.detail{display:none;background:#fafbfc;}
				.bizcity-diff tr.detail.open{display:table-row;}
				.bizcity-diff tr.summary{cursor:pointer;}
				.bizcity-diff tr.summary:hover{background:#f0f6fc;}
				.bizcity-diff .reply-box{white-space:pre-wrap;font-family:Menlo,Consolas,monospace;font-size:12px;background:#fff;border:1px solid #dcdcde;padding:8px;border-radius:4px;max-height:240px;overflow:auto;}
				.bizcity-diff td.legacy{border-right:2px solid #c3c4c7;}
				.bizcity-diff .col-msg{max-width:340px;color:#646970;font-style:italic;}
			</style>
			<table class="widefat striped bizcity-diff" id="bizcity-shadow-diff">
				<thead><tr>
					<th>ID</th><th>User</th><th>Message</th>
					<th>Legacy / Shell action</th>
					<th>Match</th><th>Legacy ms</th><th>Shell ms</th><th>When</th>
				</tr></thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="8"><em>Chưa có dữ liệu — bật <code>shadow_mode</code> để bắt đầu thu thập.</em></td></tr>
					<?php else : foreach ( $rows as $r ) :
						$legacy_reply = (string) $extract_reply( $r->legacy_resp );
						$shell_reply  = (string) $extract_reply( $r->shell_resp );
						$shell_perf   = $extract_perf( $r->shell_resp );
					?>
						<tr class="summary" data-target="row-<?php echo (int) $r->id; ?>">
							<td><?php echo (int) $r->id; ?></td>
							<td><?php echo (int) $r->user_id; ?></td>
							<td class="col-msg"><?php echo esc_html( mb_substr( (string) $r->message, 0, 90 ) ); ?></td>
							<td>
								<code><?php echo esc_html( $r->legacy_action ?: '∅' ); ?></code>
								/
								<code><?php echo esc_html( $r->shell_action  ?: '∅' ); ?></code>
							</td>
							<td><strong style="color:<?php echo (int)$r->match_score >= 50 ? 'green' : 'crimson'; ?>"><?php echo (int) $r->match_score; ?></strong></td>
							<td><?php echo (int) $r->legacy_ms; ?></td>
							<td><?php echo (int) $r->shell_ms; ?></td>
							<td><?php echo esc_html( $r->created_at ); ?></td>
						</tr>
						<tr class="detail" id="row-<?php echo (int) $r->id; ?>">
							<td colspan="8">
								<table style="width:100%;table-layout:fixed;">
									<thead><tr>
										<th style="width:50%;">Legacy reply</th>
										<th style="width:50%;">Shell reply</th>
									</tr></thead>
									<tbody><tr>
										<td class="legacy"><div class="reply-box"><?php echo esc_html( $legacy_reply !== '' ? $legacy_reply : '(không có reply — action=' . $r->legacy_action . ')' ); ?></div></td>
										<td><div class="reply-box"><?php echo esc_html( $shell_reply !== ''  ? $shell_reply  : '(không có reply — action=' . $r->shell_action  . ')' ); ?></div></td>
									</tr></tbody>
								</table>
								<?php if ( ! empty( $r->diff_summary ) ) : ?>
									<p style="margin-top:8px;"><strong>Diff:</strong> <code><?php echo esc_html( $r->diff_summary ); ?></code></p>
								<?php endif; ?>
								<?php if ( $shell_perf ) : ?>
									<p style="margin-top:8px;"><strong>Shell perf breakdown:</strong></p>
									<?php if ( ! empty( $shell_perf['phases'] ) ) : ?>
										<div style="margin-left:12px;"><em>Phases:</em>
											<?php foreach ( $shell_perf['phases'] as $pname => $pms ) : ?>
												<code style="margin-right:6px;"><?php echo esc_html( $pname ); ?>=<?php echo (int) $pms; ?>ms</code>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
									<?php if ( ! empty( $shell_perf['runner'] ) ) : ?>
										<div style="margin-left:12px;margin-top:4px;"><em>Runner steps:</em>
											<?php foreach ( $shell_perf['runner'] as $step ) :
												$kind = $step['kind'] ?? '?';
												$name = $step['name'] ?? '';
												$ms   = (int) ( $step['ms'] ?? 0 );
												$color = $ms >= 3000 ? 'crimson' : ( $ms >= 1500 ? 'darkorange' : '#1d6b1d' );
											?>
												<code style="margin-right:6px;color:<?php echo $color; ?>;"><?php echo esc_html( $kind ); ?><?php if ( $name ) : ?>:<?php echo esc_html( $name ); ?><?php endif; ?>=<?php echo $ms; ?>ms</code>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<script>
		(function(){
			document.querySelectorAll('#bizcity-shadow-diff tr.summary').forEach(function(row){
				row.addEventListener('click', function(){
					var id = row.getAttribute('data-target');
					var detail = document.getElementById(id);
					if (detail) detail.classList.toggle('open');
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Sprint 9 / B2 — Compute next-step recommendation based on 7-day daily averages.
	 *
	 * Promotion ladder:
	 *   • OFF (0%)  → 5%   when shadow has ≥3 days data avg ≥ 65 (warm-up)
	 *   • 5%        → 25%  when ≥3 consecutive days avg ≥ 70
	 *   • 25%       → 50%  when ≥4 consecutive days avg ≥ 75
	 *   • 50%       → 100% when ≥5 consecutive days avg ≥ 80
	 *   • else hold / revert if any day < 50
	 *
	 * @param array $daily      Rows from "GROUP BY DATE" query (asc order).
	 * @param int   $current    Current rollout_pct.
	 * @return array{recommendation:string,color:string,threshold:int}
	 */
	public static function compute_rollout_health( array $daily, int $current ): array {
		if ( empty( $daily ) ) {
			return [ 'recommendation' => 'Chưa đủ dữ liệu — cần bật shadow_mode để bắt đầu.', 'color' => '#646970', 'threshold' => 70 ];
		}

		$ladders = [
			0   => [ 'next' => 5,   'days' => 3, 'thr' => 65 ],
			5   => [ 'next' => 25,  'days' => 3, 'thr' => 70 ],
			25  => [ 'next' => 50,  'days' => 4, 'thr' => 75 ],
			50  => [ 'next' => 100, 'days' => 5, 'thr' => 80 ],
			100 => null,
		];
		$key  = isset( $ladders[ $current ] ) ? $current : self::nearest_rung( $current, array_keys( array_filter( $ladders ) ) );
		$rung = $ladders[ $key ] ?? null;

		// Revert check: if any day in the window < 50 average, recommend revert.
		foreach ( $daily as $d ) {
			if ( (float) $d->avg_match < 50 && (int) $d->n >= 5 ) {
				return [
					'recommendation' => sprintf( 'Có ngày avg < 50 (%s) — cân nhắc giảm rollout về %d%%.', esc_html( $d->d ), max( 0, $current - 25 ) ),
					'color'          => 'crimson',
					'threshold'      => 50,
				];
			}
		}

		if ( $rung === null ) {
			return [ 'recommendation' => 'Đang ở 100% — không còn bậc nào.', 'color' => 'green', 'threshold' => 80 ];
		}

		// Count last-N days where avg >= threshold.
		$tail   = array_slice( $daily, -$rung['days'] );
		$ok_all = ( count( $tail ) >= $rung['days'] );
		foreach ( $tail as $d ) {
			if ( (float) $d->avg_match < $rung['thr'] ) {
				$ok_all = false;
				break;
			}
		}

		if ( $ok_all ) {
			return [
				'recommendation' => sprintf( 'Đủ điều kiện nâng %d%% → %d%%.', $current, $rung['next'] ),
				'color'          => 'green',
				'threshold'      => $rung['thr'],
			];
		}

		return [
			'recommendation' => sprintf( 'Giữ %d%% — cần %d ngày liên tiếp avg ≥ %d.', $current, $rung['days'], $rung['thr'] ),
			'color'          => '#996600',
			'threshold'      => $rung['thr'],
		];
	}

	private static function nearest_rung( int $current, array $rungs ): int {
		$best = 0;
		foreach ( $rungs as $r ) {
			if ( $r <= $current && $r > $best ) { $best = $r; }
		}
		return $best;
	}
}
