<?php
/**
 * BizCity Diagnostics — modules.twinweb.channel probe (PHASE-TWINWEB-SEARCH W1).
 *
 * R-DDV: 3 layers evidence for TwinWeb Channel Binding.
 *
 * DDV rows:
 *   twinweb.channel.disk_bootstrap  — class-twinweb-binding-bootstrap.php readable
 *   twinweb.channel.loader_class    — BizCity_TwinWeb_Binding_Bootstrap loaded + resolve_character_id()
 *   twinweb.channel.loader_catalog  — bizcity_channel_platform_catalog filter has 'twinweb' (zone='admin')
 *   twinweb.channel.runtime_binding — BizCity_Channel_Binding::resolve('TWINWEB', blog_id) trả row
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since      2026-07-15 (PHASE-TWINWEB-SEARCH W1)
 */

// [2026-07-15 Johnny Chu] PHASE-TWINWEB-SEARCH W1 — DDV probe modules.twinweb.channel
defined( 'ABSPATH' ) || exit;

// [2026-07-15 Johnny Chu] HOTFIX — resolve from canonical plugin root.
// Previous double dirname() escaped to wp-content/plugins and caused fatal require.
$bizcity_twinweb_plugin_root = defined( 'BIZCITY_TWIN_AI_DIR' )
	? BIZCITY_TWIN_AI_DIR
	: dirname( __DIR__, 3 ) . '/';
$bizcity_twinweb_probe_iface = $bizcity_twinweb_plugin_root . 'core/diagnostics/includes/interface-diagnostics-probe.php';
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) && is_readable( $bizcity_twinweb_probe_iface ) ) {
	require_once $bizcity_twinweb_probe_iface;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_TwinWeb_Channel', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Channel implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'modules.twinweb.channel'; }
	public function label(): string       { return 'Twin GPT · Channel Binding Bootstrap'; }
	public function description(): string {
		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP TG-0 — user-facing diagnostic brand is Twin GPT.
		return 'Disk / Loader / Runtime: Twin GPT channel binding row, platform catalog entry (zone=admin), và BizCity_TwinWeb_Binding_Bootstrap class contract (PHASE-TWINWEB-SEARCH W1).';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 80; }
	public function icon(): string        { return 'globe'; }
	public function estimate_ms(): int    { return 30; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		// ── Disk: bootstrap file ────────────────────────────────────────────
		$bootstrap_file = __DIR__ . '/class-twinweb-binding-bootstrap.php';
		$disk_ok = is_readable( $bootstrap_file );
		$step = array(
			'label'  => 'Disk · class-twinweb-binding-bootstrap.php readable',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'File exists and readable.' : 'File missing: ' . $bootstrap_file,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_ok ) { $pass = false; }

		// ── Loader: class + method contract ────────────────────────────────
		$class_ok  = class_exists( 'BizCity_TwinWeb_Binding_Bootstrap' );
		$method_ok = $class_ok && method_exists( 'BizCity_TwinWeb_Binding_Bootstrap', 'resolve_character_id' )
			&& method_exists( 'BizCity_TwinWeb_Binding_Bootstrap', 'ensure' );
		$step = array(
			'label'  => 'Loader · BizCity_TwinWeb_Binding_Bootstrap class + methods',
			'status' => ( $class_ok && $method_ok ) ? 'pass' : 'fail',
			'detail' => ( $class_ok && $method_ok )
				? 'Class loaded; ensure() + resolve_character_id() present.'
				: ( ! $class_ok ? 'Class not loaded (check bootstrap require_once order).' : 'Methods ensure()/resolve_character_id() missing.' ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $class_ok || ! $method_ok ) { $pass = false; }

		// ── Loader: catalog filter has 'twinweb' zone='admin' ────────────
		$catalog  = (array) apply_filters( 'bizcity_channel_platform_catalog', array() );
		$tw_entry = null;
		foreach ( $catalog as $item ) {
			if ( isset( $item['code'] ) && (string) $item['code'] === 'twinweb' ) {
				$tw_entry = $item;
				break;
			}
		}
		$catalog_ok = null !== $tw_entry
			&& isset( $tw_entry['zone'] ) && (string) $tw_entry['zone'] === 'admin';
		$step = array(
			'label'  => "Loader · platform catalog has 'twinweb' with zone='admin'",
			'status' => $catalog_ok ? 'pass' : 'fail',
			'detail' => $catalog_ok
				? "Catalog entry found: code=twinweb zone=admin label=" . ( $tw_entry['label'] ?? '?' )
				: ( null === $tw_entry ? "No 'twinweb' entry found in bizcity_channel_platform_catalog." : "Entry found but zone != 'admin': " . ( $tw_entry['zone'] ?? 'missing' ) ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $catalog_ok ) { $pass = false; }

		// ── Runtime: BizCity_Channel_Binding::resolve for TWINWEB ─────────
		$runtime_ok     = false;
		$runtime_detail = 'BizCity_Channel_Binding class not loaded.';
		if ( class_exists( 'BizCity_Channel_Binding' ) ) {
			$account_id = (string) get_current_blog_id();
			$binding    = BizCity_Channel_Binding::resolve( 'TWINWEB', $account_id );
			if ( is_array( $binding ) && ! empty( $binding['id'] ) ) {
				$cid        = (int) ( $binding['character_id'] ?? 0 );
				$runtime_ok = true;
				$runtime_detail = $cid > 0
					? "Binding row found (id={$binding['id']}, character_id={$cid})."
					: "Binding row found but character_id=0 — configure Guru in Channel Gateway → Twin GPT → Guru & Knowledge.";
			} else {
				$runtime_detail = "No TWINWEB binding row for blog_id={$account_id}. Set a default Guru via option bizcity_twinweb_default_character_id or use Channel Gateway admin UI.";
			}
		}
		$step = array(
			'label'  => 'Runtime · BizCity_Channel_Binding::resolve(TWINWEB, blog_id)',
			'status' => $runtime_ok ? 'pass' : 'warn',
			'detail' => $runtime_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		// Runtime warn is non-fatal — binding may not exist yet on new installs.
		// Disk + Loader failures are real failures.

		return array(
			'status'   => $pass ? ( $runtime_ok ? 'pass' : 'warn' ) : 'fail',
			'summary'  => $pass
				? ( $runtime_ok
					? 'Twin GPT channel binding is fully configured.'
					: 'Twin GPT channel bootstrap is ready but Guru binding not configured — set up via Channel Gateway admin.' )
				: 'Twin GPT channel binding bootstrap is incomplete.',
			'error'    => $pass ? '' : 'twinweb_channel_bootstrap_failed',
			'fix_hint' => $pass ? ( $runtime_ok ? '' : 'Đi tới Channel Gateway → Twin GPT → Guru & Knowledge và chọn Guru phụ trách.' ) : 'Kiểm tra modules/twinweb/bootstrap.php — đảm bảo class-twinweb-binding-bootstrap.php được require_once.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void { /* Read-only. */ }
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_Channel';
	return $list;
} );
