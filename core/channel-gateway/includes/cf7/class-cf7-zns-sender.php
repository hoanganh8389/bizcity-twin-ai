<?php
/**
 * CF7 ZNS Sender — Gửi Zalo ZNS qua eSMS API.
 *
 * eSMS endpoint: POST https://rest.esms.vn/MainService.svc/json/SendZaloMessage_V6/
 *
 * Flow:
 *   1. BizCity_CF7_Channel_Listener::on_submit() gọi self::dispatch() sau CRM sync.
 *   2. Load per-form config + global credentials.
 *   3. Build TempData từ temp_vars config + mapped CF7 data.
 *   4. File-log TRƯỚC HTTP call (R-CH-FILE-LOG).
 *   5. POST to eSMS API, parse CodeResult.
 *   6. File-log kết quả (ok / failed / exception).
 *
 * Security:
 *   - ApiKey/SecretKey KHÔNG xuất hiện trong log.
 *   - TempData được mask PII trước khi log.
 *   - Mọi exception được catch — KHÔNG block form submission.
 *
 * [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — new class.
 *
 * @package BizCity_Channel_Gateway
 * @since   PHASE-CG-CF7-ZNS (2026-06-25)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CF7_ZNS_Sender' ) ) {
	return;
}

class BizCity_CF7_ZNS_Sender {

	const ESMS_ENDPOINT = 'https://rest.esms.vn/MainService.svc/json/SendZaloMessage_V6/';
	const TIMEOUT       = 15;

	/**
	 * Orchestrate ZNS dispatch for a CF7 submission.
	 * Called by BizCity_CF7_Channel_Listener::on_submit() after CRM sync.
	 *
	 * Returns result array for optional logging by caller.
	 *
	 * @param  int    $form_id
	 * @param  string $phone     Raw phone from CF7 (already extracted + validated).
	 * @param  array  $mapped    CRM-mapped data from CF7 posted fields.
	 * @return array  { sent: bool, code: string, sms_id: string, error: string }
	 */
	public static function dispatch( $form_id, $phone, array $mapped ) {
		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — orchestrate

		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS DEBUG — verbose error_log for diagnosing send failures
		error_log( '[bizcity-zns] dispatch() START form_id=' . $form_id . ' phone=' . self::mask_phone( (string) $phone ) . ' mapped_keys=' . implode( ',', array_keys( $mapped ) ) );

		$result = array( 'sent' => false, 'code' => '', 'sms_id' => '', 'error' => '' );

		try {
			// ── Load config ───────────────────────────────────────────────
			$cfg = BizCity_CF7_ZNS_Config::get_form_config( $form_id );

			error_log( '[bizcity-zns] config for form_id=' . $form_id . ': enabled=' . ( ! empty( $cfg['enabled'] ) ? '1' : '0' ) . ' temp_id=' . ( $cfg['temp_id'] ?? '' ) . ' oa_id=' . ( $cfg['oa_id'] ?? '' ) . ' sandbox=' . ( ! empty( $cfg['sandbox'] ) ? '1' : '0' ) . ' temp_vars_count=' . count( $cfg['temp_vars'] ?? array() ) );

			if ( empty( $cfg['enabled'] ) ) {
				self::file_log( 'zns_dispatch_skip', 'WARN', 'ZNS not enabled for form #' . $form_id, $form_id, array() );
				return $result;
			}

			if ( empty( $cfg['temp_id'] ) ) {
				self::file_log( 'zns_dispatch_skip', 'WARN', 'No TempID configured for form #' . $form_id, $form_id, array() );
				return $result;
			}

			$phone = self::normalize_phone( $phone );
			if ( empty( $phone ) ) {
				self::file_log( 'zns_dispatch_skip', 'WARN', 'Invalid phone for form #' . $form_id, $form_id, array() );
				return $result;
			}

			// ── Global credentials ─────────────────────────────────────────
			$global = BizCity_CF7_ZNS_Config::get_global_settings();
			if ( empty( $global['api_key'] ) || empty( $global['secret_key'] ) ) {
				self::file_log( 'zns_dispatch_skip', 'WARN', 'eSMS credentials not configured', $form_id, array() );
				return $result;
			}

			// ── Effective OA ID (per-form overrides global) ────────────────
			$oa_id = ! empty( $cfg['oa_id'] ) ? $cfg['oa_id'] : $global['oa_id'];
			if ( empty( $oa_id ) ) {
				self::file_log( 'zns_dispatch_skip', 'WARN', 'No OA ID configured', $form_id, array() );
				return $result;
			}

			// ── Build TempData ─────────────────────────────────────────────
			$temp_data = self::build_temp_data( $cfg['temp_vars'], $mapped );
			// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — empty variable fallback to phone number
			foreach ( $temp_data as $_k => $_v ) {
				if ( $_v === '' || $_v === null ) { $temp_data[ $_k ] = $phone; }
			}
			// ── Build request ──────────────────────────────────────────────
			$request_id = substr( 'cf7_' . $form_id . '_' . time(), 0, 50 );
			$args       = array(
				'ApiKey'      => $global['api_key'],
				'SecretKey'   => $global['secret_key'],
				'OAID'        => $oa_id,
				'Phone'       => $phone,
				'TempData'    => $temp_data,
				'TempID'      => (string) $cfg['temp_id'],
				'SendingMode' => '1',
				'campaignid'  => $cfg['campaign_id'] ?: ( 'CF7 Form ' . $form_id ),
				'RequestId'   => $request_id,
			);
			if ( ! empty( $cfg['sandbox'] ) ) {
				$args['Sandbox'] = '1';
			}

			// ── File-log TRƯỚC HTTP (R-CH-FILE-LOG) ───────────────────────
			self::file_log( 'zns_send_attempt', 'INFO', 'Sending ZNS to ' . self::mask_phone( $phone ), $form_id, array(
				'temp_id'    => $cfg['temp_id'],
				'oa_id'      => $oa_id,
				'phone'      => self::mask_phone( $phone ),
				'temp_data'  => self::mask_temp_data( $temp_data ),
				'sandbox'    => ! empty( $cfg['sandbox'] ),
				'request_id' => $request_id,
			) );

			// ── Send ───────────────────────────────────────────────────────
			$result = self::send( $args );

			// ── Log result ─────────────────────────────────────────────────
			if ( $result['sent'] ) {
				error_log( '[bizcity-zns] SENT OK form_id=' . $form_id . ' sms_id=' . $result['sms_id'] . ' code=' . $result['code'] );
				self::file_log( 'zns_send_ok', 'INFO', 'ZNS sent OK — SMSID: ' . $result['sms_id'], $form_id, array(
					'phone'      => self::mask_phone( $phone ),
					'sms_id'     => $result['sms_id'],
					'code'       => $result['code'],
					'temp_data'  => self::mask_temp_data( $temp_data ),
					'sandbox'    => ! empty( $cfg['sandbox'] ),
				) );
			} else {
				error_log( '[bizcity-zns] FAILED form_id=' . $form_id . ' code=' . $result['code'] . ' error=' . $result['error'] );
				self::file_log( 'zns_send_failed', 'ERROR', 'ZNS send failed — code: ' . $result['code'] . ' — ' . $result['error'], $form_id, array(
					'phone'      => self::mask_phone( $phone ),
					'code'       => $result['code'],
					'error'      => $result['error'],
					'temp_data'  => self::mask_temp_data( $temp_data ),
					'sandbox'    => ! empty( $cfg['sandbox'] ),
					'temp_vars'  => $cfg['temp_vars'],
					'mapped_keys'=> array_keys( $mapped ),
				) );
			}
		} catch ( \Exception $e ) {
			$result['error'] = $e->getMessage();
			error_log( '[bizcity-zns] EXCEPTION form_id=' . $form_id . ' class=' . get_class( $e ) . ' msg=' . $e->getMessage() );
			self::file_log( 'zns_send_exception', 'ERROR', 'Exception: ' . $e->getMessage(), $form_id, array(
				'exception_class' => get_class( $e ),
				'exception_trace' => substr( $e->getTraceAsString(), 0, 500 ),
			) );
		}

		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — write ZNS send log to CRM if class available
		if ( class_exists( 'BizCity_CRM_ZNS_Send_Log', false ) ) {
			$cfg        = isset( $cfg ) ? $cfg : array();
			$phone_disp = self::mask_phone( $phone );
			BizCity_CRM_ZNS_Send_Log::write( array(
				'form_id'       => $form_id,
				'form_title'    => isset( $cfg['form_title'] ) ? $cfg['form_title'] : ( 'Form #' . $form_id ),
				'phone'         => $phone_disp,
				'temp_id'       => isset( $cfg['temp_id'] ) ? $cfg['temp_id'] : '',
				'oa_id'         => isset( $oa_id ) ? $oa_id : '',
				'status'        => $result['sent'] ? 'sent' : 'failed',
				'esms_code'     => $result['code'],
				'sms_id'        => $result['sms_id'],
				'error_message' => $result['error'] ?: null,
				'temp_data'     => isset( $temp_data ) ? $temp_data : array(),
				'is_test'       => ( isset( $cfg['sandbox'] ) && $cfg['sandbox'] ) ? 1 : 0,
			) );
		}

		return $result;
	}

	/**
	 * Perform the actual HTTP POST to eSMS API.
	 *
	 * @param  array $args  Full request body (includes ApiKey, SecretKey).
	 * @return array { sent: bool, code: string, sms_id: string, error: string }
	 */
	public static function send( array $args ) {
		$result = array( 'sent' => false, 'code' => '', 'sms_id' => '', 'error' => '' );

		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS FIX v2 — eSMS ZNS V6 expects TempData as a
		// nested JSON OBJECT (not a JSON-encoded string). Keep array as-is; wp_json_encode will
		// serialize it correctly as {"key":"value"} inside the outer JSON body.
		// (Reverting v1 string-encode which caused error 791 "TempData is empty".)

		$response = wp_remote_post( self::ESMS_ENDPOINT, array(
			'timeout'     => self::TIMEOUT,
			'headers'     => array( 'Content-Type' => 'application/json; charset=UTF-8' ),
			'body'        => wp_json_encode( $args ),
			'data_format' => 'body',
		) );

		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			error_log( '[bizcity-zns] wp_remote_post WP_Error: ' . $result['error'] );
			return $result;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$json      = json_decode( $body, true );

		error_log( '[bizcity-zns] eSMS HTTP ' . $http_code . ' raw_body=' . substr( (string) $body, 0, 300 ) );

		if ( ! is_array( $json ) ) {
			$result['error'] = 'Invalid JSON response (HTTP ' . $http_code . ')';
			return $result;
		}

		$code = (string) ( $json['CodeResult'] ?? '' );
		$result['code'] = $code;

		if ( $code === '100' ) {
			$result['sent']   = true;
			$result['sms_id'] = (string) ( $json['SMSID'] ?? '' );
		} else {
			$result['error'] = (string) ( $json['ErrorMessage'] ?? ( 'eSMS code ' . $code ) );
		}

		return $result;
	}

	/**
	 * Build TempData array from temp_vars config + CRM mapped data.
	 *
	 * @param  array $temp_vars  Config from BizCity_CF7_ZNS_Config.
	 * @param  array $mapped     CRM-mapped fields from CF7 submission.
	 * @return array
	 */
	public static function build_temp_data( array $temp_vars, array $mapped ) {
		$temp_data = array();
		foreach ( $temp_vars as $tv ) {
			$var_name = (string) ( $tv['var_name'] ?? '' );
			if ( empty( $var_name ) ) {
				continue;
			}
			if ( $tv['source'] === 'literal' ) {
				$temp_data[ $var_name ] = (string) ( $tv['value'] ?? '' );
			} else {
				// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — accept mapped_field as alias for field
				// $mapped is now the raw $posted CF7 data — field name = CF7 input name e.g. 'parent-name'
				$field     = (string) ( $tv['mapped_field'] ?? $tv['field'] ?? '' );
				$raw_val   = $mapped[ $field ] ?? '';
				// CF7 checkboxes/multiselect return arrays — join to string for ZNS
				if ( is_array( $raw_val ) ) {
					$raw_val = implode( ', ', $raw_val );
				}
				$temp_data[ $var_name ] = sanitize_text_field( (string) $raw_val );
			}
		}
		return $temp_data;
	}

	/**
	 * Normalize Vietnamese phone number.
	 * Strips non-numeric chars except leading +, trims to 20 chars.
	 *
	 * @param  string $phone
	 * @return string
	 */
	public static function normalize_phone( $phone ) {
		$clean = preg_replace( '/[^0-9+]/', '', (string) $phone );
		if ( empty( $clean ) ) {
			return '';
		}
		// Remove leading +84 → 0
		if ( strpos( $clean, '+84' ) === 0 ) {
			$clean = '0' . substr( $clean, 3 );
		} elseif ( strpos( $clean, '84' ) === 0 && strlen( $clean ) >= 11 ) {
			$clean = '0' . substr( $clean, 2 );
		}
		return substr( $clean, 0, 20 );
	}

	// ── PII helpers ───────────────────────────────────────────────────────────

	/**
	 * Mask phone for logs: "0901234567" → "090***"
	 */
	public static function mask_phone( $phone ) {
		$phone = (string) $phone;
		if ( strlen( $phone ) <= 3 ) {
			return '***';
		}
		return substr( $phone, 0, 3 ) . '***';
	}

	/**
	 * Mask TempData for logs — shorten/mask any name-like values.
	 *
	 * @param  array $temp_data
	 * @return array
	 */
	private static function mask_temp_data( array $temp_data ) {
		$masked = array();
		foreach ( $temp_data as $k => $v ) {
			$v = (string) $v;
			// Mask if looks like a name or phone (long string, not URL)
			if ( strlen( $v ) > 4 && strpos( $v, 'http' ) === false ) {
				$masked[ $k ] = substr( $v, 0, 3 ) . '***';
			} else {
				$masked[ $k ] = $v;
			}
		}
		return $masked;
	}

	// ── File log wrapper ──────────────────────────────────────────────────────

	/**
	 * Write to cf7 channel file log (R-CH-FILE-LOG).
	 *
	 * @param  string $event    Event slug.
	 * @param  string $level    INFO | WARN | ERROR
	 * @param  string $message  Human-readable message.
	 * @param  int    $form_id
	 * @param  array  $ctx      Extra context (NO credentials, NO PII raw).
	 */
	private static function file_log( $event, $level, $message, $form_id, array $ctx ) {
		if ( ! class_exists( 'BizCity_Channel_File_Logger', false ) ) {
			return;
		}
		$level_const = 'INFO' === $level ? BizCity_Channel_File_Logger::LEVEL_INFO
			: ( 'WARN' === $level ? BizCity_Channel_File_Logger::LEVEL_WARN : BizCity_Channel_File_Logger::LEVEL_ERROR );

		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — use CH_ZALO_ZNS (zalo_zns/ folder, multisite-aware)
		// instead of CH_CF7 so ZNS events have their own log stream like facebook/zalo_oa/zalo_bot.
		// Use class_exists() guard — defined() cannot check class constants (always false).
		$channel = class_exists( 'BizCity_Channel_File_Logger', false )
			? BizCity_Channel_File_Logger::CH_ZALO_ZNS
			: 'zalo_zns';

		BizCity_Channel_File_Logger::write(
			$channel,
			$level_const,
			$event,
			$message,
			array_merge( array( 'form_id' => (int) $form_id, 'channel' => 'zalo_zns' ), $ctx )
		);
	}
}
