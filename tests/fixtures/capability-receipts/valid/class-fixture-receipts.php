<?php
/**
 * Positive fixture classes for the WP3 capability receipt gate.
 *
 * Demonstrates the typed-capability requirement: each declared class must
 * exist in the package and implement the interface matching its capability
 * kind. All three naming styles found in this repository are accepted:
 *   - runtime suffixed:   `BizCity_Tool_Interface`
 *     (core/twin-core/contracts/framework-contracts.php)
 *   - runtime unsuffixed: `BizCity_Channel_Adapter`
 *     (core/channel-gateway/includes/interface-channel-adapter.php)
 *   - SDK namespaced:     `BizCity\Twin\Contracts\ChannelAdapterInterface`
 *     (packages/bizcity-framework-sdk/src/Contracts.php)
 *
 * This fixture uses one style per capability so the gate is proven to accept
 * all of them; if `shortForm()` regresses, the clean fixture fails.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Receipts_Echo_Tool implements BizCity_Tool_Interface {
	public function id() { return 'fixture.receipts.echo'; }
	public function label() { return 'Echo'; }
	public function schema() { return array( 'type' => 'object' ); }
	public function run( array $args, array $context = array() ) { return $args; }
}

// Unsuffixed runtime interface, exactly as the real channel adapters declare it.
final class Fixture_Receipts_Channel implements BizCity_Channel_Adapter {
	public function id() { return 'fixture.receipts.channel'; }
	public function platform() { return 'fixture'; }
	public function zone() { return 'admin'; }
}

final class Fixture_Receipts_Skill implements \BizCity\Twin\Contracts\SkillInterface {
	public function id() { return 'fixture.receipts.skill'; }
	public function label() { return 'Skill'; }
	public function instructions() { return ''; }
	public function subTools() { return array(); }
	public function meta() { return array(); }
}