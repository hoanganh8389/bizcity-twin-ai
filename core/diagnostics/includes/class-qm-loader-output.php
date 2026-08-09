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
		echo '<p>' . esc_html__( 'Loader hook snapshots captured in this request.', 'bizcity-twin-ai' ) . '</p>';
		echo '<table class="qm-sortable"><thead><tr>';
		echo '<th>' . esc_html__( 'Phase', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Hooks', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Callbacks', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Memory delta', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Memory now', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'New files/classes', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'New file buckets', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Source groups', 'bizcity-twin-ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $snapshots ) ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No loader snapshot was captured.', 'bizcity-twin-ai' ) . '</td></tr>';
		} else {
			foreach ( $snapshots as $phase => $snapshot ) {
				$source_summary = isset( $snapshot['source_summary'] ) && is_array( $snapshot['source_summary'] )
					? $snapshot['source_summary']
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
				echo '<td class="qm-num">+' . (float) ( $snapshot['memory_delta_kb'] ?? 0 ) . ' KB</td>';
				echo '<td class="qm-num">' . (float) ( $snapshot['memory_mb'] ?? 0 ) . ' MB</td>';
				echo '<td class="qm-num">+' . (int) ( $snapshot['new_file_count'] ?? 0 ) . ' / +' . (int) ( $snapshot['new_class_count'] ?? 0 ) . '</td>';
				echo '<td>' . esc_html( implode( ', ', $new_file_text ) ) . '</td>';
				echo '<td>' . esc_html( implode( ', ', $source_text ) ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		if ( isset( $data->peak_memory_mb ) ) {
			echo '<p class="qm-info">';
			echo esc_html__( 'Request peak memory:', 'bizcity-twin-ai' ) . ' ';
			echo esc_html( (string) $data->peak_memory_mb ) . ' MB';
			echo ' · ' . esc_html__( 'Included files:', 'bizcity-twin-ai' ) . ' ' . (int) $data->included_files;
			echo ' · ' . esc_html__( 'Declared classes:', 'bizcity-twin-ai' ) . ' ' . (int) $data->declared_classes;
			echo '</p>';
		}
		$this->after_non_tabular_output();
	}
}

