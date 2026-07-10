<?php
/**
 * ZNS General Sender — Gửi Zalo ZNS theo event-driven rules (general purpose).
 *
 * Tách biệt với BizCity_CF7_ZNS_Sender (CF7-specific).
 * Reuse normalize_phone(), mask_phone() pattern, ESMS_ENDPOINT const.
 *
 * Security:
 *   - ApiKey/SecretKey KHÔNG xuất hiện trong log.
 *   - Phone được mask trong logs + DB.
 *   - Dedup: max 1 ZNS per phone per rule per 5 phút (transient).
 *   - ZNS fail KHÔNG block caller (mọi exception bị catch).
 *
 * [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — new class.
 *
 * @package BizCity_Channel_Gateway
 * @since   PHASE-CG-ZNS-AUTO (2026-06-27)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_ZNS_General_Sender' ) ) {
	return;
}

class BizCity_ZNS_General_Sender {

	const ESMS_ENDPOINT = 'https://rest.esms.vn/MainService.svc/json/SendZaloMessage_V6/';
	const TIMEOUT       = 15;
	const DEDUP_TTL     = 300; // 5 phút

	/**
	 * Dispatch ZNS gửi theo rule + context.
	 *
	 * @param  array $args {
	 *   rule_id, event_key, phone, temp_id, oa_id, temp_data,
	 *   sandbox, campaign_id, api_key, secret_key
	 * }
	 * @return array { success: bool, code_result: string, sms_id: string, error: string }
	 */
	public static function dispatch( array $args ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — dispatch with file-log BEFORE http
		$result = array( 'success' => false, 'code_result' => '', 'sms_id' => '', 'error' => '' );

		// Guard credentials
		if ( empty( $args['api_key'] ) || empty( $args['secret_key'] ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_OA,
				BizCity_Channel_File_Logger::LEVEL_WARN,
				'zns_skip_no_creds',
				'Missing eSMS credentials for rule #' . ( $args['rule_id'] ?? 0 ),
				array( 'rule_id' => (int) ( $args['rule_id'] ?? 0 ), 'event_key' => (string) ( $args['event_key'] ?? '' ) )
			);
			$result['error'] = 'missing_credentials';
			return $result;
		}

		// Guard temp_id
		if ( empty( $args['temp_id'] ) || ! preg_match( '/^[0-9A-Za-z_\-]{1,64}$/', $args['temp_id'] ) ) {
			$result['error'] = 'invalid_temp_id';
			return $result;
		}

		// Normalize phone
		$phone = self::normalize_phone( (string) ( $args['phone'] ?? '' ) );
		if ( empty( $phone ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_OA,
				BizCity_Channel_File_Logger::LEVEL_WARN,
				'zns_skip_invalid_phone',
				'Invalid phone for rule #' . ( $args['rule_id'] ?? 0 ),
				array( 'rule_id' => (int) ( $args['rule_id'] ?? 0 ) )
			);
			$result['error'] = 'invalid_phone';
			return $result;
		}

		// Dedup check — max 1 ZNS per phone per rule per 5 min
		$rule_id    = (int) ( $args['rule_id'] ?? 0 );
		$dedup_key  = 'bizcity_zns_dedup_' . md5( $rule_id . '_' . $phone );
		if ( get_transient( $dedup_key ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_OA,
				BizCity_Channel_File_Logger::LEVEL_WARN,
				'zns_skip_dedup',
				'Dedup: phone+rule combo fired within 5 min — skipped',
				array( 'rule_id' => $rule_id, 'phone' => self::mask_phone( $phone ) )
			);
			$result['error'] = 'dedup_skip';
			return $result;
		}

		// File-log TRƯỚC HTTP (R-CH-FILE-LOG)
		BizCity_Channel_File_Logger::write(
			BizCity_Channel_File_Logger::CH_ZALO_OA,
			BizCity_Channel_File_Logger::LEVEL_INFO,
			'zns_send_attempt',
			'Sending ZNS to ' . self::mask_phone( $phone ),
			array(
				'rule_id'    => $rule_id,
				'event_key'  => (string) ( $args['event_key'] ?? '' ),
				'phone'      => self::mask_phone( $phone ),
				'temp_id'    => $args['temp_id'],
				'oa_id'      => (string) ( $args['oa_id'] ?? '' ),
				'sandbox'    => ! empty( $args['sandbox'] ),
				'var_names'  => array_keys( (array) ( $args['temp_data'] ?? array() ) ),
			)
		);

		// HTTP POST to eSMS
		$http_args = array(
			'ApiKey'      => $args['api_key'],
			'SecretKey'   => $args['secret_key'],
			'OAID'        => (string) ( $args['oa_id'] ?? '' ),
			'Phone'       => $phone,
			'TempData'    => (array) ( $args['temp_data'] ?? array() ),
			'TempID'      => $args['temp_id'],
			'SendingMode' => '1',
			'campaignid'  => (string) ( $args['campaign_id'] ?? ( 'zns_rule_' . $rule_id ) ),
			'RequestId'   => substr( 'zns_r' . $rule_id . '_' . time(), 0, 50 ),
		);
		if ( ! empty( $args['sandbox'] ) ) {
			$http_args['Sandbox'] = '1';
		}

		$raw = self::http_post( $http_args );

		$code    = (string) ( $raw['CodeResult'] ?? '' );
		$sms_id  = (string) ( $raw['SMSID'] ?? '' );
		$err_msg = (string) ( $raw['ErrorMessage'] ?? '' );
		$success = ( '100' === $code );

		$result['code_result'] = $code;
		$result['sms_id']      = $sms_id;

		if ( $success ) {
			// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — dedup transient after success
			set_transient( $dedup_key, 1, self::DEDUP_TTL );
			$result['success'] = true;
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_OA,
				BizCity_Channel_File_Logger::LEVEL_INFO,
				'zns_send_ok',
				'ZNS sent OK — SMSID: ' . $sms_id,
				array(
					'rule_id'  => $rule_id,
					'sms_id'   => $sms_id,
					'phone'    => self::mask_phone( $phone ),
					'sandbox'  => ! empty( $args['sandbox'] ),
				)
			);
		} else {
			$result['error'] = $err_msg ?: ( 'esms_code_' . $code );
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_OA,
				BizCity_Channel_File_Logger::LEVEL_ERROR,
				'zns_send_failed',
				'ZNS failed — code: ' . $code . ' — ' . $err_msg,
				array(
					'rule_id'   => $rule_id,
					'phone'     => self::mask_phone( $phone ),
					'code'      => $code,
					'error'     => $err_msg,
					'sandbox'   => ! empty( $args['sandbox'] ),
				)
			);
		}

		// Ghi send log vào DB
		if ( class_exists( 'BizCity_ZNS_Send_Tracker', false ) ) {
			BizCity_ZNS_Send_Tracker::record( array(
				'rule_id'           => $rule_id,
				'event_key'         => (string) ( $args['event_key'] ?? '' ),
				'phone'             => self::mask_phone( $phone ),
				'temp_id'           => $args['temp_id'],
				'oa_id'             => (string) ( $args['oa_id'] ?? '' ),
				'esms_code'         => $code,
				'sms_id'            => $sms_id,
				'error_msg'         => $result['error'],
				'success'           => $success,
				'sandbox'           => ! empty( $args['sandbox'] ),
				'source_object_id'  => (int) ( $args['source_object_id'] ?? 0 ),
				'source_object_type'=> (string) ( $args['source_object_type'] ?? '' ),
			) );
		}

		// Cập nhật rule stats
		if ( $rule_id && class_exists( 'BizCity_ZNS_Rules_Repo', false ) ) {
			BizCity_ZNS_Rules_Repo::update_fire_stats( $rule_id, $success, $result['error'] );
		}

		return $result;
	}

	/**
	 * Build TempData array từ temp_vars config + placeholders context.
	 *
	 * @param  array $temp_vars    Array of { var_name, source, field|value }.
	 * @param  array $placeholders Resolved key=>value placeholders.
	 * @param  int   $user_id      Optional: for user_meta source.
	 * @return array  TempData { var_name => value }
	 */
	public static function build_temp_data( array $temp_vars, array $placeholders, $user_id = 0 ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — build TempData from temp_vars
		$data = array();
		foreach ( $temp_vars as $v ) {
			$var_name = (string) ( $v['var_name'] ?? '' );
			if ( empty( $var_name ) ) {
				continue;
			}
			$source = (string) ( $v['source'] ?? 'placeholder' );
			if ( 'literal' === $source ) {
				$data[ $var_name ] = (string) ( $v['value'] ?? '' );
			} elseif ( 'user_meta' === $source && $user_id ) {
				$data[ $var_name ] = (string) get_user_meta( (int) $user_id, (string) ( $v['field'] ?? '' ), true );
			} else {
				// placeholder or mapped
				$field             = (string) ( $v['field'] ?? $v['mapped_field'] ?? '' );
				$data[ $var_name ] = (string) ( $placeholders[ $field ] ?? '' );
			}
		}
		return $data;
	}

	/**
	 * Normalize Vietnamese phone number to 10-11 digits starting with 0.
	 *
	 * @param  string $phone
	 * @return string
	 */
	public static function normalize_phone( $phone ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — phone normalize (reuse CF7 pattern)
		$clean = preg_replace( '/[^0-9+]/', '', (string) $phone );
		if ( empty( $clean ) ) {
			return '';
		}
		if ( strpos( $clean, '+84' ) === 0 ) {
			$clean = '0' . substr( $clean, 3 );
		} elseif ( strpos( $clean, '84' ) === 0 && strlen( $clean ) >= 11 ) {
			$clean = '0' . substr( $clean, 2 );
		}
		$clean = substr( $clean, 0, 11 );
		// Must start with 0 and be 10-11 digits
		if ( ! preg_match( '/^0[0-9]{9,10}$/', $clean ) ) {
			return '';
		}
		return $clean;
	}

	/**
	 * Mask phone for logs: "0901234567" → "090***"
	 *
	 * @param  string $phone
	 * @return string
	 */
	public static function mask_phone( $phone ) {
		$phone = (string) $phone;
		if ( strlen( $phone ) <= 3 ) {
			return '***';
		}
		return substr( $phone, 0, 3 ) . '***';
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Thực hiện HTTP POST lên eSMS API.
	 *
	 * @param  array $args  Full request body (includes ApiKey, SecretKey).
	 * @return array  Raw JSON decoded response.
	 */
	private static function http_post( array $args ) {
		$response = wp_remote_post( self::ESMS_ENDPOINT, array(
			'timeout'     => self::TIMEOUT,
			'headers'     => array( 'Content-Type' => 'application/json; charset=UTF-8' ),
			'body'        => wp_json_encode( $args ),
			'data_format' => 'body',
		) );

		if ( is_wp_error( $response ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_OA,
				BizCity_Channel_File_Logger::LEVEL_ERROR,
				'zns_http_error',
				'wp_remote_post error: ' . $response->get_error_message(),
				array( 'error' => $response->get_error_message() )
			);
			return array( 'CodeResult' => '', 'ErrorMessage' => $response->get_error_message() );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$json      = json_decode( $body, true );

		if ( ! is_array( $json ) ) {
			return array( 'CodeResult' => '', 'ErrorMessage' => 'Invalid JSON (HTTP ' . $http_code . ')' );
		}
		return $json;
	}
}
