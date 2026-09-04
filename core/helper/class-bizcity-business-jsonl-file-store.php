<?php
/**
 * Canonical encrypted JSONL filestore for business records.
 *
 * Records use append-only upsert/tombstone operations. Reads fold the newest
 * operation per stable record_id into the current business read model.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Helper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'BizCity_Business_JSONL_File_Store', false ) ) {
	return;
}

final class BizCity_Business_JSONL_File_Store {

	/**
	 * Write one encrypted business record.
	 *
	 * @param string $contract_id Registered contract ID.
	 * @param array  $record Business record fields.
	 * @param string $operation upsert or delete.
	 * @return bool
	 */
	public static function write( $contract_id, array $record, $operation = 'upsert' ) {
		// [2026-09-01 Johnny Chu] PHASE-CB2.3 — preserve the legacy boolean API while routing durable writes through the receipt owner.
		return false !== self::write_with_receipt( $contract_id, $record, $operation );
	}

	/**
	 * Write one encrypted business record and return its durable file receipt.
	 *
	 * The byte offset and row hash are captured while the append lock is held,
	 * so a Context Bank ledger never has to guess the location after unlock.
	 *
	 * @param string $contract_id Registered contract ID.
	 * @param array  $record Business record fields.
	 * @param string $operation upsert or delete.
	 * @return array|false
	 */
	public static function write_with_receipt( $contract_id, array $record, $operation = 'upsert' ) {
		// [2026-09-01 Johnny Chu] PHASE-CB2.3 — return a lock-captured receipt for Context Bank pointer registration.
		$contract = self::contract( $contract_id );
		$operation = $operation === 'delete' ? 'delete' : 'upsert';
		if ( ! $contract || ! isset( $record['record_id'] ) || (string) $record['record_id'] === '' ) {
			return false;
		}
		if ( ! class_exists( 'BizCity_Codec' ) || ! function_exists( 'wp_salt' ) ) {
			return false;
		}

		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$record['record_id'] = (string) $record['record_id'];
		$record['blog_id']   = $blog_id;
		$token = BizCity_Codec::encrypt_json_payload(
			$record,
			self::key(),
			'bzfs1.',
			'bizcity-business-file|' . (string) $contract_id . '|' . $blog_id
		);
		if ( $token === '' ) {
			return false;
		}

		$dir = self::directory( $contract );
		if ( $dir === '' || ! self::ensure_directory( $dir ) ) {
			return false;
		}
		$ts = gmdate( 'Y-m-d\\TH:i:s\\Z' );
		$event_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : hash( 'sha256', $ts . '|' . $record['record_id'] );
		$content_json = wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $content_json ) || $content_json === '' ) {
			return false;
		}
		$line = wp_json_encode( array(
			'schema_version' => (string) ( $contract['schema_version'] ?? '1.0' ),
			'ts'        => $ts,
			'blog_id'   => $blog_id,
			'event_uuid'=> $event_uuid,
			'record_id' => $record['record_id'],
			'op'        => $operation,
			'payload'   => $token,
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $line ) || $line === '' ) {
			return false;
		}

		$file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . gmdate( 'Y-m-d' ) . '.jsonl';
		$relative_file = gmdate( 'Y-m-d' ) . '.jsonl';
		$handle = @fopen( $file, 'ab' );
		if ( ! $handle || ! flock( $handle, LOCK_EX ) ) {
			if ( $handle ) {
				@fclose( $handle );
			}
			return false;
		}
		// [2026-09-01 Johnny Chu] CB2.3-FIX — fstat() reports the real append position while LOCK_EX is held; ftell() may remain zero in append mode.
		$file_stat = fstat( $handle );
		$offset = is_array( $file_stat ) && isset( $file_stat['size'] ) ? (int) $file_stat['size'] : ftell( $handle );
		$durable_line = $line . "\n";
		$written = fwrite( $handle, $durable_line );
		fflush( $handle );
		$end_offset = $offset + strlen( $durable_line );
		$write_ok = false !== $written && $written === strlen( $durable_line )
			&& false !== $offset && false !== $end_offset
			&& (int) $end_offset === (int) $offset + strlen( $durable_line );
		$receipt = $write_ok ? array(
			'contract_id'  => (string) $contract_id,
			'record_id'    => (string) $record['record_id'],
			'event_uuid'   => $event_uuid,
			'relative_file'=> $relative_file,
			'byte_offset'  => (int) $offset,
			'row_hash'     => hash( 'sha256', $durable_line ),
			'content_hash' => hash( 'sha256', $content_json ),
			'occurred_at'  => $ts,
			'operation'    => $operation,
			'blog_id'      => $blog_id,
		) : false;
		flock( $handle, LOCK_UN );
		fclose( $handle );
		return $receipt;
	}

	/**
	 * Return the newest current records matching exact filters.
	 *
	 * @param string $contract_id Registered contract ID.
	 * @param array  $args {days, limit, record_id, blog_id, user_id, identity_uuid, event_type, filter}
	 * @return array<int,array>
	 */
	public static function query( $contract_id, array $args = array() ) {
		$contract = self::contract( $contract_id );
		if ( ! $contract || ! class_exists( 'BizCity_Codec' ) || ! function_exists( 'wp_salt' ) ) {
			return array();
		}
		$days  = max( 1, (int) ( $args['days'] ?? 30 ) );
		$limit = max( 1, (int) ( $args['limit'] ?? 200 ) );
		$wanted_blog = array_key_exists( 'blog_id', $args )
			? (int) $args['blog_id']
			: ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 );
		$seen = array();
		$out  = array();
		$dir  = self::directory( $contract );
		if ( $dir === '' || ! is_dir( $dir ) ) {
			return array();
		}
		$files = glob( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.jsonl' );
		if ( ! is_array( $files ) ) {
			return array();
		}
		rsort( $files );
		$cutoff = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );

		foreach ( $files as $file ) {
			$date = basename( $file, '.jsonl' );
			if ( ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $date ) || $date < $cutoff ) {
				continue;
			}
			$lines = @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			if ( ! is_array( $lines ) ) {
				continue;
			}
			foreach ( array_reverse( $lines ) as $line ) {
				$envelope = json_decode( (string) $line, true );
				if ( ! is_array( $envelope ) || (int) ( $envelope['blog_id'] ?? -1 ) !== $wanted_blog ) {
					continue;
				}
				$record_id = (string) ( $envelope['record_id'] ?? '' );
				if ( $record_id === '' || isset( $seen[ $record_id ] ) ) {
					continue;
				}
				$seen[ $record_id ] = true;
				$payload = BizCity_Codec::decrypt_json_payload(
					(string) ( $envelope['payload'] ?? '' ),
					self::key(),
					'bzfs1.',
					'bizcity-business-file|' . (string) $contract_id . '|' . $wanted_blog
				);
				if ( ! is_array( $payload ) || (string) ( $payload['record_id'] ?? '' ) !== $record_id ) {
					continue;
				}
				if ( (string) ( $envelope['op'] ?? 'upsert' ) === 'delete' ) {
					continue;
				}
				if ( ! self::matches( $payload, $args ) ) {
					continue;
				}
				if ( isset( $args['filter'] ) && is_callable( $args['filter'] ) && ! call_user_func( $args['filter'], $payload ) ) {
					continue;
				}
				$out[] = $payload;
				if ( count( $out ) >= $limit ) {
					return $out;
				}
			}
		}
		return $out;
	}

	/**
	 * Read one bounded encrypted JSONL page from a lock-captured file offset.
	 *
	 * @param string $contract_id Registered contract ID.
	 * @param string $relative_file YYYY-MM-DD.jsonl file name.
	 * @param int    $byte_offset Inclusive byte offset.
	 * @param int    $limit Maximum rows to inspect.
	 * @param int    $max_ms Maximum read time in milliseconds.
	 * @return array<string,mixed>
	 */
	public static function read_page( $contract_id, $relative_file, $byte_offset = 0, $limit = 50, $max_ms = 500 ) {
		// [2026-09-01 Johnny Chu] CB3.4 — expose a bounded file page for resumable ledger reconciliation.
		$fail = static function ( $reason ) {
			return array( 'ok' => false, 'reason' => (string) $reason, 'rows' => array(), 'next_offset' => 0, 'eof' => false );
		};
		$started_at = microtime( true );
		$max_ms = max( 1, min( 5000, (int) $max_ms ) );
		$limit = max( 1, min( 500, (int) $limit ) );
		$contract = self::contract( $contract_id );
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$relative_file = (string) $relative_file;
		$byte_offset = (int) $byte_offset;
		if ( ! $contract || ! class_exists( 'BizCity_Codec' ) || ! function_exists( 'wp_salt' ) || $blog_id <= 0 ) {
			return $fail( 'reader_dependency_missing' );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}\.jsonl$/', $relative_file ) || $byte_offset < 0 ) {
			return $fail( 'page_shape_invalid' );
		}
		$path = rtrim( self::directory( $contract ), '/\\' ) . DIRECTORY_SEPARATOR . $relative_file;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return $fail( 'pointer_missing' );
		}
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle || false === fseek( $handle, $byte_offset ) ) {
			if ( $handle ) {
				@fclose( $handle );
			}
			return $fail( 'pointer_seek_failed' );
		}
		$rows = array();
		$offset = $byte_offset;
		$inspected = 0;
		while ( $inspected < $limit ) {
			if ( ( microtime( true ) - $started_at ) * 1000 > $max_ms ) {
				@fclose( $handle );
				return array( 'ok' => false, 'reason' => 'page_budget_exhausted', 'rows' => $rows, 'next_offset' => $offset, 'eof' => false, 'inspected' => $inspected );
			}
			$line_offset = $offset;
			$line = fgets( $handle );
			if ( false === $line ) {
				@fclose( $handle );
				return array( 'ok' => true, 'rows' => $rows, 'next_offset' => $offset, 'eof' => true, 'inspected' => $inspected );
			}
			$offset += strlen( $line );
			$inspected++;
			$row_hash = hash( 'sha256', $line );
			$envelope = json_decode( trim( $line ), true );
			if ( ! is_array( $envelope ) ) {
				$rows[] = array( 'valid' => false, 'reason' => 'row_json_invalid', 'byte_offset' => $line_offset, 'row_hash' => $row_hash );
				continue;
			}
			$record_id = (string) ( $envelope['record_id'] ?? '' );
			$event_uuid = (string) ( $envelope['event_uuid'] ?? '' );
			$payload = BizCity_Codec::decrypt_json_payload(
				(string) ( $envelope['payload'] ?? '' ),
				self::key(),
				'bzfs1.',
				'bizcity-business-file|' . (string) $contract_id . '|' . $blog_id
			);
			if ( (int) ( $envelope['blog_id'] ?? -1 ) !== $blog_id || $record_id === '' || $event_uuid === '' || ! is_array( $payload ) || (string) ( $payload['record_id'] ?? '' ) !== $record_id ) {
				$rows[] = array( 'valid' => false, 'reason' => 'row_envelope_invalid', 'byte_offset' => $line_offset, 'row_hash' => $row_hash );
				continue;
			}
			$content_json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $content_json ) || $content_json === '' ) {
				$rows[] = array( 'valid' => false, 'reason' => 'row_payload_invalid', 'byte_offset' => $line_offset, 'row_hash' => $row_hash );
				continue;
			}
			$rows[] = array(
				'valid' => true,
				'receipt' => array(
					'contract_id' => (string) $contract_id,
					'record_id' => $record_id,
					'event_uuid' => $event_uuid,
					'relative_file' => $relative_file,
					'byte_offset' => $line_offset,
					'row_hash' => $row_hash,
					'content_hash' => hash( 'sha256', $content_json ),
					'occurred_at' => (string) ( $envelope['ts'] ?? '' ),
					'operation' => (string) ( $envelope['op'] ?? 'upsert' ),
					'blog_id' => $blog_id,
				),
				'record' => $payload,
			);
		}
		@fclose( $handle );
		return array( 'ok' => true, 'rows' => $rows, 'next_offset' => $offset, 'eof' => false, 'inspected' => $inspected );
	}

	/**
	 * Follow one lock-captured receipt without scanning the whole contract.
	 *
	 * @param string $contract_id Registered contract ID.
	 * @param array  $receipt Receipt fields returned by write_with_receipt().
	 * @param int    $max_ms Maximum allowed read time in milliseconds.
	 * @return array|false
	 */
	public static function read_receipt( $contract_id, array $receipt, $max_ms = 100 ) {
		// [2026-09-01 Johnny Chu] CB3.3 — follow only a verified bounded pointer; never accept an arbitrary filesystem path.
		$fail = static function ( $reason ) {
			return array( 'ok' => false, 'reason' => (string) $reason );
		};
		$started_at = microtime( true );
		$max_ms = max( 1, min( 1000, (int) $max_ms ) );
		$budget_exceeded = static function () use ( $started_at, $max_ms ) {
			return ( microtime( true ) - $started_at ) * 1000 > $max_ms;
		};
		$contract = self::contract( $contract_id );
		if ( ! $contract || ! class_exists( 'BizCity_Codec' ) || ! function_exists( 'wp_salt' ) ) {
			return $fail( 'reader_dependency_missing' );
		}
		$blog_id = (int) ( $receipt['blog_id'] ?? 0 );
		$current_blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$relative_file = (string) ( $receipt['relative_file'] ?? '' );
		$record_id = (string) ( $receipt['record_id'] ?? '' );
		if ( $blog_id <= 0 || $blog_id !== $current_blog_id || $record_id === ''
			|| ! preg_match( '/^\d{4}-\d{2}-\d{2}\.jsonl$/', $relative_file )
			|| (int) ( $receipt['byte_offset'] ?? -1 ) < 0
			|| ! preg_match( '/^[a-f0-9]{64}$/i', (string) ( $receipt['row_hash'] ?? '' ) ) ) {
			return $fail( 'receipt_shape_invalid' );
		}
		$path = rtrim( self::directory( $contract ), '/\\' ) . DIRECTORY_SEPARATOR . $relative_file;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return $fail( 'pointer_missing' );
		}
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle || false === fseek( $handle, (int) $receipt['byte_offset'] ) ) {
			if ( $handle ) {
				@fclose( $handle );
			}
			return $fail( 'pointer_seek_failed' );
		}
		$line = fgets( $handle );
		@fclose( $handle );
		if ( $budget_exceeded() ) {
			return $fail( 'pointer_budget_exhausted' );
		}
		$actual_hash = is_string( $line ) ? hash( 'sha256', $line ) : '';
		if ( ! is_string( $line ) || ! hash_equals( strtolower( (string) $receipt['row_hash'] ), strtolower( $actual_hash ) ) ) {
			return $fail( 'pointer_hash_mismatch' );
		}
		$envelope = json_decode( trim( $line ), true );
		if ( ! is_array( $envelope )
			|| (int) ( $envelope['blog_id'] ?? -1 ) !== $blog_id
			|| (string) ( $envelope['record_id'] ?? '' ) !== $record_id
			|| (string) ( $envelope['event_uuid'] ?? '' ) !== (string) ( $receipt['event_uuid'] ?? '' ) ) {
			return $fail( 'pointer_envelope_mismatch' );
		}
		$payload = BizCity_Codec::decrypt_json_payload(
			(string) ( $envelope['payload'] ?? '' ),
			self::key(),
			'bzfs1.',
			'bizcity-business-file|' . (string) $contract_id . '|' . $blog_id
		);
		if ( ! is_array( $payload ) || (string) ( $payload['record_id'] ?? '' ) !== $record_id ) {
			return $fail( 'pointer_payload_invalid' );
		}
		return array(
			'ok' => true,
			'operation' => (string) ( $envelope['op'] ?? 'upsert' ),
			'record' => $payload,
			'envelope' => array(
				'event_uuid' => (string) ( $envelope['event_uuid'] ?? '' ),
				'record_id' => $record_id,
				'blog_id' => $blog_id,
			),
		);
	}

	public static function find( $contract_id, $record_id, array $args = array() ) {
		$args['record_id'] = (string) $record_id;
		$args['limit'] = 1;
		$rows = self::query( $contract_id, $args );
		return isset( $rows[0] ) ? $rows[0] : array();
	}

	public static function delete( $contract_id, $record_id, array $identity = array() ) {
		// [2026-09-01 Johnny Chu] PHASE-CB4.5 — preserve the boolean compatibility API while exposing the tombstone receipt to Context Bank owners.
		return false !== self::delete_with_receipt( $contract_id, $record_id, $identity );
	}

	/**
	 * Write one tombstone and return its durable receipt.
	 *
	 * @param string $contract_id Registered contract ID.
	 * @param string $record_id Stable business record ID.
	 * @param array  $identity   Tenant/owner scope fields.
	 * @return array|false
	 */
	public static function delete_with_receipt( $contract_id, $record_id, array $identity = array() ) {
		// [2026-09-01 Johnny Chu] PHASE-CB4.5 — expose a lock-captured receipt for Context Bank tombstone admission.
		return self::write_with_receipt( $contract_id, array_merge( $identity, array( 'record_id' => (string) $record_id ) ), 'delete' );
	}

	private static function contract( $contract_id ) {
		if ( ! class_exists( 'BizCity_File_Contract_Registry' ) ) {
			return null;
		}
		$contract = BizCity_File_Contract_Registry::get( $contract_id );
		return is_array( $contract ) && (string) ( $contract['storage_scope'] ?? 'blog' ) === 'blog' ? $contract : null;
	}

	private static function directory( array $contract ) {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$upload = wp_upload_dir();
		return isset( $upload['basedir'] )
			? rtrim( $upload['basedir'], '/\\' ) . DIRECTORY_SEPARATOR . $contract['folder'] . DIRECTORY_SEPARATOR . $contract['module']
			: '';
	}

	private static function ensure_directory( $dir ) {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$htaccess = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . '.htaccess';
		if ( ! is_file( $htaccess ) ) {
			@file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
		}
		$web_config = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . 'web.config';
		if ( ! is_file( $web_config ) ) {
			@file_put_contents( $web_config, "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><requestFiltering><fileExtensions><add fileExtension=\".jsonl\" allowed=\"false\" /></fileExtensions></requestFiltering></security></system.webServer></configuration>" );
		}
		$index = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . 'index.php';
		if ( ! is_file( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		return is_dir( $dir );
	}

	private static function key() {
		return wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' );
	}

	private static function matches( array $record, array $args ) {
		foreach ( array( 'record_id', 'blog_id', 'user_id', 'identity_uuid', 'event_type' ) as $field ) {
			if ( ! array_key_exists( $field, $args ) || $args[ $field ] === '' || $args[ $field ] === null ) {
				continue;
			}
			if ( (string) ( $record[ $field ] ?? '' ) !== (string) $args[ $field ] ) {
				return false;
			}
		}
		return true;
	}
}
