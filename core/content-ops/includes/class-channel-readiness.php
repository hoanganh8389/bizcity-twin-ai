<?php
/**
 * Content Ops — Channel Readiness Matrix
 *
 * Aggregates BizCity_Integration_Registry data into a per-platform readiness
 * snapshot used by the SPA <ReadinessBanner /> and the schedule UI to gate
 * publish actions.
 *
 * @package BizCity_Twin_AI
 * @subpackage Content_Ops
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Content_Channel_Readiness {

	/**
	 * Return { CODE => { ready, accounts, healthy, last_check, reason?, fix_url? }, ... }
	 */
	public static function matrix(): array {
		if ( ! class_exists( 'BizCity_Integration_Registry' ) ) {
			return array();
		}
		$registry = BizCity_Integration_Registry::instance();
		$out      = array();

		foreach ( $registry->get_all() as $code => $integ ) {
			if ( ! ( $integ instanceof BizCity_Channel_Integration ) ) {
				continue;
			}
			$accounts = (array) $registry->get_accounts( $code );
			$total    = count( $accounts );
			$healthy  = 0;
			$last     = 0;
			foreach ( $accounts as $acc ) {
				$status = (string) ( $acc['_status'] ?? '' );
				$ts     = (int)    ( $acc['_status_at'] ?? 0 );
				if ( $status === 'ok' ) {
					++$healthy;
				}
				if ( $ts > $last ) {
					$last = $ts;
				}
			}
			$ready = $total > 0 && $healthy > 0;

			$reason  = null;
			$fix_url = null;
			if ( ! $ready ) {
				if ( $total === 0 ) {
					$reason  = 'no_accounts';
				} else {
					$reason  = 'all_unhealthy';
				}
				$fix_url = admin_url( 'admin.php?page=bizchat-gateway-spa#/p/' . strtolower( $code ) . '/settings' );
			}

			$out[ $code ] = array(
				'code'       => $code,
				'label'      => method_exists( $integ, 'get_label' ) ? $integ->get_label() : $code,
				'ready'      => $ready,
				'accounts'   => $total,
				'healthy'    => $healthy,
				'last_check' => $last ?: null,
				'reason'     => $reason,
				'fix_url'    => $fix_url,
			);
		}

		ksort( $out );
		return $out;
	}

	/**
	 * Quick boolean: is a given platform ready?
	 */
	public static function is_ready( string $code ): bool {
		$m = self::matrix();
		return ! empty( $m[ $code ]['ready'] );
	}
}
