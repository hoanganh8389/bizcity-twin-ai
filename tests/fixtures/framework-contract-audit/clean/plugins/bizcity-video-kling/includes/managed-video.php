<?php

// Compliant fixture: video transport and failures use canonical boundaries.
$response = BizCity_Video_Client::instance()->generate( array( 'prompt' => 'fixture' ) );
wp_send_json_error( BizCity_Error_Payload::make( 'llm_error', 'Khong the tao video.', 'Thu lai sau.', 'llm_unavailable' ) );
