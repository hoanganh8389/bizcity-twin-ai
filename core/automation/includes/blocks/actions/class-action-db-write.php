<?php
/**
 * Action: db_write — INSERT vào table whitelist.
 *
 * Whitelist mặc định CHỈ chấp nhận:
 *   - bizcity_automation_logs (debug)
 *   - bizcity_crm_events       (CRM bridge canonical)
 * Plugin ngoài có thể thêm qua filter `bizcity_automation_db_write_whitelist`.
 *
 * Cấm raw SQL — chỉ wpdb->insert() với cột mapped từ payload JSON.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_DB_Write extends BizCity_Automation_Block_Base {

	const DEFAULT_WHITELIST = array(
		'bizcity_automation_logs',
		'bizcity_crm_events',
	);

	public function id(): string   { return 'action.db_write'; }
	public function kind(): string { return 'action'; }
	public function meta(): array {
		return array(
			'label'    => 'Ghi DB',
			'short'    => 'db',
			'category' => 'action',
			'color'    => '#92400e',
			'icon'     => 'database',
			'defaults' => array( 'label' => 'db_write', 'table' => 'bizcity_crm_events', 'payload' => '{}' ),
			'fields'   => array(
				array( 'name' => 'label',   'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'table',   'label' => 'Bảng (không prefix)', 'type' => 'text' ),
				array( 'name' => 'payload', 'label' => 'Payload JSON', 'type' => 'textarea' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		global $wpdb;
		$table_suffix = ltrim( (string) ( $data['table'] ?? '' ), $wpdb->prefix );

		$whitelist = apply_filters( 'bizcity_automation_db_write_whitelist', self::DEFAULT_WHITELIST );
		if ( ! in_array( $table_suffix, (array) $whitelist, true ) ) {
			return new WP_Error( 'table_not_allowed', 'db_write: bảng không có trong whitelist.', array(
				'table' => $table_suffix,
			) );
		}

		$payload_raw = (string) $this->resolve( $data['payload'] ?? '{}', $ctx );
		$payload     = json_decode( $payload_raw, true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'invalid_payload', 'db_write: payload không phải JSON object.' );
		}

		// PG-S9 — dry-run mock (skip wpdb->insert).
		if ( ! empty( $ctx['_dry_run'] ) ) {
			return array( 'inserted_id' => 0, 'dry' => true, 'table' => $table_suffix, 'columns' => array_keys( $payload ) );
		}

		$ok = $wpdb->insert( $wpdb->prefix . $table_suffix, $payload );
		if ( $ok === false ) {
			return new WP_Error( 'db_insert_failed', 'db_write: wpdb error.', array(
				'last_error' => $wpdb->last_error,
			) );
		}
		return array( 'inserted_id' => (int) $wpdb->insert_id );
	}
}
