<?php

// Intentional broken fixture for R-KG-HUB.direct-table-access-outside-owner.
global $wpdb;
$wpdb->get_results( "SELECT * FROM {$wpdb->prefix}bizcity_kg_notebooks" );