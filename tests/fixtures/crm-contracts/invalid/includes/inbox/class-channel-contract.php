<?php
class Fixture_CRM_Channel_Contract {
	public static function normalize_inbound( string $code, array $normalized ) {
		$required = array( 'inbox_ref', 'source_id', 'content' );
		return $normalized;
	}
}
