<?php
/**
 * Positive fixture for the diagnostics-fixture exemption (WP5 CRM ownership).
 *
 * This file sits in the exempted directory `core/diagnostics/includes/fixtures/`
 * AND proves teardown: it exposes a `destroy()` entry point and every delete is
 * marker-scoped. It must PASS with zero findings.
 *
 * Its counterpart
 * (`tests/fixtures/crm-ownership/fixture-unproven/`) is the same directory with
 * no teardown, and must FAIL. Asserting both pins the exemption in both
 * directions: an exemption that stops matching starts failing the real factory,
 * and an exemption that over-reaches would excuse an unowned writer.
 */

class BizCity_CRM_Proven_Fixture_Writer {

	const MARKER = 'bztest_';

	private static $state = array();

	public static function build( array $spec ): array {
		global $wpdb;

		$token = 'fixture_' . wp_generate_password( 8, false );
		self::$state[ $token ] = array( 'message_ids' => array() );

		$table  = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$marker = self::marker_ref( 0, 0 );

		$wpdb->insert(
			$table,
			array(
				'conversation_id' => 1,
				'content'         => $marker,
			)
		);
		self::$state[ $token ]['message_ids'][] = (int) $wpdb->insert_id;

		return array( 'cleanup_token' => $token );
	}

	public static function destroy( string $cleanup_token ): array {
		if ( $cleanup_token === '' || ! isset( self::$state[ $cleanup_token ] ) ) {
			return array( 'ok' => true, 'remaining' => 0 );
		}

		global $wpdb;
		$state   = self::$state[ $cleanup_token ];
		$removed = 0;
		$skipped = array();
		$table   = BizCity_CRM_DB_Installer_V2::tbl_messages();

		foreach ( $state['message_ids'] as $message_id ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT id, content FROM `{$table}` WHERE id = %d", (int) $message_id ),
				ARRAY_A
			);
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( ! self::is_marker_value( (string) ( $row['content'] ?? '' ) ) ) {
				$skipped[] = 'message_unmarked:' . (int) $message_id;
				continue;
			}
			$deleted = $wpdb->delete( $table, array( 'id' => (int) $message_id ), array( '%d' ) );
			if ( false !== $deleted ) {
				$removed += (int) $deleted;
			}
		}

		unset( self::$state[ $cleanup_token ] );

		return array( 'ok' => true, 'removed' => $removed, 'skipped' => $skipped );
	}

	public static function count_remaining( ?array $state = null ): int {
		$state  = $state ?? array( 'message_ids' => array() );
		$table  = BizCity_CRM_DB_Installer_V2::tbl_messages();
		return self::count_rows_in( $table, 'id', $state['message_ids'] );
	}

	private static function count_rows_in( string $table, string $column, array $ids ): int {
		global $wpdb;
		$total = 0;
		foreach ( $ids as $id ) {
			$found = $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = %d", (int) $id )
			);
			$total += (int) $found;
		}
		return $total;
	}

	private static function marker_ref( int $user_index, int $index ): string {
		return self::MARKER . 'msg_' . $user_index . '_' . $index;
	}

	private static function is_marker_value( string $value ): bool {
		return strpos( $value, self::MARKER ) === 0;
	}
}