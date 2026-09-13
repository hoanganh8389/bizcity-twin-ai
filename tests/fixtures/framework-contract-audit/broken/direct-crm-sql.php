<?php

// Intentional broken fixture for R-CRM.direct-sql-outside-owner.
global $wpdb;
$wpdb->insert( 'wp_bizcity_crm_messages', array( 'content' => 'fixture' ) );