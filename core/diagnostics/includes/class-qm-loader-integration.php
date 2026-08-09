<?php
/**
 * Query Monitor integration loader for BizCity loader observability.
 *
 * This file is intentionally tiny and safe to load before Query Monitor. The
 * collector/output classes are loaded only when Query Monitor asks for them.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since 2026-08-09
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bizcity_qm_loader_probe_enabled' ) ) {
	/**
	 * Enable loader evidence by default for privileged admin requests.
	 *
	 * The query flag remains available for non-admin diagnostic requests.
	 *
	 * @return bool
	 */
	function bizcity_qm_loader_probe_enabled( bool $check_capability = true ): bool {
		if ( ! empty( $_GET['bizcity_qm_probe'] ) && '1' === (string) $_GET['bizcity_qm_probe'] ) {
			return true;
		}
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return false;
		}
		// [2026-08-09 Johnny Chu] PHP74-COMPAT/R-PERF-LOADER-EARLY - do not
		// call current_user_can() while the mu-plugin/file-scope loader is still
		// before the normal WordPress capability lifecycle.
		if ( ! $check_capability ) {
			return true;
		}
		return ! function_exists( 'current_user_can' ) || current_user_can( 'manage_options' );
	}
}

// [2026-08-09 Johnny Chu] R-PERF-LOADER-EARLY - capture the admin-default
// baseline before regular plugin loading so preload cost is not hidden.
if ( empty( $GLOBALS['bizcity_qm_loader_early_baseline'] )
	&& bizcity_qm_loader_probe_enabled( false ) ) {
	$GLOBALS['bizcity_qm_loader_early_baseline'] = array(
		'memory'  => memory_get_usage( true ),
		'files'   => array_fill_keys( get_included_files(), true ),
		'classes' => array_fill_keys( get_declared_classes(), true ),
	);
}

if ( ! function_exists( 'bizcity_qm_loader_probe_stage' ) ) {
	/**
	 * Append a Query Monitor integration stage marker for the active admin trace.
	 *
	 * @param string $stage Stage name.
	 * @param array  $extra Safe scalar diagnostic fields.
	 * @return void
	 */
	function bizcity_qm_loader_probe_stage( string $stage, array $extra = array() ): void {
		if ( ! bizcity_qm_loader_probe_enabled() ) {
			return;
		}
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$base_dir = ! empty( $uploads['basedir'] )
			? trailingslashit( $uploads['basedir'] ) . 'bizcity-diagnostics'
			: '';
		if ( $base_dir === '' ) {
			return;
		}
		if ( ! is_dir( $base_dir ) && function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $base_dir );
		}
		if ( ! is_dir( $base_dir ) || ! is_writable( $base_dir ) ) {
			return;
		}
		$row = array_merge(
			array(
				'time_utc' => gmdate( 'c' ),
				'event'    => 'qm_loader_stage',
				'stage'    => $stage,
			),
			$extra
		);
		$path = trailingslashit( $base_dir ) . 'qm-loader-probe-' . gmdate( 'Y-m-d' ) . '.jsonl';
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $row, JSON_UNESCAPED_SLASHES ) : json_encode( $row );
		if ( is_string( $json ) ) {
			@file_put_contents( $path, $json . PHP_EOL, FILE_APPEND | LOCK_EX );
		}
	}
}

if ( ! function_exists( 'bizcity_qm_loader_export_jsonl' ) ) {
	/**
	 * Export one bounded loader snapshot for agent/debugger consumption.
	 *
	 * @param array $snapshots Lifecycle snapshots.
	 * @param array $extra Safe scalar request fields.
	 * @return void
	 */
	function bizcity_qm_loader_export_jsonl( array $snapshots, array $extra = array() ): void {
		// [2026-08-09 Johnny Chu] R-PERF-LOADER-QM - export one bounded admin JSONL request snapshot.
		if ( ! bizcity_qm_loader_probe_enabled() ) {
			return;
		}
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$base_dir = ! empty( $uploads['basedir'] )
			? trailingslashit( $uploads['basedir'] ) . 'bizcity-diagnostics'
			: '';
		if ( $base_dir === '' ) {
			return;
		}
		if ( ! is_dir( $base_dir ) && function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $base_dir );
		}
		if ( ! is_dir( $base_dir ) || ! is_writable( $base_dir ) ) {
			return;
		}
		$row = array_merge(
			array(
				'time_utc'         => gmdate( 'c' ),
				'event'            => 'qm_loader_snapshot',
				'request_uri'      => isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '',
				'peak_memory_mb'   => round( memory_get_peak_usage( true ) / 1048576, 2 ),
				'included_files'   => count( get_included_files() ),
				'declared_classes' => count( get_declared_classes() ),
				'snapshots'        => $snapshots,
			),
			$extra
		);
		$path = trailingslashit( $base_dir ) . 'qm-loader-snapshots-' . gmdate( 'Y-m-d' ) . '.jsonl';
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $row, JSON_UNESCAPED_SLASHES ) : json_encode( $row );
		if ( is_string( $json ) ) {
			@file_put_contents( $path, $json . PHP_EOL, FILE_APPEND | LOCK_EX );
		}
	}
}

