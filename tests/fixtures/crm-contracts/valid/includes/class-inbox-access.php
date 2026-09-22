<?php
class Fixture_CRM_Inbox_Access {
	public static function scope( int $user_id ) {
		BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $user_id );
		$access_mode = 'owner_only';
		BizCity_CRM_Repository::list_conversations_for_member( $user_id, array() );
		$args = array( 'contact_wp_user_id' => $user_id );
		return array( $access_mode, $args );
	}
}
