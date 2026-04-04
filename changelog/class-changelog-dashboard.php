<?php
/**
 * Changelog Dashboard — Central hub linking all phase changelogs.
 *
 * Registered as hidden admin page: wp-admin/admin.php?page=bizcity-changelog
 * Each phase gets its own sub-page: wp-admin/admin.php?page=bizcity-changelog-1.5
 *
 * @package BizCity_Twin_AI
 * @since   4.2.0
 */

defined( 'ABSPATH' ) or die();

class BizCity_Changelog_Dashboard {

	/** @var BizCity_Changelog_Base[] Registered changelogs */
	private static $changelogs = [];

	/**
	 * Register a changelog instance.
	 */
	public static function register( BizCity_Changelog_Base $changelog ): void {
		self::$changelogs[ $changelog->get_phase_id() ] = $changelog;
	}

	/**
	 * Get all registered changelogs, sorted by phase ID.
	 */
	public static function get_all(): array {
		$list = self::$changelogs;
		uksort( $list, 'version_compare' );
		return $list;
	}

	/**
	 * Get a specific changelog by phase ID.
	 */
	public static function get( string $phase_id ): ?BizCity_Changelog_Base {
		return self::$changelogs[ $phase_id ] ?? null;
	}

	/**
	 * Register admin pages (dashboard + per-phase pages).
	 */
	public static function register_admin_pages(): void {
		// Main dashboard
		add_submenu_page(
			null,
			'BizCity Changelog Dashboard',
			'Changelog',
			'manage_options',
			'bizcity-changelog',
			[ static::class, 'render_dashboard' ]
		);

		// Per-phase pages
		foreach ( self::$changelogs as $id => $changelog ) {
			add_submenu_page(
				null,
				'Phase ' . $id . ' Changelog',
				'Phase ' . $id,
				'manage_options',
				'bizcity-changelog-' . $id,
				function () use ( $changelog ) {
					echo $changelog->render_html();
				}
			);
		}
	}

