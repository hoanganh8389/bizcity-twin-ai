<?php
class Fixture_CRM_AI_Autoreply_Listener {
	public static function on_message_received( $payload ): void {
		$descriptor = BizCity_CRM_Channel_Contract::describe( $payload['channel'] ?? '' );
		if ( ( $descriptor['ai_policy'] ?? '' ) !== 'customer_autoreply' ) { return; }
	}
}
