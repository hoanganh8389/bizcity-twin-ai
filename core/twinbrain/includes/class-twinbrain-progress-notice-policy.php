<?php
/**
 * TwinBrain V5 — Progress notice policy boundary.
 *
 * Default policy is intentionally ON. Channel Gateway Control Plane can later
 * override the filtered policy without changing the Runner or projector.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-15
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_Progress_Notice_Policy' ) ) {
	return;
}

final class BizCity_TwinBrain_Progress_Notice_Policy {

	public static function resolve( array $context = array() ): array {
		// [2026-08-15 Johnny Chu] MPR-V5-NOTICE — keep default progress visibility ON while exposing one Control Plane filter boundary.
		$policy = array(
			'enabled'          => true,
			'detail_level'     => 'standard',
			'send_started'     => true,
			'send_completed'   => true,
			'send_skipped'     => true,
			'send_failed'      => true,
			'minimum_interval' => 1,
			'dedupe_window_seconds' => 30,
		);
		// [2026-08-15 Johnny Chu] MPR-V5-NOTICE — consume the existing Channel Gateway notify-settings cache/option; no second settings store.
		$settings = class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( 'notify', 'settings' ) : false;
		if ( false === $settings ) {
			$settings = get_option( 'bizcity_cg_notify_settings', array() );
		}
		if ( is_array( $settings ) ) {
			$policy['enabled'] = array_key_exists( 'twin_progress_notice_enabled', $settings ) ? ! empty( $settings['twin_progress_notice_enabled'] ) : $policy['enabled'];
			$policy['detail_level'] = (string) ( $settings['twin_progress_notice_detail'] ?? $policy['detail_level'] );
			$policy['send_started'] = array_key_exists( 'twin_progress_send_started', $settings ) ? ! empty( $settings['twin_progress_send_started'] ) : $policy['send_started'];
			$policy['send_completed'] = array_key_exists( 'twin_progress_send_completed', $settings ) ? ! empty( $settings['twin_progress_send_completed'] ) : $policy['send_completed'];
			$policy['send_skipped'] = array_key_exists( 'twin_progress_send_skipped', $settings ) ? ! empty( $settings['twin_progress_send_skipped'] ) : $policy['send_skipped'];
			$policy['send_failed'] = array_key_exists( 'twin_progress_send_failed', $settings ) ? ! empty( $settings['twin_progress_send_failed'] ) : $policy['send_failed'];
			$policy['dedupe_window_seconds'] = isset( $settings['twin_progress_dedupe_window_seconds'] ) ? (int) $settings['twin_progress_dedupe_window_seconds'] : $policy['dedupe_window_seconds'];
		}
		$filtered = apply_filters( 'bizcity_twin_progress_notice_policy', $policy, $context );
		if ( is_array( $filtered ) ) {
			$policy = array_merge( $policy, $filtered );
		}
		$policy['enabled'] = ! empty( $policy['enabled'] );
		$policy['detail_level'] = in_array( (string) $policy['detail_level'], array( 'compact', 'standard', 'full' ), true )
			? (string) $policy['detail_level']
			: 'standard';
		$policy['minimum_interval'] = max( 0, min( 60, (int) $policy['minimum_interval'] ) );
		$policy['dedupe_window_seconds'] = max( 1, min( 3600, (int) $policy['dedupe_window_seconds'] ) );
		return $policy;
	}

	public static function should_send( string $status, array $context = array() ): bool {
		// [2026-08-15 Johnny Chu] MPR-V5-NOTICE — suppress only non-terminal notices when policy disables them; failures remain visible by default.
		$policy = self::resolve( $context );
		if ( ! $policy['enabled'] ) {
			return false;
		}
		if ( $status === 'started' ) {
			return ! empty( $policy['send_started'] ) && 'compact' !== $policy['detail_level'];
		}
		if ( $status === 'completed' ) {
			return ! empty( $policy['send_completed'] );
		}
		if ( $status === 'skipped' ) {
			return ! empty( $policy['send_skipped'] ) && 'compact' !== $policy['detail_level'];
		}
		if ( $status === 'failed' ) {
			return ! empty( $policy['send_failed'] );
		}
		return true;
	}
}