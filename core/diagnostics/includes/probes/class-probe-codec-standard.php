<?php
/**
 * BizCity Diagnostics — shared codec standard probe.
 *
 * Verifies the canonical helper, legacy-function removal from active runtime,
 * authenticated JSON round-trip/tamper rejection, and legacy URL compatibility.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_Codec_Standard', false ) ) {
	return;
}

// [2026-08-20 Johnny Chu] CODEC-CORE-DDV — shared codec Disk/Loader/Runtime probe.
final class BizCity_Probe_Codec_Standard implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.helper.codec_standard'; }
	public function label(): string { return 'Core Helper · Codec Standard'; }
	public function description(): string {
		return 'Kiểm tra BizCity_Codec Disk/Loader/Runtime, tamper rejection, legacy URL round-trip và không còn gọi twf_* trong active runtime.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 12; }
	public function icon(): string { return 'key-round'; }
	public function estimate_ms(): int { return 250; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		$steps = array();
		$root  = dirname( __DIR__, 4 );
		$codec = $root . '/core/helper/class-bizcity-codec.php';

		$disk_ok = is_readable( $codec ) && filesize( $codec ) > 0;
		$ctx->emit_step( $step = array(
			'label'  => 'Disk · canonical codec helper',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'class-bizcity-codec.php readable' : 'Missing or empty canonical codec helper.',
		) );
		$steps[] = $step;
		if ( ! $disk_ok ) {
			return array(
				'status'  => 'fail',
				'summary' => 'Canonical codec helper is missing.',
				'error'   => 'codec_helper_missing',
				'fix_hint'=> 'Deploy core/helper/class-bizcity-codec.php and load it from core/helper/bootstrap.php.',
				'steps'   => $steps,
			);
		}

		$loader_methods = array(
			'base64url_encode',
			'base64url_decode',
			'json_base64url_encode',
			'json_base64url_decode',
			'hmac_sha256',
			'encrypt_json_payload',
			'decrypt_json_payload',
			'legacy_url_encode',
			'legacy_url_decode',
		);
		$loader_ok = class_exists( 'BizCity_Codec' );
		foreach ( $loader_methods as $method ) {
			$loader_ok = $loader_ok && method_exists( 'BizCity_Codec', $method );
		}
		$ctx->emit_step( $step = array(
			'label'  => 'Loader · BizCity_Codec API',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'class and required methods loaded' : 'Class or required method missing; this can cause a public-request 500.',
		) );
		$steps[] = $step;
		if ( ! $loader_ok ) {
			return array(
				'status'  => 'fail',
				'summary' => 'BizCity_Codec is not loaded with the required API.',
				'error'   => 'codec_class_or_method_missing',
				'fix_hint'=> 'Load core/helper/bootstrap.php before modules and bundled plugins.',
				'steps'   => $steps,
			);
		}

		$payload = array(
			'v'       => 1,
			'purpose' => 'diagnostic',
			'blog_id' => (int) get_current_blog_id(),
			'exp'     => time() + 300,
			'nonce'   => substr( wp_generate_password( 32, false, false ), 0, 16 ),
		);
		$token = BizCity_Codec::encrypt_json_payload( $payload, 'bizcity-codec-probe-key', 'probe_', 'codec_probe' );
		$decoded = BizCity_Codec::decrypt_json_payload( $token, 'bizcity-codec-probe-key', 'probe_', 'codec_probe' );
		$tampered = '';
		if ( $token !== '' ) {
			$tampered = substr( $token, 0, -1 ) . ( substr( $token, -1 ) === 'A' ? 'B' : 'A' );
		}
		$tamper_rejected = false === BizCity_Codec::decrypt_json_payload( $tampered, 'bizcity-codec-probe-key', 'probe_', 'codec_probe' );
		$json_ok = is_array( $decoded )
			&& (int) ( $decoded['blog_id'] ?? 0 ) === (int) get_current_blog_id()
			&& $tamper_rejected;
		$ctx->emit_step( $step = array(
			'label'  => 'Runtime · authenticated JSON round-trip',
			'status' => $json_ok ? 'pass' : 'fail',
			'detail' => $json_ok ? 'decode OK and tampered payload rejected' : 'round-trip or tamper rejection failed',
		) );
		$steps[] = $step;

		$legacy_key = defined( 'AUTH_SALT' ) && AUTH_SALT ? (string) AUTH_SALT : 'your-fallback-secret';
		$legacy_iv  = substr( $legacy_key, 0, 16 );
		$legacy_token = BizCity_Codec::legacy_url_encode( 'vietqr|42', $legacy_key, $legacy_iv );
		$legacy_plain = BizCity_Codec::legacy_url_decode( $legacy_token, $legacy_key, $legacy_iv );
		$legacy_wire_ok = $legacy_token !== '' && $legacy_plain === 'vietqr|42';
		$ctx->emit_step( $step = array(
			'label'  => 'Runtime · legacy URL wire round-trip',
			'status' => $legacy_wire_ok ? 'pass' : 'fail',
			'detail' => $legacy_wire_ok ? 'historical AES/double-Base64 format preserved' : 'legacy wire round-trip failed',
		) );
		$steps[] = $step;

		$legacy_calls = self::find_active_legacy_calls( $root );
		$legacy_ok = empty( $legacy_calls );
		$ctx->emit_step( $step = array(
			'label'  => 'Disk · active twf_* call sweep',
			'status' => $legacy_ok ? 'pass' : 'fail',
			'detail' => $legacy_ok ? 'no active twf_encrypt_chat_id/twf_decrypt_chat_id calls' : implode( ' | ', array_slice( $legacy_calls, 0, 5 ) ),
		) );
		$steps[] = $step;

		$ok = $json_ok && $legacy_wire_ok && $legacy_ok;
		return array(
			'status'  => $ok ? 'pass' : 'fail',
			'summary' => $ok ? 'Codec standard PASS: loaded, round-trip OK, tamper rejected, no active twf calls.' : 'Codec standard FAIL: inspect the failed step.',
			'error'   => $ok ? '' : 'codec_standard_runtime_failed',
			'fix_hint'=> $ok ? '' : 'Deploy/load BizCity_Codec, migrate the reported legacy call, and rerun this probe.',
			'steps'   => $steps,
		);
	}

	private static function find_active_legacy_calls( string $root ): array {
		$found = array();
		// [2026-08-28 Johnny Chu] CODEC-CORE-DDV — inspect loaded runtime files, not the whole OneDrive tree.
		$root_prefix = str_replace( '\\', '/', rtrim( $root, '/\\' ) ) . '/';
		$skip_pattern = '#[\\/](?:_archived|vendor|_library|docs|changelog|tests|assets|build|dist|packages|node_modules)[\\/]#i';
		foreach ( get_included_files() as $path ) {
			$normalized_path = str_replace( '\\', '/', $path );
			if ( strpos( $normalized_path, $root_prefix ) !== 0 || substr( $normalized_path, -4 ) !== '.php' ) {
				continue;
			}
			if ( preg_match( $skip_pattern, $normalized_path ) ) {
				continue;
			}
			$lines = @file( $path, FILE_IGNORE_NEW_LINES );
			if ( ! is_array( $lines ) ) {
				continue;
			}
			foreach ( $lines as $line_number => $line ) {
				if ( preg_match( '/^\s*(?:\/\/|#|\*|\/\*)/', $line ) ) {
					continue;
				}
				if ( preg_match( '/twf_(?:encrypt|decrypt)_chat_id\s*\(/', $line ) ) {
					$found[] = str_replace( $root . DIRECTORY_SEPARATOR, '', $path ) . ':' . ( $line_number + 1 );
				}
			}
		}
		return $found;
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Codec_Standard';
	return $list;
} );