if ( ! function_exists( 'bizcity_register_qm_loader_collector' ) ) {
	/**
	 * Add the loader collector when Query Monitor is collecting data.
	 *
	 * @param array<int,QM_Collector> $collectors
	 * @param object                 $query_monitor
	 * @return array<int,QM_Collector>
	 */
	function bizcity_register_qm_loader_collector( array $collectors, $query_monitor ): array {
		// [2026-08-09 Johnny Chu] R-PERF-LOADER-QM - load collector only inside QM collector discovery.
		class_exists( 'QM_DataCollector' );
		class_exists( 'QM_Data_Fallback' );
		$panel_file = __DIR__ . '/class-diagnostics-loader-hook-panel.php';
		if ( file_exists( $panel_file ) ) {
			require_once $panel_file;
		}
		$collector_file = __DIR__ . '/class-qm-loader-collector.php';
		if ( file_exists( $collector_file ) ) {
			require_once $collector_file;
		}
		if ( class_exists( 'BizCity_Diagnostics_Loader_Hook_Panel' ) ) {
			BizCity_Diagnostics_Loader_Hook_Panel::init();
		}
		if ( class_exists( 'BizCity_QM_Loader_Collector', false ) ) {
			$collectors[] = new BizCity_QM_Loader_Collector();
		}
		bizcity_qm_loader_probe_stage( 'collector_filter', array(
			'candidate_count' => count( $collectors ),
			'collector_class' => class_exists( 'BizCity_QM_Loader_Collector', false ),
		) );
		return $collectors;
	}
}

if ( ! function_exists( 'bizcity_register_qm_loader_output' ) ) {
	/**
	 * Add the HTML panel to Query Monitor's output menu.
	 *
	 * @param array<string,QM_Output> $output
	 * @param object                 $collectors
	 * @return array<string,QM_Output>
	 */
	function bizcity_register_qm_loader_output( array $output, $collectors ): array {
		// [2026-08-09 Johnny Chu] R-PERF-LOADER-QM - load HTML output only inside QM output discovery.
		class_exists( 'QM_Output_Html' );
		$output_file = __DIR__ . '/class-qm-loader-output.php';
		if ( file_exists( $output_file ) ) {
			require_once $output_file;
		}
		$collector = class_exists( 'QM_Collectors' )
			? QM_Collectors::get( 'bizcity_loader' )
			: null;
		if ( ! $collector && class_exists( 'QM_DataCollector' ) && class_exists( 'QM_Data_Fallback' ) ) {
			$collector_file = __DIR__ . '/class-qm-loader-collector.php';
			if ( file_exists( $collector_file ) ) {
				require_once $collector_file;
			}
			if ( class_exists( 'BizCity_QM_Loader_Collector', false ) ) {
				$collector = new BizCity_QM_Loader_Collector();
				QM_Collectors::add( $collector );
				$collector->process();
			}
		}
		if ( $collector && class_exists( 'BizCity_QM_Loader_Output', false ) ) {
			$output['bizcity_loader'] = new BizCity_QM_Loader_Output( $collector );
		}
		bizcity_qm_loader_probe_stage( 'html_output_filter', array(
			'collector_present' => (bool) $collector,
			'output_class'      => class_exists( 'BizCity_QM_Loader_Output', false ),
			'menu_filter'       => has_filter( 'qm/output/menus' ) !== false,
			'output_count'      => count( $output ),
		) );
		return $output;
	}
}

if ( ! function_exists( 'bizcity_register_qm_loader_headers' ) ) {
	/**
	 * Add compact loader metrics to Query Monitor REST headers.
	 *
	 * @param array<string,QM_Output> $output
	 * @param object                 $collectors
	 * @return array<string,QM_Output>
	 */
	function bizcity_register_qm_loader_headers( array $output, $collectors ): array {
		// [2026-08-09 Johnny Chu] R-PERF-LOADER-QM - load header output only for QM REST dispatch.
		class_exists( 'QM_Output_Headers' );
		$header_file = __DIR__ . '/class-qm-loader-header-output.php';
		if ( file_exists( $header_file ) ) {
			require_once $header_file;
		}
		$collector = class_exists( 'QM_Collectors' ) ? QM_Collectors::get( 'bizcity_loader' ) : null;
		if ( $collector && class_exists( 'BizCity_QM_Loader_Header_Output', false ) ) {
			$output['bizcity_loader'] = new BizCity_QM_Loader_Header_Output( $collector );
		}
		bizcity_qm_loader_probe_stage( 'headers_output_filter', array(
			'collector_present' => (bool) $collector,
			'output_class'      => class_exists( 'BizCity_QM_Loader_Header_Output', false ),
			'output_count'      => count( $output ),
		) );
		return $output;
	}
}

