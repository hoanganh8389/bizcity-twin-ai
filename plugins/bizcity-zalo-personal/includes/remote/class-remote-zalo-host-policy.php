<?php
/**
 * Remote Zalo Hub host validation and DNS safety policy.
 *
 * @package BizCity_Zalo_Personal
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Host_Policy', false ) ) {
	return;
}

// [2026-09-29 12:00 PM GitHub Copilot] PHASE-0.82-B1 — enforce URL, DNS and IP safety before Remote Zalo Hub requests.
final class BizCity_Remote_Zalo_Host_Policy {

	const MAX_URL_LENGTH = 255;

	/** @var callable|null */
	public static $resolver = null;

	/** @var array|null Test-only allowlist override. */
	private static $allowed_hosts_override = null;

	/** Validate the configured base URL without performing DNS. */
	public static function validate_base_url( string $url ): array {
		$url = trim( $url );
		if ( strlen( $url ) > self::MAX_URL_LENGTH ) {
			return self::failure( 'remote_url_too_long' );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return self::failure( 'remote_url_invalid' );
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( rtrim( (string) $parts['host'], '.' ) );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$path   = '/' . trim( preg_replace( '#/+#', '/', $path ), '/' );
		$is_dev_loopback = self::is_dev_loopback_url( $scheme, $host, $port, $path );

		if ( $is_dev_loopback ) {
			return array( 'ok' => true, 'code' => null, 'normalized' => 'http://127.0.0.1:' . $port . '/client/v1' );
		}
		if ( 'https' !== $scheme ) {
			return self::failure( 'remote_https_required' );
		}
		if ( isset( $parts['user'], $parts['pass'] ) || isset( $parts['user'] ) ) {
			return self::failure( 'remote_userinfo_forbidden' );
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) !== false || ! self::is_domain_name( $host ) ) {
			return self::failure( 'remote_host_invalid' );
		}
		if ( null !== $port && 443 !== $port ) {
			return self::failure( 'remote_port_forbidden' );
		}
		if ( ! self::host_is_allowed( $host ) ) {
			return self::failure( 'remote_host_not_allowlisted' );
		}
		// The published guide uses /client/v1; the operator endpoint also ships a legacy /client-api base.
		if ( ! preg_match( '#^/(?:client/v1|client-api)(?:/|$)#', $path ) ) {
			return self::failure( 'remote_path_invalid' );
		}
		if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return self::failure( 'remote_url_invalid' );
		}

		$normalized = 'https://' . $host . ( null !== $port ? ':' . $port : '' ) . $path;
		return array( 'ok' => true, 'code' => null, 'normalized' => $normalized );
	}

	/** Resolve every address and reject any unsafe result. */
	public static function resolve_and_check( string $host ): array {
		$host = strtolower( rtrim( trim( $host ), '.' ) );
		if ( self::is_dev_loopback_host( $host ) ) {
			return array( 'ok' => true, 'code' => null, 'ips' => array( '127.0.0.1' ) );
		}

		$resolver = self::$resolver;
		if ( ! is_callable( $resolver ) ) {
			$resolver = static function ( $name ) {
				$ips = array();
				if ( function_exists( 'dns_get_record' ) ) {
					$records = dns_get_record( $name, DNS_A | DNS_AAAA );
					if ( is_array( $records ) ) {
						foreach ( $records as $record ) {
							if ( isset( $record['ip'] ) ) {
								$ips[] = $record['ip'];
							}
							if ( isset( $record['ipv6'] ) ) {
								$ips[] = $record['ipv6'];
							}
						}
					}
				}
				if ( ! $ips && function_exists( 'gethostbynamel' ) ) {
					$ips = gethostbynamel( $name );
				}
				return is_array( $ips ) ? $ips : array();
			};
		}

		try {
			$resolved = call_user_func( $resolver, $host );
		} catch ( Throwable $e ) {
			return array( 'ok' => false, 'code' => 'remote_dns_failed', 'ips' => array() );
		}
		$ips = self::extract_ips( $resolved );
		if ( ! $ips ) {
			return array( 'ok' => false, 'code' => 'remote_dns_failed', 'ips' => array() );
		}
		foreach ( $ips as $ip ) {
			if ( self::is_unsafe_ip( $ip ) ) {
				return array( 'ok' => false, 'code' => 'remote_host_unsafe', 'ips' => $ips );
			}
		}
		return array( 'ok' => true, 'code' => null, 'ips' => $ips );
	}

	/** Test seam for DNS; production leaves this null. */
	public static function set_resolver( $resolver ): void {
		self::$resolver = is_callable( $resolver ) ? $resolver : null;
	}

	/** Test seam; null restores the configured constant. */
	public static function set_allowed_hosts( $hosts ): void {
		self::$allowed_hosts_override = null === $hosts ? null : (array) $hosts;
	}

	public static function reset_seams(): void {
		self::$resolver             = null;
		self::$allowed_hosts_override = null;
	}

	private static function failure( string $code ): array {
		return array( 'ok' => false, 'code' => $code, 'normalized' => null );
	}

	private static function is_domain_name( string $host ): bool {
		if ( strlen( $host ) < 1 || strlen( $host ) > 253 || strpos( $host, '.' ) === false ) {
			return false;
		}
		return (bool) preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host );
	}

	private static function host_is_allowed( string $host ): bool {
		if ( null !== self::$allowed_hosts_override ) {
			$allowed = self::$allowed_hosts_override;
		} elseif ( defined( 'BIZCITY_ZALO_REMOTE_ALLOWED_HOSTS' ) ) {
			$allowed = preg_split( '/\s*,\s*/', (string) BIZCITY_ZALO_REMOTE_ALLOWED_HOSTS, -1, PREG_SPLIT_NO_EMPTY );
		} else {
			return true;
		}
		$allowed = array_map( 'strtolower', array_map( 'rtrim', $allowed, array_fill( 0, count( $allowed ), '.' ) ) );
		return in_array( $host, $allowed, true );
	}

	private static function is_dev_loopback_url( string $scheme, string $host, $port, string $path ): bool {
		return 'http' === $scheme && '127.0.0.1' === $host && null !== $port && $port >= 1 && $port <= 65535 && '/client/v1' === $path && self::dev_loopback_enabled();
	}

	private static function is_dev_loopback_host( string $host ): bool {
		return '127.0.0.1' === $host && self::dev_loopback_enabled();
	}

	private static function dev_loopback_enabled(): bool {
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		return 'local' === $environment && defined( 'BIZCITY_ZALO_REMOTE_DEV_ALLOW_LOOPBACK' ) && true === BIZCITY_ZALO_REMOTE_DEV_ALLOW_LOOPBACK;
	}

	private static function extract_ips( $resolved ): array {
		if ( ! is_array( $resolved ) ) {
			return array();
		}
		$ips = array();
		foreach ( $resolved as $value ) {
			if ( is_array( $value ) ) {
				foreach ( array( 'ip', 'ipv6', 'address' ) as $key ) {
					if ( isset( $value[ $key ] ) ) {
						$ips[] = (string) $value[ $key ];
					}
				}
			} elseif ( is_string( $value ) ) {
				$ips[] = trim( $value );
			}
		}
		return array_values( array_unique( array_filter( $ips, 'strlen' ) ) );
	}

	private static function is_unsafe_ip( string $ip ): bool {
		$ip = trim( $ip, '[]' );
		$packed = @inet_pton( $ip );
		if ( false === $packed ) {
			return true;
		}
		if ( 4 === strlen( $packed ) ) {
			$value = (float) sprintf( '%u', ip2long( $ip ) );
			$ranges = array(
				array( 0, 16777215 ), array( 167772160, 184549375 ), array( 1681915904, 1686110207 ),
				array( 2130706432, 2147483647 ), array( 2851995648, 2852061183 ), array( 2886729728, 2887778303 ),
				array( 3232235520, 3232301055 ),
				array( 2887778304, 2887778559 ), array( 3221225472, 3221225727 ), array( 3221225984, 3221226239 ),
				array( 3323068416, 3323199487 ), array( 3405803776, 3405804031 ), array( 3758096384, 4294967295 ),
			);
			foreach ( $ranges as $range ) {
				if ( $value >= $range[0] && $value <= $range[1] ) {
					return true;
				}
			}
			return false;
		}

		if ( "\0\0\0\0\0\0\0\0\0\0\xff\xff" === substr( $packed, 0, 12 ) ) {
			return self::is_unsafe_ip( inet_ntop( substr( $packed, 12, 4 ) ) );
		}
		$prefixes = array(
			array( '::', 128 ), array( '::1', 128 ), array( 'fc00::', 7 ), array( 'fe80::', 10 ),
			array( 'ff00::', 8 ), array( '2001:db8::', 32 ), array( '2001:2::', 48 ), array( '2001:10::', 28 ),
			array( '2001:20::', 28 ), array( '3fff::', 20 ), array( '2001::', 32 ),
		);
		foreach ( $prefixes as $prefix ) {
			$prefix_bin = @inet_pton( $prefix[0] );
			if ( false !== $prefix_bin && self::matches_prefix( $packed, $prefix_bin, $prefix[1] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function matches_prefix( string $ip, string $prefix, int $bits ): bool {
		$bytes = (int) floor( $bits / 8 );
		if ( substr( $ip, 0, $bytes ) !== substr( $prefix, 0, $bytes ) ) {
			return false;
		}
		if ( 0 === $bits % 8 ) {
			return true;
		}
		$mask = 0xff << ( 8 - ( $bits % 8 ) );
		return ( ord( $ip[ $bytes ] ) & $mask ) === ( ord( $prefix[ $bytes ] ) & $mask );
	}
}