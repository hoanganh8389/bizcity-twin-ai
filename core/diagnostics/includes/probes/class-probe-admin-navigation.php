<?php
/**
 * DDV probe for the unified admin-navigation contract and runtime menu tree.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-11 (PHASE-1.26-CONTRACT)
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Admin_Navigation', false ) ) {
	return;
}

final class BizCity_Probe_Admin_Navigation implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.admin_menu.unified'; }
	public function label(): string { return 'Unified admin navigation contract'; }
	public function description(): string { return 'Kiểm tra ba nhóm Settings/Workspace/Diagnostics, alias, duplicate và boundary tools/network.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 19; }
	public function icon(): string { return 'layout-dashboard'; }
	public function estimate_ms(): int { return 300; }

	public function precondition() {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return new WP_Error( 'admin_navigation_context', 'Probe cần chạy trong wp-admin.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$files = array(
			'core/twin-core/contracts/framework-contracts.php',
			'core/twin-core/contracts/class-admin-navigation-registry.php',
			'core/twin-core/contracts/schema/manifest.schema.json',
			'core/twin-core/contracts/schema/public/v1/admin-navigation.schema.json',
			'includes/class-admin-menu.php',
		);
		$missing = array();
		foreach ( $files as $relative ) {
			if ( ! is_readable( $root . $relative ) ) {
				$missing[] = $relative;
			}
		}
		$steps[] = array(
			'label'  => 'Disk — navigation contract and central menu files exist',
			'status' => empty( $missing ) ? 'pass' : 'fail',
			'detail' => empty( $missing ) ? implode( ', ', $files ) : implode( ', ', $missing ),
		);
		if ( ! empty( $missing ) ) {
			return array( 'status' => 'fail', 'summary' => 'Admin navigation contract file is missing.', 'steps' => $steps );
		}

		$classes_ok = class_exists( 'BizCity_Admin_Menu' )
			&& interface_exists( 'BizCity_Admin_Navigation_Provider_Interface' )
			&& class_exists( 'BizCity_Admin_Navigation_Item' )
			&& class_exists( 'BizCity_Admin_Navigation_Registry' );
		$steps[] = array(
			'label'  => 'Loader — registry and opt-in navigation contract are loaded',
			'status' => $classes_ok ? 'pass' : 'fail',
			'detail' => $classes_ok ? 'BizCity_Admin_Menu + provider interface + DTO' : 'Missing central navigation class, interface or DTO',
		);
		if ( ! $classes_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Admin navigation contract is not loaded.', 'steps' => $steps );
		}

		$slots = BizCity_Admin_Navigation_Registry::slots();
		$extension_slot_ok = in_array( 'workspace.extensions', $slots['workspace'] ?? array(), true );
		$steps[] = array(
			'label'  => 'Loader — Workspace has a deterministic extension slot',
			'status' => $extension_slot_ok ? 'pass' : 'fail',
			'detail' => $extension_slot_ok ? 'workspace.extensions' : 'workspace.extensions is missing',
		);
		if ( class_exists( 'BZGoogle_Admin' ) ) {
			$google_key  = 'bizcity-ai:bzgoogle-settings';
			$google_item = BizCity_Admin_Navigation_Registry::all()[ $google_key ] ?? null;
			$google_ok   = is_array( $google_item )
				&& ( $google_item['slot'] ?? '' ) === 'settings.integrations'
				&& ( $google_item['origin'] ?? '' ) === 'bundle';
			$steps[] = array(
				'label'  => 'Runtime — Google bundle provider occupies Settings integrations slot',
				'status' => $google_ok ? 'pass' : 'fail',
				'detail' => $google_ok ? 'bzgoogle-settings → settings.integrations' : 'Google provider metadata missing or misplaced',
			);
		}

		// [2026-08-19 Johnny Chu] HOTFIX — validate Twin Plugins and Tools-mounted Twin Diagnostics groups.
		$expected_groups = array(
			'bizcity-ai',
			'bizcity-twin-workspace',
			'bizcity-twin-plugins',
		);
		$top_level = array();
		global $menu, $submenu;
		if ( is_array( $menu ) ) {
			foreach ( $menu as $item ) {
				if ( isset( $item[2] ) ) {
					$top_level[] = (string) $item[2];
				}
			}
		}
		$missing_groups = array_values( array_diff( $expected_groups, $top_level ) );
		$steps[] = array(
			'label'  => 'Runtime — exactly three canonical top-level groups are present',
			'status' => empty( $missing_groups ) ? 'pass' : 'fail',
			'detail' => empty( $missing_groups ) ? implode( ', ', $expected_groups ) : 'Missing: ' . implode( ', ', $missing_groups ),
		);

		$forbidden_top_level = array(
			'bizcity-crm',
			'bizcity-zalo-bots',
			'bizcity-facebook-bots',
			'bizcity-webchat',
			'bztimg-dashboard',
			'bizcity-kling',
			'bizcity-automation',
			'bizcity-knowledge',
			'bizcity-skills-hub',
			'bizchat-gateway',
			'bccm_user_profiles',
			'bcpro_my_astro',
		);
		$legacy_visible = array_values( array_intersect( $forbidden_top_level, $top_level ) );
		$steps[] = array(
			'label'  => 'Runtime — no legacy module top-level menu remains',
			'status' => empty( $legacy_visible ) ? 'pass' : 'fail',
			'detail' => empty( $legacy_visible ) ? 'No forbidden legacy top-level slug' : implode( ', ', $legacy_visible ),
		);

		$pair_keys = array();
		$duplicate_pairs = array();
		if ( is_array( $submenu ) ) {
			foreach ( $submenu as $parent => $items ) {
				if ( ! is_array( $items ) ) {
					continue;
				}
				foreach ( $items as $item ) {
					if ( ! isset( $item[2] ) ) {
						continue;
					}
					$key = (string) $parent . ':' . (string) $item[2];
					if ( isset( $pair_keys[ $key ] ) ) {
						$duplicate_pairs[] = $key;
					}
					$pair_keys[ $key ] = true;
				}
			}
		}
		$steps[] = array(
			'label'  => 'Runtime — no duplicate parent/slug registration',
			'status' => empty( $duplicate_pairs ) ? 'pass' : 'fail',
			'detail' => empty( $duplicate_pairs ) ? 'All parent/slug pairs are unique' : implode( ', ', $duplicate_pairs ),
		);

		$alias_specs = array(
			array( 'bizcity-twinchat', '' ),
			array( 'bizcity-channels', '' ),
			array( 'bizcity-kg-hub', 'BizCity_KG_Admin_Menu' ),
			array( 'bizcity-crm', 'BizCity_CRM_Admin_Menu' ),
			array( 'bizcity-automation', 'BizCity_Automation_Admin_SPA' ),
		);
		$missing_aliases = array();
		foreach ( $alias_specs as $spec ) {
			if ( $spec[1] !== '' && ! class_exists( $spec[1] ) ) {
				continue;
			}
			$found = false;
			if ( is_array( $submenu ) ) {
				foreach ( $submenu as $items ) {
					if ( ! is_array( $items ) ) {
						continue;
					}
					foreach ( $items as $item ) {
						if ( isset( $item[2] ) && (string) $item[2] === $spec[0] ) {
							$found = true;
							break 2;
						}
					}
				}
			}
			if ( ! $found ) {
				$missing_aliases[] = $spec[0];
			}
		}
		$steps[] = array(
			'label'  => 'Runtime — migrated legacy slugs remain registered',
			'status' => empty( $missing_aliases ) ? 'pass' : 'fail',
			'detail' => empty( $missing_aliases ) ? 'Known aliases are present' : 'Missing: ' . implode( ', ', $missing_aliases ),
		);

		$tools_diagnostics = array();
		if ( is_array( $submenu ) && isset( $submenu['tools.php'] ) && is_array( $submenu['tools.php'] ) ) {
			foreach ( $submenu['tools.php'] as $item ) {
				if ( isset( $item[2] ) && in_array( (string) $item[2], array( 'bizcity-twin-diagnostics', 'bizcity-twin-event-inspector' ), true ) ) {
					$tools_diagnostics[] = (string) $item[2];
				}
			}
		}
		$tools_has_hub = in_array( 'bizcity-twin-diagnostics', $tools_diagnostics, true );
		$tools_has_event_inspector = in_array( 'bizcity-twin-event-inspector', $tools_diagnostics, true );
		$steps[] = array(
			'label'  => 'Runtime — Twin Diagnostics is the only canonical Diagnostics Tools entry',
			'status' => $tools_has_hub && ! $tools_has_event_inspector ? 'pass' : 'fail',
			'detail' => $tools_has_hub && ! $tools_has_event_inspector ? 'Twin Diagnostics is mounted under tools.php' : 'Twin Diagnostics Tools entry is missing or Event Inspector is exposed separately',
		);

		$source = (string) file_get_contents( $root . 'includes/class-admin-menu.php' );
		$site_network_ok = strpos( $source, "add_action( 'network_admin_menu'" ) === false;
		$steps[] = array(
			'label'  => 'Runtime — site registry does not register network menu hooks',
			'status' => $site_network_ok ? 'pass' : 'fail',
			'detail' => $site_network_ok ? 'Network scope remains outside site registry' : 'Central site registry contains network_admin_menu hook',
		);

		$ok = $extension_slot_ok && empty( $missing_groups ) && empty( $legacy_visible ) && empty( $duplicate_pairs )
			&& empty( $missing_aliases ) && $tools_has_hub && ! $tools_has_event_inspector && $site_network_ok;
		return array(
			'status'  => $ok ? 'pass' : 'fail',
			'summary' => $ok ? 'Unified admin navigation contract passed.' : 'Unified admin navigation contract has runtime failures.',
			'fix_hint'=> 'Keep core/module pages in Twin Workspace, bundled plugin pages in Twin Plugins, and Diagnostics under Tools.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Admin_Navigation';
	return $probes;
} );
