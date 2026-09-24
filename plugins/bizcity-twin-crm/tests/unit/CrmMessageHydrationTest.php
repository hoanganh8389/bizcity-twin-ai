<?php
/** PHASE-0.56 H-06 — mixed hot/offloaded/expired hydration assertions. */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

class Fake_CRM_DB_Installer_For_H06 {
	public static function tbl_attachments() { return 'attachments'; }
	public static function tbl_archive_receipts() { return 'receipts'; }
	// hydrate_messages() now skips the attachments query when the table is absent (2026-09-22 hotfix).
	public static function table_exists( string $table ): bool { return true; }
}
class Fake_WPDB_For_H06 {
	public function prepare( $query, ...$args ) { return $query; }
	public function get_results( $query, $output = null ) {
		if ( false !== strpos( $query, 'archive_status = \'written\'' ) ) {
			return array( array( 'crm_message_id' => 2, 'line_hash' => '', 'byte_offset' => null, 'line_bytes' => null ) );
		}
		return array();
	}
}
class BizCity_CRM_DB_Installer_V2 extends Fake_CRM_DB_Installer_For_H06 {}
class BizCity_Channel_Conversation_Archive {
	public static function read_batch( array $pointers, int $max_ms = 800 ): array {
		$items = array();
		foreach ( $pointers as $index => $pointer ) {
			$items[ $index ] = 2 === (int) $pointer['crm_message_id']
				? array( 'ok' => true, 'content' => 'cold body', 'body' => '', 'content_type' => 'text' )
				: array( 'ok' => false, 'cold_error' => 'archive_pointer_missing' );
		}
		return array( 'items' => $items, 'partial' => false, 'files_scanned' => 1 );
	}
}
require __DIR__ . '/../../includes/class-repository.php';

$wpdb = new Fake_WPDB_For_H06();
$rows = array(
	array( 'id' => 1, 'content' => 'hot body', 'content_storage_state' => 'hot', 'content_type' => 'text' ),
	array( 'id' => 2, 'content' => null, 'body' => null, 'content_storage_state' => 'offloaded', 'content_type' => 'text', 'archive_channel' => 'zalo_personal', 'archive_account_key' => 'a_' . str_repeat( 'a', 64 ), 'archive_peer_key' => 'p_' . str_repeat( 'b', 64 ), 'archive_month' => '2026-09' ),
	array( 'id' => 3, 'content' => null, 'content_storage_state' => 'expired', 'content_type' => 'text' ),
);
$hydrated = BizCity_CRM_Repository::hydrate_messages( $rows );
$pass = 0; $fail = 0;
function check_h06( string $label, bool $condition ): void { global $pass, $fail; if ( $condition ) { $pass++; } else { $fail++; echo "FAIL: {$label}\n"; } }
check_h06( 'hot content remains SQL value', 'hot body' === $hydrated[0]['content'] && empty( $hydrated[0]['cold'] ) );
check_h06( 'offloaded content is hydrated', 'cold body' === $hydrated[1]['content'] && true === $hydrated[1]['cold'] );
check_h06( 'expired row is marked cold', true === $hydrated[2]['cold'] && 'expired' === $hydrated[2]['cold_error'] );
check_h06( 'all rows receive attachments array', isset( $hydrated[0]['attachments'], $hydrated[1]['attachments'], $hydrated[2]['attachments'] ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
