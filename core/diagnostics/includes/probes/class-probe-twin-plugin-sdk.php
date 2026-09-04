<?php
/**
 * DDV probe for the public Twin Plugin SDK registration boundary.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 1.3.8
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Twin_Plugin_SDK', false ) ) {
	return;
}

final class BizCity_Probe_Twin_Plugin_SDK implements BizCity_Diagnostics_Probe {
	public function id(): string { return 'core.framework.plugin_sdk'; }
	public function label(): string { return 'Twin Plugin SDK registration boundary'; }
	public function description(): string { return 'Kiểm tra 7 verb SDK, taxonomy-gated events, typed content registry và bốn built-in tools qua Disk/Loader/Runtime.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 19; }
	public function icon(): string { return 'plug-zap'; }
	public function estimate_ms(): int { return 300; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — prove the public registration boundary in a real WordPress request.
		$steps = array();
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$files = array(
			'core/twin-core/contracts/class-twin-plugin-sdk.php',
			'core/twin-core/event-stream/class-twin-event-registry.php',
			'core/twin-core/includes/class-twin-content-registry.php',
			'core/twin-core/includes/class-twin-tool-registry.php',
		);
		$missing = array();
		foreach ( $files as $relative ) {
			if ( ! is_readable( $root . $relative ) ) {
				$missing[] = $relative;
			}
		}
		$steps[] = array(
			'label'  => 'Disk — SDK registry artifacts exist',
			'status' => empty( $missing ) ? 'pass' : 'fail',
			'detail' => empty( $missing ) ? implode( ', ', $files ) : implode( ', ', $missing ),
		);
		if ( ! empty( $missing ) ) {
			return array( 'status' => 'fail', 'summary' => 'SDK registry artifact missing.', 'steps' => $steps );
		}

		$classes = array( 'BizCity_Twin_Plugin_SDK', 'BizCity_Event_Registry', 'BizCity_Twin_Content_Registry', 'BizCity_Twin_Tool_Registry', 'BizCity_Typed_Tool_Adapter' );
		$missing_classes = array();
		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing_classes[] = $class;
			}
		}
		$steps[] = array(
			'label'  => 'Loader — SDK facade and registries loaded',
			'status' => empty( $missing_classes ) ? 'pass' : 'fail',
			'detail' => empty( $missing_classes ) ? implode( ', ', $classes ) : implode( ', ', $missing_classes ),
		);
		if ( ! empty( $missing_classes ) ) {
			return array( 'status' => 'fail', 'summary' => 'SDK facade or registry is not loaded.', 'steps' => $steps );
		}

		$verbs = array( 'register_plugin', 'register_tool', 'register_skill', 'register_source', 'register_event', 'register_diagnostic', 'register_ui' );
		$verbs_ok = true;
		foreach ( $verbs as $verb ) {
			if ( ! method_exists( 'BizCity_Twin_Plugin_SDK', $verb ) ) {
				$verbs_ok = false;
			}
		}
		$steps[] = array(
			'label'  => 'Runtime — seven SDK registration verbs are exposed',
			'status' => $verbs_ok ? 'pass' : 'fail',
			'detail' => $verbs_ok ? implode( ', ', $verbs ) : 'One or more registration verbs are missing.',
		);

		$canonical_event_ok = false;
		$unknown_event_ok = false;
		if ( class_exists( 'BizCity_Twin_Event_Taxonomy' ) ) {
			$registered_events = BizCity_Event_Registry::events();
			$canonical_event_ok = isset( $registered_events['tool_call'] )
				|| BizCity_Event_Registry::register_event( 'tool_call', array( 'source' => 'tool', 'owner' => 'sdk_probe' ) );
			$unknown_event_ok = false === BizCity_Event_Registry::register_event( 'sdk_probe_unknown', array( 'source' => 'tool' ) );
		}
		$steps[] = array(
			'label'  => 'Runtime — event registry accepts canonical and rejects unknown types',
			'status' => $canonical_event_ok && $unknown_event_ok ? 'pass' : 'fail',
			'detail' => $canonical_event_ok && $unknown_event_ok ? 'tool_call accepted; sdk_probe_unknown rejected.' : 'Event whitelist behavior failed.',
		);

		$tool_names = array( 'memory_remember', 'memory_forget', 'memory_recall', 'ingest_document' );
		$typed_tools = array();
		if ( class_exists( 'BizCity_Twin_Tool_Registry' ) ) {
			$registry = BizCity_Twin_Tool_Registry::instance();
			foreach ( $tool_names as $tool_name ) {
				$tool = $registry->get( $tool_name );
				$typed_tools[ $tool_name ] = $tool instanceof BizCity_Tool_Interface;
			}
		}
		$typed_tools_ok = count( $typed_tools ) === count( $tool_names ) && ! in_array( false, $typed_tools, true );
		$steps[] = array(
			'label'  => 'Runtime — built-in memory/ingest tools implement typed contract',
			'status' => $typed_tools_ok ? 'pass' : 'fail',
			'detail' => $typed_tools_ok ? implode( ', ', $tool_names ) : 'One or more typed tools are not available in the canonical registry.',
		);

		$pass = $verbs_ok && $canonical_event_ok && $unknown_event_ok && $typed_tools_ok;
		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'Twin Plugin SDK registration boundary passed.' : 'Twin Plugin SDK registration boundary failed.',
			'fix_hint'=> 'Load the SDK facade before extensions and keep event/tool registration on canonical registries.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Twin_Plugin_SDK';
	return $probes;
} );