	/**
	 * Render the dashboard page.
	 */
	public static function render_dashboard(): void {
		$all = self::get_all();

		// Collect summaries
		$summaries = [];
		$total_pass = 0;
		$total_fail = 0;
		$total_skip = 0;
		$total_checks = 0;

		foreach ( $all as $changelog ) {
			$s = $changelog->get_summary();
			$summaries[] = $s;
			$total_pass   += $s['pass'];
			$total_fail   += $s['fail'];
			$total_skip   += $s['skip'];
			$total_checks += $s['total'];
		}

		$overall_score = $total_checks > 0 ? round( $total_pass / $total_checks * 100 ) : 0;

		echo '<div class="bizcity-changelog-dashboard" style="font-family:system-ui,-apple-system,sans-serif;max-width:1100px;margin:20px auto;padding:20px">';

		// ── Header ──
		echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">';
		echo '<div>';
		echo '<h1 style="margin:0 0 4px;font-size:24px">📋 BizCity Twin AI — Changelog Dashboard</h1>';
		echo '<p style="color:#64748b;margin:0">Roadmap verification · Mỗi phase = 1 changelog với giả lập check tự động</p>';
		echo '</div>';
		echo '<div style="text-align:right">';
		echo '<div style="font-size:32px;font-weight:bold;color:' . self::score_color( $overall_score ) . '">' . $overall_score . '%</div>';
		echo '<div style="color:#64748b;font-size:13px">' . $total_pass . '/' . $total_checks . ' verified</div>';
		echo '</div>';
		echo '</div>';

		// ── Overall progress bar ──
		echo '<div style="background:#f1f5f9;border-radius:8px;padding:16px;margin-bottom:24px">';
		echo '<div style="display:flex;gap:24px;margin-bottom:8px">';
		echo '<span>✅ <strong>' . $total_pass . '</strong> passed</span>';
		echo '<span>❌ <strong>' . $total_fail . '</strong> failed</span>';
		echo '<span>⏭️ <strong>' . $total_skip . '</strong> skipped</span>';
		echo '<span>📦 <strong>' . count( $all ) . '</strong> phases</span>';
		echo '</div>';
		echo '<div style="background:#e2e8f0;border-radius:4px;height:10px;overflow:hidden">';
		if ( $total_checks > 0 ) {
			$pass_pct = round( $total_pass / $total_checks * 100 );
			$fail_pct = round( $total_fail / $total_checks * 100 );
			$skip_pct = 100 - $pass_pct - $fail_pct;
			echo '<div style="display:flex;height:100%">';
			echo '<div style="background:#059669;width:' . $pass_pct . '%"></div>';
			echo '<div style="background:#ef4444;width:' . $fail_pct . '%"></div>';
			echo '<div style="background:#94a3b8;width:' . $skip_pct . '%"></div>';
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';

		// ── Phase cards ──
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px">';

		foreach ( $summaries as $s ) {
			$score      = $s['score'];
			$color      = self::score_color( $score );
			$border     = $s['fail'] > 0 ? '#ef4444' : ( $score === 100 ? '#059669' : '#d97706' );
			$phase_url  = admin_url( 'admin.php?page=bizcity-changelog-' . $s['phase_id'] );

			echo '<a href="' . esc_url( $phase_url ) . '" style="text-decoration:none;color:inherit">';
			echo '<div style="border:1px solid #e2e8f0;border-left:4px solid ' . $border . ';border-radius:8px;padding:16px;background:white;transition:box-shadow .2s;cursor:pointer" onmouseover="this.style.boxShadow=\'0 4px 12px rgba(0,0,0,.08)\'" onmouseout="this.style.boxShadow=\'none\'">';

			// Card header
			echo '<div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:8px">';
			echo '<div>';
			echo '<div style="font-size:13px;color:#94a3b8;font-weight:600">PHASE ' . esc_html( $s['phase_id'] ) . '</div>';
			echo '<div style="font-size:16px;font-weight:600;margin-top:2px">' . esc_html( $s['phase_title'] ) . '</div>';
			echo '</div>';
			echo '<div style="font-size:24px;font-weight:bold;color:' . $color . '">' . $score . '%</div>';
			echo '</div>';

			// Description
			echo '<p style="color:#64748b;font-size:13px;margin:0 0 12px;line-height:1.4">' . esc_html( $s['description'] ) . '</p>';

			// Stats bar
			echo '<div style="display:flex;gap:12px;font-size:12px;color:#94a3b8">';
			echo '<span>✅ ' . $s['pass'] . '</span>';
			if ( $s['fail'] > 0 ) {
				echo '<span style="color:#ef4444">❌ ' . $s['fail'] . '</span>';
			}
			if ( $s['skip'] > 0 ) {
				echo '<span>⏭️ ' . $s['skip'] . '</span>';
			}
			echo '<span style="margin-left:auto">' . esc_html( $s['dates']['updated'] ?? '' ) . '</span>';
			echo '</div>';

			// Mini progress bar
			echo '<div style="background:#f1f5f9;border-radius:3px;height:4px;margin-top:8px;overflow:hidden">';
			echo '<div style="background:' . $color . ';height:100%;width:' . $score . '%"></div>';
			echo '</div>';

			// Changelog groups preview
			if ( ! empty( $s['changelog'] ) ) {
				echo '<div style="margin-top:10px;padding-top:8px;border-top:1px solid #f1f5f9;font-size:12px;color:#94a3b8">';
				$groups = [];
				foreach ( $s['changelog'] as $g ) {
					$groups[] = ( $g['icon'] ?? '' ) . ' ' . $g['group'];
				}
				echo esc_html( implode( ' · ', array_slice( $groups, 0, 3 ) ) );
				if ( count( $groups ) > 3 ) {
					echo ' +' . ( count( $groups ) - 3 );
				}
				echo '</div>';
			}

			echo '</div>';
			echo '</a>';
		}

		echo '</div>'; // grid

		// ── Roadmap Timeline ──
		echo '<div style="margin-top:32px;padding-top:20px;border-top:2px solid #e2e8f0">';
		echo '<h2 style="margin:0 0 16px;font-size:18px">🗺️ Roadmap Timeline</h2>';

		echo '<div style="position:relative;padding-left:24px">';
		foreach ( $summaries as $i => $s ) {
			$is_last = $i === count( $summaries ) - 1;
			$score   = $s['score'];
			$dot_color = $score === 100 ? '#059669' : ( $s['fail'] > 0 ? '#ef4444' : '#d97706' );

			// Vertical line
			if ( ! $is_last ) {
				echo '<div style="position:absolute;left:9px;top:' . ( $i * 56 + 20 ) . 'px;width:2px;height:36px;background:#e2e8f0"></div>';
			}

			echo '<div style="position:relative;margin-bottom:20px">';
			// Dot
			echo '<div style="position:absolute;left:-24px;top:4px;width:18px;height:18px;border-radius:50%;background:' . $dot_color . ';border:2px solid white;box-shadow:0 0 0 2px ' . $dot_color . '"></div>';

			echo '<div style="display:flex;justify-content:space-between;align-items:center">';
			echo '<div>';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=bizcity-changelog-' . $s['phase_id'] ) ) . '" style="font-weight:600;color:#1e293b;text-decoration:none">';
			echo 'Phase ' . esc_html( $s['phase_id'] ) . ' — ' . esc_html( $s['phase_title'] );
			echo '</a>';
			echo '<div style="font-size:12px;color:#94a3b8">' . esc_html( $s['dates']['started'] ?? '' ) . ' → ' . esc_html( $s['dates']['updated'] ?? '' ) . '</div>';
			echo '</div>';
			echo '<div style="font-weight:bold;color:' . self::score_color( $score ) . '">' . $score . '%</div>';
			echo '</div>';

			echo '</div>';
		}
		echo '</div>';
		echo '</div>';

		// ── Footer ──
		echo '<div style="margin-top:24px;padding:12px;background:#f8fafc;border-radius:6px;font-size:13px;color:#94a3b8;text-align:center">';
		echo 'BizCity Twin AI · Changelog Dashboard · Generated ' . current_time( 'Y-m-d H:i' );
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Get color based on score percentage.
	 */
	private static function score_color( int $score ): string {
		if ( $score === 100 ) {
			return '#059669';
		}
		if ( $score >= 80 ) {
			return '#d97706';
		}
		return '#ef4444';
	}
}
