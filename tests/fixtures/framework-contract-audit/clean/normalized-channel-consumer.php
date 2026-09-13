<?php

// Compliant fixture: business logic consumes the normalized channel envelope.
add_action( 'bizcity_channel_normalized', 'fixture_normalized_channel_handler' );