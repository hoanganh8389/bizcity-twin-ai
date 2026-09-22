<?php
/**
 * Registration-contract probe for the Twin Brain admin menu owner.
 *
 * PHASE-0-SETTING-PANEL-G6-HOTFIX3. Guards the regression that made the whole
 * **Twin Brain** menu disappear from wp-admin when the operator opened the
 * TwinChat admin shell (`?page=bizcity-twinchat`).
 *
 * Root cause being locked in place:
 *   The menu used to be registered inside `core/twinbrain/bootstrap.php`, which
 *   is gated in `bizcity-twin-ai.php` behind
 *   `$_bizcity_admin_ctx && !$_bizcity_twinchat_admin_shell_request`. Inside the
 *   TwinChat shell the gate was false, the bootstrap never loaded, and the menu
 *   never reached `admin_menu`.
 *
 * This probe is Disk + Loader + static registration-contract only. It is
 * deliberately CLI-safe: it never fires `admin_menu`, builds no `$menu` tree and
 * creates no fixture. Browser visibility remains a separate acceptance step.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-16 (PHASE-0-SETTING-PANEL-G6-HOTFIX3)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_bizcity_safe_loader = dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_bizcity_safe_loader ) && is_readable( $_bizcity_safe_loader ) ) {
		require_once $_bizcity_safe_loader;
	}
	unset( $_bizcity_safe_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	return;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_Admin_Menu_Twin_Brain_Owner', false ) ) {
	return;
}

final class BizCity_Probe_Admin_Menu_Twin_Brain_Owner implements BizCity_Diagnostics_Probe {

	const PARENT_SLUG = 'bizcity-twin-brain';
	const OWNER_FILE  = 'core/twinbrain/includes/class-twinbrain-admin-menu.php';

	public function id(): string { return 'core.admin_menu.twin_brain_owner'; }
	public function label(): string { return 'Twin Brain admin menu owner'; }
	public function description(): string { return 'Kiểm tra menu Twin Brain được đăng ký ngoài gate TwinChat shell và không bị đăng ký trùng.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 20; }
	public function icon(): string { return 'layout-dashboard'; }
	public function estimate_ms(): int { return 150; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		$steps = array();
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';

		$owner_path   = $root . self::OWNER_FILE;
		$bootstrap    = $root . 'core/twinbrain/bootstrap.php';
		$entrypoint   = $root . 'bizcity-twin-ai.php';

		// Step 1 — Disk.
		$disk_ok = is_readable( $owner_path ) && is_readable( $bootstrap ) && is_readable( $entrypoint );
		$this->emit( $ctx, $steps, 'Disk - menu owner, TwinBrain bootstrap and plugin entrypoint are readable', $disk_ok, $disk_ok ? 'All three artifacts are present.' : 'A required artifact is missing or unreadable.' );
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Twin Brain menu artifacts are missing on disk.', 'error' => 'twin_brain_menu_artifact_missing', 'fix_hint' => 'Restore the TwinBrain menu owner, bootstrap and plugin entrypoint.', 'steps' => $steps );
		}

		$owner_src     = (string) file_get_contents( $owner_path );
		$bootstrap_src = (string) file_get_contents( $bootstrap );
		$entry_src     = (string) file_get_contents( $entrypoint );
		// Comment-free variants: the explanatory comments in these files name the
		// gate variable on purpose, and a raw substring scan would read that prose
		// as if the code were conditioned on it.
		$bootstrap_code = $this->strip_php_comments( $bootstrap_src );
		$entry_code     = $this->strip_php_comments( $entry_src );

		// Step 2 — Loader: the menu owner declares the parent and registers on admin_menu.
		$owner_declares = false !== strpos( $owner_src, "'" . self::PARENT_SLUG . "'" )
			&& false !== strpos( $owner_src, 'add_menu_page' )
			&& false !== strpos( $owner_src, "add_action( 'admin_menu'" );
		$this->emit( $ctx, $steps, 'Loader - menu owner declares the parent and registers on admin_menu', $owner_declares, $owner_declares ? 'The owner declares the Tw' . 'in Brain parent and hooks admin_menu.' : 'The menu owner is missing its parent declaration or admin_menu hook.' );

		// Step 3 — The heavy bootstrap must no longer own menu registration.
		$bootstrap_clean = false === strpos( $bootstrap_code, 'add_menu_page(' )
			&& false === strpos( $bootstrap_code, "add_action( 'admin_menu'" );
		$this->emit( $ctx, $steps, 'Disk - TwinBrain runtime bootstrap no longer registers the admin menu', $bootstrap_clean, $bootstrap_clean ? 'Menu registration lives only in the lightweight owner.' : 'The gated runtime bootstrap still registers the admin menu, so the menu will vanish inside the TwinChat shell.' );

		// Step 4 — The entrypoint must load the menu owner outside the shell-request gate.
		$owner_position = strpos( $entry_code, self::OWNER_FILE );
		$owner_loaded_outside_gate = false;
		$owner_gate_detail = 'The menu owner is not loaded outside the TwinChat shell gate; the menu will disappear again.';
		if ( false !== $owner_position ) {
			// Find the `if (...)` that actually encloses this load. A fixed-size
			// lookback window would also capture the *previous* gate block, which
			// legitimately names the shell flag for the heavy runtime bootstrap.
			$if_position = strrpos( substr( $entry_code, 0, $owner_position ), 'if (' );
			$condition   = false !== $if_position ? substr( $entry_code, $if_position, $owner_position - $if_position ) : '';
			$after       = substr( $entry_code, $owner_position, 600 );
			$owner_loaded_outside_gate = $condition !== ''
				&& false === strpos( $condition, '$_bizcity_twinchat_admin_shell_request' )
				&& false !== strpos( $condition, 'is_admin()' )
				&& false !== strpos( $after, 'BizCity_TwinBrain_Admin_Menu::register()' );
			if ( $owner_loaded_outside_gate ) {
				$owner_gate_detail = 'The menu owner is loaded on any admin request, independent of the TwinChat shell flag.';
			} elseif ( $condition === '' ) {
				$owner_gate_detail = 'Could not locate the enclosing condition for the menu owner load.';
			} elseif ( false !== strpos( $condition, '$_bizcity_twinchat_admin_shell_request' ) ) {
				$owner_gate_detail = 'The menu owner load is still conditioned on the TwinChat shell flag.';
			} elseif ( false === strpos( $after, 'BizCity_TwinBrain_Admin_Menu::register()' ) ) {
				$owner_gate_detail = 'The menu owner file is required but register() is never called.';
			}
		}
		$this->emit( $ctx, $steps, 'Disk - plugin entrypoint loads the menu owner outside the TwinChat shell gate', $owner_loaded_outside_gate, $owner_gate_detail );

		// Step 5 — Exactly one parent declaration across the plugin (no duplicate registration).
		$duplicate_sites = array();
		$scan = array(
			'core/twinbrain/bootstrap.php'                    => $bootstrap_src,
			self::OWNER_FILE                                  => $owner_src,
		);
		foreach ( $scan as $relative => $src ) {
			if ( false !== strpos( $src, "add_menu_page(" ) && false !== strpos( $src, "'" . self::PARENT_SLUG . "'" ) ) {
				$duplicate_sites[] = $relative;
			}
		}
		$unique_ok = count( $duplicate_sites ) === 1 && $duplicate_sites[0] === self::OWNER_FILE;
		$this->emit( $ctx, $steps, 'Disk - exactly one canonical parent registration exists', $unique_ok, $unique_ok ? 'Only the lightweight menu owner registers the Twin Brain parent.' : 'Parent registration sites: ' . ( empty( $duplicate_sites ) ? 'none' : implode( ', ', $duplicate_sites ) ) );

		// Step 6 — Runtime class availability through the canonical loader.
		$loaded = false;
		if ( class_exists( 'BizCity_TwinBrain_Admin_Menu', false ) ) {
			$loaded = true;
		} elseif ( BizCity_Safe_Loader::require_file( $owner_path, 'twinbrain.admin_menu' ) ) {
			$loaded = class_exists( 'BizCity_TwinBrain_Admin_Menu', false );
		}
		$class_ok = $loaded
			&& method_exists( 'BizCity_TwinBrain_Admin_Menu', 'register' )
			&& defined( 'BizCity_TwinBrain_Admin_Menu::PARENT_SLUG' )
			&& self::PARENT_SLUG === (string) BizCity_TwinBrain_Admin_Menu::PARENT_SLUG;
		$this->emit( $ctx, $steps, 'Loader - menu owner class and register() contract are available', $class_ok, $class_ok ? 'The owner class exposes register() and the expected parent slug.' : 'The menu owner class, its register() method or the parent slug constant is unavailable.' );

		// Step 7 — register() must be idempotent (no duplicate admin_menu callback).
		$idempotent = false;
		$idempotent_detail = 'register() idempotency was not evaluated.';
		if ( $class_ok ) {
			BizCity_TwinBrain_Admin_Menu::register();
			$first_count = $this->admin_menu_callback_count();
			BizCity_TwinBrain_Admin_Menu::register();
			$second_count = $this->admin_menu_callback_count();
			$idempotent = $first_count > 0 && $first_count === $second_count;
			$idempotent_detail = $idempotent
				? sprintf( 'register() is idempotent; admin_menu callback count stayed at %d.', $second_count )
				: sprintf( 'register() is not idempotent; callback count went %d -> %d.', $first_count, $second_count );
		}
		$this->emit( $ctx, $steps, 'Runtime - register() is idempotent and does not duplicate the hook', $idempotent, $idempotent_detail );

		$passed = true;
		foreach ( $steps as $step ) {
			if ( 'fail' === (string) ( $step['status'] ?? '' ) ) { $passed = false; break; }
		}
		return array(
			'status'   => $passed ? 'pass' : 'fail',
			'summary'  => $passed ? 'Twin Brain admin menu registration contract passed.' : 'Twin Brain admin menu registration contract failed.',
			'error'    => $passed ? '' : 'twin_brain_menu_contract_failed',
			'fix_hint' => $passed ? '' : 'Keep menu registration in core/twinbrain/includes/class-twinbrain-admin-menu.php and load it on every admin request outside the TwinChat shell gate.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {}

	/**
	 * Remove PHP comments so a prose mention of a gate variable is not read as code.
	 */
	private function strip_php_comments( string $source ): string {
		if ( ! function_exists( 'token_get_all' ) ) {
			return $source;
		}
		$out = '';
		try {
			foreach ( token_get_all( $source ) as $token ) {
				if ( is_array( $token ) ) {
					if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
						continue;
					}
					$out .= $token[1];
					continue;
				}
				$out .= $token;
			}
		} catch ( \Throwable $e ) {
			return $source;
		}
		return $out;
	}

	/** Count admin_menu callbacks bound to the menu owner class. */
	private function admin_menu_callback_count(): int {
		global $wp_filter;
		if ( empty( $wp_filter['admin_menu'] ) || ! is_object( $wp_filter['admin_menu'] ) ) {
			return 0;
		}
		$count = 0;
		foreach ( (array) $wp_filter['admin_menu']->callbacks as $priority => $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				$fn = $callback['function'] ?? null;
				if ( is_array( $fn ) && isset( $fn[0] ) && 'BizCity_TwinBrain_Admin_Menu' === $fn[0] ) {
					$count++;
				}
			}
		}
		return $count;
	}

	private function emit( $ctx, array &$steps, string $label, bool $ok, string $detail ): void {
		$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $step;
		if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) {
			$ctx->emit_step( $step );
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Admin_Menu_Twin_Brain_Owner';
	return $probes;
} );