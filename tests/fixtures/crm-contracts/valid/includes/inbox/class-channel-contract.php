<?php
class Fixture_CRM_Channel_Contract {
	public static function normalize_inbound( string $code, array $normalized ) {
		$required = array( 'inbox_ref', 'source_id', 'content', 'content_type', 'attachments', 'external_source_id', 'received_at' );
		$normalized['identity'] = array( 'inbox_ref' => $normalized['inbox_ref'], 'source_id' => $normalized['source_id'], 'external_source_id' => $normalized['external_source_id'] );
		return $normalized;
	}
}
