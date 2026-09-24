<?php
/**
 * Canonical CRM outbound dispatcher and delivery state machine.
 *
 * PHASE-0.41D §5 (D5). One owner for every outbound customer message so that
 * idempotency, CRM truth, provider transport, delivery status and notification
 * account binding cannot drift between call sites.
 *
 * Contract highlights:
 *   - Provider acceptance (job_id / accepted / queued) is ALWAYS `queued`.
 *     Only a provider callback may move a message to `sent` or `delivered`.
 *   - Idempotency reuses `BizCity_Twin_Mutation_Store`; the same key with the
 *     same request hash replays the stored envelope without a second provider
 *     attempt, and a changed payload is a conflict that never sends.
 *   - Authorization is re-resolved from the conversation. Posted inbox,
 *     account or owner identifiers are ignored, never trusted.
 *   - Attachments are validated (ownership, MIME, size) BEFORE any provider
 *     call, so a rejected attachment costs zero provider attempts.
 *   - A non-interactive caller (automation run, campaign, AI replier, worker)
 *     sends with `actor => 'system'` and MUST name the owner it acts for via
 *     `on_behalf_of_user_id` (R-TWEB-17 owner continuity). That owner is then
 *     authorized exactly like an interactive user; a system actor gains no
 *     extra scope and an anonymous system send is refused.
 *
 * @package BizCity_Twin_CRM
 * @subpackage Inbox
 * @since 2026-09-16 (PHASE-0.41D-CLOSURE / D5)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Outbound_Dispatcher' ) ) {
	return;
}

final class BizCity_CRM_Outbound_Dispatcher {

	const VERSION     = '1.1.0';
	const CONTRACT_ID = 'core.crm.outbound_delivery';
	const ACTION      = 'crm.message.send';
	const MIN_KEY_LEN = 16;

	/**
	 * Non-interactive callers that may anchor on the inbox capability when no
	 * human owner exists (inbound-triggered replies are owned by the tenant
	 * inbox, not by a person). Anything outside this list must name an owner.
	 */
	const SYSTEM_SOURCES = array( 'ai_autoreply', 'kg_reply', 'automation', 'campaign', 'conversion', 'worker' );

	/** Outcome ladder. A callback may only move a message forward. */
	const OUTCOME_RANK = array(
		'failed'    => 0,
		'queued'    => 1,
		'accepted'  => 1,
		'sent'      => 2,
		'delivered' => 3,
	);

	/**
	 * Dispatch one outbound message through the canonical owner chain.
	 *
	 * @param array $request Outbound request: conversation_id, content,
	 *                       content_type, attachments, idempotency_key,
	 *                       request_hash, user_id, trace_id.
	 * @return array Normalized outbound envelope (never throws).
	 */
	public static function dispatch( array $request ): array {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5 — one outbound owner; every branch returns the same normalized envelope.
		$conversation_id = isset( $request['conversation_id'] ) ? (int) $request['conversation_id'] : 0;
		$user_id         = isset( $request['user_id'] ) ? (int) $request['user_id'] : 0;
		if ( $user_id <= 0 && function_exists( 'get_current_user_id' ) ) {
			$user_id = (int) get_current_user_id();
		}
		$idempotency_key = isset( $request['idempotency_key'] ) ? (string) $request['idempotency_key'] : '';
		$request_hash    = isset( $request['request_hash'] ) ? (string) $request['request_hash'] : '';
		$content         = isset( $request['content'] ) ? (string) $request['content'] : '';
		$content_type    = sanitize_key( (string) ( $request['content_type'] ?? 'text' ) );
		$trace_id        = substr( sanitize_text_field( (string) ( $request['trace_id'] ?? '' ) ), 0, 128 );
		$attachments_in  = isset( $request['attachments'] ) && is_array( $request['attachments'] ) ? $request['attachments'] : array();

		if ( ! in_array( $content_type, array( 'text', 'image', 'file' ), true ) ) {
			$content_type = 'text';
		}

		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5.7 / R-TWEB-17 — a non-interactive caller must carry owner continuity; there is no anonymous system send.
		$actor = sanitize_key( (string) ( $request['actor'] ?? 'user' ) );
		if ( ! in_array( $actor, array( 'user', 'system' ), true ) ) {
			$actor = 'user';
		}
		$on_behalf_of  = 'system' === $actor ? (int) ( $request['on_behalf_of_user_id'] ?? 0 ) : 0;
		$system_source = 'system' === $actor ? sanitize_key( (string) ( $request['system_source'] ?? '' ) ) : '';
		$effective_user_id = 'system' === $actor ? $on_behalf_of : $user_id;
		$owner_source  = 'system' === $actor ? ( $on_behalf_of > 0 ? 'caller' : '' ) : 'current_user';

		$base = array(
			'conversation_id' => $conversation_id,
			'idempotency_key' => $idempotency_key,
			'content_type'    => $content_type,
			'actor'           => $actor,
			'on_behalf_of_user_id' => $on_behalf_of,
			'system_source'   => $system_source,
			'owner_source'    => $owner_source,
		);

		// Fail closed when a canonical owner is missing; never fall back to a direct provider call.
		if ( ! class_exists( 'BizCity_CRM_Repository' )
			|| ! class_exists( 'BizCity_CRM_Inbox_Access' )
			|| ! class_exists( 'BizCity_CRM_Channel_Contract' )
			|| ! class_exists( 'BizCity_CRM_Channel_Registry' )
			|| ! class_exists( 'BizCity_Twin_Mutation_Store' ) ) {
			return self::envelope( $base, 'failed', 'module_not_loaded', 'module_not_loaded', false, array( 'error' => 'Một owner CRM/idempotency bắt buộc chưa được nạp.' ) );
		}

		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5 — the idempotency contract matches the shipped C mutation routes; no second contract.
		if ( strlen( $idempotency_key ) < self::MIN_KEY_LEN || strlen( $request_hash ) < self::MIN_KEY_LEN ) {
			return self::envelope( $base, 'failed', 'invalid_param', 'invalid_param', false, array( 'error' => 'Thiếu idempotency key hoặc request hash hợp lệ.' ) );
		}

		if ( 'system' === $actor && $on_behalf_of <= 0 && ! in_array( $system_source, self::SYSTEM_SOURCES, true ) ) {
			// A system caller must either name the owner it acts for, or declare a
			// registered inbound source that may anchor on the inbox capability.
			return self::envelope( $base, 'failed', 'system_owner_required', 'permission_denied', false, array( 'error' => 'Caller hệ thống phải kèm owner (on_behalf_of_user_id) hoặc system_source hợp lệ.' ) );
		}

		$conversation = BizCity_CRM_Repository::get_conversation( $conversation_id );
		if ( ! is_array( $conversation ) ) {
			return self::envelope( $base, 'failed', 'not_found', 'not_found', false, array( 'error' => 'Hội thoại không tồn tại.' ) );
		}

		$inbox = BizCity_CRM_Repository::get_inbox( (int) ( $conversation['inbox_id'] ?? 0 ) );
		if ( ! is_array( $inbox ) ) {
			return self::envelope( $base, 'failed', 'not_found', 'not_found', false, array( 'error' => 'Inbox của hội thoại không tồn tại.' ) );
		}

		if ( 'system' === $actor && $on_behalf_of <= 0 ) {
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5.7 — resolve a real anchor instead of sending anonymously.
			$anchor = self::resolve_system_anchor( $conversation, $inbox, $system_source, ! empty( $attachments_in ) );
			$effective_user_id = (int) $anchor['user_id'];
			$owner_source      = (string) $anchor['owner_source'];
			$on_behalf_of      = $effective_user_id;
			$base['on_behalf_of_user_id'] = $effective_user_id;
			$base['owner_source']         = $owner_source;
		}

		// Authorization is resolved from the conversation, never from the request body.
		// A system actor with a human anchor is authorized exactly like that user and
		// gains nothing extra; an inbound source with no human anchor is authorized by
		// the inbox capability itself (the conversation's own CRM-enabled channel).
		// [2026-09-24 Claude Sonnet 5] PHASE-0.60H D-H2 — `system_owner` is authorised like `inbox_capability`: by the
		// conversation's own bot binding (checked in resolve_system_anchor), NOT by generic user scope. The generic
		// scope cannot be granted to a powerless user for a zalo_personal inbox — `resolve_scope()` only admits inboxes
		// the user OWNS (filter_c_personal_inbox_membership drops mere membership) or tenant admins, and making the
		// system owner an admin is exactly what this design forbids. The ownership rule for the FILE is unchanged.
		if ( ! in_array( $owner_source, array( 'inbox_capability', 'system_owner' ), true )
			&& ! BizCity_CRM_Inbox_Access::can_view_conversation( $conversation_id, $effective_user_id ) ) {
			return self::envelope( $base, 'failed', 'permission_denied', 'permission_denied', false, array( 'error' => 'Hội thoại không thuộc phạm vi của owner được uỷ quyền.' ) );
		}
		$channel    = sanitize_key( (string) ( $inbox['channel_type'] ?? '' ) );
		$descriptor = BizCity_CRM_Channel_Contract::require_crm_enabled( $channel );
		if ( is_wp_error( $descriptor ) ) {
			$base['channel_code'] = $channel;
			return self::envelope( $base, 'failed', sanitize_key( $descriptor->get_error_code() ), 'permission_denied', false, array( 'error' => $descriptor->get_error_message() ) );
		}
		$base['channel_code'] = $channel;
		$base['notify']       = self::notify_target( $conversation, $inbox );

		if ( 'text' === $content_type && '' === trim( $content ) ) {
			return self::envelope( $base, 'failed', 'invalid_param', 'invalid_param', false, array( 'error' => 'Nội dung tin nhắn trống.' ) );
		}
		if ( 'text' !== $content_type && empty( $attachments_in ) ) {
			return self::envelope( $base, 'failed', 'invalid_param', 'invalid_param', false, array( 'error' => 'Tin nhắn media cần ít nhất một tệp đính kèm.' ) );
		}

		if ( ! empty( $attachments_in ) && 'inbox_capability' === $owner_source ) {
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5.7 — media ownership cannot be verified without a human owner, so a capability-anchored send stays text-only.
			return self::envelope( $base, 'failed', 'permission_denied', 'permission_denied', false, array( 'error' => 'Gửi theo capability của inbox không kèm được tệp đính kèm; cần owner cụ thể.' ) );
		}

		// Attachment policy runs before the provider so a rejected file costs zero attempts.
		$attachments = self::validate_attachments( $attachments_in, $effective_user_id );
		if ( empty( $attachments['ok'] ) ) {
			$base['attachment'] = $attachments['attachment'];
			return self::envelope(
				$base,
				'failed',
				(string) $attachments['code'],
				(string) $attachments['reason_bucket'],
				false,
				array( 'error' => (string) $attachments['error'] )
			);
		}
		$base['attachment'] = $attachments['attachment'];

		$adapter = BizCity_CRM_Channel_Registry::get( $channel );
		if ( ! $adapter instanceof BizCity_CRM_Channel_Adapter ) {
			return self::envelope( $base, 'failed', 'channel_adapter_unavailable', 'bridge_degraded', false, array( 'error' => 'Channel chưa có adapter runtime được đăng ký.' ) );
		}

		$mutation = array(
			'action'          => self::ACTION,
			'resource'        => array( 'scope' => 'conversation:' . $conversation_id ),
			'idempotency_key' => $idempotency_key,
			'trace_id'        => $trace_id,
		);
		$context = array(
			'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'user_id' => $effective_user_id,
		);
		$claim        = BizCity_Twin_Mutation_Store::begin( $mutation, $context, $request_hash );
		$claim_status = (string) ( $claim['status'] ?? '' );
		$claim_key    = (string) ( $claim['key'] ?? '' );

		if ( 'conflict' === $claim_status ) {
			// Same key, different payload: never send, never mutate CRM.
			return self::envelope( $base, 'failed', 'conflict', 'permission_denied', false, array( 'error' => 'Idempotency key đã dùng cho một payload khác.' ) );
		}
		if ( 'replay' === $claim_status ) {
			$stored = isset( $claim['response'] ) && is_array( $claim['response'] ) ? $claim['response'] : array();
			if ( ! empty( $stored ) ) {
				$stored['replayed'] = true;
				return $stored;
			}
			return self::envelope( $base, 'queued', 'idempotency_replayed', '', false, array( 'replayed' => true ) );
		}
		if ( 'new' !== $claim_status ) {
			// Pending claim from another worker: do not risk a duplicate provider attempt.
			return self::envelope( $base, 'queued', 'mutation_in_progress', '', true, array( 'error' => 'Thao tác gửi đang được xử lý.' ) );
		}

		// R-CH-FILE-LOG: file evidence before any DB or provider call.
		self::log( $channel, 'info', 'crm_outbound_attempt', 'Outbound dispatch started.', array(
			'conversation_id' => $conversation_id,
			'inbox_id'        => (int) $inbox['id'],
			'content_type'    => $content_type,
			'idempotency'     => substr( md5( $idempotency_key ), 0, 8 ),
		) );

		$message_id = (int) BizCity_CRM_Repository::insert_message( array(
			'conversation_id' => $conversation_id,
			'inbox_id'        => (int) $inbox['id'],
			'content'         => $content,
			'content_type'    => $content_type,
			'message_type'    => 'outgoing',
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5.7 — a system send is stored as a bot message so the thread does not attribute it to a person.
			'sender_type'     => 'system' === $actor ? 'bot' : 'user',
			'sender_id'       => $effective_user_id,
			'status'          => 'queued',
			'attachments'     => $attachments['rows'],
			'trace_id'        => $trace_id,
			// `responder_kind` is VARCHAR(10) in CRM schema; truncate so a long slug cannot silently drop the row under STRICT_TRANS_TABLES.
			'responder_kind'  => self::responder_kind( $request, $system_source ),
			'parent_event_uuid' => isset( $request['parent_event_uuid'] ) ? $request['parent_event_uuid'] : null,
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5.7c — keep macro provenance that the campaign path used to write directly.
			'macro_id'        => isset( $request['macro_id'] ) ? (int) $request['macro_id'] : null,
		) );
		if ( $message_id <= 0 ) {
			BizCity_Twin_Mutation_Store::release( $claim_key );
			return self::envelope( $base, 'failed', 'crm_message_write_failed', 'http_error', true, array( 'error' => 'Không ghi được message CRM cho outbound.' ) );
		}
		$base['message_id'] = $message_id;

		$message_payload = array(
			'content'      => $content,
			'content_type' => $content_type,
			'attachments'  => $attachments['rows'],
		);
		try {
			$raw = $adapter->send( $conversation, $message_payload );
		} catch ( Throwable $e ) {
			$raw = new WP_Error( 'provider_exception', $e->getMessage() );
		}
		$normalized = BizCity_CRM_Channel_Contract::normalize_send_result( $channel, $raw );

		$job_id             = (string) ( $normalized['job_id'] ?? $normalized['task_id'] ?? '' );
		$external_source_id = isset( $normalized['external_source_id'] ) ? (string) $normalized['external_source_id'] : '';
		$retryable          = ! empty( $normalized['retryable'] );
		$reason_bucket      = self::map_reason_bucket( $normalized );

		if ( ! empty( $normalized['success'] ) ) {
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5.4 — distinguish a synchronous provider send from an async job acceptance.
			//
			// A provider that returns its own message id with no queue handle has
			// already accepted and sent the message (Messenger/Zalo OA style), so
			// `sent` is truthful. A bridge that only returns a job/task id has
			// merely queued it (Zalo Personal style); that must stay `queued`
			// until a delivery callback confirms it. Marking a job id as `sent`
			// is exactly the defect PHASE-0.41 §5.5B point 10 describes.
			$synchronous_send = '' !== $external_source_id && '' === $job_id;
			$accepted_outcome = $synchronous_send ? 'sent' : 'queued';
			BizCity_CRM_Repository::update_message_delivery( $message_id, array(
				'outcome'  => $accepted_outcome,
				'platform' => $channel,
				'error'    => '',
			) );
			if ( '' !== $external_source_id ) {
				self::remember_external_id( $message_id, $external_source_id );
			}
			$envelope = self::envelope( $base, $accepted_outcome, (string) ( $normalized['code'] ?? 'accepted' ), '', false, array(
				'external_source_id' => $external_source_id,
				'job_id'             => $job_id,
				'attempts'           => 1,
				'delivery_mode'      => $synchronous_send ? 'provider_synchronous' : 'provider_queued',
			) );
			BizCity_Twin_Mutation_Store::complete( $claim_key, $request_hash, $envelope );
			self::record_evidence( $envelope, $effective_user_id, $trace_id );
			self::log( $channel, 'info', 'crm_outbound_queued', 'Provider accepted the outbound message.', array( 'message_id' => $message_id, 'job_ref' => '' !== $job_id ? substr( md5( $job_id ), 0, 8 ) : '' ) );
			return $envelope;
		}

		if ( $retryable ) {
			// Retryable transport failure: release the claim so a retry may proceed, keep the row queued.
			BizCity_CRM_Repository::update_message_delivery( $message_id, array(
				'outcome'  => 'queued',
				'platform' => $channel,
				'error'    => (string) ( $normalized['error'] ?? '' ),
			) );
			BizCity_Twin_Mutation_Store::release( $claim_key );
			$envelope = self::envelope( $base, 'queued', (string) ( $normalized['code'] ?? 'http_error' ), $reason_bucket, true, array(
				'error'    => (string) ( $normalized['error'] ?? '' ),
				'attempts' => 1,
			) );
			self::record_evidence( $envelope, $effective_user_id, $trace_id );
			self::log( $channel, 'warn', 'crm_outbound_retryable', 'Retryable provider failure; claim released.', array( 'message_id' => $message_id, 'reason' => $reason_bucket ) );
			return $envelope;
		}

		BizCity_CRM_Repository::update_message_delivery( $message_id, array(
			'outcome'  => 'failed',
			'platform' => $channel,
			'error'    => (string) ( $normalized['error'] ?? '' ),
		) );
		$envelope = self::envelope( $base, 'failed', (string) ( $normalized['code'] ?? 'channel_send_failed' ), $reason_bucket, false, array(
			'error'    => (string) ( $normalized['error'] ?? '' ),
			'attempts' => 1,
		) );
		BizCity_Twin_Mutation_Store::complete( $claim_key, $request_hash, $envelope );
		self::record_evidence( $envelope, $effective_user_id, $trace_id );
		self::log( $channel, 'error', 'crm_outbound_failed', 'Permanent provider failure.', array( 'message_id' => $message_id, 'reason' => $reason_bucket ) );
		return $envelope;
	}

	/**
	 * Apply one provider/bridge delivery callback to an existing outbound message.
	 *
	 * A callback never creates a message and never moves the ladder backwards.
	 *
	 * @param array $callback Callback payload: message_id, outcome,
	 *                        external_source_id, platform, error.
	 * @return array Normalized envelope with `applied` and `previous_outcome`.
	 */
	public static function confirm( array $callback ): array {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5 — monotonic delivery ladder; a late or duplicate callback is ignored, not replayed as a new message.
		$message_id = isset( $callback['message_id'] ) ? (int) $callback['message_id'] : 0;
		$outcome    = sanitize_key( (string) ( $callback['outcome'] ?? '' ) );
		$external   = isset( $callback['external_source_id'] ) ? (string) $callback['external_source_id'] : '';
		$base       = array( 'message_id' => $message_id );

		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return self::envelope( $base, 'failed', 'module_not_loaded', 'module_not_loaded', false, array( 'applied' => false ) );
		}
		if ( $message_id <= 0 || ! in_array( $outcome, array( 'sent', 'delivered', 'failed' ), true ) ) {
			return self::envelope( $base, 'failed', 'invalid_param', 'invalid_param', false, array( 'applied' => false ) );
		}
		$row = BizCity_CRM_Repository::get_message( $message_id );
		if ( ! is_array( $row ) ) {
			return self::envelope( $base, 'failed', 'not_found', 'not_found', false, array( 'applied' => false ) );
		}
		if ( 'outgoing' !== (string) ( $row['message_type'] ?? '' ) ) {
			return self::envelope( $base, 'failed', 'invalid_param', 'invalid_param', false, array( 'applied' => false, 'error' => 'Callback chỉ áp dụng cho message outgoing.' ) );
		}

		$previous      = self::current_outcome( $row );
		$previous_rank = self::outcome_rank( $previous );
		$next_rank     = self::outcome_rank( $outcome );
		$allowed       = 'failed' === $outcome ? ( $previous_rank <= 1 ) : ( $next_rank > $previous_rank );
		if ( ! $allowed ) {
			$base['channel_code'] = (string) ( $row['channel_code'] ?? '' );
			return self::envelope(
				$base,
				$previous,
				'ignored_regression',
				'',
				false,
				array( 'applied' => false, 'previous_outcome' => $previous )
			);
		}

		$existing_external = (string) ( $row['external_source_id'] ?? '' );
		if ( '' !== $external && '' === $existing_external ) {
			self::remember_external_id( $message_id, $external );
			$existing_external = $external;
		}

		BizCity_CRM_Repository::update_message_delivery( $message_id, array(
			'outcome'  => $outcome,
			'platform' => (string) ( $callback['platform'] ?? '' ),
			'error'    => (string) ( $callback['error'] ?? '' ),
		) );

		return self::envelope( $base, $outcome, 'delivery_updated', 'failed' === $outcome ? self::map_reason_bucket( $callback ) : '', false, array(
			'applied'            => true,
			'previous_outcome'   => $previous,
			'external_source_id' => $existing_external,
		) );
	}

	/** Ladder rank; unknown outcomes are treated as `failed`. */
	public static function outcome_rank( string $outcome ): int {
		$outcome = sanitize_key( $outcome );
		return isset( self::OUTCOME_RANK[ $outcome ] ) ? (int) self::OUTCOME_RANK[ $outcome ] : 0;
	}

	/** Map a normalized provider result onto the R-CRON-META reason vocabulary. */
	public static function map_reason_bucket( array $normalized ): string {
		$code  = strtolower( (string) ( $normalized['code'] ?? '' ) );
		$error = strtolower( (string) ( $normalized['error'] ?? '' ) );
		$probe = $code . ' ' . $error;
		$map   = array(
			'token_invalid'          => array( 'token', 'oauth', '(#190)' ),
			'permission_denied'      => array( 'permission', 'not authorized', '(#10)', 'forbidden' ),
			'rate_limited'           => array( 'rate', 'throttl', 'too many requests', '429' ),
			'timeout'                => array( 'timeout', 'timed out' ),
			'session_expired'        => array( 'session', 'logged out', 'relogin', 'qr' ),
			'bridge_degraded'        => array( 'bridge', 'sidecar', 'degraded', 'unavailable' ),
			'attachment_too_large'   => array( 'too large', 'file size', 'payload too large' ),
			'attachment_mime_denied' => array( 'mime', 'unsupported type', 'file type' ),
			'provider_rejected'      => array( 'rejected', 'invalid recipient', 'blocked' ),
		);
		foreach ( $map as $bucket => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $probe, $needle ) ) {
					return $bucket;
				}
			}
		}
		return 'http_error';
	}

	/**
	 * Resolve the notification account strictly from the conversation's inbox.
	 *
	 * The caller cannot choose the notification account; W8.5 precedence stays
	 * with the Scheduler resolver for scheduled events.
	 *
	 * @param array $conversation Conversation row.
	 * @param array $inbox        Inbox row owning the conversation.
	 * @return array Bounded notify descriptor.
	 */
	public static function notify_target( array $conversation, array $inbox ): array {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5.5 — notification account is server-derived from the conversation, never posted.
		return array(
			'inbox_id'        => (int) ( $inbox['id'] ?? 0 ),
			'channel'         => sanitize_key( (string) ( $inbox['channel_type'] ?? '' ) ),
			'ref'             => (string) ( $inbox['channel_ref_id'] ?? '' ),
			'conversation_id' => (int) ( $conversation['id'] ?? 0 ),
			'resolver'        => class_exists( 'BizCity_Scheduler_Notify_Target_Resolver' ) ? 'scheduler_precedence_available' : 'conversation_inbox_only',
		);
	}

	/**
	 * Resolve the stored responder slug for an outbound message.
	 *
	 * @param array  $request       Dispatch request.
	 * @param string $system_source Declared system source.
	 * @return string|null
	 */
	private static function responder_kind( array $request, string $system_source ) {
		$kind = sanitize_key( (string) ( $request['responder_kind'] ?? '' ) );
		if ( '' === $kind ) {
			$kind = $system_source;
		}
		if ( '' === $kind ) {
			return null;
		}
		return substr( $kind, 0, 10 );
	}

	/**
	 * Resolve the authorization anchor for a system caller with no explicit owner.
	 *
	 * Order: conversation assignee -> inbox default assignee -> inbox capability.
	 * The first two keep human owner continuity (R-TWEB-17). The last one is the
	 * honest description of an inbound-triggered bot reply: it is owned by the
	 * tenant inbox, not by a person, and it is recorded as such so an audit can
	 * tell the two apart instead of seeing a fabricated user.
	 *
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60H D-H2/D-H2b — a fourth outcome, `system_owner`, sits between
	 * the human anchors and the capability fallback. It exists ONLY for Bot Studio media:
	 *  - `system_source` must be exactly `ai_autoreply` (kg_reply / automation / campaign / … keep the old
	 *    behaviour: capability-anchored, text-only);
	 *  - the send must carry attachments (a plain text reply keeps `inbox_capability`, byte for byte);
	 *  - the inbox must have an ACTIVE, auto-replying bot binding (see bot_media_system_owner()).
	 *
	 * @param array  $conversation    Conversation row.
	 * @param array  $inbox           Inbox row.
	 * @param string $system_source   Registered SYSTEM_SOURCES value of the caller ('' when unknown).
	 * @param bool   $has_attachments Whether the send carries attachments.
	 * @return array{user_id:int,owner_source:string}
	 */
	private static function resolve_system_anchor( array $conversation, array $inbox, string $system_source = '', bool $has_attachments = false ): array {
		$assignee = (int) ( $conversation['assignee_id'] ?? 0 );
		if ( $assignee > 0 && BizCity_CRM_Inbox_Access::can_view_conversation( (int) $conversation['id'], $assignee ) ) {
			return array( 'user_id' => $assignee, 'owner_source' => 'conversation_assignee' );
		}
		$default_assignee = (int) ( $inbox['default_assignee_id'] ?? 0 );
		if ( $default_assignee > 0 && BizCity_CRM_Inbox_Access::can_view_conversation( (int) $conversation['id'], $default_assignee ) ) {
			return array( 'user_id' => $default_assignee, 'owner_source' => 'inbox_default_assignee' );
		}
		if ( 'ai_autoreply' === $system_source && $has_attachments ) {
			$system_owner = self::bot_media_system_owner( $inbox );
			if ( $system_owner > 0 ) {
				return array( 'user_id' => $system_owner, 'owner_source' => 'system_owner' );
			}
		}
		return array( 'user_id' => 0, 'owner_source' => 'inbox_capability' );
	}

	/**
	 * The system owner id to use for a bot media send on this inbox, or 0 (⇒ stay capability-anchored / text-only).
	 *
	 * Fail-closed on every input: no system owner user, an owner that is (or became) privileged, an inbox that is
	 * not a Bot Studio channel, or no ACTIVE binding with a Guru and auto-reply on. The binding is what makes
	 * this a legitimate bot conversation — the same fact the turn runner itself acted on to reach this send.
	 */
	private static function bot_media_system_owner( array $inbox ): int {
		if ( ! class_exists( 'BizCity_CRM_System_Owner' ) || ! self::has_active_bot_binding( $inbox ) ) {
			return 0;
		}
		return BizCity_CRM_System_Owner::resolve( false );
	}

	/** True when the inbox is a Bot Studio channel with an ACTIVE binding that has a Guru and auto-reply on. */
	private static function has_active_bot_binding( array $inbox ): bool {
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return false;
		}
		// Bot Studio runs on Zalo Cá nhân only today; the inbox's channel_ref_id IS the binding's account_id.
		if ( 'zalo_personal' !== sanitize_key( (string) ( $inbox['channel_type'] ?? '' ) ) ) {
			return false;
		}
		$account_id = trim( (string) ( $inbox['channel_ref_id'] ?? '' ) );
		if ( '' === $account_id ) {
			return false;
		}
		$binding = BizCity_Channel_Binding::resolve( 'ZALO_PERSONAL', $account_id );
		return is_array( $binding ) && (int) ( $binding['character_id'] ?? 0 ) > 0 && 1 === (int) ( $binding['auto_reply'] ?? 0 );
	}

	/**
	 * The owner a Bot Studio media send on this conversation WOULD be authorised as — the single source of
	 * truth the bot's tools call BEFORE spending money on generating a file, so "the tool thinks it has an
	 * owner" and "the dispatcher accepts it" can never drift apart again (they had: the tool used to mirror
	 * the assignee lookup without the scope check the dispatcher applies).
	 *
	 * @param int  $conversation_id
	 * @param bool $create_system_owner When true, the powerless system owner user is created on first use — but
	 *                                  only for a conversation that really belongs to an active bot binding.
	 *                                  The bot's tool passes true; nothing on the send path ever creates users.
	 * @return array{user_id:int,owner_source:string} `user_id` 0 ⇒ this conversation cannot carry bot media.
	 */
	public static function resolve_bot_media_owner( int $conversation_id, bool $create_system_owner = false ): array {
		$none = array( 'user_id' => 0, 'owner_source' => 'inbox_capability' );
		if ( $conversation_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_CRM_Inbox_Access' ) ) {
			return $none;
		}
		$conversation = BizCity_CRM_Repository::get_conversation( $conversation_id );
		if ( ! is_array( $conversation ) ) {
			return $none;
		}
		$inbox = BizCity_CRM_Repository::get_inbox( (int) ( $conversation['inbox_id'] ?? 0 ) );
		if ( ! is_array( $inbox ) ) {
			return $none;
		}
		if ( $create_system_owner && class_exists( 'BizCity_CRM_System_Owner' ) && self::has_active_bot_binding( $inbox ) ) {
			BizCity_CRM_System_Owner::resolve( true );
		}
		$anchor = self::resolve_system_anchor( $conversation, $inbox, 'ai_autoreply', true );
		return (int) $anchor['user_id'] > 0 ? $anchor : $none;
	}

	/** Allowed outbound MIME types; filterable but never empty. */
	public static function allowed_mimes(): array {
		$default  = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/csv' );
		$filtered = apply_filters( 'bizcity_crm_outbound_allowed_mimes', $default );
		return is_array( $filtered ) && ! empty( $filtered ) ? array_values( array_unique( array_map( 'strval', $filtered ) ) ) : $default;
	}

	/** Maximum outbound attachment size; reuses the shipped Twin GPT upload ceiling. */
	public static function max_attachment_bytes( int $user_id ): int {
		$max = (int) apply_filters( 'bizcity_twinweb_attachment_max_bytes', 10 * 1024 * 1024, $user_id );
		return $max > 0 ? $max : 10 * 1024 * 1024;
	}

	/**
	 * Validate ownership, MIME and size for outbound attachments.
	 *
	 * @param array $attachments Requested attachments (ids or descriptors).
	 * @param int   $user_id     Authorized sender.
	 * @return array Validation result with rows and a bounded summary.
	 */
	private static function validate_attachments( array $attachments, int $user_id ): array {
		$rows    = array();
		$summary = array( 'count' => 0, 'mime' => '', 'bytes' => 0, 'owner_ok' => true );
		foreach ( $attachments as $attachment ) {
			$attachment_id = 0;
			if ( is_int( $attachment ) || ( is_string( $attachment ) && ctype_digit( $attachment ) ) ) {
				$attachment_id = (int) $attachment;
			} elseif ( is_array( $attachment ) ) {
				$attachment_id = (int) ( $attachment['attachment_id'] ?? $attachment['id'] ?? 0 );
			}
			if ( $attachment_id <= 0 ) {
				return self::attachment_failure( 'invalid_param', 'invalid_param', 'Tệp đính kèm phải tham chiếu media đã tải lên.', $summary );
			}
			$post = get_post( $attachment_id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				return self::attachment_failure( 'not_found', 'not_found', 'Không tìm thấy tệp đính kèm.', $summary );
			}
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5 — ownership is checked before MIME/size so a foreign file never reaches the provider.
			if ( (int) $post->post_author !== $user_id ) {
				$summary['owner_ok'] = false;
				return self::attachment_failure( 'permission_denied', 'permission_denied', 'Tệp đính kèm không thuộc quyền sở hữu của bạn.', $summary );
			}
			$mime = (string) get_post_mime_type( $attachment_id );
			if ( ! in_array( $mime, self::allowed_mimes(), true ) ) {
				$summary['mime'] = $mime;
				return self::attachment_failure( 'attachment_mime_denied', 'attachment_mime_denied', 'Định dạng tệp chưa được hỗ trợ cho outbound.', $summary );
			}
			$path  = get_attached_file( $attachment_id );
			$bytes = ( $path && file_exists( $path ) ) ? (int) filesize( $path ) : 0;
			if ( $bytes > self::max_attachment_bytes( $user_id ) ) {
				$summary['mime']  = $mime;
				$summary['bytes'] = $bytes;
				return self::attachment_failure( 'attachment_too_large', 'attachment_too_large', 'Tệp vượt quá dung lượng cho phép.', $summary );
			}
			$summary['count']++;
			$summary['mime']  = $mime;
			$summary['bytes'] = $bytes;
			$rows[] = array(
				'file_type' => 0 === strpos( $mime, 'image/' ) ? 'image' : 'file',
				'data_url'  => (string) wp_get_attachment_url( $attachment_id ),
				'thumb_url' => null,
				'meta'      => array( 'attachment_id' => $attachment_id, 'mime' => $mime, 'bytes' => $bytes ),
			);
		}
		return array( 'ok' => true, 'rows' => $rows, 'attachment' => $summary, 'code' => '', 'reason_bucket' => '', 'error' => '' );
	}

	/**
	 * Build a fail-closed attachment validation result.
	 *
	 * @param string $code    Error code.
	 * @param string $bucket  Reason bucket.
	 * @param string $error   Vietnamese message.
	 * @param array  $summary Bounded attachment summary.
	 * @return array
	 */
	private static function attachment_failure( string $code, string $bucket, string $error, array $summary ): array {
		return array( 'ok' => false, 'rows' => array(), 'attachment' => $summary, 'code' => $code, 'reason_bucket' => $bucket, 'error' => $error );
	}

	/**
	 * Read the current delivery outcome from the message row.
	 *
	 * @param array $row Message row.
	 * @return string
	 */
	private static function current_outcome( array $row ): string {
		$payload = ! empty( $row['payload_json'] ) ? json_decode( (string) $row['payload_json'], true ) : array();
		if ( is_array( $payload ) && ! empty( $payload['delivery']['outcome'] ) ) {
			return sanitize_key( (string) $payload['delivery']['outcome'] );
		}
		$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
		if ( 'sent' === $status ) {
			return 'sent';
		}
		if ( 'failed' === $status ) {
			return 'failed';
		}
		return 'queued';
	}

	/**
	 * Persist a provider identifier exactly once through the repository owner.
	 *
	 * @param int    $message_id         CRM message id.
	 * @param string $external_source_id Provider identifier.
	 * @return void
	 */
	private static function remember_external_id( int $message_id, string $external_source_id ): void {
		if ( method_exists( 'BizCity_CRM_Repository', 'set_message_external_source_id' ) ) {
			BizCity_CRM_Repository::set_message_external_source_id( $message_id, $external_source_id );
		}
	}

	/**
	 * Metadata-only action evidence; never the message body.
	 *
	 * @param array  $envelope Outbound envelope.
	 * @param int    $user_id  Authorized sender.
	 * @param string $trace_id Correlation id.
	 * @return void
	 */
	private static function record_evidence( array $envelope, int $user_id, string $trace_id ): void {
		if ( ! class_exists( 'BizCity_Twin_Action_Evidence' ) ) {
			return;
		}
		$evidence = BizCity_Twin_Action_Evidence::build(
			array(
				'action'          => self::ACTION,
				'resource'        => array( 'scope' => 'conversation:' . (int) ( $envelope['conversation_id'] ?? 0 ) ),
				'idempotency_key' => (string) ( $envelope['idempotency_key'] ?? '' ),
				'trace_id'        => $trace_id,
			),
			array( 'outcome' => 'none' ),
			array( 'outcome' => (string) ( $envelope['outcome'] ?? '' ), 'message_id' => (int) ( $envelope['message_id'] ?? 0 ) ),
			array( 'user_id' => $user_id, 'contract' => self::CONTRACT_ID )
		);
		BizCity_Twin_Action_Evidence::record( $evidence );
	}

	/**
	 * Operational file evidence; redacted context only.
	 *
	 * @param string $channel Channel code.
	 * @param string $level   Log level.
	 * @param string $event   Event key.
	 * @param string $message Message.
	 * @param array  $ctx     Redacted context.
	 * @return void
	 */
	private static function log( string $channel, string $level, string $event, string $message, array $ctx ): void {
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) ) {
			return;
		}
		$known        = array( 'facebook', 'messenger', 'zalo_oa', 'zalo_personal', 'zalo_bot', 'webchat', 'telegram', 'email' );
		$file_channel = in_array( $channel, $known, true ) ? $channel : 'channel_gateway';
		BizCity_Channel_File_Logger::write( $file_channel, $level, $event, $message, $ctx );
	}

	/**
	 * Build the single normalized outbound envelope shape.
	 *
	 * @param array  $base          Base descriptor collected so far.
	 * @param string $outcome       queued|sent|delivered|failed.
	 * @param string $code          Machine code.
	 * @param string $reason_bucket R-CRON-META reason bucket.
	 * @param bool   $retryable     Whether a retry may succeed.
	 * @param array  $extra         Extra envelope fields.
	 * @return array
	 */
	private static function envelope( array $base, string $outcome, string $code, string $reason_bucket, bool $retryable, array $extra = array() ): array {
		$envelope = array(
			'contract'           => self::CONTRACT_ID,
			'contract_version'   => self::VERSION,
			'outcome'            => sanitize_key( $outcome ),
			'code'               => sanitize_key( $code ),
			'retryable'          => $retryable,
			'reason_bucket'      => sanitize_key( $reason_bucket ),
			'channel_code'       => (string) ( $base['channel_code'] ?? '' ),
			'conversation_id'    => (int) ( $base['conversation_id'] ?? 0 ),
			'message_id'         => (int) ( $base['message_id'] ?? 0 ),
			'external_source_id' => '',
			'job_id'             => '',
			'attempts'           => 0,
			'idempotency_key'    => (string) ( $base['idempotency_key'] ?? '' ),
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5.7 — owner continuity travels with every envelope so an audit can tell who a system send acted for.
			'actor'              => (string) ( $base['actor'] ?? 'user' ),
			'on_behalf_of_user_id' => (int) ( $base['on_behalf_of_user_id'] ?? 0 ),
			'system_source'      => (string) ( $base['system_source'] ?? '' ),
			'owner_source'       => (string) ( $base['owner_source'] ?? '' ),
			'replayed'           => false,
			'attachment'         => isset( $base['attachment'] ) && is_array( $base['attachment'] ) ? $base['attachment'] : array( 'count' => 0, 'mime' => '', 'bytes' => 0, 'owner_ok' => true ),
			'notify'             => isset( $base['notify'] ) && is_array( $base['notify'] ) ? $base['notify'] : array(),
			'error'              => '',
		);
		foreach ( $extra as $key => $value ) {
			$envelope[ $key ] = $value;
		}
		return $envelope;
	}
}
