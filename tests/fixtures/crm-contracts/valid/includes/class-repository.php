<?php
class Fixture_CRM_Repository {
	public static function list_conversations_for_member( int $user_id, $allowed_inbox_ids = null ): array {
		return self::list_conversations( array( 'contact_wp_user_id' => $user_id, 'inbox_ids' => $allowed_inbox_ids ) );
	}
	public static function list_conversations( array $args ): array { return array(); }
	public static function upsert_contact_by_identity( int $inbox_id, string $source_id, array $data = array() ): array {
		$wpdb->insert( $contacts_table, $data );
		BizCity_CRM_Event_Emitter::emit( 'crm_contact_upserted', array( 'contact_id' => 1 ) );
		return array();
	}
	public static function upsert_contact( int $inbox_id, string $source_id, array $data = array() ): array {
		$wpdb->insert( $contacts_table, $data );
		BizCity_CRM_Event_Emitter::emit( 'crm_contact_upserted', array( 'contact_id' => 1 ) );
		return array();
	}
	public static function open_or_get_conversation( int $inbox_id, int $contact_inbox_id ): int {
		$wpdb->insert( $conversation_table, array() );
		BizCity_CRM_Event_Emitter::emit( 'crm_conversation_opened', array( 'conversation_id' => 1 ) );
		return 1;
	}
	public static function insert_message( array $data ): int {
		BizCity_CRM_Channel_Contract::require_crm_enabled( 'webchat' );
		$existing = $wpdb->get_var( "SELECT id FROM messages WHERE external_source_id = 'x' LIMIT 1" );
		$wpdb->insert( $message_table, $data );
		BizCity_CRM_Event_Emitter::emit( 'crm_message_received', array( 'message_id' => 1 ) );
		return 1;
	}
}
