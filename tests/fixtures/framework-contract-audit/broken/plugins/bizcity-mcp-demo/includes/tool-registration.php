<?php

// Intentional broken fixture for R-MCP.tool-registration-metadata.
add_filter( 'bizcity_twin_register_tool', function ( $registry ) {
    $registry['demo.tool'] = new Demo_Tool();
    return $registry;
} );