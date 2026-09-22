<?php
/**
 * CRM Team Ops Board — deterministic leader insights.
 *
 * PHASE-0.56 I-1: recommendations are derived only from the aggregated
 * team-ops-board payload. No customer content, customer phone number, or LLM
 * call enters this class. The returned shape is part of team-ops-board@1.0.0.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Team_Insights' ) ) {
	return;
}

final class BizCity_CRM_Team_Insights {

	const MAX_INSIGHTS = 3;
	const LOAD_SKEW_RATIO = 0.50;
	const SLOW_REPLY_RATIO = 1.80;

	/**
	 * Build deterministic recommendations from aggregate staff rows.
	 *
	 * @param array $payload Team dashboard aggregate payload.
	 * @return array<int,array<string,mixed>>
	 */
	public static function from_dashboard( array $payload ): array {
		$employees = isset( $payload['employees'] ) && is_array( $payload['employees'] )
			? array_values( $payload['employees'] )
			: array();
		$insights = array();

		$load = self::load_skew( $employees );
		if ( $load ) {
			$insights[] = $load;
		}

		$dead_channel = self::dead_channel( $employees );
		if ( $dead_channel ) {
			$insights[] = $dead_channel;
		}

		$slow_reply = self::slow_first_reply( $employees );
		if ( $slow_reply ) {
			$insights[] = $slow_reply;
		}

		return array_slice( $insights, 0, self::MAX_INSIGHTS );
	}

	/** @param array<int,array<string,mixed>> $employees */
	private static function load_skew( array $employees ): ?array {
		if ( count( $employees ) < 3 ) {
			return null;
		}
		$total = 0;
		$leader = null;
		foreach ( $employees as $employee ) {
			$open = max( 0, (int) ( $employee['open'] ?? 0 ) );
			$total += $open;
			if ( null === $leader || $open > $leader['open'] ) {
				$leader = array(
					'user_id'      => (int) ( $employee['user_id'] ?? 0 ),
					'name'         => self::safe_label( $employee ),
					'open'         => $open,
				);
			}
		}
		if ( ! $leader || $leader['user_id'] <= 0 || $total <= 0 || $leader['open'] / $total < self::LOAD_SKEW_RATIO ) {
			return null;
		}
		$percent = (int) round( $leader['open'] / $total * 100 );
		return array(
			'kind'    => 'load_skew',
			'user_id' => $leader['user_id'],
			'value'   => sprintf( '%s đang giữ %d/%d hội thoại mở (%d%%).', $leader['name'], $leader['open'], $total, $percent ),
			'action'  => array( 'kind' => 'open_handoff', 'target_id' => $leader['user_id'] ),
		);
	}

	/** @param array<int,array<string,mixed>> $employees */
	private static function dead_channel( array $employees ): ?array {
		foreach ( $employees as $employee ) {
			foreach ( (array) ( $employee['phones'] ?? array() ) as $phone ) {
				if ( empty( $phone['dead'] ) || (int) ( $phone['inbox_id'] ?? 0 ) <= 0 ) {
					continue;
				}
				$name = self::safe_label( $employee );
				return array(
					'kind'    => 'dead_channel',
					'user_id' => (int) ( $employee['user_id'] ?? 0 ),
					'inbox_id' => (int) $phone['inbox_id'],
					'value'   => sprintf( 'SĐT của %s đang hết phiên; cần đăng nhập QR hộ.', $name ),
					'action'  => array( 'kind' => 'qr', 'target_id' => (int) $phone['inbox_id'] ),
				);
			}
		}
		return null;
	}

	/** @param array<int,array<string,mixed>> $employees */
	private static function slow_first_reply( array $employees ): ?array {
		$values = array();
		foreach ( $employees as $employee ) {
			$frt = $employee['frt_minutes'] ?? null;
			if ( null !== $frt && is_numeric( $frt ) && (float) $frt >= 0 ) {
				$values[] = array(
					'user_id' => (int) ( $employee['user_id'] ?? 0 ),
					'name'    => self::safe_label( $employee ),
					'frt'     => (float) $frt,
				);
			}
		}
		if ( count( $values ) < 3 ) {
			return null;
		}
		$median_values = array_map( static function ( $row ) { return $row['frt']; }, $values );
		sort( $median_values, SORT_NUMERIC );
		$middle = intdiv( count( $median_values ), 2 );
		$median = 1 === count( $median_values ) % 2
			? $median_values[ $middle ]
			: ( $median_values[ $middle - 1 ] + $median_values[ $middle ] ) / 2;
		$slowest = null;
		foreach ( $values as $value ) {
			if ( null === $slowest || $value['frt'] > $slowest['frt'] ) {
				$slowest = $value;
			}
		}
		if ( ! $slowest || $slowest['user_id'] <= 0 || $median <= 0 || $slowest['frt'] < $median * self::SLOW_REPLY_RATIO ) {
			return null;
		}
		return array(
			'kind'    => 'slow_first_reply',
			'user_id' => $slowest['user_id'],
			'value'   => sprintf( '%s có phản hồi đầu %.1f phút, cao hơn trung vị đội %.1f phút.', $slowest['name'], $slowest['frt'], $median ),
			'action'  => array( 'kind' => 'open_profile', 'target_id' => $slowest['user_id'] ),
		);
	}

	private static function safe_label( array $employee ): string {
		$label = trim( (string) ( $employee['display_name'] ?? $employee['name'] ?? '' ) );
		return '' !== $label ? $label : 'Một nhân viên';
	}
}
