<?php
class Fixture_CRM_Repository {
	public static function upsert_contact_by_identity( int $inbox_id, string $source_id, array $data = array() ): array {
		$wpdb->insert( $contacts_table, $data );
		return array();
	}
	public static function upsert_contact( int $inbox_id, string $source_id, array $data = array() ): array {
		$wpdb->insert( $contacts_table, $data );
		BizCity_CRM_Event_Emitter::emit( 'crm_contact_upserted', array() );
		return array();
	}
	public static function open_or_get_conversation( int $inbox_id, int $contact_inbox_id ): int {
		$wpdb->insert( $conversation_table, array() );
		BizCity_CRM_Event_Emitter::emit( 'crm_conversation_opened', array() );
		return 1;
	}
	public static function insert_message( array $data ): int {
		$wpdb->insert( $message_table, $data );
		return 1;
	}
}
