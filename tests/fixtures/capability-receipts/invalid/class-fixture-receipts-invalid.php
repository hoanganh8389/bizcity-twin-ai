<?php
/**
 * Negative fixture classes for the WP3 capability receipt gate.
 *
 * `Fixture_Receipts_Incomplete_Tool` implements the correct interface but its
 * manifest receipt is incomplete, so it must surface as receipt_incomplete.
 *
 * `Fixture_Receipts_Untyped_Channel` deliberately does NOT implement the
 * channel interface and must surface as class_not_typed. A class that is never
 * declared anywhere must surface as class_not_found.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Receipts_Incomplete_Tool implements BizCity_Tool_Interface {
	public function id() { return 'fixture.receipts.incomplete'; }
	public function label() { return 'Incomplete receipt'; }
	public function schema() { return array( 'type' => 'object' ); }
	public function run( array $args, array $context = array() ) { return $args; }
}

// Declared in the manifest but intentionally implements nothing.
final class Fixture_Receipts_Untyped_Channel {
	public function id() { return 'fixture.receipts.untyped'; }
}

// Correct interface, but the manifest names a contract that is not in the
// catalog, so this must surface as contract_unknown (not class_not_typed).
final class Fixture_Receipts_Renderer implements BizCity_Output_Renderer_Interface {
	public function id() { return 'fixture.receipts.bad_contract'; }
	public function artifactType() { return 'text'; }
	public function supports( array $output ) { return true; }
	public function render( array $output, array $context = array() ) { return ''; }
	public function meta() { return array(); }
}