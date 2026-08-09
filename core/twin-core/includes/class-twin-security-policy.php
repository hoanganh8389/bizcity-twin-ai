<?php
/**
 * Shared network and upload policy enforcement for Twin extensions.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 1.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Twin_Security_Policy' ) ) {
	final class BizCity_Twin_Security_Policy {

		/**
		 * Validate an outbound URL before a HTTP client is called.
		 *
		 * @param string              $url
		 * @param array<string,mixed> $policy
		 * @return array{allowed:bool,code:string,message:string,host:string}
		 */
		public static function validate_url( $url, array $policy = array() ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — shared SSRF boundary for every outbound URL caller.
			$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) $url ) : parse_url( (string) $url );
			$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
			$host   = strtolower( trim( (string) ( $parts['host'] ?? '' ), '.' ) );
			if ( strlen( $host ) > 2 && '[' === $host[0] && ']' === substr( $host, -1 ) ) {
				// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — normalize bracketed IPv6 before private-range checks.
				$host = substr( $host, 1, -1 );
			}
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				return self::url_denied( 'invalid_url', $host );
			}

			$allow_hosts = isset( $policy['allow_hosts'] ) && is_array( $policy['allow_hosts'] ) ? array_map( 'strtolower', $policy['allow_hosts'] ) : array();
			if ( ! empty( $allow_hosts ) && ! in_array( $host, array_map( static function ( $allowed ) { return trim( (string) $allowed, '.' ); }, $allow_hosts ), true ) ) {
				return self::url_denied( 'host_not_allowed', $host );
			}

			$block_private = ! array_key_exists( 'block_private_ranges', $policy ) || ! empty( $policy['block_private_ranges'] );
			if ( false !== strpos( $host, '%' ) || ( $block_private && self::is_private_host( $host ) ) ) {
				return self::url_denied( 'private_range_blocked', $host );
			}

			return array( 'allowed' => true, 'code' => '', 'message' => '', 'host' => $host );
		}

		/**
		 * Validate a PHP upload array against an extension upload policy.
		 *
		 * @param array<string,mixed> $file
		 * @param array<string,mixed> $policy
		 * @param callable|null       $scanner Receives the temporary file path.
		 * @return array{allowed:bool,code:string,message:string,mime:string,size:int}
		 */
		public static function validate_upload( array $file, array $policy = array(), $scanner = null ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — shared dangerous-upload boundary.
			$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
			$size  = (int) ( $file['size'] ?? 0 );
			$path  = (string) ( $file['tmp_name'] ?? '' );
			if ( UPLOAD_ERR_OK !== $error || '' === $path || ! is_uploaded_file( $path ) ) {
				return self::upload_denied( 'upload_invalid', $size );
			}
			$max_bytes = (int) ( $policy['max_bytes'] ?? 0 );
			if ( $max_bytes > 0 && $size > $max_bytes ) {
				return self::upload_denied( 'upload_too_large', $size );
			}

			$mime = '';
			if ( class_exists( 'finfo' ) ) {
				$finfo = new finfo( FILEINFO_MIME_TYPE );
				$mime  = (string) $finfo->file( $path );
			}
			if ( '' === $mime ) {
				$mime = (string) ( $file['type'] ?? '' );
			}
			$allowed_mime = isset( $policy['allowed_mime'] ) && is_array( $policy['allowed_mime'] ) ? array_map( 'strtolower', $policy['allowed_mime'] ) : array();
			if ( ! empty( $allowed_mime ) && ! in_array( strtolower( $mime ), $allowed_mime, true ) ) {
				return self::upload_denied( 'mime_not_allowed', $size, $mime );
			}

			if ( ! empty( $policy['scan_required'] ) ) {
				if ( ! is_callable( $scanner ) || true !== call_user_func( $scanner, $path ) ) {
					return self::upload_denied( 'upload_scan_required', $size, $mime );
				}
			}

			return array( 'allowed' => true, 'code' => '', 'message' => '', 'mime' => $mime, 'size' => $size );
		}

		private static function is_private_host( $host ) {
			$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : ( function_exists( 'gethostbynamel' ) ? (array) gethostbynamel( $host ) : array() );
			if ( empty( $ips ) ) {
				return false;
			}
			foreach ( $ips as $ip ) {
				if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return true;
				}
			}
			return false;
		}

		private static function url_denied( $code, $host ) {
			return array( 'allowed' => false, 'code' => (string) $code, 'message' => 'URL is not allowed by the network policy.', 'host' => (string) $host );
		}

		private static function upload_denied( $code, $size, $mime = '' ) {
			return array( 'allowed' => false, 'code' => (string) $code, 'message' => 'Upload is not allowed by the upload policy.', 'mime' => (string) $mime, 'size' => (int) $size );
		}
	}
}