if ( ! function_exists( 'bizcity_qm_loader_fallback_register' ) ) {
	/**
	 * Recover from a plugin-order case where QM already passed its collector filter.
	 *
	 * @return void
	 */
	function bizcity_qm_loader_fallback_register(): void {
		// [2026-08-09 Johnny Chu] R-PERF-LOADER-QM - fallback only when QM missed the public collector filter.
		if ( ! class_exists( 'QM_Collectors' ) || ! class_exists( 'QM_DataCollector' ) ) {
			return;
		}
		if ( QM_Collectors::get( 'bizcity_loader' ) ) {
			return;
		}

		$panel_file = __DIR__ . '/class-diagnostics-loader-hook-panel.php';
		$collector_file = __DIR__ . '/class-qm-loader-collector.php';
		if ( file_exists( $panel_file ) ) {
			require_once $panel_file;
		}
		if ( file_exists( $collector_file ) ) {
			require_once $collector_file;
		}
		if ( class_exists( 'BizCity_Diagnostics_Loader_Hook_Panel' ) ) {
			BizCity_Diagnostics_Loader_Hook_Panel::init();
		}
		if ( class_exists( 'BizCity_QM_Loader_Collector', false ) ) {
			QM_Collectors::add( new BizCity_QM_Loader_Collector() );
		}
		bizcity_qm_loader_probe_stage( 'fallback_register', array(
			'collector_present' => (bool) QM_Collectors::get( 'bizcity_loader' ),
		) );
	}
}

if ( ! function_exists( 'bizcity_qm_loader_probe_marker' ) ) {
	/**
	 * Write an admin-default loader marker that does not depend on PHP error_log routing.
	 *
	 * @return void
	 */
	function bizcity_qm_loader_probe_marker(): void {
		// [2026-08-09 Johnny Chu] R-PERF-LOADER-QM - runtime marker for admin lifecycle diagnosis.
		if ( ! bizcity_qm_loader_probe_enabled() ) {
			return;
		}
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$base_dir = ! empty( $uploads['basedir'] )
			? trailingslashit( $uploads['basedir'] ) . 'bizcity-diagnostics'
			: '';
		if ( $base_dir === '' ) {
			return;
		}
		if ( ! is_dir( $base_dir ) && function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $base_dir );
		}
		if ( ! is_dir( $base_dir ) || ! is_writable( $base_dir ) ) {
			return;
		}

		$row = array(
			'time_utc'      => gmdate( 'c' ),
			'event'         => 'qm_loader_integration_loaded',
			'integration'   => function_exists( 'bizcity_register_qm_loader_collector' ),
			'qm_collectors' => class_exists( 'QM_Collectors' ),
			'loader_collector' => class_exists( 'QM_Collectors' ) && (bool) QM_Collectors::get( 'bizcity_loader' ),
			'collector_filter' => has_filter( 'qm/collectors' ) !== false,
			'html_output_filter' => has_filter( 'qm/outputter/html' ) !== false,
			'headers_output_filter' => has_filter( 'qm/outputter/headers' ) !== false,
			'qm_version'    => defined( 'QM_VERSION' ) ? (string) QM_VERSION : '',
			'loader_file'   => __FILE__,
			'plugin_dir'    => defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : '',
		);
		$path = trailingslashit( $base_dir ) . 'qm-loader-probe-' . gmdate( 'Y-m-d' ) . '.jsonl';
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $row, JSON_UNESCAPED_SLASHES ) : json_encode( $row );
		if ( is_string( $json ) ) {
			@file_put_contents( $path, $json . PHP_EOL, FILE_APPEND | LOCK_EX );
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'qm/debug', 'BizCity loader integration probe marker written.', array(
				'integration'   => $row['integration'],
				'qm_collectors' => $row['qm_collectors'],
				'qm_version'    => $row['qm_version'],
			) );
		}
	}
}

// [2026-08-09 Johnny Chu] R-PERF-LOADER-QM - register only Query Monitor
// extension filters; no collector, hook scan, DB query, or output is created here.
add_filter( 'qm/collectors', 'bizcity_register_qm_loader_collector', 20, 2 );
add_filter( 'qm/outputter/html', 'bizcity_register_qm_loader_output', 80, 2 );
add_filter( 'qm/outputter/headers', 'bizcity_register_qm_loader_headers', 80, 2 );
add_action( 'plugins_loaded', 'bizcity_qm_loader_fallback_register', 999 );
add_action( 'plugins_loaded', 'bizcity_qm_loader_probe_marker', 1000 );
