<?php
/**
 * Valid fixture for the WP4 sender ownership gate.
 *
 * Sends only through the canonical sender and passes an `idempotency_key`, so a
 * retry cannot duplicate the message. No direct provider POST appears here.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Sender_Compliant {
	public static function reply( string $chat_id, string $text, string $event_uuid ): array {
		return BizCity_Gateway_Sender::instance()->send(
			$chat_id,
			$text,
			'text',
			array(
				'idempotency_key' => 'reply:' . $event_uuid,
			)
		);
	}
}