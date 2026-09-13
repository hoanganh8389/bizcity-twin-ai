<?php

// Intentional broken fixture for R-CH-UNI.raw-business-channel-listener.
add_action( 'bizcity_zalo_message_received', 'fixture_raw_channel_handler' );