<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

require_once dirname( __DIR__, 2 ) . '/core/twin-core/contracts/class-setting-panel-registry.php';

final class SettingPanelRegistryTest extends TestCase {

	public function test_native_registration_is_sorted_and_exposed_without_runtime_reads(): void {
		// [2026-09-13 09:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3 — prove deterministic metadata ordering.
		$this->assertTrue( BizCity_Setting_Panel_Registry::register_item( $this->item( 'test.settings.late', 200, 'renderer.late' ) ) );
		$this->assertTrue( BizCity_Setting_Panel_Registry::register_item( $this->item( 'test.settings.early', 100, 'renderer.early' ) ) );

		$ids = array_map(
			static function ( $item ) { return $item['id']; },
			BizCity_Setting_Panel_Registry::all()
		);

		$this->assertSame( array( 'test.settings.early', 'test.settings.late' ), $ids );
	}

	public function test_duplicate_id_and_native_renderer_collision_fail_closed(): void {
		// [2026-09-13 09:41 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3 — reject duplicate owners and native renderer collisions.
		$this->assertTrue( BizCity_Setting_Panel_Registry::register_item( $this->item( 'test.collision.owner', 300, 'renderer.shared' ) ) );
		$this->assertFalse( BizCity_Setting_Panel_Registry::register_item( $this->item( 'test.collision.owner', 301, 'renderer.other' ) ) );
		$this->assertFalse( BizCity_Setting_Panel_Registry::register_item( $this->item( 'test.collision.second', 302, 'renderer.shared' ) ) );
		$this->assertContains( 'duplicate_id:test.collision.owner', BizCity_Setting_Panel_Registry::errors() );
		$this->assertContains( 'renderer_collision:renderer.shared', BizCity_Setting_Panel_Registry::errors() );
	}

	public function test_legacy_adapter_can_reuse_canonical_renderer_with_migration_metadata(): void {
		// [2026-09-13 09:42 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3 — preserve legacy deep-links as explicit adapters.
		$this->assertTrue(
			BizCity_Setting_Panel_Registry::register_legacy_navigation(
				array(
					'id'         => 'bizcity-legacy-settings',
					'slug'       => 'bizcity-legacy-settings',
					'renderer'   => 'test.canonical.renderer',
					'slot'       => 'settings.api',
					'capability' => 'manage_options',
					'scope'      => 'site',
					'surface'    => 'admin_page',
				),
				'core/test-owner',
				'PHASE-SETTING-PANEL-G4'
			)
		);

		$legacy = BizCity_Setting_Panel_Registry::get( 'legacy.bizcity-legacy-settings' );
		$this->assertSame( 'legacy_adapter', $legacy['origin'] );
		$this->assertFalse( $legacy['native_contract'] );
		$this->assertSame( 'core/test-owner', $legacy['migration_owner'] );
	}

	private function item( $id, $position, $renderer_id ): array {
		// [2026-09-13 09:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3 — provide a metadata-only test fixture.
		return array(
			'contract'     => 'setting-panel-registration',
			'version'      => '1.0.0',
			'id'           => $id,
			'owner'        => 'core/test',
			'origin'       => 'core',
			'destination'  => 'settings',
			'group'        => 'test',
			'label_key'    => 'test.label',
			'icon'         => 'cil-settings',
			'capability'   => 'manage_options',
			'scope'        => 'site',
			'surface'      => 'admin_page',
			'renderer'     => array(
				'type'  => 'route',
				'id'    => $renderer_id,
				'route' => '/settings/test',
			),
			'availability' => array( 'policy' => 'registered-owner' ),
			'position'     => $position,
		);
	}
}
