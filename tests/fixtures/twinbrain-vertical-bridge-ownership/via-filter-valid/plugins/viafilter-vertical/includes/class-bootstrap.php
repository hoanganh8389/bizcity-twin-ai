<?php
defined( 'ABSPATH' ) || exit;

add_filter( 'bizcity_twinbrain_vertical_bridge_registry', function ( array $verticals ) {
	$verticals[] = array(
		'id'    => 'viafilter',
		'label' => 'Via Filter',
	);
	return $verticals;
} );
