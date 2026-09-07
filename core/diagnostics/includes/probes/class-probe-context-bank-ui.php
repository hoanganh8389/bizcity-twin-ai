<?php
/**
 * DDV probe for the Skills Context Bank read-through UI boundary.
 *
 * This probe validates the source/bundle contract and the feature-off default.
 * It does not open a browser, call a provider or mutate Context Bank data.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Context_Bank_UI', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_UI implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-09-04 Johnny Chu - Chu Hoàng Anh] PHASE-CB8.2-DDV — expose the Skills read-through UI probe.
		return 'core.context_bank.ui';
	}

	public function label(): string {
		return 'Context Bank - Skills read-through UI';
	}

	public function description(): string {
		return 'Checks the built Skills read-through panel, same-origin Context Bank endpoint and feature-off default without exposing payload pointers.';
	}

	public function severity(): string { return 'critical'; }
	public function order(): int { return 86; }
	public function icon(): string { return 'layout'; }
	public function estimate_ms(): int { return 80; }

	public function precondition() {
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$source = $root . 'core/skills/app/src/ContextBankPanel.tsx';
		$bundle = $root . 'core/skills/assets/dist/skill-app.js';
		if ( ! is_readable( $source ) || ! is_readable( $bundle ) ) {
			return new WP_Error( 'context_bank_ui_artifact_missing', 'Context Bank Skills UI source or built artifact is not readable.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-04 Johnny Chu - Chu Hoàng Anh] PHASE-CB8.2-DDV — validate the read-only UI contract and default-off boundary without browser or storage mutation.
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$panel_file = $root . 'core/skills/app/src/ContextBankPanel.tsx';
		$skill_app_file = $root . 'core/skills/app/src/SkillApp.tsx';
		$admin_file = $root . 'core/skills/includes/class-admin-page.php';
		$bundle_file = $root . 'core/skills/assets/dist/skill-app.js';
		$style_file = $root . 'core/skills/assets/dist/skill-app.css';
		$panel = is_readable( $panel_file ) ? file_get_contents( $panel_file ) : '';
		$skill_app = is_readable( $skill_app_file ) ? file_get_contents( $skill_app_file ) : '';
		$admin = is_readable( $admin_file ) ? file_get_contents( $admin_file ) : '';
		$bundle = is_readable( $bundle_file ) ? file_get_contents( $bundle_file ) : '';
		$style = is_readable( $style_file ) ? file_get_contents( $style_file ) : '';

		$artifact_ok = is_string( $panel ) && $panel !== ''
			&& is_string( $skill_app ) && $skill_app !== ''
			&& is_string( $admin ) && $admin !== ''
			&& is_string( $bundle ) && $bundle !== ''
			&& is_string( $style ) && $style !== '';
		$endpoint_ok = is_string( $panel )
			&& strpos( $panel, '"/wp-json/bizcity-context/v1/records"' ) !== false
			&& strpos( $panel, 'credentials: "same-origin"' ) !== false
			&& strpos( $panel, 'X-WP-Nonce' ) !== false
			&& strpos( $panel, 'https://' ) === false
			&& strpos( $panel, 'http://' ) === false;
		$projection_ok = is_string( $panel )
			&& strpos( $panel, 'record_id' ) !== false
			&& strpos( $panel, 'source_contract_id' ) !== false
			&& strpos( $panel, 'occurred_at' ) !== false
			&& strpos( $panel, 'absolute_path' ) === false
			&& strpos( $panel, 'encrypted_token' ) === false
			&& strpos( $panel, 'byte_offset' ) === false
			&& strpos( $panel, 'row_hash' ) === false
			&& strpos( $panel, 'content_md' ) === false;
		$state_ok = is_string( $panel )
			&& strpos( $panel, 'if (!enabled) return' ) !== false
			&& strpos( $panel, 'role="alert"' ) !== false
			&& strpos( $panel, 'Chưa có metadata Context Bank phù hợp.' ) !== false
			&& strpos( $panel, 'Trang tiếp' ) !== false;
		$mount_ok = is_string( $skill_app )
			&& strpos( $skill_app, 'ContextBankPanel' ) !== false
			&& strpos( $skill_app, 'view === "context-bank"' ) !== false
			&& strpos( $skill_app, 'contextBankEnabled === true' ) !== false;
		$config_ok = is_string( $admin )
			&& strpos( $admin, "bizcity_context_bank_ui_enabled', false" ) !== false;
		$bundle_ok = is_string( $bundle )
			&& strpos( $bundle, 'bizcity-context/v1/records' ) !== false;
		$feature_off = function_exists( 'get_option' )
			? ! (bool) get_option( 'bizcity_context_bank_ui_enabled', false )
			: false;

		$checks = array(
			array( 'label' => 'Skills UI artifacts are readable', 'ok' => $artifact_ok, 'detail' => $artifact_ok ? 'Panel source, Skills mount, admin config and built assets are readable.' : 'One or more Skills Context Bank UI artifacts are missing.' ),
			array( 'label' => 'UI uses same-origin Context Bank REST', 'ok' => $endpoint_ok, 'detail' => $endpoint_ok ? 'The panel uses the canonical same-origin endpoint with the WordPress nonce.' : 'The panel endpoint or credential boundary is not canonical.' ),
			array( 'label' => 'UI projection excludes protected pointer fields', 'ok' => $projection_ok, 'detail' => $projection_ok ? 'The panel renders bounded metadata and does not include payload, path, offset or hash fields.' : 'The panel source contains a protected payload or pointer field.' ),
			array( 'label' => 'Loading, error, empty and pagination states exist', 'ok' => $state_ok, 'detail' => $state_ok ? 'The read-through panel has disabled, error, empty and cursor-next states.' : 'A required read-through UI state is missing.' ),
			array( 'label' => 'Context Bank tab is explicitly mounted', 'ok' => $mount_ok, 'detail' => $mount_ok ? 'The existing Skills SPA owns the tab and gates it by the server-provided flag.' : 'The Context Bank panel is not mounted through the existing Skills owner.' ),
			array( 'label' => 'Server config defaults UI flag off', 'ok' => $config_ok, 'detail' => $config_ok ? 'The admin config reads the tenant option with a false default.' : 'The server config does not preserve the false-by-default UI contract.' ),
			array( 'label' => 'Built bundle contains the read-through endpoint', 'ok' => $bundle_ok, 'detail' => $bundle_ok ? 'The deployed bundle contains the canonical Context Bank route.' : 'The built bundle is stale or does not contain the Context Bank route.' ),
			array( 'label' => 'Runtime feature flag remains off', 'ok' => $feature_off, 'detail' => $feature_off ? 'The target tenant keeps Context Bank UI disabled by default.' : 'Context Bank UI is enabled on the target tenant; keep this canary explicit before rollout.' ),
		);
		$pass = true;
		foreach ( $checks as $check ) {
			$ctx->emit_step( array( 'label' => $check['label'], 'status' => $check['ok'] ? 'pass' : 'fail', 'detail' => $check['detail'] ) );
			$pass = $pass && $check['ok'];
		}
		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'Skills Context Bank read-through UI passed artifact, route, projection and feature-off checks.' : 'Skills Context Bank read-through UI contract failed.', 'fix_hint' => $pass ? '' : 'Deploy the current Skills bundle/config and keep the Context Bank UI flag disabled until the failed boundary is corrected.', 'steps' => array() );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Context_Bank_UI';
	return $list;
} );