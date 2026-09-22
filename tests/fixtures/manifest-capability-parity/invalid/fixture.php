<?php

// Invalid fixture: declares a namespace class that is never defined anywhere.
add_filter( 'bizcity_twin_register_tool', function ( $registry ) {
    $registry['fixture.parity.other'] = null;
    return $registry;
} );