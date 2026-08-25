<?php
/**
 * BizCity CRM — Channel Adapter Interface.
 *
 * Implementations: bizcity-twin-crm/includes/inbox/adapters/class-adapter-*.php
 *
 * Registration:
 *   add_filter( 'bizcity_crm_register_adapters', function ( $a ) {
 *       $a['my_channel'] = new My_Adapter();
 *       return $a;
 *   } );
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

interface BizCity_CRM_Channel_Adapter {

	/** Stable channel code. */
	public function code(): string;

	/** Human label. */
	public function label(): string;

	/** Capability flags: text|image|file|quick_reply|typing|mark_seen */
	public function capabilities(): array;

	/**
	 * Framework contract: inbound adapters must produce identity + storage-ready
	 * data; outbound adapters must return a normalized delivery outcome.
	 * Implementations remain provider-specific, but all writes pass the CRM gate.
	 */

	/**
	 * Normalize an inbound webhook/event payload into CRM-ready shape.
	 *
	 * @return array|null  null = skip (e.g. not a message event)
	 *
	 * Required keys when returned:
	 *   inbox_ref           string  page_id|oa_id|widget_key
	 *   source_id           string  PSID|user_id|visitor_id
	 *   contact_name        string
	 *   contact_avatar      ?string
	 *   content             string
	 *   content_type        'text'|'image'|'file'|'audio'|'card'|'quick_reply'
	 *   attachments         array<array{file_type,data_url,thumb_url?,meta?}>
	 *   external_source_id  string  unique per inbox (FB mid, Zalo msg_id…)
	 *   received_at         DateTimeInterface|string
	 *   inbox_name          ?string  (used to seed inbox row)
	 *   channel_code        string   (injected by the framework gate)
	 *   contract_version    string   (injected by the framework gate)
	 *   identity            array    (inbox_ref, source_id, external_source_id)
	 */
	public function normalize_inbound( array $raw ): ?array;

	/**
	 * Send an outbound message via channel API.
	 *
	 * @param array $conversation Conversation row (assoc array from CRM table).
	 * @param array $message      ['content','content_type','attachments']
	 * @return array{success:bool, outcome:string, code:string, external_source_id:?string, error:?string, retryable:bool, channel_code:string, contract_version:string}
	 */
	public function send( array $conversation, array $message ): array;

	/** Optional. Default no-op via abstract base — implementations override. */
	public function mark_seen( array $conversation, string $external_source_id ): void;
	public function set_typing( array $conversation, bool $on ): void;
}
