<?php
/**
 * BizCity CRM — AI Auto-Reply Listener (Wave 0.35.G+)
 *
 * Hooks `crm_message_received` so any inbound message into a conversation
 * whose inbox/conversation has a notebook attached is auto-answered by
 * `BizCity_CRM_AI_Replier::reply()` — instead of the legacy
 * `bizgpt_chatbot_run_guest_flows` raw-LLM path.
 *
 * Side effects:
 *   - When eligible, suppresses the legacy reply by returning true on the
 *     `bizcity_facebook_workflow_handle_message` filter.
 *   - Lays a 30-second transient lock per inbound message to avoid replying
 *     to the same event twice. A conversation-wide lock would incorrectly
 *     drop a newer customer message while an older reply is still running.
 *   - Emits dense `error_log()` lines tagged `[bizcity-crm-autoreply]` so the
 *     pipeline is debuggable without extra tooling.
 *
 * Disable globally:
 *   add_filter( 'bizcity_crm_ai_autoreply_enabled', '__return_false' );
 *
 * Disable per-event (e.g. specific inbox / contact):
 *   add_filter( 'bizcity_crm_ai_autoreply_should_run',
 *       function( $yes, $payload ) { return $yes; }, 10, 2 );
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_AI_Autoreply_Listener {

	const LOCK_TTL = 30; // seconds

	/** @var string Channel currently being handled in this request. */
	private static $current_channel = '';

	public static function register(): void {
		// CRM event emitted by Repository::insert_message after every insert.
		add_action( 'bizcity_crm_event_crm_message_received', array( __CLASS__, 'on_message_received' ), 10, 1 );

		// Suppress legacy fb-bot AI reply when CRM is going to handle.
		add_filter( 'bizcity_facebook_workflow_handle_message', array( __CLASS__, 'maybe_suppress_legacy' ), 10, 3 );
	}

	/**
	 * Listener for `crm_message_received`. Synchronously fires AI Replier when
	 * eligible. Catches all throwables → error_log only.
	 */
	public static function on_message_received( $payload ): void {
		try {
			// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — P11: trace autoreply entry.
			$p11_msg = 'P11 autoreply_listener sender_type=' . ( $payload['sender_type'] ?? '?' ) . ' conv=' . ( $payload['conversation_id'] ?? 0 ) . ' inbox=' . ( $payload['inbox_id'] ?? '?' );
			error_log( '[bizcity-crm-trace] ' . $p11_msg );
			// [2026-08-01 Johnny Chu] R-CH-FILE-LOG — channel not resolved yet at this step; use shared gateway bucket.
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY, BizCity_Channel_File_Logger::LEVEL_DEBUG, 'crm_trace_p11', $p11_msg, array( 'conv_id' => (int) ( $payload['conversation_id'] ?? 0 ) ) );
			}
			if ( ! is_array( $payload ) ) { return; }
			if ( ( $payload['sender_type'] ?? '' ) !== 'contact' ) {
				self::log( 'skip: sender_type is not contact', $payload );
				return;
			}
			$conv_id = (int) ( $payload['conversation_id'] ?? 0 );
			$msg_id  = (int) ( $payload['message_id']      ?? 0 );
			if ( ! $conv_id ) {
				self::log( 'skip: missing conversation_id' );
				return;
			}
			// [2026-08-02 Johnny Chu] PHASE-ZALO-VISION — media-only CRM rows have no text prompt; leave them to the automation/pending-vision pipeline.
			if ( $msg_id > 0 ) {
				$current_message = BizCity_CRM_Repository::get_message( $msg_id );
				$current_content = trim( (string) ( $current_message['content'] ?? '' ) );
				$current_type    = strtolower( (string) ( $current_message['content_type'] ?? '' ) );
				$current_attachments = $current_message['attachments'] ?? '';
				if ( is_string( $current_attachments ) ) {
					$current_attachments = json_decode( $current_attachments, true );
				}
				$has_attachment = is_array( $current_attachments ) && ! empty( $current_attachments );
				// [2026-08-02 Johnny Chu] PHASE-ZALO-VISION — never invoke a text
				// replier for an attachment-only CRM row, even if an older adapter
				// stored content_type=text.
				if ( $current_content === '' && ( $has_attachment || in_array( $current_type, array( 'image', 'file', 'audio', 'voice' ), true ) ) ) {
					self::log( sprintf( 'skip conv#%d msg#%d: media_only content_type=%s — handled by attachment/vision pipeline', $conv_id, $msg_id, $current_type ) );
					return;
				}
			}

			if ( ! self::is_globally_enabled() ) {
				self::log( "skip conv#{$conv_id}: globally disabled" );
				return;
			}

			$conv = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) {
				self::log( "skip conv#{$conv_id}: conversation_not_found" );
				return;
			}

			$inbox = BizCity_CRM_Repository::get_inbox( (int) $conv['inbox_id'] );
			self::$current_channel = (string) ( $inbox['channel_type'] ?? '' );

			// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — P11b: trace inbox channel_type + ref_id for Resolver debug.
			$p11b_channel_type = (string) ( $inbox['channel_type'] ?? '' );
			error_log( '[bizcity-crm-trace] P11b inbox_channel_type=' . ( $p11b_channel_type !== '' ? $p11b_channel_type : 'NULL' ) . ' channel_ref_id=' . ( $inbox['channel_ref_id'] ?? 'NULL' ) );
			// [2026-08-02 Johnny Chu] R-ZONE — Zalo Bot/Telegram/TwinChat are Zone 2 command surfaces; workflow matcher owns their reply and CRM AI must not run a parallel TwinBrain response.
			if ( in_array( strtolower( $p11b_channel_type ), array( 'zalo', 'zalo_bot', 'telegram', 'twinchat_be' ), true ) ) {
				self::log( sprintf( 'skip conv#%d msg#%d: zone2_channel=%s — automation workflow owns reply', $conv_id, $msg_id, $p11b_channel_type ) );
				return;
			}
			// [2026-08-01 Johnny Chu] R-CH-FILE-LOG — channel now known; route into the matching per-channel JSONL file.
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write(
					self::map_channel_type( $p11b_channel_type ),
					BizCity_Channel_File_Logger::LEVEL_DEBUG,
					'crm_trace_p11b',
					'inbox_channel_type=' . ( $p11b_channel_type !== '' ? $p11b_channel_type : 'NULL' ) . ' channel_ref_id=' . ( $inbox['channel_ref_id'] ?? 'NULL' ),
					array( 'conv_id' => $conv_id, 'channel_ref_id' => (string) ( $inbox['channel_ref_id'] ?? '' ) )
				);
			}

			$inbox_settings = $inbox && $inbox['settings_json']
				? ( json_decode( (string) $inbox['settings_json'], true ) ?: array() )
				: array();

			// Twin Guru on Duty: resolve character + attached notebooks from binding.
			$guru_ctx = ( $inbox && class_exists( 'BizCity_CRM_Guru_Resolver' ) )
				? BizCity_CRM_Guru_Resolver::resolve_for_inbox( $inbox )
				: array( 'character_id' => 0, 'guru_uuid' => '', 'notebooks' => array() );
			// [2026-08-01 Johnny Chu] PHASE-0.39 GURU-BIND — keep CRM on the
			// Chat Gateway path when binding is absent; never fall into legacy flow tables.
			$resolved_character_id = (int) ( $guru_ctx['character_id'] ?? 0 );
			if ( $resolved_character_id <= 0 ) {
				$resolved_character_id = (int) ( $inbox_settings['default_character_id'] ?? 0 );
			}
			if ( $resolved_character_id <= 0 ) {
				$resolved_character_id = (int) ( $conv['character_id'] ?? 0 );
			}
			if ( $resolved_character_id <= 0 && in_array( strtolower( (string) ( $inbox['channel_type'] ?? '' ) ), array( 'facebook', 'messenger', 'zalo_oa' ), true ) ) {
				$resolved_character_id = self::resolve_default_character_id();
			}
			if ( $resolved_character_id > 0 ) {
				$guru_ctx['character_id'] = $resolved_character_id;
			}

			$notebook_id = (int) ( $conv['notebook_id']
				?? $inbox['default_notebook_id']
				?? ( $guru_ctx['notebooks'][0] ?? 0 ) );

			// ── Live re-binding guard (P0-Q1, 2026-05-26) ──────────────────────────
			// When the conversation has a sticky `notebook_id` that no longer matches
			// the currently bound Guru-on-Duty notebooks, re-pin to the live binding.
			// Symptom this fixes: admin re-points page→notebook in Guru-on-Duty UI
			// but old conversations keep replying from the stale notebook
			// (e.g. log showed `notebook#26 (eligible=[23])` → KG passages=0).
			// Filter `bizcity_crm_ai_autoreply_allow_stale_notebook` lets ops opt-out
			// of auto re-pin (e.g. when an admin has intentionally pinned a conv).
			$live_nbs = array_map( 'intval', (array) ( $guru_ctx['notebooks'] ?? array() ) );
			if (
				$notebook_id > 0
				&& ! empty( $live_nbs )
				&& ! in_array( $notebook_id, $live_nbs, true )
			) {
				$allow_stale = (bool) apply_filters(
					'bizcity_crm_ai_autoreply_allow_stale_notebook',
					false,
					$conv,
					$inbox,
					$guru_ctx
				);
				if ( ! $allow_stale ) {
					$new_nb  = (int) $live_nbs[0];
					$old_nb  = $notebook_id;
					global $wpdb;
					$tbl     = BizCity_CRM_DB_Installer_V2::tbl_conversations();
					$updated = $wpdb->update(
						$tbl,
						array( 'notebook_id' => $new_nb, 'updated_at' => current_time( 'mysql', true ) ),
						array( 'id' => $conv_id ),
						array( '%d', '%s' ),
						array( '%d' )
					);
					$notebook_id = $new_nb;
					self::log( sprintf(
						'nb_repinned conv#%d %d→%d (eligible=[%s], wpdb_updated=%s)',
						$conv_id, $old_nb, $new_nb,
						implode( ',', $live_nbs ),
						$updated === false ? 'ERR' : (string) $updated
					) );
				}
			}

			$autoreply_inbox = isset( $inbox_settings['ai_autoreply'] )
				? (bool) $inbox_settings['ai_autoreply']
				: true; // default ON when notebook attached

			if ( $notebook_id <= 0 ) {
				// [2026-06-29 Johnny Chu] HOTFIX — character bound with system_prompt + FAQ
				// MUST reply even without a notebook. Notebook makes answers richer (KG retrieval)
				// but is NOT required when character_id > 0 has system_prompt configured.
				// Skip ONLY when neither notebook NOR character is resolved.
				// [2026-08-01 Johnny Chu] PHASE-0.39 GURU-BIND — honor the same
				// inbox/conversation character fallback used by AI_Replier.
				$effective_character_id = (int) ( $guru_ctx['character_id'] ?? 0 );
				if ( $effective_character_id <= 0 ) {
					$effective_character_id = (int) ( $inbox_settings['default_character_id'] ?? 0 );
				}
				if ( $effective_character_id <= 0 ) {
					$effective_character_id = (int) ( $conv['character_id'] ?? 0 );
				}
				if ( $effective_character_id > 0 && (int) ( $guru_ctx['character_id'] ?? 0 ) <= 0 ) {
					// [2026-08-01 Johnny Chu] PHASE-0.39 GURU-BIND — forward the
					// fallback into the shared context passed to AI_Replier.
					$guru_ctx['character_id'] = $effective_character_id;
				}
				$has_character = $effective_character_id > 0;
				if ( ! $has_character ) {
					self::log( sprintf(
						'no_notebook_no_character conv#%d: use default Chat Gateway (conv.notebook_id=%s, inbox.default_notebook_id=%s, guru_char#%d guru_uuid=%s, kg_notebooks.character_id rows=[%s], attachments rows=[%s])',
						$conv_id,
						$conv['notebook_id'] ?? 'NULL',
						$inbox['default_notebook_id'] ?? 'NULL',
						(int) ( $guru_ctx['character_id'] ?? 0 ),
						$guru_ctx['guru_uuid'] ? substr( $guru_ctx['guru_uuid'], 0, 8 ) . '…' : '—',
						implode( ',', $guru_ctx['trace']['notebooks_by_character_id'] ?? array() ),
						implode( ',', $guru_ctx['trace']['notebooks_by_guru_uuid']    ?? array() )
					) );
				}
				self::log( sprintf(
					'no_notebook_but_character: conv#%d char#%d — reply with system_prompt only (no KG retrieval)',
					$conv_id, $effective_character_id
				) );
			}
			if ( ! $autoreply_inbox ) {
				self::log( "skip conv#{$conv_id}: inbox.settings.ai_autoreply=false" );
				return;
			}

			// P0-Q2 (2026-05-26) — Campaign Scenario Dispatcher claim check.
			// When a referral/campaign envelope hits this conversation, the
			// dispatcher claims the turn at priority 5 (before us @10) so we
			// must NOT send a second generic AI reply on top of the scenario
			// template/shortcode. The claim transient is TTL=90s.
			$claim = get_transient( 'bz_crm_scenario_claim_' . $conv_id );
			if ( $claim ) {
				$cid  = is_array( $claim ) ? (int) ( $claim['campaign_id'] ?? 0 ) : 0;
				self::log( "skip conv#{$conv_id}: scenario_claimed campaign#{$cid} (dispatcher handled this turn)" );
				delete_transient( 'bz_crm_scenario_claim_' . $conv_id );
				self::record_skip( $conv_id, array(
					'kind'        => 'scenario_claimed',
					'campaign_id' => $cid,
					'msg_id'      => $msg_id,
					'at'          => time(),
				) );
				return;
			}

			// Allow filter veto (e.g. business hours, blacklist).
			$should = (bool) apply_filters( 'bizcity_crm_ai_autoreply_should_run', true, $payload, $conv );
			if ( ! $should ) {
				self::log( "skip conv#{$conv_id}: vetoed by filter" );
				return;
			}

			// Channel ↔ role_scope guard: reject when this Guru is configured
			// for a different channel scope than the current inbox channel.
			// External templates only serve facebook/zalo/telegram; Internal
			// only serves crm/web/twinchat. `both` (or no template) passes.
			$channel = strtolower( (string) ( $inbox['channel_type'] ?? '' ) );
			// [2026-07-06 Johnny Chu] PHASE-0.39 GURU-BIND HOTFIX — normalize Zone-1 aliases so
			// template allowlist using "zalo" still matches channel_type="zalo_oa".
			$channel_norm = in_array( $channel, array( 'zalo_oa', 'zalo_personal' ), true ) ? 'zalo' : $channel;
			$char_id = (int) ( $guru_ctx['character_id'] ?? 0 );
			if ( $channel !== '' && $char_id > 0 && class_exists( 'BizCity_CRM_Service_Templates' ) ) {
				$svc = BizCity_CRM_Service_Templates::resolve_for_character( $char_id, $channel_norm );
				$role_scope    = (string) ( $svc['template']['role_scope']      ?? 'both' );
				$char_role     = (string) ( $svc['char_role']                   ?? 'both' );
				$allowed_chans = array_map( 'strtolower', (array) ( $svc['template']['allowed_channels'] ?? array() ) );
				$is_external   = in_array( $channel_norm, array( 'facebook', 'zalo', 'telegram' ), true );
				$is_internal   = in_array( $channel_norm, array( 'crm', 'web', 'twinchat' ),       true );

				$mismatch_reason = '';
				if ( $char_role === 'external' && $is_internal ) {
					$mismatch_reason = "char_role=external blocked on internal channel '{$channel}'";
				} elseif ( $char_role === 'internal' && $is_external ) {
					$mismatch_reason = "char_role=internal blocked on external channel '{$channel}'";
				} elseif ( $role_scope === 'external' && $is_internal ) {
					$mismatch_reason = "template role_scope=external blocked on internal channel '{$channel}'";
				} elseif ( $role_scope === 'internal' && $is_external ) {
					$mismatch_reason = "template role_scope=internal blocked on external channel '{$channel}'";
				} elseif ( ! empty( $allowed_chans ) && ! in_array( $channel_norm, $allowed_chans, true ) && $svc['slug'] !== 'none' ) {
					$mismatch_reason = "template '{$svc['slug']}' allowed_channels=[" . implode( ',', $allowed_chans ) . "] does not include '{$channel}' (normalized='{$channel_norm}')";
				}
				if ( $mismatch_reason !== '' ) {
					$override = (bool) apply_filters( 'bizcity_crm_ai_autoreply_role_mismatch_allow', false, $svc, $conv, $inbox );
					if ( $override ) {
						self::log( sprintf( 'role-mismatch ALLOWED (filter override) conv#%d: %s', $conv_id, $mismatch_reason ) );
					} else {
						self::log( sprintf( 'skip conv#%d ROLE-MISMATCH: %s (char#%d template=%s)', $conv_id, $mismatch_reason, $char_id, $svc['slug'] ) );
						self::record_skip( $conv_id, array(
							'kind'         => 'role_mismatch',
							'reason'       => $mismatch_reason,
							'character_id' => $char_id,
							'template'     => $svc['slug'],
							'channel'      => $channel,
							'msg_id'       => $msg_id,
							'at'           => time(),
						) );
						return;
					}
				}
			}

			// [2026-08-02 Johnny Chu] HOTFIX — dedupe the exact inbound message,
			// not the whole conversation, so a newer customer message is not
			// discarded while an older LLM request is still running.
			$lock_key = 'bz_crm_ai_lock_' . $conv_id . '_' . ( $msg_id > 0 ? $msg_id : 'latest' );
			if ( get_transient( $lock_key ) ) {
				self::log( "skip conv#{$conv_id} msg#{$msg_id}: lock_held (duplicate inbound within " . self::LOCK_TTL . 's)' );
				self::write_lifecycle_log( 'ai_replier_lock_held', array(
					'conversation_id' => $conv_id,
					'message_id'      => $msg_id,
					'channel'         => (string) ( $inbox['channel_type'] ?? '' ),
					'reason'          => 'duplicate_inbound',
				) );
				return;
			}
			set_transient( $lock_key, $msg_id ?: 1, self::LOCK_TTL );

			self::log( sprintf(
				'fire conv#%d msg#%d notebook#%d (eligible=[%s]) inbox#%d channel=%s guru_char#%d',
				$conv_id, $msg_id, $notebook_id,
				implode( ',', $guru_ctx['notebooks'] ?? array() ),
				(int) $conv['inbox_id'],
				(string) ( $inbox['channel_type'] ?? '' ),
				(int) ( $guru_ctx['character_id'] ?? 0 )
			) );
			self::write_lifecycle_log( 'ai_replier_started', array(
				'conversation_id' => $conv_id,
				'message_id'      => $msg_id,
				'channel'         => (string) ( $inbox['channel_type'] ?? '' ),
				'notebook_id'     => $notebook_id,
				'character_id'    => (int) ( $guru_ctx['character_id'] ?? 0 ),
			) );

			$t0 = microtime( true );
			// [2026-08-02 Johnny Chu] HOTFIX — bind the reply prompt to the exact CRM event message; never reread a stale conversation message.
			try {
				$result = BizCity_CRM_AI_Replier::reply( $conv_id, array(
					'notebook_id'  => $notebook_id,
					'character_id' => (int) ( $guru_ctx['character_id'] ?? 0 ) ?: null,
					'message_id'   => $msg_id,
				) );
			} finally {
				// [2026-08-02 Johnny Chu] HOTFIX — an exception must not leave the
				// inbound lock behind and block subsequent customer messages.
				delete_transient( $lock_key );
			}
			$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

			self::log( sprintf(
				'done conv#%d trace=%s reply_chars=%d sent=%s platform=%s err=%s lat=%dms',
				$conv_id,
				(string) ( $result['trace_uuid'] ?? '?' ),
				strlen( (string) ( $result['reply']    ?? '' ) ),
				! empty( $result['dispatch']['sent'] ) ? 'YES' : 'NO',
				(string) ( $result['dispatch']['platform'] ?? '?' ),
				(string) ( $result['dispatch']['error']    ?? '' ),
				$ms
			) );
			self::write_lifecycle_log( 'ai_replier_completed', array(
				'conversation_id' => $conv_id,
				'message_id'      => $msg_id,
				'channel'         => (string) ( $inbox['channel_type'] ?? '' ),
				'trace_uuid'      => (string) ( $result['trace_uuid'] ?? '' ),
				'sent'            => ! empty( $result['dispatch']['sent'] ),
				'duration_ms'     => $ms,
				'error_bucket'    => (string) ( $result['dispatch']['error'] ?? '' ) !== '' ? 'dispatch_failed' : '',
			) );
		} catch ( \Throwable $e ) {
			self::log( 'EXCEPTION: ' . get_class( $e ) . ' — ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
			self::log( 'TRACE: ' . str_replace( "\n", ' | ', $e->getTraceAsString() ) );
			self::write_lifecycle_log( 'ai_replier_exception', array(
				'conversation_id' => (int) ( $payload['conversation_id'] ?? 0 ),
				'message_id'      => (int) ( $payload['message_id'] ?? 0 ),
				'channel'         => self::$current_channel,
				'exception_class' => get_class( $e ),
				'error_bucket'    => 'replier_exception',
			) );
		}
	}

	private static function write_lifecycle_log( string $event, array $context ): void {
		// [2026-08-02 Johnny Chu] HOTFIX — record structured CRM reply lifecycle evidence without prompt or credential data.
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) ) {
			return;
		}
		$channel = self::map_channel_type( (string) ( $context['channel'] ?? self::$current_channel ) );
		BizCity_Channel_File_Logger::write(
			$channel,
			BizCity_Channel_File_Logger::LEVEL_INFO,
			$event,
			'CRM AI replier lifecycle event.',
			$context
		);
	}

	/**
	 * `bizcity_facebook_workflow_handle_message` filter — return true to skip
	 * the legacy `bizgpt_chatbot_run_guest_flows` AI path when CRM auto-reply
	 * will handle this inbound.
	 *
	 * Decision relies ONLY on inbox/notebook configuration (not on conversation
	 * existence) because this filter fires BEFORE the CRM ingestor inserts the
	 * conversation row.
	 *
	 * @param bool  $handled
	 * @param array $trigger_data { bot_id, page_id, user_id, message, ... }
	 * @param array $input_data
	 */
	public static function maybe_suppress_legacy( $handled, $trigger_data, $input_data ) {
		if ( $handled ) { return $handled; } // already handled by upstream filter

		try {
			if ( ! self::is_globally_enabled() ) { return $handled; }
			$page_id = (string) ( $trigger_data['page_id'] ?? '' );
			if ( $page_id === '' ) { return $handled; }
			self::$current_channel = 'facebook';

			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, channel_type, default_notebook_id, settings_json FROM $tbl
				 WHERE channel_type='facebook' AND channel_ref_id=%s LIMIT 1",
				$page_id
			), ARRAY_A );
			if ( ! $row ) {
				self::log( "legacy-passthrough: no_inbox for fb page={$page_id}" );
				return $handled;
			}
			// [2026-08-01 Johnny Chu] PHASE-0.39 GURU-BIND — read inbox character
			// before deciding whether the legacy flow may handle a no-notebook turn.
			$settings = $row['settings_json'] ? ( json_decode( (string) $row['settings_json'], true ) ?: array() ) : array();
			$nb = (int) ( $row['default_notebook_id'] ?? 0 );
			// Fallback to Guru-on-Duty's attached notebooks when inbox has no default.
			if ( $nb <= 0 && class_exists( 'BizCity_CRM_Guru_Resolver' ) ) {
				$guru = BizCity_CRM_Guru_Resolver::resolve_for_inbox( array(
					'channel_type'   => 'facebook',
					'channel_ref_id' => $page_id,
				) );
				if ( ! empty( $guru['notebooks'] ) ) {
					$nb = (int) $guru['notebooks'][0];
					self::log( sprintf(
						'guru-on-duty: inbox#%d fb page=%s char#%d guru=%s notebooks=[%s] → use #%d',
						(int) $row['id'], $page_id,
						(int) $guru['character_id'],
						$guru['guru_uuid'] ? substr( $guru['guru_uuid'], 0, 8 ) . '…' : '—',
						implode( ',', $guru['notebooks'] ),
						$nb
					) );
				}
			}
			if ( $nb <= 0 ) {
				// [2026-06-29 Johnny Chu] HOTFIX — character with system_prompt/FAQ should suppress
				// legacy reply even without a notebook. Check if a Guru character is bound.
				$guru_char = isset( $guru ) ? (int) ( $guru['character_id'] ?? 0 ) : 0;
				if ( $guru_char <= 0 ) {
					$guru_char = (int) ( $settings['default_character_id'] ?? 0 );
				}
				if ( $guru_char <= 0 ) {
					$guru_char = self::resolve_default_character_id();
				}
				if ( $guru_char <= 0 ) {
					// [2026-08-01 Johnny Chu] PHASE-0.39 GURU-BIND — CRM now owns
					// the generic Gateway fallback; do not enter legacy flow tables.
					self::log( "suppress legacy: inbox#{$row['id']} fb page={$page_id} has no notebook/character; CRM default Chat Gateway will handle" );
					$nb = 0;
				}
				self::log( sprintf(
					'suppress legacy (char-only): inbox#%d fb page=%s char#%d — system_prompt only (no KG)',
					(int) $row['id'], $page_id, $guru_char
				) );
				$nb = -1; // sentinel: character bound but no notebook — allow through
			}
			if ( isset( $settings['ai_autoreply'] ) && ! $settings['ai_autoreply'] ) {
				self::log( "legacy-passthrough: inbox#{$row['id']} ai_autoreply=false" );
				return $handled;
			}
			self::log( "suppress legacy: inbox#{$row['id']} fb page={$page_id} → CRM AI Replier will handle (notebook#{$nb})" );
			return true;
		} catch ( \Throwable $e ) {
			self::log( 'maybe_suppress_legacy threw: ' . $e->getMessage() );
			return $handled;
		}
	}

	private static function is_globally_enabled(): bool {
		$enabled = get_option( 'bizcity_crm_ai_autoreply_enabled', '1' ) !== '0';
		return (bool) apply_filters( 'bizcity_crm_ai_autoreply_enabled', $enabled );
	}

	/**
	 * [2026-08-01 Johnny Chu] R-CH-FILE-LOG — dual-write autoreply decisions into
	 * the correct per-channel JSONL evidence file, using `channel` in $ctx when the
	 * caller knows the inbox channel_type; falls back to the shared channel_gateway
	 * bucket for early-pipeline steps where the channel isn't resolved yet.
	 */
	private static function log( string $msg, array $ctx = array() ): void {
		$line = '[bizcity-crm-autoreply] ' . $msg;
		if ( $ctx ) {
			$line .= ' ' . wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		error_log( $line );

		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) ) {
			return;
		}
		$channel = self::map_channel_type( (string) ( $ctx['channel'] ?? self::$current_channel ) );
		BizCity_Channel_File_Logger::write( $channel, BizCity_Channel_File_Logger::LEVEL_INFO, 'autoreply_decision', $msg, $ctx );
	}

	/**
	 * Resolve the active character used by the existing Chat Gateway fallbacks.
	 */
	private static function resolve_default_character_id(): int {
		$character_id = (int) get_option( 'bizcity_webchat_default_character_id', 0 );
		if ( $character_id <= 0 ) {
			$bot_options = get_option( 'pmfacebook_options', array() );
			$character_id = (int) ( $bot_options['default_character_id'] ?? 0 );
		}
		if ( $character_id <= 0 && class_exists( 'BizCity_Knowledge_Database' ) ) {
			$characters = BizCity_Knowledge_Database::instance()->get_characters( array( 'status' => 'active', 'limit' => 1 ) );
			if ( ! empty( $characters ) ) {
				$character_id = (int) ( $characters[0]->id ?? 0 );
			}
		}
		return max( 0, $character_id );
	}

	/**
	 * Map a CRM inbox `channel_type` (facebook|messenger|zalo_oa|zalo|zalo_bot|webchat)
	 * to the canonical BizCity_Channel_File_Logger::CH_* folder constant.
	 */
	private static function map_channel_type( string $channel_type ): string {
		$t = strtolower( $channel_type );
		if ( $t === '' ) {
			return BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY;
		}
		if ( strpos( $t, 'messenger' ) !== false ) { return BizCity_Channel_File_Logger::CH_MESSENGER; }
		if ( strpos( $t, 'zalo_oa' ) !== false )   { return BizCity_Channel_File_Logger::CH_ZALO_OA; }
		if ( strpos( $t, 'zalo' ) !== false )      { return BizCity_Channel_File_Logger::CH_ZALO_BOT; }
		if ( strpos( $t, 'telegram' ) !== false )  { return BizCity_Channel_File_Logger::CH_TELEGRAM; }
		if ( strpos( $t, 'web' ) !== false )       { return BizCity_Channel_File_Logger::CH_WEBCHAT; }
		// [2026-08-01 Johnny Chu] R-CH-FILE-LOG — CRM's facebook inbox is the
		// customer Messenger surface; transport-level FB webhook logs stay in facebook/.
		if ( strpos( $t, 'facebook' ) !== false )  { return BizCity_Channel_File_Logger::CH_MESSENGER; }
		return BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY;
	}

	/**
	 * Record a skip event for a conversation so the FE can surface it.
	 * Stored as a 1-hour transient keyed by conv_id; only the most recent
	 * skip survives (good enough for "why didn't AI reply?" diagnostics).
	 */
	public static function record_skip( int $conv_id, array $detail ): void {
		if ( $conv_id <= 0 ) { return; }
		set_transient( 'bz_crm_skip_' . $conv_id, $detail, HOUR_IN_SECONDS );
	}

	public static function get_recent_skip( int $conv_id ): ?array {
		if ( $conv_id <= 0 ) { return null; }
		$d = get_transient( 'bz_crm_skip_' . $conv_id );
		return is_array( $d ) ? $d : null;
	}
}
