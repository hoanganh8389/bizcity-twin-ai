<?php
/**
 * Positive fixture for the DDL table parity gate.
 *
 * Both fixture tables are registered before create, satisfying R-CR.
 */

defined( 'ABSPATH' ) || exit;

BizCity_Schema_Registry::register( 'fixture_widgets', 'fixture.parity', '1.0.0', 'fixture_parity_db_version', array( 'Fixture_Installer', 'ensure' ) );
BizCity_Schema_Registry::register( 'fixture_widget_events', 'fixture.parity', '1.0.0', 'fixture_parity_db_version', array( 'Fixture_Installer', 'ensure' ) );