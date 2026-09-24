<?php
/**
 * PHASE-0.69 S-04 — `catalogs` on a `pipeline-definition` (skills/areas/services vocabulary declared in
 * the definition itself, never hardcoded per industry) and `BizCity_CRM_Pipeline_Registry::catalog_service()`,
 * the lookup `class-service-rest.php` uses to resolve a booking's default duration (D69-5).
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'current_time' ) ) { function current_time( $f ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); } }

require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/pipeline/class-pipeline-registry.php';

final class CrmPipelineRegistryCatalogTest extends TestCase {

	private function baseDefinition(): array {
		return array(
			'contract' => 'pipeline-definition',
			'version'  => '1.0.0',
			'kind'     => 'service',
			'label'    => 'Dịch vụ có lịch',
			'stages'   => array( array( 'key' => 'booked', 'label' => 'Đã đặt lịch' ) ),
		);
	}

	public function test_catalog_service_finds_entry_by_key(): void {
		$definition = $this->baseDefinition();
		$definition['catalogs'] = array(
			'skills'   => array( 'ho_ta' ),
			'services' => array( array( 'key' => 'tam_be', 'label' => 'Tắm cho bé', 'duration_minutes' => 60, 'required_skill' => 'ho_ta' ) ),
		);
		$service = BizCity_CRM_Pipeline_Registry::catalog_service( $definition, 'tam_be' );
		$this->assertSame( 60, $service['duration_minutes'] );
		$this->assertSame( 'ho_ta', $service['required_skill'] );
	}

	public function test_catalog_service_returns_null_for_unknown_key(): void {
		$definition = $this->baseDefinition();
		$definition['catalogs'] = array( 'services' => array( array( 'key' => 'tam_be', 'label' => 'Tắm cho bé', 'duration_minutes' => 60 ) ) );
		$this->assertNull( BizCity_CRM_Pipeline_Registry::catalog_service( $definition, 'giao_hang' ) );
	}

	public function test_catalog_service_null_when_definition_has_no_catalogs_at_all(): void {
		$this->assertNull( BizCity_CRM_Pipeline_Registry::catalog_service( $this->baseDefinition(), 'anything' ) );
	}

	public function test_validate_accepts_a_well_formed_catalogs_block(): void {
		$definition = $this->baseDefinition();
		$definition['catalogs'] = array(
			'skills'   => array( 'ho_ta', 'lai_xe' ),
			'areas'    => array( 'cau_giay' ),
			'services' => array( array( 'key' => 'tam_be', 'label' => 'Tắm cho bé', 'duration_minutes' => 60, 'required_skill' => 'ho_ta' ) ),
		);
		$this->assertTrue( BizCity_CRM_Pipeline_Registry::validate( $definition ) );
	}

	public function test_validate_rejects_a_service_with_no_duration(): void {
		$definition = $this->baseDefinition();
		$definition['catalogs'] = array( 'services' => array( array( 'key' => 'tam_be', 'label' => 'Tắm cho bé' ) ) );
		$result = BizCity_CRM_Pipeline_Registry::validate( $definition );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'duration_minutes', implode( ';', $result->get_error_data()['reasons'] ) );
	}

	public function test_validate_rejects_duplicate_service_keys(): void {
		$definition = $this->baseDefinition();
		$definition['catalogs'] = array( 'services' => array(
			array( 'key' => 'tam_be', 'label' => 'A', 'duration_minutes' => 60 ),
			array( 'key' => 'tam_be', 'label' => 'B', 'duration_minutes' => 30 ),
		) );
		$result = BizCity_CRM_Pipeline_Registry::validate( $definition );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'twice', implode( ';', $result->get_error_data()['reasons'] ) );
	}

	public function test_validate_rejects_required_skill_not_in_the_skills_list(): void {
		$definition = $this->baseDefinition();
		$definition['catalogs'] = array(
			'skills'   => array( 'lai_xe' ),
			'services' => array( array( 'key' => 'tam_be', 'label' => 'Tắm cho bé', 'duration_minutes' => 60, 'required_skill' => 'ho_ta' ) ),
		);
		$result = BizCity_CRM_Pipeline_Registry::validate( $definition );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_the_real_service_json_template_still_validates(): void {
		$path = dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/templates/pipelines/_bundled_pending_split/service.json';
		$definition = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $definition, 'service.json must be valid JSON' );
		$this->assertTrue( BizCity_CRM_Pipeline_Registry::validate( $definition ) );
		$service = BizCity_CRM_Pipeline_Registry::catalog_service( $definition, 'tam_be' );
		$this->assertNotNull( $service );
		$this->assertSame( 60, $service['duration_minutes'] );
	}
}
