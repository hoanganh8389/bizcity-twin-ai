<?php

// Compliant fixture: credential access stays behind the managed client.
$api_key = BizCity_LLM_Client::instance()->get_api_key();
