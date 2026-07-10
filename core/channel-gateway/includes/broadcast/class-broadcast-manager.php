<?php
/**
 * Broadcast Manager — CRUD cho bizcity_cg_broadcasts + bizcity_cg_broadcast_recipients.
 * Mọi read đi qua BizCity_Cache (group: bzcast).
 * Mọi write flush_group('bzcast').
 *
 * [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — new class.
 *
 * @package BizCity_Channel_Gateway
 * @since   PHASE-CG-BROADCAST (2026-06-27)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Broadcast_Manager' ) ) {
	return;
}

class BizCity_Broadcast_Manager {

	const CACHE_GROUP = 'bzcast';
	const TTL         = 300; // 5 min

	// ── Broadcasts CRUD ──────────────────────────────────────────────────────

	/**
	 * Create a new broadcast.
	 *
	 * @param  array $data { name, type, meta_json, batch_size, delay_sec, created_by }
	 * @return int   New broadcast ID, 0 on failure.
	 */
	public static function insert( array $data ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — insert broadcast
		global $wpdb;
		$row = array(
			'name'       => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'type'       => sanitize_key( (string) ( $data['type'] ?? 'zns' ) ),
			'status'     => 'draft',
			'meta_json'  => isset( $data['meta_json'] ) ? wp_json_encode( $data['meta_json'] ) : null,
			'batch_size' => max( 1, min( 100, (int) ( $data['batch_size'] ?? 10 ) ) ),
			'delay_sec'  => max( 0, (int) ( $data['delay_sec'] ?? 5 ) ),
			'created_by' => (int) ( $data['created_by'] ?? get_current_user_id() ),
			'created_at' => current_time( 'mysql' ),
		);
		$wpdb->insert( $wpdb->prefix . 'bizcity_cg_broadcasts', $row );
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			self::flush();
		}
		return $id;
	}

	/**
	 * Get paginated list.
	 *
	 * @param  array $args { status, page, per_page, q }
	 * @return array { items[], total }
	 */
	public static function get_list( array $args = array() ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — get list with cache
		$status   = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$q        = sanitize_text_field( (string) ( $args['q'] ?? '' ) );

		$cache_key = 'list_' . md5( $status . '|' . $page . '|' . $per_page . '|' . $q );

		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'bizcity_cg_broadcasts';
		$offset = ( $page - 1 ) * $per_page;

		$where_parts = array( '1=1' );
		$where_vals  = array();

		if ( $status ) {
			$where_parts[] = 'status = %s';
			$where_vals[]  = $status;
		}
		if ( $q ) {
			$where_parts[] = 'name LIKE %s';
			$where_vals[]  = '%' . $wpdb->esc_like( $q ) . '%';
		}

		$where_sql = implode( ' AND ', $where_parts );

		if ( $where_vals ) {
			$count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}", $where_vals );
			$items_sql = $wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				array_merge( $where_vals, array( $per_page, $offset ) )
			);
		} else {
			$count_sql = "SELECT COUNT(*) FROM `{$table}`";
			$items_sql = $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset );
		}

		$total = (int) $wpdb->get_var( $count_sql );
		$items = $wpdb->get_results( $items_sql, ARRAY_A );
		if ( ! is_array( $items ) ) {
			$items = array();
		}
		foreach ( $items as &$item ) {
			$item = self::decode_meta( $item );
		}
		unset( $item );

		$result = array( 'items' => $items, 'total' => $total );

		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result, self::TTL );
		}

		return $result;
	}

	/**
	 * Get single broadcast by ID.
	 *
	 * @param  int $id
	 * @return array|null
	 */
	public static function get_one( $id ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — get single with cache
		$id = (int) $id;
		if ( ! $id ) {
			return null;
		}
		$cache_key = 'one_' . $id;
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}bizcity_cg_broadcasts` WHERE id = %d", $id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row = self::decode_meta( $row );

		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $row, self::TTL );
		}
		return $row;
	}

	/**
	 * Update broadcast row.
	 *
	 * @param  int   $id
	 * @param  array $data
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — update broadcast
		$id = (int) $id;
		if ( ! $id ) {
			return false;
		}

		$allowed = array( 'name', 'status', 'meta_json', 'batch_size', 'delay_sec', 'total_count', 'sent_count', 'failed_count', 'started_at', 'done_at' );
		$row     = array();
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$val = $data[ $key ];
				if ( 'meta_json' === $key && is_array( $val ) ) {
					$val = wp_json_encode( $val );
				}
				$row[ $key ] = $val;
			}
		}
		if ( empty( $row ) ) {
			return false;
		}

		global $wpdb;
		$result = $wpdb->update( $wpdb->prefix . 'bizcity_cg_broadcasts', $row, array( 'id' => $id ) );
		if ( false !== $result ) {
			self::flush();
		}
		return false !== $result;
	}

	/**
	 * Delete broadcast + all its recipients.
	 *
	 * @param  int $id
	 * @return bool
	 */
	public static function delete( $id ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — delete broadcast + recipients
		$id = (int) $id;
		if ( ! $id ) {
			return false;
		}
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'bizcity_cg_broadcast_recipients', array( 'broadcast_id' => $id ) );
		$result = $wpdb->delete( $wpdb->prefix . 'bizcity_cg_broadcasts', array( 'id' => $id ) );
		if ( false !== $result ) {
			self::flush();
		}
		return false !== $result;
	}

	// ── Recipients ───────────────────────────────────────────────────────────

	/**
	 * Bulk-insert recipients for a broadcast.
	 *
	 * @param  int   $broadcast_id
	 * @param  array $rows  [ { name, phone, email, custom_data } ]
	 * @return int   Number of rows inserted.
	 */
	public static function add_recipients( $broadcast_id, array $rows ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — bulk insert recipients
		$broadcast_id = (int) $broadcast_id;
		if ( ! $broadcast_id || empty( $rows ) ) {
			return 0;
		}
		global $wpdb;
		$table   = $wpdb->prefix . 'bizcity_cg_broadcast_recipients';
		$count   = 0;
		$chunks  = array_chunk( $rows, 200 );
		foreach ( $chunks as $chunk ) {
			$values = array();
			$placeholders = array();
			foreach ( $chunk as $r ) {
				$name    = sanitize_text_field( (string) ( $r['name'] ?? '' ) );
				$phone   = sanitize_text_field( (string) ( $r['phone'] ?? '' ) );
				$email   = sanitize_email( (string) ( $r['email'] ?? '' ) );
				$custom  = isset( $r['custom_data'] ) ? wp_json_encode( $r['custom_data'] ) : null;
				$placeholders[] = '(%d, %s, %s, %s, %s, %s)';
				array_push( $values, $broadcast_id, $name, $phone ? $phone : null, $email ? $email : null, $custom, 'queued' );
			}
			$sql = "INSERT INTO `{$table}` (broadcast_id, name, phone, email, custom_data, status) VALUES " . implode( ', ', $placeholders );
			$wpdb->query( $wpdb->prepare( $sql, $values ) );
			$count += count( $chunk );
		}

		// Update total_count
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE broadcast_id = %d", $broadcast_id ) );
		self::update( $broadcast_id, array( 'total_count' => $total ) );

		self::flush();
		return $count;
	}

	/**
	 * Get next batch of queued recipients for a broadcast.
	 *
	 * @param  int $broadcast_id
	 * @param  int $limit
	 * @return array
	 */
	public static function get_next_batch( $broadcast_id, $limit = 10 ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — get next queued batch
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_cg_broadcast_recipients';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE broadcast_id = %d AND status = 'queued' ORDER BY id ASC LIMIT %d",
				$broadcast_id, $limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark a recipient as sent or failed.
	 *
	 * @param int    $recipient_id
	 * @param bool   $success
	 * @param string $error
	 */
	public static function mark_recipient( $recipient_id, $success, $error = '' ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — mark recipient result
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'bizcity_cg_broadcast_recipients',
			array(
				'status'  => $success ? 'sent' : 'failed',
				'error'   => $error ? $error : null,
				'sent_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $recipient_id )
		);
	}

	/**
	 * Get progress counters for a broadcast.
	 *
	 * @param  int $broadcast_id
	 * @return array { total, queued, sent, failed }
	 */
	public static function get_progress( $broadcast_id ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — get progress counters
		$broadcast_id = (int) $broadcast_id;
		$cache_key    = 'progress_' . $broadcast_id;

		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_cg_broadcast_recipients';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS cnt FROM `{$table}` WHERE broadcast_id = %d GROUP BY status",
				$broadcast_id
			),
			ARRAY_A
		);

		$progress = array( 'total' => 0, 'queued' => 0, 'sent' => 0, 'failed' => 0 );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$cnt = (int) $row['cnt'];
				$progress['total'] += $cnt;
				if ( isset( $progress[ $row['status'] ] ) ) {
					$progress[ $row['status'] ] = $cnt;
				}
			}
		}

		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $progress, 60 ); // 1 min cache for progress
		}

		return $progress;
	}

	/**
	 * Sync sent_count + failed_count from recipients table into broadcasts table.
	 *
	 * @param int $broadcast_id
	 */
	public static function sync_counters( $broadcast_id ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — sync counters
		$progress = self::get_progress( $broadcast_id );
		self::update( (int) $broadcast_id, array(
			'sent_count'   => $progress['sent'],
			'failed_count' => $progress['failed'],
			'total_count'  => $progress['total'],
		) );
	}

	/**
	 * Get paginated recipients of one broadcast.
	 *
	 * @param  int   $broadcast_id
	 * @param  array $args { q, status, page, per_page, activity }
	 * @return array { items[], total, page, per_page, counts }
	 */
	public static function get_recipients( $broadcast_id, array $args = array() ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — recipient list with search/pagination/activity filter.
		$broadcast_id = (int) $broadcast_id;
		if ( $broadcast_id <= 0 ) {
			return array( 'items' => array(), 'total' => 0, 'page' => 1, 'per_page' => 50, 'counts' => array() );
		}

		$q         = sanitize_text_field( (string) ( $args['q'] ?? '' ) );
		$status    = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page  = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$activity  = ! empty( $args['activity'] );
		$offset    = ( $page - 1 ) * $per_page;

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_cg_broadcast_recipients';

		$where_parts = array( 'broadcast_id = %d' );
		$where_vals  = array( $broadcast_id );

		if ( $status !== '' ) {
			$where_parts[] = 'status = %s';
			$where_vals[]  = $status;
		}
		if ( $q !== '' ) {
			$like = '%' . $wpdb->esc_like( $q ) . '%';
			$where_parts[] = '(name LIKE %s OR phone LIKE %s OR email LIKE %s OR error LIKE %s)';
			array_push( $where_vals, $like, $like, $like, $like );
		}
		if ( $activity ) {
			$where_parts[] = "(status <> 'queued' OR sent_at IS NOT NULL OR COALESCE(error,'') <> '')";
		}

		$where_sql = implode( ' AND ', $where_parts );

		$count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}", $where_vals );
		$list_sql  = $wpdb->prepare(
			"SELECT id, name, phone, email, status, error, sent_at FROM `{$table}` WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
			array_merge( $where_vals, array( $per_page, $offset ) )
		);

		$total = (int) $wpdb->get_var( $count_sql );
		$items = $wpdb->get_results( $list_sql, ARRAY_A );

		$counts_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS cnt FROM `{$table}` WHERE broadcast_id = %d GROUP BY status",
				$broadcast_id
			),
			ARRAY_A
		);
		$counts = array( 'queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0 );
		foreach ( (array) $counts_raw as $row ) {
			$st = (string) ( $row['status'] ?? '' );
			if ( ! isset( $counts[ $st ] ) ) {
				$counts[ $st ] = 0;
			}
			$counts[ $st ] = (int) ( $row['cnt'] ?? 0 );
		}

		return array(
			'items'    => is_array( $items ) ? $items : array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'counts'   => $counts,
		);
	}

	/**
	 * Get one recipient row by broadcast and recipient id.
	 *
	 * @param  int $broadcast_id
	 * @param  int $recipient_id
	 * @return array|null
	 */
	public static function get_recipient( $broadcast_id, $recipient_id ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — load single recipient for one-click retry.
		$broadcast_id = (int) $broadcast_id;
		$recipient_id = (int) $recipient_id;
		if ( $broadcast_id <= 0 || $recipient_id <= 0 ) {
			return null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_cg_broadcast_recipients';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, broadcast_id, name, phone, email, status, error, sent_at FROM `{$table}` WHERE broadcast_id = %d AND id = %d LIMIT 1",
				$broadcast_id,
				$recipient_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Reset recipient rows to queued by recipient IDs.
	 *
	 * @param  int    $broadcast_id
	 * @param  array  $recipient_ids
	 * @param  bool   $failed_only
	 * @return int    Number of rows updated.
	 */
	public static function queue_recipients_by_ids( $broadcast_id, array $recipient_ids, $failed_only = true ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — bulk retry by selected recipient IDs.
		$broadcast_id = (int) $broadcast_id;
		if ( $broadcast_id <= 0 || empty( $recipient_ids ) ) {
			return 0;
		}

		$recipient_ids = array_values( array_filter( array_map( 'absint', $recipient_ids ) ) );
		if ( empty( $recipient_ids ) ) {
			return 0;
		}

		global $wpdb;
		$table   = $wpdb->prefix . 'bizcity_cg_broadcast_recipients';
		$updated = 0;

		foreach ( array_chunk( $recipient_ids, 500 ) as $chunk ) {
			$in_placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$where_sql       = "broadcast_id = %d AND id IN ({$in_placeholders})";
			$args            = array_merge( array( $broadcast_id ), $chunk );
			if ( $failed_only ) {
				$where_sql .= " AND status = 'failed'";
			}

			$sql = "UPDATE `{$table}` SET status='queued', error=NULL, sent_at=NULL WHERE {$where_sql}";
			$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );
			if ( $result ) {
				$updated += (int) $result;
			}
		}

		if ( $updated > 0 ) {
			self::sync_counters( $broadcast_id );
			self::flush();
		}

		return $updated;
	}

	/**
	 * Reset recipient rows to queued by phone numbers.
	 *
	 * @param  int   $broadcast_id
	 * @param  array $phones
	 * @param  bool  $failed_only
	 * @return int
	 */
	public static function queue_recipients_by_phones( $broadcast_id, array $phones, $failed_only = true ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — retry by phone list (resume/retry by phone).
		$ids = self::find_recipient_ids_by_phones( $broadcast_id, $phones );
		if ( empty( $ids ) ) {
			return 0;
		}
		return self::queue_recipients_by_ids( $broadcast_id, $ids, $failed_only );
	}

	/**
	 * Reset all failed recipients to queued in one broadcast.
	 *
	 * @param  int $broadcast_id
	 * @return int
	 */
	public static function queue_all_failed( $broadcast_id ) {
		// [2026-07-10 Johnny Chu] PHASE-0.47 — quick retry all failed recipients.
		$broadcast_id = (int) $broadcast_id;
		if ( $broadcast_id <= 0 ) {
			return 0;
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'bizcity_cg_broadcast_recipients';
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status='queued', error=NULL, sent_at=NULL WHERE broadcast_id = %d AND status = 'failed'",
				$broadcast_id
			)
		);

		$updated = $result ? (int) $result : 0;
		if ( $updated > 0 ) {
			self::sync_counters( $broadcast_id );
			self::flush();
		}

		return $updated;
	}

	/**
	 * Find recipient IDs from phone numbers (normalized compare).
	 *
	 * @param  int   $broadcast_id
	 * @param  array $phones
	 * @return int[]
	 */
	private static function find_recipient_ids_by_phones( $broadcast_id, array $phones ) {
		$broadcast_id = (int) $broadcast_id;
		if ( $broadcast_id <= 0 || empty( $phones ) ) {
			return array();
		}

		$needle = array();
		foreach ( $phones as $phone ) {
			$norm = self::normalize_phone( (string) $phone );
			if ( $norm !== '' ) {
				$needle[ $norm ] = true;
			}
		}
		if ( empty( $needle ) ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_cg_broadcast_recipients';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, phone FROM `{$table}` WHERE broadcast_id = %d AND COALESCE(phone,'') <> ''",
				$broadcast_id
			),
			ARRAY_A
		);

		$ids = array();
		foreach ( (array) $rows as $row ) {
			$norm_phone = self::normalize_phone( (string) ( $row['phone'] ?? '' ) );
			if ( $norm_phone !== '' && isset( $needle[ $norm_phone ] ) ) {
				$ids[] = (int) $row['id'];
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Normalize VN phone for robust matching in retry-by-phone flow.
	 *
	 * @param  string $phone
	 * @return string
	 */
	private static function normalize_phone( $phone ) {
		$phone = preg_replace( '/[^0-9+]/', '', (string) $phone );
		if ( $phone === '' ) {
			return '';
		}
		if ( strpos( $phone, '+84' ) === 0 ) {
			$phone = '0' . substr( $phone, 3 );
		}
		if ( strpos( $phone, '84' ) === 0 && strlen( $phone ) >= 10 ) {
			$phone = '0' . substr( $phone, 2 );
		}
		if ( preg_match( '/^[3-9][0-9]{8}$/', $phone ) ) {
			$phone = '0' . $phone;
		}
		return $phone;
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Flush all bzcast cache.
	 */
	private static function flush() {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
	}

	/**
	 * Decode meta_json field in a broadcast row.
	 *
	 * @param  array $row
	 * @return array
	 */
	private static function decode_meta( array $row ) {
		if ( isset( $row['meta_json'] ) && $row['meta_json'] ) {
			$meta = json_decode( $row['meta_json'], true );
			$row['meta'] = is_array( $meta ) ? $meta : array();
		} else {
			$row['meta'] = array();
		}
		return $row;
	}
}

// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — Cache Registry.
if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'bzcast', 'core.cg.broadcast', array(
		'list_{args_hash}'     => array( 'ttl' => 300, 'desc' => 'Paginated broadcast list' ),
		'one_{id}'             => array( 'ttl' => 300, 'desc' => 'Single broadcast row'     ),
		'progress_{id}'        => array( 'ttl' =>  60, 'desc' => 'Broadcast progress counters' ),
	) );
}
