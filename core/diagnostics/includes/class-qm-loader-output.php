<?php
/**
 * Query Monitor HTML output for BizCity loader observability.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since 2026-08-09
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'QM_Output_Html' ) || class_exists( 'BizCity_QM_Loader_Output', false ) ) {
	return;
}

final class BizCity_QM_Loader_Output extends QM_Output_Html {

	public function __construct( QM_Collector $collector ) {
		parent::__construct( $collector );
		// [2026-08-09 Johnny Chu] R-PERF-LOADER-QM - register the outputter in
		// Query Monitor's sidebar menu; without this the panel is rendered but hidden.
		add_filter( 'qm/output/menus', array( $this, 'admin_menu' ), 85 );
	}

	public function name() {
		return __( 'BizCity Loader', 'bizcity-twin-ai' );
	}

	public function output(): void {
		// [2026-08-09 Johnny Chu] R-PERF-LOADER-QM - render phase summaries without duplicating QM hook rows.
		$data = $this->collector->get_data();
		$snapshots = isset( $data->snapshots ) && is_array( $data->snapshots )
			? $data->snapshots
			: array();

		$this->before_non_tabular_output();
		$detail_mode = 'full';
		foreach ( $snapshots as $snapshot ) {
			if ( isset( $snapshot['detail_mode'] ) ) {
				$detail_mode = (string) $snapshot['detail_mode'];
				break;
			}
		}
		echo '<p>' . esc_html__( 'Loader hook snapshots captured in this request.', 'bizcity-twin-ai' ) . ' ';
		echo esc_html( 'Detail mode: ' . $detail_mode ) . '</p>';
		$runtime_identity = array();
		foreach ( $snapshots as $snapshot ) {
			if ( isset( $snapshot['context']['runtime_identity'] ) && is_array( $snapshot['context']['runtime_identity'] ) ) {
				$runtime_identity = $snapshot['context']['runtime_identity'];
			}
		}
		if ( ! empty( $runtime_identity ) ) {
			// [2026-08-11 Johnny Chu] PHASE-1.23-MONITOR-ID - expose bounded
			// artifact identity beside the trace so deploy drift is visible.
			echo '<p class="qm-info">';
			echo '<strong>Runtime identity:</strong> release=' . esc_html( (string) ( $runtime_identity['release_hash'] ?? 'unknown' ) );
			echo ' · plugin=' . esc_html( (string) ( $runtime_identity['plugin_version'] ?? 'unknown' ) );
			echo ' · version_source=' . esc_html( (string) ( $runtime_identity['version_source'] ?? 'unknown' ) );
			echo ' · PHP=' . esc_html( (string) ( $runtime_identity['php_version'] ?? 'unknown' ) );
			echo ' · OPcache=' . esc_html( (string) ( $runtime_identity['opcache_status'] ?? 'unknown' ) );
			$artifact_hashes = isset( $runtime_identity['artifact_hashes'] ) && is_array( $runtime_identity['artifact_hashes'] )
				? $runtime_identity['artifact_hashes']
				: array();
			if ( ! empty( $artifact_hashes ) ) {
				echo ' · artifacts=' . esc_html( implode( ',', array_map( static function ( $name, $hash ) {
					return (string) $name . ':' . (string) $hash;
				}, array_keys( $artifact_hashes ), $artifact_hashes ) ) );
			}
			echo '</p>';
		}
		echo '<table class="qm-sortable"><thead><tr>';
		echo '<th>' . esc_html__( 'Phase', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Hooks', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Callbacks', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Capture overhead', 'bizcity-twin-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Memory used/peak', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'New files/classes', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'New file buckets', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Source groups', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'First new files', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Registration', 'bizcity-twin-ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $snapshots ) ) {
			echo '<tr><td colspan="10">' . esc_html__( 'No loader snapshot was captured.', 'bizcity-twin-ai' ) . '</td></tr>';
		} else {
			foreach ( $snapshots as $phase => $snapshot ) {
				$source_summary = isset( $snapshot['source_summary_all'] ) && is_array( $snapshot['source_summary_all'] )
					? $snapshot['source_summary_all']
					: ( isset( $snapshot['source_summary'] ) && is_array( $snapshot['source_summary'] )
						? $snapshot['source_summary']
						: array() );
				$first_file_text = array();
				$first_files = isset( $snapshot['first_new_file_by_group'] ) && is_array( $snapshot['first_new_file_by_group'] )
					? $snapshot['first_new_file_by_group']
					: array();
				foreach ( array_slice( $first_files, 0, 6, true ) as $first_group => $first_paths ) {
					$first_file_text[] = $first_group . ': ' . implode( ', ', array_slice( (array) $first_paths, 0, 2 ) );
				}
				$registration = isset( $snapshot['registration_delta'] ) && is_array( $snapshot['registration_delta'] )
					? $snapshot['registration_delta']
					: array();
				$source_text = array();
				arsort( $source_summary );
				foreach ( array_slice( $source_summary, 0, 8, true ) as $source_group => $count ) {
					$source_text[] = $source_group . ': ' . (int) $count;
				}
				$new_file_groups = isset( $snapshot['new_file_groups'] ) && is_array( $snapshot['new_file_groups'] )
					? $snapshot['new_file_groups']
					: array();
				arsort( $new_file_groups );
				$new_file_text = array();
				foreach ( array_slice( $new_file_groups, 0, 8, true ) as $new_group => $count ) {
					$new_file_text[] = $new_group . ': ' . (int) $count;
				}
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) $phase ) . '</code></td>';
				echo '<td class="qm-num">' . (int) ( $snapshot['hook_count'] ?? 0 ) . '</td>';
				echo '<td class="qm-num">' . (int) ( $snapshot['callback_count'] ?? 0 ) . '</td>';
				echo '<td class="qm-num">+' . (float) ( $snapshot['capture_overhead_delta_kb'] ?? 0 ) . ' KB</td>';
				echo '<td class="qm-num">' . (float) ( $snapshot['memory_current_mb'] ?? $snapshot['memory_mb'] ?? 0 ) . ' / ' . (float) ( $snapshot['memory_peak_mb'] ?? 0 ) . ' MB</td>';
				echo '<td class="qm-num">+' . (int) ( $snapshot['new_file_count'] ?? 0 ) . ' / +' . (int) ( $snapshot['new_class_count'] ?? 0 ) . '</td>';
				echo '<td>' . esc_html( implode( ', ', $new_file_text ) ) . '</td>';
				echo '<td>' . esc_html( implode( ', ', $source_text ) ) . '</td>';
				echo '<td>' . esc_html( implode( '; ', $first_file_text ) ) . '</td>';
				echo '<td>' . esc_html( sprintf(
					'+%d/-%d dup:%d%s',
					(int) ( $registration['added'] ?? 0 ),
					(int) ( $registration['removed'] ?? 0 ),
					(int) ( $registration['duplicate'] ?? 0 ),
					! empty( $registration['truncated'] ) ? ' truncated' : ''
				) ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		$ownership_records = array();
		$ownership_events = array();
		$ownership_mode = 'not_instrumented';
		foreach ( array_reverse( $snapshots, true ) as $snapshot ) {
			if ( isset( $snapshot['boot_state_delta']['records'] ) && is_array( $snapshot['boot_state_delta']['records'] ) ) {
				$ownership_records = $snapshot['boot_state_delta']['records'];
				$ownership_events = isset( $snapshot['boot_state_delta']['events'] ) && is_array( $snapshot['boot_state_delta']['events'] )
					? $snapshot['boot_state_delta']['events']
					: array();
				$ownership_mode = isset( $snapshot['boot_state_delta']['mode'] )
					? (string) $snapshot['boot_state_delta']['mode']
					: (string) ( $snapshot['boot_state_status'] ?? 'observe_only' );
				break;
			}
		}
		if ( ! empty( $ownership_records ) || ! empty( $ownership_events ) ) {
			echo '<p class="qm-info"><strong>Boot ownership:</strong> ' . esc_html( $ownership_mode );
			echo ' · features=' . count( $ownership_records );
			echo ' · events=' . count( $ownership_events );
			$conflict_count = 0;
			foreach ( $ownership_events as $ownership_event ) {
				if ( in_array( (string) ( $ownership_event['event'] ?? '' ), array( 'duplicate_owner', 'version_conflict' ), true ) ) {
					$conflict_count++;
				}
			}
			echo ' · conflicts=' . $conflict_count . '</p>';
			echo '<table class="qm-sortable"><thead><tr><th>Feature</th><th>State</th><th>Owner</th><th>Canonical path</th><th>Claims</th><th>Duplicate attempts</th></tr></thead><tbody>';
			foreach ( $ownership_records as $ownership_record ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) ( $ownership_record['feature_id'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) ( $ownership_record['state'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $ownership_record['owner_source'] ?? '' ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $ownership_record['canonical_path'] ?? '' ) ) . '</code></td>';
				echo '<td class="qm-num">' . (int) ( $ownership_record['claim_count'] ?? 0 ) . '</td>';
				echo '<td class="qm-num">' . (int) ( $ownership_record['duplicate_attempts'] ?? 0 ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		$peak_memory_allocated = $data->bizcity_peak_memory_allocated_mb ?? ( $data->peak_memory_mb ?? null );
		$peak_memory_used      = $data->bizcity_peak_memory_used_mb ?? ( $data->peak_memory_used_mb ?? $peak_memory_allocated );
		$memory_consistent     = $data->bizcity_memory_metric_consistent ?? ( $data->memory_metric_consistent ?? null );
		$raw_memory_consistent = $data->bizcity_memory_raw_metric_consistent ?? true;
		if ( null !== $peak_memory_allocated ) {
			echo '<p class="qm-info">';
			echo esc_html__( 'Request peak memory:', 'bizcity-twin-ai' ) . ' ';
			echo esc_html( (string) $peak_memory_used ) . ' MB used';
			echo ' / ' . esc_html( (string) $peak_memory_allocated ) . ' MB allocated';
			if ( false === $memory_consistent ) {
				echo ' · <strong style="color:#b32d2e">canonical metric mismatch: recapture after deploy/OPcache refresh</strong>';
			} elseif ( false === $raw_memory_consistent ) {
				echo ' · <strong style="color:#b32d2e">raw allocator mismatch: inspect PHP/OPcache/runtime evidence</strong>';
			}
			if ( isset( $data->bizcity_memory_measurement_source ) ) {
				echo ' · source: ' . esc_html( (string) $data->bizcity_memory_measurement_source );
			}
			echo ' · ' . esc_html__( 'Included files:', 'bizcity-twin-ai' ) . ' ' . (int) $data->included_files;
			echo ' · ' . esc_html__( 'Declared classes:', 'bizcity-twin-ai' ) . ' ' . (int) $data->declared_classes;
			echo '</p>';
		}
		$this->after_non_tabular_output();
	}
}

