<?php
/**
 * BizCity CRM — pipeline notifications (PHASE-0.63A WP-7). **LANE B OWNS THIS FILE.**
 *
 * Rules already settled, so lane B does not have to re-decide them:
 *  - Zalo Bot only, and only when the recipient has bound it. No binding means no send and NO fallback
 *    channel (R-LM-8) — the escalation shows up on the team board instead, and the unbound people are
 *    named in the UI (D63-9).
 *  - Off by default behind `bizcity_crm_pipeline_sla_zalo_bot`.
 *  - One message per person per 10 minutes for the same type, EXCEPT `escalate` — a supervisor warning
 *    may never be swallowed by a throttle.
 *  - Message body carries run code, step, deadline and a link. No customer PII (R-LM-5).
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-21 (PHASE-0.63A WP-7, stub)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Pipeline_Notify', false ) ) {
	return;
}

final class BizCity_CRM_Pipeline_Notify {

	const OPTION_GATE = 'bizcity_crm_pipeline_sla_zalo_bot';
	const OPTION_WORKFLOW = 'bizcity_crm_pipeline_sla_zalo_bot_workflow';
	const THROTTLE_SECONDS = 600;

	/** @param int[] $user_ids @return array|WP_Error */
	public static function notify( array $user_ids, array $payload ) {
		return self::send( $user_ids, $payload, false );
	}

	/** Never throttled. @param int[] $user_ids @return array|WP_Error */
	public static function escalate( array $user_ids, array $payload ) {
		return self::send( $user_ids, $payload, true );
	}

	/** Is the push channel enabled on this site at all? */
	public static function is_enabled(): bool {
		$enabled = function_exists( 'get_option' ) ? (bool) get_option( self::OPTION_GATE, false ) : false;
		return (bool) apply_filters( self::OPTION_GATE, $enabled );
	}

	/** Return binding facts for a bounded recipient list; no chat ids leave this method. */
	public static function binding_status( array $user_ids ): array {
		// [2026-09-22 PHASE-0.63A WP-7.5] Report only binding counts/ids; never expose Bot chat identifiers.
		$bound = array();
		$unbound = array();
		foreach ( array_values( array_unique( array_map( 'intval', $user_ids ) ) ) as $user_id ) {
			if ( $user_id <= 0 ) { continue; }
			$target = self::target_for_user( $user_id );
			if ( '' !== (string) ( $target['chat_id'] ?? '' ) ) { $bound[] = $user_id; }
			else { $unbound[] = $user_id; }
		}
		return array( 'bound' => $bound, 'unbound' => $unbound, 'bound_count' => count( $bound ), 'unbound_count' => count( $unbound ) );
	}

	/** @param int[] $user_ids @return array|WP_Error */
	private static function send( array $user_ids, array $payload, bool $escalation ) {
		// [2026-09-21 08:00 PM OpenAI GPT-5.6 Luna] PHASE-0.63A WP-7 — opt-in Zalo delivery with no fallback.
		if ( ! self::is_enabled() ) {
			return array( 'enabled' => false, 'sent' => 0, 'unbound' => array(), 'throttled' => 0 );
		}
		$ids = array();
		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			if ( $user_id > 0 && ! in_array( $user_id, $ids, true ) ) { $ids[] = $user_id; }
		}
		if ( empty( $ids ) ) {
			return array( 'enabled' => true, 'sent' => 0, 'unbound' => array(), 'throttled' => 0 );
		}
		$sent = 0;
		$unbound = array();
		$throttled = 0;
		$errors = array();
		foreach ( $ids as $user_id ) {
			$target = self::target_for_user( $user_id );
			$chat_id = (string) ( $target['chat_id'] ?? '' );
			if ( '' === $chat_id ) {
				$unbound[] = $user_id;
				continue;
			}
			$kind = $escalation ? 'escalate' : 'notify';
			if ( ! $escalation && ! self::claim_throttle( $kind, $user_id ) ) {
				$throttled++;
				continue;
			}
			$message = self::message( $payload, $escalation );
			$workflow = self::custom_workflow();
			$result = $workflow
				? self::dispatch_custom_workflow( $workflow, $chat_id, $message, $user_id, $kind, $payload )
				: self::deliver( $chat_id, $message, $user_id, $kind, $payload );
			if ( is_wp_error( $result ) ) {
				$errors[] = array( 'user_id' => $user_id, 'code' => $result->get_error_code() );
				continue;
			}
			$sent++;
		}
		return array( 'enabled' => true, 'sent' => $sent, 'unbound' => $unbound, 'throttled' => $throttled, 'errors' => $errors );
	}

	private static function target_for_user( int $user_id ): array {
		if ( class_exists( 'BizCity_Channel_User_Linker' ) && method_exists( 'BizCity_Channel_User_Linker', 'zalo_bot_target_for_user' ) ) {
			$target = BizCity_Channel_User_Linker::zalo_bot_target_for_user( $user_id );
			return is_array( $target ) ? $target : array();
		}
		return array();
	}

	private static function custom_workflow(): ?array {
		// [2026-09-22 PHASE-0.63A WP-7.3] Optional workflow escape hatch remains site-owned and disabled unless configured.
		if ( ! function_exists( 'get_option' ) ) { return null; }
		$config = get_option( self::OPTION_WORKFLOW, array() );
		$slug = is_array( $config ) ? trim( (string) ( $config['slug'] ?? '' ) ) : '';
		$secret = is_array( $config ) ? (string) ( $config['secret'] ?? '' ) : '';
		return ( '' !== $slug && '' !== $secret ) ? array( 'slug' => $slug, 'secret' => $secret ) : null;
	}

	private static function dispatch_custom_workflow( array $workflow, string $chat_id, string $message, int $user_id, string $kind, array $payload ) {
		// [2026-09-22 PHASE-0.63A WP-7.3] Delegate through the automation owner without logging secrets or customer data.
		if ( ! class_exists( 'BizCity_Automation_Trigger_Matcher' ) ) {
			return new WP_Error( 'workflow_unavailable', 'Workflow thông báo SLA chưa sẵn sàng.' );
		}
		$result = BizCity_Automation_Trigger_Matcher::instance()->dispatch_webhook(
			$workflow['slug'],
			array(
				'chat_id' => $chat_id,
				'recipient_user_id' => $user_id,
				'kind' => 'pipeline_sla_' . $kind,
				'message' => $message,
				'run_id' => self::safe_token( $payload['run_id'] ?? '' ),
				'rule_id' => self::safe_text( $payload['rule_id'] ?? '', 64 ),
				'stage_key' => self::safe_text( $payload['stage_key'] ?? '', 64 ),
				'due_at' => self::safe_text( $payload['due_at'] ?? '', 32 ),
			),
			$workflow['secret']
		);
		$ok = ! is_wp_error( $result ) && ! empty( $result['ok'] );
		self::log( $user_id, $kind, $ok, $payload, $ok ? '' : ( is_wp_error( $result ) ? $result->get_error_code() : 'workflow_dispatch_failed' ) );
		return $ok ? array( 'sent' => true ) : new WP_Error( 'workflow_dispatch_failed', 'Workflow không gửi được thông báo SLA.' );
	}

	private static function claim_throttle( string $kind, int $user_id ): bool {
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) { return true; }
		$key = 'bzc_pipeline_sla_' . $kind . '_' . $user_id;
		if ( false !== get_transient( $key ) ) { return false; }
		set_transient( $key, time(), self::THROTTLE_SECONDS );
		return true;
	}

	private static function message( array $payload, bool $escalation ): string {
		$run = self::safe_token( $payload['run_id'] ?? $payload['id'] ?? '' );
		$stage = self::safe_text( $payload['stage_key'] ?? $payload['rule_id'] ?? 'pipeline', 64 );
		$due = self::safe_text( $payload['due_at'] ?? '', 32 );
		$state = $escalation ? 'SLA leo thang' : ( ! empty( $payload['state'] ) && 'at_risk' === $payload['state'] ? 'SLA cảnh báo' : 'SLA trễ' );
		$text = $state . ': run ' . ( '' !== $run ? $run : 'đang xử lý' ) . ' · bước ' . $stage;
		if ( '' !== $due ) { $text .= ' · hạn ' . $due; }
		$link = isset( $payload['link'] ) ? esc_url_raw( (string) $payload['link'] ) : '';
		if ( '' !== $link ) { $text .= ' · mở: ' . $link; }
		return substr( $text, 0, 500 );
	}

	private static function deliver( string $chat_id, string $message, int $user_id, string $kind, array $payload ) {
		if ( ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			return new WP_Error( 'gateway_unavailable', 'Kênh gửi thông báo chưa sẵn sàng.' );
		}
		try {
			$result = BizCity_Gateway_Sender::instance()->send( $chat_id, $message, 'text', array( 'source' => 'crm_pipeline_sla_' . $kind ) );
			$ok = is_array( $result ) ? ( ! empty( $result['sent'] ) || ! empty( $result['ok'] ) ) : ( true === $result );
			self::log( $user_id, $kind, $ok, $payload );
			return $ok ? array( 'sent' => true ) : new WP_Error( 'gateway_send_failed', 'Gateway không gửi được thông báo.' );
		} catch ( Throwable $error ) {
			self::log( $user_id, $kind, false, $payload, $error->getMessage() );
			return new WP_Error( 'gateway_send_failed', 'Gateway không gửi được thông báo.' );
		}
	}

	private static function log( int $user_id, string $kind, bool $sent, array $payload, string $error = '' ): void {
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) || ! defined( 'BizCity_Channel_File_Logger::CH_ZALO_BOT' ) ) { return; }
		$detail = array( 'recipient_user_id' => $user_id, 'kind' => $kind, 'sent' => $sent ? 1 : 0, 'run_id' => self::safe_token( $payload['run_id'] ?? '' ), 'rule_id' => self::safe_text( $payload['rule_id'] ?? '', 64 ) );
		if ( '' !== $error ) { $detail['error_code'] = 'gateway_send_failed'; }
		BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_ZALO_BOT, BizCity_Channel_File_Logger::LEVEL_INFO, 'crm_pipeline_sla_' . $kind, 'Internal pipeline SLA notification.', $detail );
	}

	private static function safe_token( $value ): string { return preg_replace( '/[^A-Za-z0-9._:-]/', '', (string) $value ); }
	private static function safe_text( $value, int $limit ): string { $value = trim( (string) $value ); return function_exists( 'sanitize_text_field' ) ? substr( sanitize_text_field( $value ), 0, $limit ) : substr( $value, 0, $limit ); }
}
