<?php
/**
 * Negative fixture for the DDL table parity gate.
 *
 * `fixture_widget_events` is inventoried here but has no schema registration,
 * so it must surface as missing=schema_registry only.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Diagnostics_Table_Registry_Fixture_Invalid {
	private static function seed(): array {
		return [
			[ 'name' => 'fixture_widget_events', 'owner' => 'fixture.parity', 'group' => 'fixture' ],
		];
	}
}