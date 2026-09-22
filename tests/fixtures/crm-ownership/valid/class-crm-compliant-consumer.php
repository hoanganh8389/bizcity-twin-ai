<?php
/**
 * Valid fixture for the WP5 CRM ownership gate.
 *
 * Goes through the contract + registry and does not write CRM tables directly,
 * mirroring the canonical consumer path
 * (`BizCity_CRM_Channel_Contract::normalize_inbound()` then the repository).
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Crm_Compliant_Consumer {
	public static function ingest( string $code, array $normalized ): array {
		$descriptor = BizCity_CRM_Channel_Contract::describe( $code );
		if ( empty( $descriptor['crm_enabled'] ) ) {
			return array( 'accepted' => false, 'reason' => 'crm_disabled' );
		}
		$adapter = BizCity_CRM_Channel_Registry::get( $code );
		if ( ! $adapter ) {
			return array( 'accepted' => false, 'reason' => 'adapter_not_registered' );
		}
		// Hand off to the CRM repository; this fixture never writes CRM tables.
		return array( 'accepted' => true, 'contract_version' => $descriptor['contract_version'] );
	}
}