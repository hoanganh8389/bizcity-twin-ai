<?php
/**
 * Zalo Personal transport port (LC-2).
 *
 * @package BizCity_Twin_AI
 */

// [2026-09-28 11:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.82-A4 — define the transport boundary shared by every Zalo Personal sender.
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Zalo_Transport', false ) ) {
	interface BizCity_Zalo_Transport {
		public function id(): string;
		public function descriptor(): array;
		public function send( array $target, array $message, array $ctx ): array;
		public function account_status( string $bridge_account_id ): array;
		public function health(): array;
	}
}
