<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/core/twin-core/contracts/framework-contracts.php';
require_once dirname( __DIR__, 2 ) . '/core/twin-core/contracts/class-twin-plugin-sdk.php';
require_once dirname( __DIR__, 2 ) . '/packages/bizcity-framework-sdk/src/Contracts.php';
require_once dirname( __DIR__, 2 ) . '/packages/bizcity-framework-sdk/src/PluginSdk.php';
require_once dirname( __DIR__, 2 ) . '/core/twin-core/event-stream/class-twin-event-taxonomy.php';
require_once dirname( __DIR__, 2 ) . '/core/twin-core/event-stream/class-twin-event-registry.php';
require_once dirname( __DIR__, 2 ) . '/core/twin-core/includes/class-twin-tool-registry.php';
require_once dirname( __DIR__, 2 ) . '/core/twinbrain/tools/memory/class-twinbrain-memory-tool-remember.php';
require_once dirname( __DIR__, 2 ) . '/core/twinbrain/tools/memory/class-twinbrain-memory-tool-forget.php';
require_once dirname( __DIR__, 2 ) . '/core/twinbrain/tools/memory/class-twinbrain-memory-tool-recall.php';
require_once dirname( __DIR__, 2 ) . '/core/twinbrain/tools/knowledge/class-twinbrain-tool-ingest-document.php';

final class TypedToolAdapterTest extends TestCase {

    public function test_public_tool_contract_maps_to_runtime_contract(): void {
        // [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — prove the typed SDK bridge preserves runtime tool semantics.
        $adapter = new BizCity_Typed_Tool_Adapter( new TypedToolAdapterFixture() );

        $this->assertSame( 'fixture.echo', $adapter->name() );
        $this->assertSame( 'Echo a sanitized value.', $adapter->description() );
        $this->assertSame( array( 'type' => 'object', 'required' => array( 'text' ) ), $adapter->parameters_schema() );
        $this->assertSame(
            array(
                'ok'      => true,
                'result'  => array( 'text' => 'hello' ),
                'summary' => 'Echo fixture',
                'code'    => 'fixture_ok',
            ),
            $adapter->execute( array( 'text' => 'hello' ), array( 'trace_id' => 'trace_fixture' ) )
        );
    }

    public function test_namespaced_sdk_tool_contract_maps_to_runtime_contract(): void {
        // [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — keep the distributable SDK contract compatible with the WordPress runtime.
        $adapter = new BizCity_Typed_Tool_Adapter( new NamespacedTypedToolAdapterFixture() );

        $this->assertSame( 'fixture.namespaced', $adapter->name() );
        $this->assertSame( array( 'type' => 'object' ), $adapter->parameters_schema() );
        $this->assertSame( true, $adapter->execute( array(), array() )['ok'] );
    }

    public function test_event_registry_accepts_canonical_events_and_rejects_unknown_events(): void {
        // [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — keep extension event declarations inside the canonical whitelist.
        $this->assertTrue( BizCity_Event_Registry::register_event( 'tool_call', array( 'source' => 'tool', 'owner' => 'fixture' ) ) );
        $this->assertFalse( BizCity_Event_Registry::register_event( 'fixture_private_event', array( 'source' => 'tool' ) ) );
        $this->assertArrayHasKey( 'tool_call', BizCity_Event_Registry::events() );
    }

    public function test_package_sdk_exposes_all_seven_registration_verbs(): void {
        // [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — keep the distributed facade aligned with the runtime facade.
        foreach ( array( 'register_plugin', 'register_tool', 'register_skill', 'register_source', 'register_event', 'register_diagnostic', 'register_ui' ) as $method ) {
            $this->assertTrue( method_exists( '\BizCity\Twin\Sdk\PluginSdk', $method ), $method . ' must be public in the SDK.' );
        }
    }

    public function test_builtin_memory_and_ingest_tools_implement_typed_contract(): void {
        // [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — keep built-in tool adoption measurable at the contract boundary.
        $tools = array(
            new BizCity_TwinBrain_Memory_Tool_Remember(),
            new BizCity_TwinBrain_Memory_Tool_Forget(),
            new BizCity_TwinBrain_Memory_Tool_Recall(),
            new BizCity_TwinBrain_Tool_Ingest_Document(),
        );

        foreach ( $tools as $tool ) {
            $this->assertInstanceOf( BizCity_Tool_Interface::class, $tool );
            $schema = $tool->schema();
            $this->assertSame( $tool->id(), $schema['name'] );
            $this->assertNotSame( '', $schema['description'] );
            $this->assertIsArray( $schema['parameters'] );
        }
    }
}

final class TypedToolAdapterFixture implements BizCity_Tool_Interface {

    public function id() {
        return 'fixture.echo';
    }

    public function label() {
        return 'Echo fixture';
    }

    public function schema() {
        return array(
            'name'        => $this->id(),
            'description' => 'Echo a sanitized value.',
            'parameters'  => array( 'type' => 'object', 'required' => array( 'text' ) ),
        );
    }

    public function run( array $args, array $context = [] ) {
        return array(
            'success' => true,
            'result'  => array( 'text' => (string) $args['text'] ),
            'summary' => $this->label(),
            'code'    => 'fixture_ok',
        );
    }
}

final class NamespacedTypedToolAdapterFixture implements \BizCity\Twin\Contracts\ToolInterface {

    public function id() {
        return 'fixture.namespaced';
    }

    public function label() {
        return 'Namespaced fixture';
    }

    public function schema() {
        return array(
            'description' => 'Namespaced SDK fixture.',
            'parameters'  => array( 'type' => 'object' ),
        );
    }

    public function run( array $args, array $context = array() ) {
        return array( 'success' => true, 'result' => array() );
    }
}