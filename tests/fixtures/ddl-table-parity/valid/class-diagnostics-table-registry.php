<?php
/**
 * Positive fixture for the DDL table parity gate.
 *
 * Every table declared in `fixture.parity.json` must appear here so the
 * diagnostics inventory agrees with the changelog.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Diagnostics_Table_Registry_Fixture_Valid {
	private static function seed(): array {
		return [
			[ 'name' => 'fixture_widgets', 'owner' => 'fixture.parity', 'group' => 'fixture' ],
			[ 'name' => 'fixture_widget_events', 'owner' => 'fixture.parity', 'group' => 'fixture' ],
		];
	}
}