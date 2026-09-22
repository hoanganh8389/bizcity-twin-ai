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
// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-05 — the resolver must run without a WordPress runtime.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
	define( 'BIZCITY_TWIN_AI_VERSION', '1.3.7' );
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

	public function test_malformed_registrations_cannot_displace_admitted_owners(): void {
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G7 — G7-07 isolation: a broken plugin must not break its neighbours.
		$this->assertTrue( BizCity_Setting_Panel_Registry::register_item( $this->item( 'test.isolation.owner', 400, 'renderer.isolation' ) ) );

		$baseline_ids    = array_map( static function ( $item ) { return $item['id']; }, BizCity_Setting_Panel_Registry::all() );
		$baseline_errors = count( BizCity_Setting_Panel_Registry::errors() );

		$malformed = array(
			'missing_required_key'  => $this->without_key( $this->item( 'test.isolation.missing', 401, 'renderer.missing' ), 'availability' ),
			'duplicate_id'          => $this->item( 'test.isolation.owner', 402, 'renderer.duplicate' ),
			'invalid_destination'   => $this->with_key( $this->item( 'test.isolation.dest', 403, 'renderer.dest' ), 'destination', 'nowhere' ),
			'invalid_scope'         => $this->with_key( $this->item( 'test.isolation.scope', 404, 'renderer.scope' ), 'scope', 'galaxy' ),
			'invalid_position'      => $this->with_key( $this->item( 'test.isolation.position', 405, 'renderer.position' ), 'position', 'soon' ),
			'renderer_collision'    => $this->item( 'test.isolation.collision', 406, 'renderer.isolation' ),
			'channel_zone_missing'  => $this->with_key( $this->item( 'test.isolation.channel', 407, 'renderer.channel' ), 'destination', 'channel-settings' ),
			'invalid_renderer_type' => $this->with_renderer_key( $this->item( 'test.isolation.rtype', 408, 'renderer.rtype' ), 'type', 'shadow_dom' ),
			'invalid_policy'        => $this->with_availability_policy( $this->item( 'test.isolation.policy', 409, 'renderer.policy' ), 'sometimes' ),
			'invalid_capability'    => $this->with_key( $this->item( 'test.isolation.capability', 410, 'renderer.capability' ), 'capability', 'Manage Options!' ),
			'invalid_zone'          => $this->with_key(
				$this->with_key( $this->item( 'test.isolation.zone', 411, 'renderer.zone' ), 'destination', 'channel-settings' ),
				'zone',
				'nowhere'
			),
			'legacy_metadata_missing' => $this->with_renderer(
				$this->with_key( $this->item( 'test.isolation.legacy', 412, 'renderer.legacy' ), 'origin', 'legacy_adapter' ),
				'deep_link',
				'isolation-legacy'
			),
			'route_missing'         => $this->with_renderer( $this->item( 'test.isolation.route', 413, 'renderer.route' ), 'route' ),
			'slug_missing'          => $this->with_renderer( $this->item( 'test.isolation.slug', 414, 'renderer.slug' ), 'deep_link' ),
		);

		foreach ( $malformed as $label => $fixture ) {
			$this->assertFalse(
				BizCity_Setting_Panel_Registry::register_item( $fixture ),
				'Malformed fixture was admitted: ' . $label
			);
		}

		$after_ids = array_map( static function ( $item ) { return $item['id']; }, BizCity_Setting_Panel_Registry::all() );
		$this->assertSame( $baseline_ids, $after_ids, 'An admitted owner was displaced by a malformed registration.' );
		$this->assertGreaterThanOrEqual(
			$baseline_errors + count( $malformed ),
			count( BizCity_Setting_Panel_Registry::errors() ),
			'Every rejection must record a reason instead of failing silently.'
		);
	}

	public function test_diagnostics_snapshot_and_restore_roll_back_fixtures(): void {
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G7 — probes must not leak fixture state into later probes.
		$this->assertTrue( BizCity_Setting_Panel_Registry::register_item( $this->item( 'test.snapshot.owner', 500, 'renderer.snapshot' ) ) );

		$snapshot = BizCity_Setting_Panel_Registry::diagnostics_snapshot();
		$this->assertIsArray( $snapshot );
		$this->assertArrayHasKey( 'items', $snapshot );
		$this->assertArrayHasKey( 'errors', $snapshot );
		$this->assertArrayHasKey( 'renderer_owners', $snapshot );

		$this->assertTrue( BizCity_Setting_Panel_Registry::register_item( $this->item( 'test.snapshot.fixture', 501, 'renderer.snapshot.fixture' ) ) );
		BizCity_Setting_Panel_Registry::register_item( $this->item( 'test.snapshot.fixture', 502, 'renderer.snapshot.duplicate' ) );
		$this->assertNotSame( $snapshot['errors'], BizCity_Setting_Panel_Registry::errors() );

		BizCity_Setting_Panel_Registry::diagnostics_restore( $snapshot );

		$this->assertSame( $snapshot['errors'], BizCity_Setting_Panel_Registry::errors() );
		$this->assertNull( BizCity_Setting_Panel_Registry::get( 'test.snapshot.fixture' ) );
		$this->assertNotNull( BizCity_Setting_Panel_Registry::get( 'test.snapshot.owner' ) );

		$this->assertTrue( BizCity_Setting_Panel_Registry::register_item( $this->item( 'test.snapshot.fixture', 503, 'renderer.snapshot.fixture' ) ) );
	}

	public function test_diagnostics_restore_ignores_malformed_state(): void {
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G7 — a bad snapshot argument must not clear the registry.
		$this->assertTrue( BizCity_Setting_Panel_Registry::register_item( $this->item( 'test.restore.guard', 600, 'renderer.restore.guard' ) ) );

		BizCity_Setting_Panel_Registry::diagnostics_restore( array( 'items' => 'not-an-array', 'errors' => 5 ) );

		$this->assertNotNull( BizCity_Setting_Panel_Registry::get( 'test.restore.guard' ) );
	}

	public function test_resolve_produces_label_url_and_availability_without_runtime_reads(): void {
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-05 — resolution must be metadata-only and always complete.
		$deep_link = $this->item( 'test.resolve.deep_link', 700, 'test.resolve.deep_link' );
		$deep_link['label_key'] = 'settings.test.deep_link';
		$deep_link['renderer']  = array(
			'type'           => 'deep_link',
			'id'             => 'test.resolve.deep_link',
			'canonical_slug' => 'test-resolve-deep-link',
		);
		$this->assertTrue( BizCity_Setting_Panel_Registry::register_item( $deep_link ) );

		$resolved    = BizCity_Setting_Panel_Registry::resolved_all();
		$missing     = array();
		$bad_state   = array();
		$known_state = array( 'available', 'unavailable', 'incompatible', 'update_required' );

		foreach ( $resolved as $item ) {
			if ( empty( $item['label'] ) || empty( $item['url'] ) ) {
				$missing[] = $item['id'];
			}
			if ( ! in_array( (string) $item['availability_state'], $known_state, true ) ) {
				$bad_state[] = $item['id'] . ':' . $item['availability_state'];
			}
			$this->assertArrayHasKey( 'url', $item );
			$this->assertArrayHasKey( 'label', $item );
			$this->assertArrayHasKey( 'availability_state', $item );
		}

		$this->assertSame( array(), $missing, 'Every resolved item must carry a label and a URL.' );
		$this->assertSame( array(), $bad_state, 'Every resolved item must carry a known availability state.' );

		$one = BizCity_Setting_Panel_Registry::resolve( BizCity_Setting_Panel_Registry::get( 'test.resolve.deep_link' ) );
		$this->assertSame( 'Deep Link', $one['label'], 'Fallback label derives from the last label_key segment.' );
		$this->assertStringContainsString( 'admin.php?page=test-resolve-deep-link', $one['url'] );
		$this->assertSame( 'available', $one['availability_state'] );
	}

	public function test_resolve_availability_maps_missing_and_present_dependencies(): void {
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-05 — dependency presence drives unavailable, not admission.
		$absent = $this->item( 'test.resolve.absent_dep', 710, 'renderer.resolve.absent' );
		$absent['availability'] = array(
			'policy'         => 'registered-owner',
			'dependency_ids' => array( 'plugins.this-plugin-does-not-exist-anywhere' ),
		);
		$this->assertTrue( BizCity_Setting_Panel_Registry::register_item( $absent ) );

		$any = $this->item( 'test.resolve.any_dep', 711, 'renderer.resolve.any' );
		$any['availability'] = array(
			'policy'         => 'any',
			'dependency_ids' => array( 'plugins.this-plugin-does-not-exist-anywhere', 'core.setting-panel.registry' ),
		);
		$this->assertTrue( BizCity_Setting_Panel_Registry::register_item( $any ) );

		$always = $this->item( 'test.resolve.always', 712, 'renderer.resolve.always' );
		$always['availability'] = array( 'policy' => 'always' );
		$this->assertTrue( BizCity_Setting_Panel_Registry::register_item( $always ) );

		$this->assertSame( 'unavailable', BizCity_Setting_Panel_Registry::resolve( BizCity_Setting_Panel_Registry::get( 'test.resolve.absent_dep' ) )['availability_state'] );
		$this->assertSame( 'available', BizCity_Setting_Panel_Registry::resolve( BizCity_Setting_Panel_Registry::get( 'test.resolve.any_dep' ) )['availability_state'] );
		$this->assertSame( 'available', BizCity_Setting_Panel_Registry::resolve( BizCity_Setting_Panel_Registry::get( 'test.resolve.always' ) )['availability_state'] );
	}

	public function test_resolve_availability_reports_version_floors(): void {
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-05 — an unmet floor is update_required/incompatible, never silently available.
		$future_framework = $this->item( 'test.resolve.min_framework', 720, 'renderer.resolve.framework' );
		$future_framework['availability'] = array( 'policy' => 'always', 'min_framework' => '99.0.0' );
		$this->assertTrue( BizCity_Setting_Panel_Registry::register_item( $future_framework ) );

		$future_php = $this->item( 'test.resolve.min_php', 721, 'renderer.resolve.php' );
		$future_php['availability'] = array( 'policy' => 'always', 'min_php' => '99.0' );
		$this->assertTrue( BizCity_Setting_Panel_Registry::register_item( $future_php ) );

		$this->assertSame( 'update_required', BizCity_Setting_Panel_Registry::resolve( BizCity_Setting_Panel_Registry::get( 'test.resolve.min_framework' ) )['availability_state'] );
		$this->assertSame( 'incompatible', BizCity_Setting_Panel_Registry::resolve( BizCity_Setting_Panel_Registry::get( 'test.resolve.min_php' ) )['availability_state'] );
	}

	public function test_resolve_url_matches_renderer_type(): void {
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-05 — each renderer type resolves to its own URL shape.
		$route = $this->item( 'test.resolve.route', 730, 'renderer.resolve.route' );
		$route['renderer'] = array( 'type' => 'route', 'id' => 'test.resolve.route', 'route' => '/setting-panel/settings' );
		$this->assertTrue( BizCity_Setting_Panel_Registry::register_item( $route ) );
		$this->assertSame( '/setting-panel/settings', BizCity_Setting_Panel_Registry::resolve( BizCity_Setting_Panel_Registry::get( 'test.resolve.route' ) )['url'] );

		$external = $this->item( 'test.resolve.external', 731, 'renderer.resolve.external' );
		$external['renderer'] = array(
			'type'       => 'external',
			'id'         => 'test.resolve.external',
			'target_url' => 'https://hub.bizcity.vn/checkout',
		);
		$this->assertTrue( BizCity_Setting_Panel_Registry::register_item( $external ) );
		$this->assertSame( 'https://hub.bizcity.vn/checkout', BizCity_Setting_Panel_Registry::resolve( BizCity_Setting_Panel_Registry::get( 'test.resolve.external' ) )['url'] );
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

	private function without_key( array $item, $key ): array {
		unset( $item[ $key ] );
		return $item;
	}

	private function with_key( array $item, $key, $value ): array {
		$item[ $key ] = $value;
		return $item;
	}

	private function with_renderer_key( array $item, $key, $value ): array {
		$item['renderer'][ $key ] = $value;
		return $item;
	}

	private function with_availability_policy( array $item, $policy ): array {
		$item['availability']['policy'] = $policy;
		return $item;
	}

	private function with_renderer( array $item, $type, $canonical_slug = '' ): array {
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-04 — exercise renderer shape rules from the v1 schema.
		$renderer = array( 'type' => $type, 'id' => (string) $item['renderer']['id'] );
		if ( '' !== $canonical_slug ) {
			$renderer['canonical_slug'] = $canonical_slug;
		}
		$item['renderer'] = $renderer;
		return $item;
	}
}
