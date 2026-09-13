<?php

// Compliant fixture: tool registration carries permission/scope metadata.
$permissions = array( 'demo.read' );
$scope_bindings = array( array( 'permission' => 'demo.read', 'scope_level' => 'tenant' ) );
add_filter( 'bizcity_twin_register_tool', function ( $registry ) use ( $permissions, $scope_bindings ) {
    $registry['demo.tool'] = new Demo_Tool();
    return $registry;
} );