<?php
class Fixture_CRM_Inbox_Access {
	public static function scope( int $user_id ) {
		return array( 'channel' => 'zalo_personal' );
	}
}
