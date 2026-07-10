<?php
/**
 * PHASE 0.37.2 — Lead Capture Engine.
 *
 * Đầu vào (đã chuẩn hoá) từ adapter:
 *   [ 'email' => ..., 'phone' => ..., 'first_name' => ..., 'last_name' => ...,
 *     'full_name' => ..., 'company' => ..., 'message' => ..., 'meta' => [] ]
 *
 * Quy trình:
 *   1) Validate (email hoặc phone phải có).
 *   2) Dedup theo email rồi phone (trong N ngày, mặc định 30; chưa converted).
 *   3) Insert mới hoặc append capture event vào custom_json của lead cũ.
 *   4) Áp classifier → set status / rating / tags.
 *   5) Fire action `bizcity_crm_lead_created` (cho email automation).
 *
 * Trả về: lead row array hoặc WP_Error.
 *
 * @package BizCity\CRM\LeadCapture
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BizCity_CRM_Lead_Capture_Engine {

	const DEDUP_WINDOW_DAYS = 30;

	/**
	 * @param array  $data   Normalized lead payload.
	 * @param string $source Source code (e.g. 'cf7:contact-form-1', 'comment', 'webchat', 'manual').
	 * @return array|WP_Error
	 */
	public static function capture( array $data, string $source ) {
		global $wpdb;

		$email = isset( $data['email'] ) ? sanitize_email( trim( (string) $data['email'] ) ) : '';
		$phone = isset( $data['phone'] ) ? self::norm_phone( (string) $data['phone'] ) : '';

		if ( ! $email && ! $phone ) {
			return new WP_Error( 'lead_capture_no_identity', 'Lead phải có ít nhất email hoặc phone.' );
		}
		if ( $email && ! is_email( $email ) ) {
			return new WP_Error( 'lead_capture_bad_email', 'Email không hợp lệ: ' . $email );
		}

		list( $first, $last ) = self::split_name( $data );
		$company = isset( $data['company'] ) ? trim( (string) $data['company'] ) : '';
		$message = isset( $data['message'] ) ? trim( (string) $data['message'] ) : '';
		$meta    = is_array( $data['meta'] ?? null ) ? $data['meta'] : array();
		$now     = current_time( 'mysql' );

		$existing = self::find_existing( $email, $phone );
		$tbl      = BizCity_CRM_DB_Installer_V2::tbl_crm_leads();

		if ( $existing ) {
			// Append capture event into custom_json, refresh latest message + source if helpful.
			$cj = self::decode_custom( $existing['custom_json'] );
			$cj['capture_events'] = isset( $cj['capture_events'] ) && is_array( $cj['capture_events'] ) ? $cj['capture_events'] : array();
			$cj['capture_events'][] = array(
				'source'  => $source,
				'at'      => $now,
				'message' => self::truncate( $message, 500 ),
				'meta'    => $meta,
			);
			$cj['last_message'] = $message ?: ( $cj['last_message'] ?? '' );

			$update = array(
				'updated_at'  => $now,
				'custom_json' => wp_json_encode( $cj ),
			);
			// Fill blanks only.
			if ( empty( $existing['email'] )      && $email )   { $update['email']      = $email; }
			if ( empty( $existing['phone'] )      && $phone )   { $update['phone']      = $phone; }
			if ( empty( $existing['first_name'] ) && $first )   { $update['first_name'] = $first; }
			if ( empty( $existing['last_name'] )  && $last )    { $update['last_name']  = $last; }
			if ( empty( $existing['company'] )    && $company ) { $update['company']    = $company; }
			if ( empty( $existing['source'] )     && $source )  { $update['source']     = $source; }

			// Re-classify (may upgrade status/rating).
			$row_for_class = array_merge( $existing, $update, array( 'message' => $message ) );
			$cls           = BizCity_CRM_Lead_Classifier::apply( $row_for_class, $source );
			if ( $cls['status'] && self::is_status_upgrade( (string) $existing['status'], $cls['status'] ) ) {
				$update['status'] = $cls['status'];
			}
			if ( $cls['rating'] && empty( $existing['rating'] ) ) {
				$update['rating'] = $cls['rating'];
			}

			$wpdb->update( $tbl, $update, array( 'id' => (int) $existing['id'] ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", (int) $existing['id'] ), ARRAY_A );

			do_action( 'bizcity_crm_lead_recaptured', (int) $row['id'], $row, $source );
			return $row;
		}

		// Brand new lead.
		$payload = array(
			'first_name' => $first,
			'last_name'  => $last,
			'email'      => $email ?: null,
			'phone'      => $phone ?: null,
			'company'    => $company ?: null,
			'source'     => $source,
			'status'     => 'new',
			'notes'      => $message ?: null,
			'custom_json'=> wp_json_encode( array(
				'capture_events' => array( array(
					'source'  => $source,
					'at'      => $now,
					'message' => self::truncate( $message, 500 ),
					'meta'    => $meta,
				) ),
				'last_message' => $message,
			) ),
			'created_at' => $now,
			'updated_at' => $now,
		);

		$cls = BizCity_CRM_Lead_Classifier::apply( array_merge( $payload, array( 'message' => $message ) ), $source );
		if ( $cls['status'] ) { $payload['status'] = $cls['status']; }
		if ( $cls['rating'] ) { $payload['rating'] = $cls['rating']; }

		$ok = $wpdb->insert( $tbl, $payload );
		if ( ! $ok ) {
			return new WP_Error( 'lead_capture_db_error', $wpdb->last_error ?: 'insert failed' );
		}
		$id  = (int) $wpdb->insert_id;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );

		// Build normalized "row" the email registry expects ({name, email, phone, source}).
		$registry_row = array(
			'id'     => $id,
			'name'   => trim( $first . ' ' . $last ) ?: ( $email ?: $phone ),
			'email'  => $email,
			'phone'  => $phone,
			'source' => $source,
		);
		do_action( 'bizcity_crm_lead_created', $id, $registry_row );

		return $row;
	}

	private static function find_existing( string $email, string $phone ) {
		global $wpdb;
		$tbl   = BizCity_CRM_DB_Installer_V2::tbl_crm_leads();
		$since = gmdate( 'Y-m-d H:i:s', time() - self::DEDUP_WINDOW_DAYS * DAY_IN_SECONDS );

		if ( $email ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM `{$tbl}` WHERE email=%s AND deleted_at IS NULL AND status<>'converted' AND created_at>=%s ORDER BY id DESC LIMIT 1",
				$email, $since
			), ARRAY_A );
			if ( $row ) { return $row; }
		}
		if ( $phone ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM `{$tbl}` WHERE phone=%s AND deleted_at IS NULL AND status<>'converted' AND created_at>=%s ORDER BY id DESC LIMIT 1",
				$phone, $since
			), ARRAY_A );
			if ( $row ) { return $row; }
		}
		return null;
	}

	private static function split_name( array $data ): array {
		$first = isset( $data['first_name'] ) ? trim( (string) $data['first_name'] ) : '';
		$last  = isset( $data['last_name'] )  ? trim( (string) $data['last_name'] )  : '';
		if ( ( $first || $last ) ) { return array( $first, $last ); }

		$full = isset( $data['full_name'] ) ? trim( (string) $data['full_name'] ) : '';
		if ( ! $full ) { return array( '', '' ); }
		$parts = preg_split( '/\s+/', $full );
		if ( count( $parts ) === 1 ) { return array( $parts[0], '' ); }
		$last  = array_pop( $parts );
		$first = implode( ' ', $parts );
		return array( $first, $last );
	}

	private static function norm_phone( string $p ): string {
		$p = preg_replace( '/[^\d+]/', '', $p );
		return (string) $p;
	}

	private static function decode_custom( $json ): array {
		if ( ! $json ) { return array(); }
		$d = json_decode( (string) $json, true );
		return is_array( $d ) ? $d : array();
	}

	private static function truncate( string $s, int $n ): string {
		return ( strlen( $s ) > $n ) ? substr( $s, 0, $n - 1 ) . '…' : $s;
	}

	/** Status ladder: new < contacted < qualified < unqualified|converted. */
	private static function is_status_upgrade( string $cur, string $next ): bool {
		$rank = array( 'new' => 0, 'contacted' => 1, 'qualified' => 2, 'unqualified' => 3, 'converted' => 9 );
		return ( $rank[ $next ] ?? 0 ) > ( $rank[ $cur ] ?? 0 );
	}
}
