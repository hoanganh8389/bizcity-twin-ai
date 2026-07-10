<?php
/**
 * BizCoach Pro — SVG Chart Diagnostics
 *
 * Diagnostic utility to verify SVG chart files are created correctly and can
 * be accessed by browser. Use via admin page widget for debugging.
 *
 * @package BizCoach_Pro
 * @since   0.3.24 (HOTFIX 2026-06-17)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_SVG_Diagnostics' ) ) { return; }

/**
 * SVG Chart Diagnostics.
 *
 * [2026-06-17 Johnny Chu] HOTFIX — diagnostic widget to debug SVG chart file
 * creation, permissions, DB record, and HTTP accessibility.
 */
class BizCoach_Pro_SVG_Diagnostics {

	/**
	 * Check if SVG chart for coachee exists and is accessible.
	 *
	 * @param int    $coachee_id Coachee ID.
	 * @param string $kind       Chart kind (natal, transit, vedic, etc).
	 * @return array Diagnostic report.
	 */
	public static function check_chart_file( $coachee_id, $kind = 'natal' ) {
		$coachee_id = (int) $coachee_id;
		$kind       = (string) $kind;

		$report = array(
			'coachee_id' => $coachee_id,
			'kind'       => $kind,
			'checks'     => array(),
			'errors'     => array(),
			'warnings'   => array(),
		);

		// 1. Check upload directory.
		$ud  = wp_upload_dir();
		$dir = trailingslashit( $ud['basedir'] ) . 'bizcoach-astro-charts';
		$report['checks']['upload_dir'] = array(
			'path'     => $dir,
			'exists'   => is_dir( $dir ),
			'writable' => is_writable( $dir ),
		);
		if ( ! is_dir( $dir ) ) {
			$report['errors'][] = "Folder không tồn tại: {$dir}";
		}
		if ( is_dir( $dir ) && ! is_writable( $dir ) ) {
			$report['warnings'][] = "Folder không writable: {$dir}";
		}

		// 2. Check chart file.
		$name = sprintf( '%d_%s.svg', $coachee_id, $kind );
		$file = $dir . '/' . $name;
		$url  = trailingslashit( $ud['baseurl'] ) . 'bizcoach-astro-charts/' . $name;
		$report['checks']['chart_file'] = array(
			'filename' => $name,
			'path'     => $file,
			'url'      => $url,
			'exists'   => file_exists( $file ),
			'readable' => is_readable( $file ),
			'size'     => file_exists( $file ) ? filesize( $file ) : 0,
		);
		if ( ! file_exists( $file ) ) {
			$report['errors'][] = "File SVG không tồn tại: {$file}";
		}
		if ( file_exists( $file ) && ! is_readable( $file ) ) {
			$report['errors'][] = "File SVG không readable: {$file}";
		}

		// 3. Check file content (first 200 chars).
		if ( file_exists( $file ) ) {
			$content = file_get_contents( $file, false, null, 0, 200 );
			$report['checks']['content_preview'] = substr( $content, 0, 200 );
			if ( stripos( $content, '<svg' ) === false ) {
				$report['warnings'][] = "File không chứa <svg> tag — có thể không phải SVG hợp lệ.";
			}
		}

		// 4. Check DB record (bccm_astro table).
		global $wpdb;
		$t_astro = $wpdb->prefix . 'bccm_astro';
		$row     = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, chart_svg, chart_type FROM {$t_astro} WHERE coachee_id=%d AND chart_type=%s ORDER BY id DESC LIMIT 1",
			$coachee_id,
			( $kind === 'natal' ? 'western' : $kind )
		), ARRAY_A );
		$report['checks']['db_record'] = array(
			'found'               => ! empty( $row ),
			'id'                  => isset( $row['id'] ) ? $row['id'] : null,
			'chart_svg'           => isset( $row['chart_svg'] ) ? $row['chart_svg'] : '',
			'matches_expected_url' => ( ! empty( $row['chart_svg'] ) && $row['chart_svg'] === $url ),
		);
		if ( empty( $row ) ) {
			$report['errors'][] = "Không tìm thấy record trong bccm_astro cho coachee_id={$coachee_id} chart_type={$kind}";
		}
		if ( ! empty( $row['chart_svg'] ) && $row['chart_svg'] !== $url ) {
			$report['warnings'][] = "DB chart_svg không match expected URL. DB: {$row['chart_svg']} | Expected: {$url}";
		}

		// 5. Test HTTP fetch (local request).
		if ( file_exists( $file ) ) {
			$fetch     = wp_remote_get( $url, array(
				'timeout'   => 10,
				'sslverify' => false,
			) );
			$http_code = is_wp_error( $fetch ) ? 0 : wp_remote_retrieve_response_code( $fetch );
			$mime_type = is_wp_error( $fetch ) ? '' : wp_remote_retrieve_header( $fetch, 'content-type' );
			$report['checks']['http_fetch'] = array(
				'url'       => $url,
				'http_code' => $http_code,
				'mime_type' => $mime_type,
				'is_error'  => is_wp_error( $fetch ),
				'error_msg' => is_wp_error( $fetch ) ? $fetch->get_error_message() : '',
			);
			if ( $http_code !== 200 ) {
				$report['errors'][] = "HTTP fetch thất bại: {$http_code} — {$url}";
			}
			if ( $http_code === 200 && stripos( $mime_type, 'svg' ) === false ) {
				$report['warnings'][] = "MIME type không phải SVG: {$mime_type}. Browser có thể không render được.";
			}
		}

		// Summary.
		$report['summary'] = array(
			'total_errors'   => count( $report['errors'] ),
			'total_warnings' => count( $report['warnings'] ),
			'status'         => empty( $report['errors'] ) ? ( empty( $report['warnings'] ) ? 'OK' : 'WARNING' ) : 'ERROR',
		);

		return $report;
	}

	/**
	 * Render diagnostic report as HTML (for admin page).
	 *
	 * @param array $report Report from check_chart_file().
	 */
	public static function render_html( $report ) {
		$status_color = array(
			'OK'      => '#10b981',
			'WARNING' => '#f59e0b',
			'ERROR'   => '#dc2626',
		);
		$color = isset( $status_color[ $report['summary']['status'] ] ) ? $status_color[ $report['summary']['status'] ] : '#6b7280';
		?>
		<div class="postbox" style="margin-top:16px;">
			<div class="inside">
				<h3 style="margin:0 0 12px;color:<?php echo esc_attr( $color ); ?>;">
					🔍 SVG Chart Diagnostic — <?php echo esc_html( $report['summary']['status'] ); ?>
				</h3>

				<?php if ( ! empty( $report['errors'] ) ): ?>
				<div style="background:#fee2e2;border:1px solid #fca5a5;padding:12px;border-radius:8px;margin-bottom:12px;">
					<strong style="color:#dc2626;">❌ Errors (<?php echo count( $report['errors'] ); ?>):</strong>
					<ul style="margin:8px 0 0;padding-left:20px;color:#b91c1c;">
						<?php foreach ( $report['errors'] as $err ): ?>
							<li><?php echo esc_html( $err ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>

				<?php if ( ! empty( $report['warnings'] ) ): ?>
				<div style="background:#fef3c7;border:1px solid #fbbf24;padding:12px;border-radius:8px;margin-bottom:12px;">
					<strong style="color:#d97706;">⚠️ Warnings (<?php echo count( $report['warnings'] ); ?>):</strong>
					<ul style="margin:8px 0 0;padding-left:20px;color:#92400e;">
						<?php foreach ( $report['warnings'] as $warn ): ?>
							<li><?php echo esc_html( $warn ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>

				<details style="margin-top:12px;">
					<summary style="cursor:pointer;color:#3b82f6;font-weight:600;">🔍 Chi tiết checks</summary>
					<pre style="background:#f9fafb;padding:12px;border-radius:8px;font-size:11px;overflow-x:auto;margin-top:8px;"><?php
						echo esc_html( json_encode( $report['checks'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
					?></pre>
				</details>
			</div>
		</div>
		<?php
	}
}
