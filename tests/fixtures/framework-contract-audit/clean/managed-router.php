<?php

// Compliant fixture: client code does not reference Router-internal classes.
BizCity_LLM_Client::instance()->chat( array(), array( 'purpose' => 'fixture' ) );
