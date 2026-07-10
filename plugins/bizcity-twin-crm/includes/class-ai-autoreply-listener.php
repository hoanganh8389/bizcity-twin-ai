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
 *   - Lays a 30-second transient lock per conversation to avoid replying to
 *     the same inbound twice (CRM emits the event AFTER insert, so the lock
 *     guards re-entrancy from rapid-fire inbound or fan-out listeners).
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
			error_log( '[bizcity-crm-trace] P11 autoreply_listener sender_type=' . ( $payload['sender_type'] ?? '?' ) . ' conv=' . ( $payload['conversation_id'] ?? 0 ) . ' inbox=' . ( $payload['inbox_id'] ?? '?' ) );
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

			// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — P11b: trace inbox channel_type + ref_id for Resolver debug.
			error_log( '[bizcity-crm-trace] P11b inbox_channel_type=' . ( $inbox['channel_type'] ?? 'NULL' ) . ' channel_ref_id=' . ( $inbox['channel_ref_id'] ?? 'NULL' ) );

			// Twin Guru on Duty: resolve character + attached notebooks from binding.
			$guru_ctx = ( $inbox && class_exists( 'BizCity_CRM_Guru_Resolver' ) )
				? BizCity_CRM_Guru_Resolver::resolve_for_inbox( $inbox )
				: array( 'character_id' => 0, 'guru_uuid' => '', 'notebooks' => array() );

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

			$inbox_settings = $inbox && $inbox['settings_json']
				? ( json_decode( (string) $inbox['settings_json'], true ) ?: array() )
				: array();
			$autoreply_inbox = isset( $inbox_settings['ai_autoreply'] )
				? (bool) $inbox_settings['ai_autoreply']
				: true; // default ON when notebook attached

			if ( $notebook_id <= 0 ) {
				// [2026-06-29 Johnny Chu] HOTFIX — character bound with system_prompt + FAQ
				// MUST reply even without a notebook. Notebook makes answers richer (KG retrieval)
				// but is NOT required when character_id > 0 has system_prompt configured.
				// Skip ONLY when neither notebook NOR character is resolved.
				$has_character = ( (int) ( $guru_ctx['character_id'] ?? 0 ) ) > 0;
				if ( ! $has_character ) {
					self::log( sprintf(
						'skip conv#%d: no_notebook (conv.notebook_id=%s, inbox.default_notebook_id=%s, guru_char#%d guru_uuid=%s, kg_notebooks.character_id rows=[%s], attachments rows=[%s])',
						$conv_id,
						$conv['notebook_id'] ?? 'NULL',
						$inbox['default_notebook_id'] ?? 'NULL',
						(int) ( $guru_ctx['character_id'] ?? 0 ),
						$guru_ctx['guru_uuid'] ? substr( $guru_ctx['guru_uuid'], 0, 8 ) . '…' : '—',
						implode( ',', $guru_ctx['trace']['notebooks_by_character_id'] ?? array() ),
						implode( ',', $guru_ctx['trace']['notebooks_by_guru_uuid']    ?? array() )
					) );
					return;
				}
				self::log( sprintf(
					'no_notebook_but_character: conv#%d char#%d — reply with system_prompt only (no KG retrieval)',
					$conv_id, (int) $guru_ctx['character_id']
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

			// De-dupe lock — same conversation re-entered within 30s = drop.
			$lock_key = 'bz_crm_ai_lock_' . $conv_id;
			if ( get_transient( $lock_key ) ) {
				self::log( "skip conv#{$conv_id}: lock_held (recent reply within {self::LOCK_TTL}s)" );
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

			$t0 = microtime( true );
			$result = BizCity_CRM_AI_Replier::reply( $conv_id, array(
				'notebook_id'  => $notebook_id,
				'character_id' => (int) ( $guru_ctx['character_id'] ?? 0 ) ?: null,
			) );
			$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

			// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — Release lock after reply completes
			// so the user can send the next message without waiting 30s.
			delete_transient( $lock_key );

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
		} catch ( \Throwable $e ) {
			self::log( 'EXCEPTION: ' . get_class( $e ) . ' — ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
			self::log( 'TRACE: ' . str_replace( "\n", ' | ', $e->getTraceAsString() ) );
		}
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
					self::log( "legacy-passthrough: inbox#{$row['id']} fb page={$page_id} has no default_notebook_id and no Guru-on-Duty notebooks or character" );
					return $handled;
				}
				self::log( sprintf(
					'suppress legacy (char-only): inbox#%d fb page=%s char#%d — system_prompt only (no KG)',
					(int) $row['id'], $page_id, $guru_char
				) );
				$nb = -1; // sentinel: character bound but no notebook — allow through
			}
			$settings = $row['settings_json'] ? ( json_decode( (string) $row['settings_json'], true ) ?: array() ) : array();
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

	private static function log( string $msg, array $ctx = array() ): void {
		$line = '[bizcity-crm-autoreply] ' . $msg;
		if ( $ctx ) {
			$line .= ' ' . wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		error_log( $line );
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
