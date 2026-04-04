<?php
/**
 * Changelog System Bootstrap
 *
 * Loads the base class, dashboard, and all phase changelogs.
 * Registers admin pages for each.
 *
 * Usage: require_once from bizcity-twin-ai.php (admin only)
 * URL:   wp-admin/admin.php?page=bizcity-changelog
 *
 * @package BizCity_Twin_AI
 * @since   4.2.0
 */

defined( 'ABSPATH' ) or die();

// ── Core ──
require_once __DIR__ . '/class-changelog-base.php';
require_once __DIR__ . '/class-changelog-dashboard.php';

// ── Phase Changelogs (sorted by phase ID) ──
require_once __DIR__ . '/changelog-phase0.php';
require_once __DIR__ . '/changelog-phase1.php';
require_once __DIR__ . '/changelog-phase11.php';
require_once __DIR__ . '/changelog-phase12.php';
require_once __DIR__ . '/changelog-phase13.php';
require_once __DIR__ . '/changelog-phase14.php';
require_once __DIR__ . '/changelog-phase15.php';
require_once __DIR__ . '/changelog-phase16.php';
require_once __DIR__ . '/changelog-phase17.php';

// ── Register changelogs ──
BizCity_Changelog_Dashboard::register( new BizCity_Changelog_Phase0() );
BizCity_Changelog_Dashboard::register( new BizCity_Changelog_Phase1() );
BizCity_Changelog_Dashboard::register( new BizCity_Changelog_Phase11() );
BizCity_Changelog_Dashboard::register( new BizCity_Changelog_Phase12() );
BizCity_Changelog_Dashboard::register( new BizCity_Changelog_Phase13() );
BizCity_Changelog_Dashboard::register( new BizCity_Changelog_Phase14() );
BizCity_Changelog_Dashboard::register( new BizCity_Changelog_Phase15() );
BizCity_Changelog_Dashboard::register( new BizCity_Changelog_Phase16() );
BizCity_Changelog_Dashboard::register( new BizCity_Changelog_Phase17() );

// ── Register admin pages ──
add_action( 'admin_menu', [ 'BizCity_Changelog_Dashboard', 'register_admin_pages' ] );

// ── WP-CLI support ──
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'bizcity changelog', function ( $args ) {
		$phase_id = $args[0] ?? null;

		if ( $phase_id ) {
			// Run single phase
			$changelog = BizCity_Changelog_Dashboard::get( $phase_id );
			if ( ! $changelog ) {
				WP_CLI::error( "Phase {$phase_id} not found. Available: " . implode( ', ', array_keys( BizCity_Changelog_Dashboard::get_all() ) ) );
				return;
			}
			render_cli_changelog( $changelog );
		} else {
			// Run all
			WP_CLI::log( '📋 BizCity Twin AI — Changelog Dashboard' );
			WP_CLI::log( str_repeat( '═', 60 ) );
			WP_CLI::log( '' );

			$all = BizCity_Changelog_Dashboard::get_all();
			$total_pass = 0;
			$total_fail = 0;
			$total_all  = 0;

			foreach ( $all as $changelog ) {
				$data = $changelog->run();
				$score = $data['total'] > 0 ? round( $data['pass'] / $data['total'] * 100 ) : 0;
				$status_icon = $data['fail'] > 0 ? '❌' : ( $score === 100 ? '✅' : '⚠️' );

				WP_CLI::log( sprintf(
					'%s Phase %s — %s : %d/%d (%d%%)',
					$status_icon,
					$data['phase_id'],
					$data['phase_title'],
					$data['pass'],
					$data['total'],
					$score
				) );

				$total_pass += $data['pass'];
				$total_fail += $data['fail'];
				$total_all  += $data['total'];
			}

			WP_CLI::log( '' );
			WP_CLI::log( str_repeat( '─', 60 ) );
			$overall = $total_all > 0 ? round( $total_pass / $total_all * 100 ) : 0;
			WP_CLI::log( "TOTAL: {$total_pass}/{$total_all} ({$overall}%)" );

			if ( $total_fail > 0 ) {
				WP_CLI::warning( "{$total_fail} check(s) failed across all phases" );
			} else {
				WP_CLI::success( 'All changelog checks passed!' );
			}
		}
	} );
}

/**
 * Render a single changelog to CLI.
 */
function render_cli_changelog( BizCity_Changelog_Base $changelog ): void {
	$data = $changelog->run();

	WP_CLI::log( '📋 Phase ' . $data['phase_id'] . ' — ' . $data['phase_title'] );
	WP_CLI::log( $data['description'] );
	WP_CLI::log( str_repeat( '─', 60 ) );

	foreach ( $data['results'] as $r ) {
		$line = $r['icon'] . ' [' . $r['id'] . '] ' . $r['name'];
		if ( $r['detail'] ) {
			$line .= ' — ' . $r['detail'];
		}
		WP_CLI::log( $line );
	}

	WP_CLI::log( '' );
	$score = $data['total'] > 0 ? round( $data['pass'] / $data['total'] * 100 ) : 0;
	WP_CLI::log( "{$data['pass']}/{$data['total']} verified ({$score}%)" );

	if ( $data['fail'] > 0 ) {
		WP_CLI::error( $data['fail'] . ' check(s) failed', false );
	} else {
		WP_CLI::success( 'All checks passed!' );
	}
}
