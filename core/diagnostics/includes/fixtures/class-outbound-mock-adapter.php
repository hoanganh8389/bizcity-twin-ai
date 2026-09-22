<?php
/**
 * Diagnostics-only mock outbound adapter for the D5 delivery probe.
 *
 * PHASE-0.41D §5.3. The mock is registered through the canonical
 * `bizcity_crm_register_adapters` filter so the dispatcher keeps using the real
 * `BizCity_CRM_Channel_Registry` boundary instead of an injected test double.
 *
 * This file is loaded lazily at probe run time: `BizCity_CRM_Adapter_Base` is
 * a bundled CRM plugin class and is not guaranteed to exist when the
 * diagnostics probe graph is first required.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Fixtures
 * @since 2026-09-16 (PHASE-0.41D-CLOSURE / D5)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_CRM_Adapter_Base' ) ) {
	// The CRM plugin is not loaded on this surface; the probe reports the real reason.
	return;
}
if ( class_exists( 'BizCity_Probe_Outbound_Mock_Adapter', false ) ) {
	return;
}

final class BizCity_Probe_Outbound_Mock_Adapter extends BizCity_CRM_Adapter_Base {

	/** @var int Provider attempts observed during the probe. */
	public static $attempts = 0;

	/** @var string success|retryable|permanent */
	public static $mode = 'success';

	public function code(): string { return 'facebook'; }

	public function label(): string { return 'Diagnostics mock outbound adapter'; }

	public function capabilities(): array { return array( 'text', 'image', 'file' ); }

	public function normalize_inbound( array $raw ): ?array {
		unset( $raw );
		return null;
	}

	/**
	 * Simulate one provider transport attempt.
	 *
	 * @param array $conversation Conversation row.
	 * @param array $message      Outbound message payload.
	 * @return array Normalized-compatible provider result.
	 */
	public function send( array $conversation, array $message ): array {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5 — count every attempt so the probe can prove exactly-once behaviour.
		unset( $conversation, $message );
		self::$attempts++;
		if ( 'retryable' === self::$mode ) {
			return array( 'success' => false, 'code' => 'timeout', 'error' => 'Provider timeout while sending.', 'retryable' => true );
		}
		if ( 'permanent' === self::$mode ) {
			return array( 'success' => false, 'code' => 'provider_rejected', 'error' => 'Recipient rejected the message.', 'retryable' => false );
		}
		return array(
			'success'            => true,
			'outcome'            => 'accepted',
			'external_source_id' => 'mock_ext_' . self::$attempts,
			'job_id'             => 'mock_job_' . self::$attempts,
		);
	}
}
