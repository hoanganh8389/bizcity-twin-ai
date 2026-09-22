<?php

// Compliant fixture: capability class and id both exist in plugin source.
class Fixture_Parity_Tool {
    public function id() { return 'fixture.parity.tool'; }
}
add_filter( 'bizcity_twin_register_tool', function ( $registry ) {
    $registry['fixture.parity.tool'] = new Fixture_Parity_Tool();
    return $registry;
} );