<?php

// Intentional broken fixture for R-ERROR-UX.video-kling-message-only.
wp_send_json_error( array( 'message' => 'Video generation failed.' ) );
