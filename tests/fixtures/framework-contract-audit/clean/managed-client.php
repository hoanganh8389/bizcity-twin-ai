<?php

// Compliant fixture: provider transport is delegated to the managed client.
BizCity_LLM_Client::instance()->chat( array(), array( 'purpose' => 'test' ) );