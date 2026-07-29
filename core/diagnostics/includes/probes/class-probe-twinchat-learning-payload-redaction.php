<?php
/**
 * BizCity Diagnostics - TwinChat learning payload redaction probe.
 *
 * Verifies the allowlist/redaction boundary used by learning REST polling,
 * SSE serialization, and public share event payloads. The probe is read-only
 * and uses in-memory fixtures only.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-27 (PHASE-0.51-DDV)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Twinchat_Learning_Payload_Redaction', false ) ) {
	return;
}

final class BizCity_Probe_Twinchat_Learning_Payload_Redaction implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-07-27 Johnny Chu] PHASE-0.51-DDV - expose a stable redaction probe id.
		return 'twinchat.learning_payload_redaction';
	}

	public function label(): string {
		return 'TwinChat Learning - Payload Redaction';
	}

	public function description(): string {
		return 'Kiểm tra allowlist/redaction payload learning tại REST polling, SSE frame và public share bằng fixture trong memory.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 76;
	}

	public function icon(): string {
		return 'shield-check';
	}

	public function estimate_ms(): int {
		return 300;
	}

	public function precondition() {
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-27 Johnny Chu] PHASE-0.51-DDV - keep all redaction checks read-only and deterministic.
		$root       = dirname( __DIR__, 4 );
		$event_file = $root . '/modules/twinchat/includes/learning/class-twinchat-learning-events.php';
		$stream_file = $root . '/modules/twinchat/includes/learning/class-twinchat-learning-stream.php';
		$rest_file  = $root . '/modules/twinchat/includes/learning/class-twinchat-rest-learning.php';
		$failures   = array();

		$event_src  = file_exists( $event_file ) ? (string) file_get_contents( $event_file ) : '';
		$stream_src = file_exists( $stream_file ) ? (string) file_get_contents( $stream_file ) : '';
		$rest_src   = file_exists( $rest_file ) ? (string) file_get_contents( $rest_file ) : '';
		$disk_ok    = $event_src !== ''
			&& $stream_src !== ''
			&& $rest_src !== ''
			&& strpos( $event_src, 'sanitize_payload_for_output' ) !== false
			&& strpos( $event_src, 'sanitize_event_row_for_output' ) !== false
			&& strpos( $stream_src, 'read_since' ) !== false
			&& strpos( $stream_src, 'send_event' ) !== false
			&& substr_count( $rest_src, 'sanitize_payload_for_output' ) >= 2
			&& strpos( $rest_src, "'public'" ) !== false;
		$ctx->emit_step( array(
			'label'  => 'Layer 1 - Disk - redaction wiring',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'Event sanitizer, SSE reader, and public-share calls found.' : 'One or more active redaction boundary files or markers are missing.',
		) );
		if ( ! $disk_ok ) {
			$failures[] = 'disk_wiring_missing';
		}

		$loader_ok = class_exists( 'BizCity_TwinChat_Learning_Events' )
			&& class_exists( 'BizCity_TwinChat_Learning_Stream' );
		$ctx->emit_step( array(
			'label'  => 'Layer 2 - Loader - event and stream classes',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'Learning event sanitizer and SSE controller are loaded.' : 'Learning event or SSE class is not loaded in this Diagnostics context.',
		) );
		if ( ! $loader_ok ) {
			$failures[] = 'learning_classes_missing';
		}

		if ( ! empty( $failures ) ) {
			return self::failure( $failures );
		}

		$fixture = self::fixture();
		$secret_values = self::secret_values();

		try {
			$rest_safe = BizCity_TwinChat_Learning_Events::sanitize_event_row_for_output( $fixture, 'private' );
			$rest_json = wp_json_encode( $rest_safe );
			$rest_ok   = self::contains_no_secret( $rest_json, $secret_values )
				&& isset( $rest_safe['payload']['stats']['processed'] )
				&& ! isset( $rest_safe['payload']['secret_field'] );
			$ctx->emit_step( array(
				'label'  => 'Layer 3 - Runtime - REST polling payload',
				'status' => $rest_ok ? 'pass' : 'fail',
				'detail' => $rest_ok ? 'Allowlisted fields remain and secret-like fixture data is absent.' : 'REST-shaped event payload still exposes a secret-like field or loses an allowed field.',
			) );
			if ( ! $rest_ok ) {
				$failures[] = 'rest_payload_exposure';
			}

			$stream = new BizCity_TwinChat_Learning_Stream();
			$method = new ReflectionMethod( $stream, 'send_event' );
			$method->setAccessible( true );
			ob_start();
			$method->invoke( $stream, $rest_safe );
			$sse_output = (string) ob_get_clean();
			$sse_ok = strpos( $sse_output, 'event: done' ) !== false
				&& strpos( $sse_output, 'data: ' ) !== false
				&& self::contains_no_secret( $sse_output, $secret_values );
			$ctx->emit_step( array(
				'label'  => 'Layer 3 - Runtime - SSE frame',
				'status' => $sse_ok ? 'pass' : 'fail',
				'detail' => $sse_ok ? 'send_event() emits a frame without secret-like fixture data.' : 'SSE frame contains secret-like fixture data or is not serialized as expected.',
			) );
			if ( ! $sse_ok ) {
				$failures[] = 'sse_payload_exposure';
			}

			$share_safe = BizCity_TwinChat_Learning_Events::sanitize_event_row_for_output( self::share_fixture(), 'public' );
			$share_json = wp_json_encode( $share_safe );
			$share_ok   = self::contains_no_secret( $share_json, $secret_values )
				&& isset( $share_safe['payload']['source_title'] )
				&& ! isset( $share_safe['payload']['owner'] );
			$ctx->emit_step( array(
				'label'  => 'Layer 3 - Runtime - public share payload',
				'status' => $share_ok ? 'pass' : 'fail',
				'detail' => $share_ok ? 'Public scope keeps share-safe fields and removes owner/secret-like data.' : 'Public share payload exposes private data or drops a required share-safe field.',
			) );
			if ( ! $share_ok ) {
				$failures[] = 'public_share_exposure';
			}
		} catch ( Throwable $e ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			$failures[] = 'runtime_exception';
		}

		return empty( $failures )
			? array(
				'status'  => 'pass',
				'summary' => 'Learning payload redaction PASS - REST, SSE, and public share boundaries are covered.',
			)
			: self::failure( $failures );
	}

	public function cleanup(): void {
		// Read-only probe; no artifacts are created.
	}

	private static function fixture(): array {
		// [2026-07-27 Johnny Chu] PHASE-0.51-DDV - fixture covers allowlist, nested data, and credential-like text.
		return array(
			'id'      => 101,
			'job_id'  => 42,
			'ts'      => '2026-07-27 00:00:00',
			'event'   => 'done',
			'payload' => array(
				'notebook_id' => 17,
				'job_id'      => 42,
				'processed'   => 9,
				'progress'    => 1,
				'msg'         => 'Bearer probe-bearer-secret api_key=probe-api-secret',
				'stats'       => array(
					'processed' => 9,
					'api_key'   => 'probe-nested-secret',
				),
				'secret_field' => 'probe-unknown-secret',
			),
		);
	}

	private static function share_fixture(): array {
		return array(
			'id'      => 102,
			'job_id'  => 43,
			'ts'      => '2026-07-27 00:00:00',
			'event'   => 'job',
			'payload' => array(
				'notebook_id'  => 17,
				'job_id'       => 43,
				'source_title' => 'Shared source fixture',
				'owner'        => 'private-owner-fixture',
				'msg'          => 'token=probe-share-secret',
				'private_field' => 'probe-private-secret',
			),
		);
	}

	private static function secret_values(): array {
		return array(
			'probe-bearer-secret',
			'probe-api-secret',
			'probe-nested-secret',
			'probe-unknown-secret',
			'private-owner-fixture',
			'probe-share-secret',
			'probe-private-secret',
		);
	}

	private static function contains_no_secret( $haystack, array $secrets ): bool {
		// [2026-07-27 Johnny Chu] PHASE-0.51-DDV - never include fixture values in probe failure details.
		$haystack = (string) $haystack;
		foreach ( $secrets as $secret ) {
			if ( strpos( $haystack, $secret ) !== false ) {
				return false;
			}
		}
		return true;
	}

	private static function failure( array $failures ): array {
		return array(
			'status'   => 'fail',
			'summary'  => 'Learning payload redaction FAIL.',
			'error'    => implode( '; ', $failures ),
			'fix_hint' => 'Kiểm tra sanitizer allowlist và các boundary REST/SSE/public share của TwinChat Learning.',
		);
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Twinchat_Learning_Payload_Redaction';
	return $list;
} );
