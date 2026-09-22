<?php

// Fixture intentionally registers a different contract id than the manifest declares.
BizCity_Log_Contract_Registry::register( 'fixture.jsonl.other', array(
    'owner_module' => 'tests/fixtures/jsonl-contract-parity/invalid',
    'label'        => 'Fixture other contract',
    'jsonl_folder' => 'fixture-jsonl-invalid',
    'jsonl_module' => 'invalid',
) );
