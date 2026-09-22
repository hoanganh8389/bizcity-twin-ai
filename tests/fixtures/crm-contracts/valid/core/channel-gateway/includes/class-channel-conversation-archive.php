<?php
class Fixture_Channel_Conversation_Archive {
	public static function archive( array $entry ) {
		$crm_message_id = $entry['crm_message_id'];
		$event_uuid = $entry['event_uuid'];
		BizCity_CRM_Repository::mark_message_archived( $crm_message_id, 'zalo_personal', $entry, 'key' );
		BizCity_Channel_File_Logger::write( 'zalo_personal', 'info', 'archive_written', 'ok', compact( 'crm_message_id', 'event_uuid' ) );
	}
}
