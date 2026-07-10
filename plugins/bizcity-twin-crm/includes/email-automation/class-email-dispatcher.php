<?php
/**
 * BizCity CRM — Email Event Dispatcher (PHASE 0.37.1)
 *
 * Listens for every event_key registered in BizCity_CRM_Email_Event_Registry
 * and fires matching rules. Send path:
 *   - If rule has account_id → use BizCity_CRM_Gmail_SMTP_Repo::send_via().
 *   - Else → use plain wp_mail() (relies on global SMTP bridge if configured).
 *
 * @package BizCity_Twin_CRM
 * @since   0.37.1
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Email_Dispatcher {

	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) { return; }
		self::$registered = true;

		// Attach a single closure per declared event hook. Closure captures the
		// event_key so we can look up normalizer at fire-time (cheap).
		foreach ( BizCity_CRM_Email_Event_Registry::events() as $key => $event ) {
			$hook = (string) ( $event['hook']      ?? $key );
			$args = (int)    ( $event['hook_args'] ?? 1 );
			add_action( $hook, static function () use ( $key ) {
				$hook_args = func_get_args();
				self::on_event( $key, $hook_args );
			}, 20, $args );
		}
	}

	public static function on_event( string $event_key, array $hook_args ): void {
		// [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — file-log hook trigger first, before any DB
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_EMAIL,
				BizCity_Channel_File_Logger::LEVEL_DEBUG,
				'hook_triggered',
				'Event hook fired: ' . $event_key,
				array( 'event_key' => $event_key )
			);
		}
		try {
			$event = BizCity_CRM_Email_Event_Registry::get( $event_key );
			if ( ! $event ) { return; }
			$rules = BizCity_CRM_Email_Rules_Repo::list_rules( $event_key );
			if ( ! $rules ) { return; }

			$ctx = is_callable( $event['normalize'] ?? null )
				? call_user_func( $event['normalize'], $hook_args )
				: array();

			// [2026-06-19 Johnny Chu] PHASE-CG-CF7 — null means "skip this invocation".
			// Per-form CF7 events return null when fired for the wrong form_id.
			if ( null === $ctx ) { return; }
			$ctx = (array) $ctx;

			foreach ( $rules as $rule ) {
				if ( empty( $rule['is_enabled'] ) ) { continue; }
				self::fire_rule( $rule, $ctx );
			}
		} catch ( \Throwable $e ) {
			// ALWAYS log to file — this is the last safety net
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::error(
					BizCity_Channel_File_Logger::CH_EMAIL,
					'dispatcher_exception',
					'on_event() threw: ' . $e->getMessage(),
					array( 'event_key' => $event_key ),
					$e
				);
			}
			error_log( '[bizcity-crm] email dispatcher error (' . $event_key . '): ' . $e->getMessage() );
		}
	}

	private static function fire_rule( array $rule, array $ctx ): void {
		// [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — outer try/catch logs to file ALWAYS
		try {
			self::do_fire_rule( $rule, $ctx );
		} catch ( \Throwable $e ) {
			$err_ctx = array(
				'rule_id'   => (int) ( $rule['id'] ?? 0 ),
				'rule_name' => (string) ( $rule['name'] ?? '' ),
				'event_key' => (string) ( $rule['event_key'] ?? '' ),
			);
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::error(
					BizCity_Channel_File_Logger::CH_EMAIL,
					'fire_rule_exception',
					'fire_rule() threw: ' . $e->getMessage(),
					$err_ctx,
					$e
				);
			}
			error_log( '[bizcity-crm] fire_rule error: ' . $e->getMessage() );
		}
	}

	private static function do_fire_rule( array $rule, array $ctx ): void {
		$render  = static function ( $tpl ) use ( $ctx ) { return BizCity_CRM_Email_Event_Registry::render( (string) $tpl, $ctx ); };
		$to      = $render( $rule['to_template'] );
		$cc      = $render( $rule['cc_template']  ?? '' );
		$bcc     = $render( $rule['bcc_template'] ?? '' );
		$subject = $render( $rule['subject_template'] );

		// [2026-06-24 Johnny Chu] PHASE-CF7-AUTO — AI reply mode: call LLM to generate body
		$reply_type = (string) ( $rule['reply_type'] ?? 'template' );
		if ( $reply_type === 'ai_reply' ) {
			$body = self::generate_ai_body( $rule, $ctx );
		} else {
			$body = $render( $rule['body_template'] ?? '' );
		}

		$to_list  = array_filter( array_map( 'trim', preg_split( '/[,;]/', $to  ) ) );
		$cc_list  = array_filter( array_map( 'trim', preg_split( '/[,;]/', $cc  ) ) );
		$bcc_list = array_filter( array_map( 'trim', preg_split( '/[,;]/', $bcc ) ) );

		if ( empty( $to_list ) || $subject === '' ) {
			$skip_msg = 'Skipped rule #' . (int) $rule['id'] . ': missing to_list or subject. to=[' . $to . '] subject=[' . $subject . ']';
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write(
					BizCity_Channel_File_Logger::CH_EMAIL,
					BizCity_Channel_File_Logger::LEVEL_WARN,
					'send_skipped',
					$skip_msg,
					array( 'rule_id' => (int) $rule['id'], 'rule_name' => (string) ( $rule['name'] ?? '' ), 'to' => $to, 'subject' => $subject )
				);
			}
			BizCity_CRM_Email_Rules_Repo::record_fire( (int) $rule['id'], false, 'missing_to_or_subject' );
			// [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG
			BizCity_CRM_Email_Send_Log::write( array(
				'rule_id'        => (int) $rule['id'],
				'rule_name'      => (string) ( $rule['name'] ?? '' ),
				'event_key'      => (string) ( $rule['event_key'] ?? '' ),
				'recipient_email'=> $to,
				'subject'        => $subject,
				'status'         => 'skipped',
				'error_message'  => 'missing_to_or_subject',
				'smtp_source'    => '',
				'has_attachment' => 0,
			) );
			return;
		}

		// [2026-06-19 Johnny Chu] PHASE-CG-CF7 — resolve attachment_url → local file path
		$attachment_paths = array();
		$tmp_files        = array();
		$attachment_url   = (string) ( $rule['attachment_url'] ?? '' );
		if ( $attachment_url !== '' ) {
			$local_path = self::url_to_local_path( $attachment_url );
			if ( $local_path !== null && @file_exists( $local_path ) ) {
				$attachment_paths[] = $local_path;
			} else {
				// Download to a temp file (handles external URLs or URL-path mismatches).
				$tmp = download_url( $attachment_url );
				if ( ! is_wp_error( $tmp ) ) {
					$attachment_paths[] = $tmp;
					$tmp_files[]        = $tmp;
				}
			}
		}

		$account_id = isset( $rule['account_id'] ) ? (int) $rule['account_id'] : 0;
		$is_html    = (bool) preg_match( '/<[a-z][^>]*>/i', $body );

		if ( $account_id > 0 ) {
			// File-log attempt BEFORE send
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_EMAIL, BizCity_Channel_File_Logger::LEVEL_INFO, 'send_attempt',
					'Sending via account #' . $account_id,
					array( 'rule_id' => (int) $rule['id'], 'rule_name' => (string) ( $rule['name'] ?? '' ), 'to' => implode( ', ', $to_list ), 'subject' => $subject, 'has_attachment' => ! empty( $attachment_paths ) )
				);
			}
			$res = BizCity_CRM_Gmail_SMTP_Repo::send_via( $account_id, array(
				'to' => $to_list, 'cc' => $cc_list, 'bcc' => $bcc_list,
				'subject' => $subject, 'body' => $body, 'is_html' => $is_html,
				'attachments' => $attachment_paths,
			) );
			self::cleanup_tmp( $tmp_files );
			$send_ok = ! empty( $res['ok'] );
			$err_msg = (string) ( $res['error'] ?? '' );
			// File-log result
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_EMAIL,
					$send_ok ? BizCity_Channel_File_Logger::LEVEL_INFO : BizCity_Channel_File_Logger::LEVEL_ERROR,
					$send_ok ? 'send_ok' : 'send_failed',
					$send_ok ? 'Sent OK via crm_gmail account #' . $account_id : 'Send FAILED: ' . $err_msg,
					array( 'rule_id' => (int) $rule['id'], 'to' => implode( ', ', $to_list ), 'subject' => $subject, 'smtp_source' => 'crm_gmail', 'error' => $err_msg )
				);
			}
			BizCity_CRM_Email_Rules_Repo::record_fire( (int) $rule['id'], $send_ok, $err_msg );
			// [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG
			BizCity_CRM_Email_Send_Log::write( array(
				'rule_id'         => (int) $rule['id'],
				'rule_name'       => (string) ( $rule['name'] ?? '' ),
				'event_key'       => (string) ( $rule['event_key'] ?? '' ),
				'recipient_email' => implode( ', ', $to_list ),
				'subject'         => $subject,
				'status'          => $send_ok ? 'sent' : 'failed',
				'error_message'   => $send_ok ? null : $err_msg,
				'smtp_source'     => 'crm_gmail',
				'has_attachment'  => empty( $attachment_paths ) ? 0 : 1,
			) );
			return;
		}

		// Fallback: plain wp_mail (uses global SMTP bridge).
		// [2026-06-19 Johnny Chu] PHASE-CG-CF7-SMTP-FIX — rule has no explicit account_id.
		// Auto-detect the site's default active CRM Gmail account so the mu-plugin
		// phpmailer_init hook (priority 999) does NOT override From/credentials.
		// Only fall back to wp_mail() if truly no CRM account is configured.
		$fallback_account_id = self::resolve_default_account();

		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_EMAIL, BizCity_Channel_File_Logger::LEVEL_DEBUG, 'account_resolve',
				'No explicit account_id. Resolved fallback=' . $fallback_account_id,
				array( 'rule_id' => (int) $rule['id'], 'fallback_account_id' => $fallback_account_id )
			);
		}

		if ( $fallback_account_id > 0 ) {
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_EMAIL, BizCity_Channel_File_Logger::LEVEL_INFO, 'send_attempt',
					'Sending via auto-resolved account #' . $fallback_account_id,
					array( 'rule_id' => (int) $rule['id'], 'rule_name' => (string) ( $rule['name'] ?? '' ), 'to' => implode( ', ', $to_list ), 'subject' => $subject, 'smtp_source' => 'crm_gmail_auto' )
				);
			}
			$res = BizCity_CRM_Gmail_SMTP_Repo::send_via( $fallback_account_id, array(
				'to' => $to_list, 'cc' => $cc_list, 'bcc' => $bcc_list,
				'subject' => $subject, 'body' => $body, 'is_html' => $is_html,
				'attachments' => $attachment_paths,
			) );
			self::cleanup_tmp( $tmp_files );
			$send_ok   = ! empty( $res['ok'] );
			$err_msg   = (string) ( $res['error'] ?? '' );
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_EMAIL,
					$send_ok ? BizCity_Channel_File_Logger::LEVEL_INFO : BizCity_Channel_File_Logger::LEVEL_ERROR,
					$send_ok ? 'send_ok' : 'send_failed',
					$send_ok ? 'Sent OK via crm_gmail_auto #' . $fallback_account_id : 'Send FAILED: ' . $err_msg,
					array( 'rule_id' => (int) $rule['id'], 'to' => implode( ', ', $to_list ), 'subject' => $subject, 'smtp_source' => 'crm_gmail_auto', 'error' => $err_msg )
				);
			}
			BizCity_CRM_Email_Rules_Repo::record_fire( (int) $rule['id'], $send_ok, $err_msg );
			BizCity_CRM_Email_Send_Log::write( array(
				'rule_id'         => (int) $rule['id'],
				'rule_name'       => (string) ( $rule['name'] ?? '' ),
				'event_key'       => (string) ( $rule['event_key'] ?? '' ),
				'recipient_email' => implode( ', ', $to_list ),
				'subject'         => $subject,
				'status'          => $send_ok ? 'sent' : 'failed',
				'error_message'   => $send_ok ? null : $err_msg,
				'smtp_source'     => 'crm_gmail_auto',
				'has_attachment'  => empty( $attachment_paths ) ? 0 : 1,
			) );
			return;
		}

		// True last-resort: no CRM account configured at all → wp_mail() with site SMTP.
		$headers = array( 'Content-Type: ' . ( $is_html ? 'text/html' : 'text/plain' ) . '; charset=UTF-8' );
		foreach ( $cc_list as $addr )  { $headers[] = 'Cc: '  . $addr; }
		foreach ( $bcc_list as $addr ) { $headers[] = 'Bcc: ' . $addr; }
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_EMAIL, BizCity_Channel_File_Logger::LEVEL_WARN, 'send_attempt',
				'No CRM account found. Falling back to wp_mail (mu-plugin may override From)',
				array( 'rule_id' => (int) $rule['id'], 'to' => implode( ', ', $to_list ), 'subject' => $subject )
			);
		}
		$ok = wp_mail( $to_list, $subject, $body, $headers, $attachment_paths );
		self::cleanup_tmp( $tmp_files );
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_EMAIL,
				$ok ? BizCity_Channel_File_Logger::LEVEL_INFO : BizCity_Channel_File_Logger::LEVEL_ERROR,
				$ok ? 'send_ok' : 'send_failed',
				$ok ? 'wp_mail returned true' : 'wp_mail returned false',
				array( 'rule_id' => (int) $rule['id'], 'to' => implode( ', ', $to_list ), 'smtp_source' => 'wp_mail' )
			);
		}
		BizCity_CRM_Email_Rules_Repo::record_fire( (int) $rule['id'], (bool) $ok, $ok ? '' : 'wp_mail_returned_false' );
		// [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG
		BizCity_CRM_Email_Send_Log::write( array(
			'rule_id'         => (int) $rule['id'],
			'rule_name'       => (string) ( $rule['name'] ?? '' ),
			'event_key'       => (string) ( $rule['event_key'] ?? '' ),
			'recipient_email' => implode( ', ', $to_list ),
			'subject'         => $subject,
			'status'          => $ok ? 'sent' : 'failed',
			'error_message'   => $ok ? null : 'wp_mail_returned_false',
			'smtp_source'     => 'wp_mail',
			'has_attachment'  => empty( $attachment_paths ) ? 0 : 1,
		) );
	}

	/**
	 * Resolve the best CRM account to use when a rule has no explicit account_id.
	 * Prefers is_default=1 + is_active=1, then any is_active=1.
	 * Returns 0 if no CRM account exists.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-CG-CF7-SMTP-FIX
	 */
	private static function resolve_default_account(): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_gmail_smtp_accounts();
		// [2026-06-24 Johnny Chu] R-SHOW-TABLES — use information_schema + dual cache
		static $s = array();
		if ( isset( $s[ $tbl ] ) ) {
			$tbl_exists = $s[ $tbl ];
		} else {
			$ck         = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $tbl );
			$tbl_exists = wp_cache_get( $ck, 'bizcity_tbl' );
			if ( false === $tbl_exists ) {
				$tbl_exists = (int) (bool) $wpdb->get_var( $wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s LIMIT 1',
					$tbl
				) );
				wp_cache_set( $ck, $tbl_exists, 'bizcity_tbl', HOUR_IN_SECONDS );
			}
			$s[ $tbl ] = $tbl_exists;
		}
		if ( ! $tbl_exists ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$id = $wpdb->get_var( "SELECT id FROM `{$tbl}` WHERE is_active=1 AND deleted_at IS NULL ORDER BY is_default DESC, id ASC LIMIT 1" );
		return (int) $id;
	}

	/**
	 * Convert a same-site URL to an absolute file-system path.
	 * Returns null if the URL belongs to a different host.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-CG-CF7
	 */
	private static function url_to_local_path( string $url ): ?string {
		$upload    = wp_upload_dir();
		$base_url  = untrailingslashit( $upload['baseurl'] );
		$base_path = untrailingslashit( $upload['basedir'] );
		if ( strpos( $url, $base_url ) === 0 ) {
			return $base_path . substr( $url, strlen( $base_url ) );
		}
		// Fallback: use home_url comparison.
		$home = untrailingslashit( home_url() );
		if ( strpos( $url, $home ) === 0 ) {
			return untrailingslashit( ABSPATH ) . substr( $url, strlen( $home ) );
		}
		return null;
	}

	/** Clean up temp files created by download_url(). [2026-06-19 Johnny Chu] PHASE-CG-CF7 */
	private static function cleanup_tmp( array $paths ): void {
		foreach ( $paths as $p ) {
			if ( is_string( $p ) && @file_exists( $p ) ) {
				@unlink( $p );
			}
		}
	}

	/** Public helper used by REST test endpoint. */
	public static function test_rule( int $rule_id, array $override_ctx = array() ): array {
		$rule = BizCity_CRM_Email_Rules_Repo::get( $rule_id );
		if ( ! $rule ) { return array( 'ok' => false, 'error' => 'rule_not_found' ); }
		$event = BizCity_CRM_Email_Event_Registry::get( (string) $rule['event_key'] );
		$placeholders = $event['placeholders'] ?? array();
		$ctx = array();
		foreach ( $placeholders as $k ) {
			$ctx[ $k ] = $override_ctx[ $k ] ?? ( '[' . $k . ']' );
		}
		$ctx = array_merge( $ctx, $override_ctx );
		self::fire_rule( $rule, $ctx );
		$fresh = BizCity_CRM_Email_Rules_Repo::get( $rule_id );
		return array(
			'ok'     => ( $fresh['last_fire_status'] ?? '' ) === 'ok',
			'status' => (string) ( $fresh['last_fire_status'] ?? '' ),
			'error'  => (string) ( $fresh['last_fire_error']  ?? '' ),
			'ctx'    => $ctx,
		);
	}

	/**
	 * Generate email body via LLM for ai_reply mode.
	 * Falls back to body_template if LLM is unavailable.
	 *
	 * @param array $rule  Rule row from DB.
	 * @param array $ctx   Template context (form field values).
	 * [2026-06-24 Johnny Chu] PHASE-CF7-AUTO
	 */
	private static function generate_ai_body( array $rule, array $ctx ): string {
		$ai_config     = array();
		$ai_config_raw = $rule['ai_config_json'] ?? '';
		if ( is_string( $ai_config_raw ) && $ai_config_raw !== '' ) {
			$decoded = json_decode( $ai_config_raw, true );
			if ( is_array( $decoded ) ) {
				$ai_config = $decoded;
			}
		}

		$prompt_prefix = (string) ( $ai_config['prompt_prefix'] ?? '' );
		if ( $prompt_prefix === '' ) {
			$prompt_prefix = 'Bạn là trợ lý chăm sóc khách hàng chuyên nghiệp. Viết email trả lời ngắn gọn, thân thiện và lịch sự bằng tiếng Việt cho khách hàng dựa trên thông tin form sau:';
		}

		// Build form data summary from ctx.
		$form_lines = array();
		$skip_keys  = array( 'form_id', 'form_title', 'site_name' );
		foreach ( $ctx as $k => $v ) {
			if ( in_array( $k, $skip_keys, true ) || $v === '' || $v === null ) { continue; }
			$form_lines[] = '- ' . $k . ': ' . (string) $v;
		}
		$form_summary = implode( "\n", $form_lines );
		$full_prompt  = $prompt_prefix . "\n\n" . $form_summary;

		// Try BizCity LLM Client (R-GW-8 — client-side lib, no router dependency).
		if ( class_exists( 'BizCity_LLM_Client', false ) ) {
			try {
				$llm = BizCity_LLM_Client::instance();
				if ( $llm->is_ready() ) {
					$messages = array(
						array( 'role' => 'user', 'content' => $full_prompt ),
					);
					$resp = $llm->chat( $messages, array( 'purpose' => 'cf7_auto_reply' ) );
					$content = (string) ( $resp['content'] ?? $resp['choices'][0]['message']['content'] ?? '' );
					if ( $content !== '' ) {
						if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
							BizCity_Channel_File_Logger::write(
								BizCity_Channel_File_Logger::CH_EMAIL,
								BizCity_Channel_File_Logger::LEVEL_INFO,
								'ai_reply_generated',
								'AI reply generated for rule #' . (int) $rule['id'],
								array( 'rule_id' => (int) $rule['id'], 'length' => strlen( $content ) )
							);
						}
						return $content;
					}
				}
			} catch ( \Throwable $e ) {
				if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
					BizCity_Channel_File_Logger::write(
						BizCity_Channel_File_Logger::CH_EMAIL,
						BizCity_Channel_File_Logger::LEVEL_ERROR,
						'ai_reply_failed',
						'AI reply failed for rule #' . (int) $rule['id'] . ': ' . $e->getMessage(),
						array( 'rule_id' => (int) $rule['id'], 'error' => $e->getMessage() )
					);
				}
			}
		}

		// Fallback to body_template.
		$render = static function ( $tpl ) use ( $ctx ) { return BizCity_CRM_Email_Event_Registry::render( (string) $tpl, $ctx ); };
		return $render( $rule['body_template'] ?? '' );
	}
}

