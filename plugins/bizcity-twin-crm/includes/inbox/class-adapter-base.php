<?php
/**
 * BizCity CRM — Adapter Base (abstract).
 *
 * Adds optional self-describing methods on top of the BizCity_CRM_Channel_Adapter
 * interface. Concrete adapters extend this base to inherit safe defaults for:
 *   - setup_form_schema()  — drives M7.W1 "Add Inbox" wizard
 *   - verify( $config )    — pre-flight sanity-check before saving an inbox
 *   - health( $inbox )     — runtime health for nav sidebar dot
 *   - mark_seen() / set_typing() — no-op defaults
 *
 * Existing FB/Zalo adapters MAY extend this base to gain wizard support without
 * rewriting their normalize/send code. Adapters NOT extending base keep working
 * because the registry / REST layer falls back to method_exists() probes.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M7.W1)
 */

defined( 'ABSPATH' ) || exit;

abstract class BizCity_CRM_Adapter_Base implements BizCity_CRM_Channel_Adapter {

	/* -- interface stubs (safe no-ops) ------------------------------------ */

	public function mark_seen( array $conversation, string $external_source_id ): void {
		// no-op default
	}

	public function set_typing( array $conversation, bool $on ): void {
		// no-op default
	}

	/* -- M7 self-description (NOT in interface — probed via method_exists) - */

	/**
	 * Declarative form schema rendered by the "Add Inbox" wizard.
	 *
	 * Shape:
	 *   array(
	 *     'fields' => array(
	 *       array(
	 *         'name'        => 'access_token',
	 *         'label'       => 'Page Access Token',
	 *         'type'        => 'text|password|textarea|select|checkbox|url',
	 *         'required'    => true,
	 *         'placeholder' => '',
	 *         'help'        => '...markdown...',
	 *         'options'     => array( 'value' => 'Label', ... ),  // for select
	 *       ),
	 *       ...
	 *     ),
	 *     'webhook' => array(
	 *       'method' => 'GET|POST',
	 *       'url'    => 'https://example.com/wp-json/...',  // shown to copy
	 *       'note'   => 'Paste this URL into the FB App webhook config.',
	 *     ),
	 *     'docs_url' => 'https://...',
	 *   )
	 *
	 * Override per adapter. Default returns an empty schema (= "no setup UI").
	 */
	public function setup_form_schema(): array {
		return array(
			'fields'   => array(),
			'webhook'  => null,
			'docs_url' => '',
		);
	}

	/**
	 * Validate user-submitted setup config BEFORE persisting an inbox row.
	 *
	 * Override to ping the channel API and confirm credentials.
	 *
	 * @param array $config user-submitted form values keyed by field name
	 * @return array{ok:bool, channel_ref_id?:string, name?:string, error?:string, hints?:array}
	 *   - ok=true  → wizard proceeds to create the inbox using channel_ref_id+name
	 *   - ok=false → error shown; hints[] = remediation steps
	 */
	public function verify( array $config ): array {
		return array(
			'ok'    => true,
			'name'  => $this->label(),
			'hints' => array( 'No verification step implemented for this adapter — manual configuration only.' ),
		);
	}

	/**
	 * Runtime health for an existing inbox row.
	 *
	 * @param array $inbox row from {prefix}bizcity_crm_inboxes
	 * @return array{
	 *     status: 'green'|'yellow'|'red'|'unknown',
	 *     last_inbound_at: ?string,
	 *     last_error: ?string,
	 *     details: array
	 * }
	 */
	public function health( array $inbox ): array {
		$last = self::query_last_inbound_at( (int) ( $inbox['id'] ?? 0 ) );
		$status = 'unknown';
		if ( $last ) {
			$age = time() - strtotime( $last );
			$status = ( $age < 86400 ) ? 'green' : ( $age < 604800 ? 'yellow' : 'red' );
		}
		return array(
			'status'          => $status,
			'last_inbound_at' => $last,
			'last_error'      => null,
			'details'         => array(
				'channel'  => $this->code(),
				'is_active' => (int) ( $inbox['is_active'] ?? 0 ) === 1,
			),
		);
	}

	/* -- helpers ---------------------------------------------------------- */

	protected static function query_last_inbound_at( int $inbox_id ): ?string {
		if ( $inbox_id <= 0 || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return null;
		}
		global $wpdb;
		$tbl_msg  = $wpdb->prefix . 'bizcity_crm_messages';
		$tbl_conv = $wpdb->prefix . 'bizcity_crm_conversations';
		$row = $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(m.created_at)
			   FROM {$tbl_msg} m
			   JOIN {$tbl_conv} c ON c.id = m.conversation_id
			  WHERE c.inbox_id = %d AND m.message_type = 'incoming'",
			$inbox_id
		) );
		return $row ?: null;
	}
}
