<?php
/**
 * BizCity Diagnostics — twinbrain.vertical_policy_e2e probe (WP7 §item 8).
 *
 * PHASE-1.22A WP7 "Verify one external vertical plugin end-to-end, not only
 * builtin modes" — verifies the EXTERNAL plugin vertical (`bizcoach-pro`,
 * id `astro`) resolves through the canonical bridge registry and is subject
 * to the WP7 server-authorized mode/plan/guest policy fix
 * (`BizCity_TwinBrain_Runtime::vertical_policy_allows()`,
 * `class-twinbrain-runtime.php:2619`), end-to-end from the manifest
 * declaration down to the policy decision — not merely that the classes
 * exist.
 *
 * Distinct from the existing astro probe family (`astro.readiness_gate`,
 * `astro.data_action_required_event`, `twinbrain.astro_mode`, chart/transit
 * probes): those test astro's OWN internal chart/transit/readiness pipeline.
 * This probe tests the SEPARATE registration/policy layer — item 1's
 * registry parity and item 7's plan/guest enforcement — using the one real
 * external vertical this codebase has, per the WP7 acceptance criterion.
 *
 * No LLM / gateway call and no real user session is impersonated (this
 * runs as the admin invoking Diagnostics, and `wp_set_current_user()` would
 * mutate global state — out of scope for a read-only probe per R-DDV rule
 * 1). The plan/guest RANK COMPARISON logic itself is tested in isolation
 * with synthetic rows and a temporarily-overridden tier filter (restored
 * immediately after), the same technique
 * `class-probe-twinweb-app-catalog.php` already uses. The guest_allowed
 * branch is tested against the real registry row only when
 * `BizCity_TwinWeb_Identity` is not loaded (its `is_guest` is derived from
 * `get_current_user_id()`, the ambient admin session, which a probe cannot
 * safely override) — skipped with an explicit reason otherwise, not silently
 * assumed passing.
 *
 * 3-layer evidence (R-DDV):
 * - Disk:    registry file + bizcoach-pro/manifest.json both declare 'astro'.
 * - Loader:  registry + runtime classes/methods are reachable (Reflection
 *            for the private policy methods, same technique as
 *            astro.readiness_gate).
 * - Runtime: registry row shape is correct; resolve_vertical_binding() binds
 *            'astro' end-to-end for an authorized identity; policy_allows()
 *            denies a synthetic below-tier request and allows an at-tier one.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_TwinBrain_Vertical_Policy_E2E', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Vertical_Policy_E2E implements BizCity_Diagnostics_Probe {

	public function id(): string       { return 'twinbrain.vertical_policy_e2e'; }
	public function label(): string    { return 'Vertical Bridge Registration + Policy (astro, end-to-end)'; }
	public function description(): string {
		return 'Xác minh vertical bên ngoài (bizcoach-pro/astro) resolve qua canonical bridge registry và bị enforce đúng bởi WP7 mode/plan/guest policy — không chỉ tồn tại class.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int       { return 46; }
	public function icon(): string     { return 'compass'; }
	public function estimate_ms(): int { return 260; }

	public function precondition() {
		if ( ! defined( 'BIZCITY_TWINBRAIN_DIR' ) ) {
			return 'BIZCITY_TWINBRAIN_DIR chưa định nghĩa — twinbrain chưa active.';
		}
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return 'WP_PLUGIN_DIR chưa định nghĩa.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps    = array();
		$failures = array();
		$warnings = array();

		/* ── Disk ──────────────────────────────────────────────────────── */
		$registry_file  = BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-vertical-bridge-registry.php';
		$manifest_file  = WP_PLUGIN_DIR . '/bizcoach-pro/manifest.json';
		$registry_src   = file_exists( $registry_file ) ? (string) file_get_contents( $registry_file ) : '';
		$manifest_raw   = file_exists( $manifest_file ) ? (string) file_get_contents( $manifest_file ) : '';
		$manifest_json  = $manifest_raw !== '' ? json_decode( $manifest_raw, true ) : null;

		$registry_has_astro = ( strpos( $registry_src, "'astro'" ) !== false );
		$manifest_has_astro = is_array( $manifest_json )
			&& isset( $manifest_json['vertical_modes'] )
			&& is_array( $manifest_json['vertical_modes'] )
			&& array_filter( $manifest_json['vertical_modes'], static function ( $row ) {
				return is_array( $row ) && ( $row['id'] ?? '' ) === 'astro';
			} );

		$disk_ok = ( $registry_src !== '' && $registry_has_astro && $manifest_raw !== '' && $manifest_has_astro );
		$steps[] = array(
			'layer' => 'disk',
			'ok'    => $disk_ok,
			'msg'   => $disk_ok
				? 'Registry file + bizcoach-pro/manifest.json both declare astro.'
				: 'Missing: registry file, astro row in registry, manifest file, or astro in manifest.vertical_modes.',
		);
		if ( ! $disk_ok ) {
			$failures[] = 'disk_declaration_missing';
		}

		/* ── Loader ────────────────────────────────────────────────────── */
		$registry_class_ok = class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' );
		$runtime_class_ok  = class_exists( 'BizCity_TwinBrain_Runtime' );
		$binding_method_ok = $runtime_class_ok && method_exists( 'BizCity_TwinBrain_Runtime', 'resolve_vertical_binding' );
		$policy_method_ok  = $runtime_class_ok && method_exists( 'BizCity_TwinBrain_Runtime', 'vertical_policy_allows' );
		$loader_ok = ( $registry_class_ok && $runtime_class_ok && $binding_method_ok && $policy_method_ok );
		$steps[] = array(
			'layer' => 'loader',
			'ok'    => $loader_ok,
			'msg'   => $loader_ok
				? 'Registry + Runtime classes loaded; resolve_vertical_binding() and vertical_policy_allows() present.'
				: 'Missing one of: Vertical_Bridge_Registry class, Runtime class, resolve_vertical_binding(), vertical_policy_allows().',
		);
		if ( ! $loader_ok ) {
			$failures[] = 'loader_symbols_missing';
			return array(
				'status'   => 'fail',
				'steps'    => $steps,
				'summary'  => 'Loader layer failed — cannot proceed to runtime checks.',
				'error'    => implode( ',', $failures ),
				'fix_hint' => 'Kiểm tra core/twinbrain/includes/class-twinbrain-vertical-bridge-registry.php và class-twinbrain-runtime.php đã load.',
			);
		}

		/* ── Runtime ───────────────────────────────────────────────────── */
		// Defined up front (not just inside the row_ok branch below) so the
		// policy rank comparison further down always has a value, even if the
		// registry lookup itself fails.
		$probe_user_id = (int) get_current_user_id();
		if ( $probe_user_id <= 0 ) {
			$probe_user_id = 1; // Diagnostics runs authenticated in practice; 1 is a safe non-zero fallback for CLI.
		}

		try {
			$row = BizCity_TwinBrain_Vertical_Bridge_Registry::get( 'astro' );
			$row_ok = is_array( $row )
				&& ( $row['id'] ?? '' ) === 'astro'
				&& ( $row['owner_plugin'] ?? '' ) === 'bizcoach-pro'
				&& array_key_exists( 'min_plan', $row )
				&& array_key_exists( 'guest_allowed', $row );
			$steps[] = array(
				'layer' => 'runtime',
				'ok'    => $row_ok,
				'msg'   => $row_ok
					? sprintf(
						'Registry::get(astro) resolved — owner=%s, min_plan=%s, guest_allowed=%s.',
						(string) ( $row['owner_plugin'] ?? '' ),
						(string) ( $row['min_plan'] ?? '' ),
						! empty( $row['guest_allowed'] ) ? 'true' : 'false'
					)
					: 'Registry::get(astro) returned an unexpected shape: ' . wp_json_encode( $row ),
			);
			if ( ! $row_ok ) {
				$failures[] = 'registry_row_shape';
			}
		} catch ( \Throwable $e ) {
			$steps[] = array( 'layer' => 'runtime', 'ok' => false, 'msg' => 'Registry::get(astro) exception: ' . $e->getMessage() );
			$failures[] = 'registry_get_exception';
			$row = null;
		}

		if ( $row_ok ?? false ) {
			try {
				$runtime = BizCity_TwinBrain_Runtime::instance();
				$rm_bind = new ReflectionMethod( 'BizCity_TwinBrain_Runtime', 'resolve_vertical_binding' );
				$rm_bind->setAccessible( true );

				// End-to-end bind: a real, non-guest, non-zero identity requesting
				// the real astro row (min_plan=free) must bind successfully.
				$bound = $rm_bind->invoke( $runtime, array( 'web_mode' => 'astro', 'user_id' => $probe_user_id ) );
				$bind_ok = is_array( $bound )
					&& ( $bound['vertical_id'] ?? '' ) === 'astro'
					&& ( $bound['web_mode'] ?? '' ) === 'astro';
				$steps[] = array(
					'layer' => 'runtime',
					'ok'    => $bind_ok,
					'msg'   => $bind_ok
						? 'resolve_vertical_binding(web_mode=astro) bound vertical_id=astro end-to-end for an authorized identity.'
						: 'resolve_vertical_binding(web_mode=astro) did not bind as expected: ' . wp_json_encode( array(
							'vertical_id' => $bound['vertical_id'] ?? null,
							'web_mode'    => $bound['web_mode'] ?? null,
						) ),
				);
				if ( ! $bind_ok ) {
					$failures[] = 'binding_e2e_failed';
				}
			} catch ( \Throwable $e ) {
				$steps[] = array( 'layer' => 'runtime', 'ok' => false, 'msg' => 'resolve_vertical_binding() exception: ' . $e->getMessage() );
				$failures[] = 'binding_e2e_exception';
			}
		}

		// Policy rank comparison, isolated with synthetic rows so the result
		// does not depend on the site's real membership/tier configuration.
		try {
			$rm_policy = new ReflectionMethod( 'BizCity_TwinBrain_Runtime', 'vertical_policy_allows' );
			$rm_policy->setAccessible( true );
			$runtime = isset( $runtime ) ? $runtime : BizCity_TwinBrain_Runtime::instance();

			$forced_tier = 'free';
			$tier_filter = static function () use ( &$forced_tier ) {
				return $forced_tier;
			};
			add_filter( 'bizcity_twinweb_user_tier', $tier_filter, 9999 );

			$denied = $rm_policy->invoke( $runtime, array( 'min_plan' => 'pro', 'guest_allowed' => true ), array( 'user_id' => $probe_user_id ) );
			$allowed = $rm_policy->invoke( $runtime, array( 'min_plan' => 'free', 'guest_allowed' => true ), array( 'user_id' => $probe_user_id ) );

			remove_filter( 'bizcity_twinweb_user_tier', $tier_filter, 9999 );

			$rank_ok = ( $denied === false ) && ( $allowed === true );
			$steps[] = array(
				'layer' => 'runtime',
				'ok'    => $rank_ok,
				'msg'   => $rank_ok
					? 'vertical_policy_allows() rank comparison correct: free-tier denied min_plan=pro, allowed min_plan=free.'
					: sprintf( 'vertical_policy_allows() rank comparison wrong: denied=%s (expected false), allowed=%s (expected true).',
						wp_json_encode( $denied ), wp_json_encode( $allowed ) ),
			);
			if ( ! $rank_ok ) {
				$failures[] = 'policy_rank_comparison';
			}

			if ( class_exists( 'BizCity_TwinWeb_Identity', false ) ) {
				$warnings[] = 'guest_allowed_check_skipped_identity_loaded';
				$steps[] = array(
					'layer' => 'runtime',
					'ok'    => true,
					'msg'   => 'guest_allowed denial path skipped: BizCity_TwinWeb_Identity is loaded, so is_guest follows the ambient admin session (always false while Diagnostics runs), not a value this probe can safely fake without mutating global state.',
				);
			} else {
				$guest_denied = $rm_policy->invoke( $runtime, array( 'min_plan' => 'free', 'guest_allowed' => false ), array( 'user_id' => 0 ) );
				$guest_ok = ( $guest_denied === false );
				$steps[] = array(
					'layer' => 'runtime',
					'ok'    => $guest_ok,
					'msg'   => $guest_ok
						? 'vertical_policy_allows() correctly denies guest_allowed=false for user_id=0.'
						: 'vertical_policy_allows() did not deny a guest_allowed=false row for user_id=0.',
				);
				if ( ! $guest_ok ) {
					$failures[] = 'policy_guest_denial';
				}
			}
		} catch ( \Throwable $e ) {
			$steps[] = array( 'layer' => 'runtime', 'ok' => false, 'msg' => 'vertical_policy_allows() exception: ' . $e->getMessage() );
			$failures[] = 'policy_check_exception';
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'steps'    => $steps,
				'summary'  => 'Vertical policy E2E issue(s): ' . implode( ', ', $failures ),
				'error'    => implode( ';', $failures ),
				'fix_hint' => 'Kiểm tra BizCity_TwinBrain_Vertical_Bridge_Registry::get(astro), resolve_vertical_binding() và vertical_policy_allows() trong class-twinbrain-runtime.php.',
			);
		}

		return array(
			'status'  => empty( $warnings ) ? 'pass' : 'warn',
			'steps'   => $steps,
			'summary' => empty( $warnings )
				? 'astro resolves end-to-end through the bridge registry and the mode/plan/guest policy correctly gates it.'
				: 'astro resolves end-to-end and plan-rank policy is correct; guest_allowed denial path not exercised (see step notes).',
		);
	}

	public function cleanup(): void {
		// Read-only probe. Any temporary filter added during run() is already
		// removed inline, immediately after use.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Vertical_Policy_E2E';
	return $list;
} );
