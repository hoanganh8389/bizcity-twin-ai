<?php

// Intentional broken fixture for R-GW-8.direct-openai-plugin.
wp_remote_post( 'https://api.openai.com/v1/chat/completions', array( 'body' => '{}' ) );