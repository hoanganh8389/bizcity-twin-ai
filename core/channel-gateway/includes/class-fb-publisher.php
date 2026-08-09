<?php
/**
 * Channel Gateway — Facebook Publisher
 *
 * Bridge between core/scheduler and Facebook Graph API.
 *
 * Listens to `bizcity_scheduler_reminder_fire` (fired by scheduler cron when
 * `start_at <= now + reminder_min*60`). When the event's `event_type` is
 * `fb_post`, it pulls page/content/image from metadata, publishes via Graph,
 * then writes the result back to the event's metadata.
 *
 * Contract (event_type='fb_post' metadata fields — see core/diagnostics/changelog/core.scheduler.json):
 *   fb_page_id          Required. Facebook page ID (string).
 *   fb_page_name        Optional. Page display name.
 *   fb_content          Required. Post message text.
 *   fb_image_url        Optional. First/legacy photo URL.
 *   fb_image_urls       Optional. Canonical array of photo URLs; 2+ publishes a multi-photo feed post.
 *   fb_publish_status   pending|publishing|published|failed|cancelled
 *   fb_post_id          Filled after publish.
 *   fb_permalink        Filled after publish.
 *   fb_error            Filled on failure.
 *
 * Idempotency: skip if metadata.fb_post_id already set, or if status !== 'active',
 * or if fb_publish_status !== 'pending'.
 *
 * R-DCL compliant: no schema change. Reuses existing `metadata` LONGTEXT column.
 * Contract bump in core.scheduler.json v3.1.0.
 * R-CH compliant: bridges via filter/action, no fork of core/scheduler.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.4.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_FB_Publisher {

	const HOOK_PRIORITY = 20;
	const GRAPH_VERSION = 'v18.0';

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function init(): void {
		add_action(
			'bizcity_scheduler_reminder_fire',
			array( self::instance(), 'on_reminder_fire' ),
			self::HOOK_PRIORITY,
			1
		);
	}

	/**
	 * Reminder callback. Returns silently for non-fb_post events.
	 *
	 * @param array|object $event Event row (array from cron::claim_due_reminders).
	 */
	public function on_reminder_fire( $event ): void {
		$event = is_object( $event ) ? (array) $event : (array) $event;
		if ( empty( $event['id'] ) ) {
			return;
		}
		if ( ( $event['event_type'] ?? '' ) !== 'fb_post' ) {
			return;
		}
		if ( ( $event['status'] ?? '' ) !== 'active' ) {
			return;
		}

		$meta = $this->decode_metadata( $event['metadata'] ?? '' );

		// Idempotency guards.
		if ( ! empty( $meta['fb_post_id'] ) ) {
			return;
		}
		$publish_status = (string) ( $meta['fb_publish_status'] ?? 'pending' );
		if ( ! in_array( $publish_status, array( 'pending', 'failed' ), true ) ) {
			return;
		}

		$event_id = (int) $event['id'];
		$content_artifact_id = (int) ( $meta['content_id'] ?? 0 );
		$page_id  = (string) ( $meta['fb_page_id'] ?? '' );
		$content  = (string) ( $meta['fb_content'] ?? '' );
		$image_urls = $this->normalize_image_urls( $meta );
		$image    = (string) ( $image_urls[0] ?? '' );
		$owner_user_id = (int) ( $event['user_id'] ?? $meta['owner_user_id'] ?? 0 );
		// [2026-07-21 Johnny Chu] PHASE-IMG-FIRST-FB-FIX — if duplicate scheduler events point at an already-published My Content item, reuse it instead of posting to Graph again.
		if ( $content_artifact_id > 0 && class_exists( 'BizCity_Content_Artifact_Service' ) ) {
			$existing_post_id = (string) get_post_meta( $content_artifact_id, '_bizcity_fb_post_id', true );
			$existing_permalink = (string) get_post_meta( $content_artifact_id, '_bizcity_fb_permalink', true );
			if ( $existing_post_id !== '' ) {
				$meta['fb_publish_status'] = 'published';
				$meta['fb_post_id'] = $existing_post_id;
				$meta['fb_permalink'] = $existing_permalink;
				$meta['fb_duplicate_suppressed'] = true;
				$this->write_metadata( $event_id, $meta, 'done' );
				$this->cron_note_event( 'fb_publish_duplicate_suppressed', array(
					'event_id'  => $event_id,
					'page_id'   => $page_id,
					'post_id'   => $existing_post_id,
					'permalink' => $existing_permalink,
				) );
				return;
			}
		}

		// R-CRON-META: attach evidence to scheduler.reminder run row.
		$this->cron_note_event( 'fb_publish_attempt', array(
			'event_id'      => $event_id,
			'page_id'       => $page_id,
			'page_name'     => (string) ( $meta['fb_page_name'] ?? '' ),
			'owner_user_id' => $owner_user_id,
			'has_image'     => $image !== '',
			'image_count'   => count( $image_urls ),
			'content_len'   => strlen( $content ),
			'prev_status'   => $publish_status,
			'is_retry'      => $publish_status === 'failed',
		) );
		// [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — R-CH-FILE-LOG: file-log before Graph API call
		if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_FACEBOOK,
				BizCity_Channel_File_Logger::LEVEL_INFO,
				'fb_publish_attempt',
				'Publishing FB post for page #' . $page_id,
				array( 'event_id' => $event_id, 'page_id' => $page_id, 'has_image' => $image !== '' )
			);
		}

		if ( $page_id === '' || $content === '' ) {
			$this->mark_failed( $event_id, $meta, 'Missing fb_page_id or fb_content in metadata.' );
			$this->cron_note_event( 'fb_publish_failed', array(
				'event_id' => $event_id,
				'reason'   => 'invalid_metadata',
				'error'    => 'Missing fb_page_id or fb_content in metadata.',
			) );
			$this->cron_bump_counter( 'fb_failed' );
			return;
		}

		// [2026-07-15 Johnny Chu] PHASE-TWINWEB F4 — runtime owner-page revalidation before claim/publish.
		$owner_check = $this->assert_page_owner_matches_event( $page_id, $owner_user_id );
		if ( is_wp_error( $owner_check ) ) {
			$err_code = (string) $owner_check->get_error_code();
			$err_msg  = (string) $owner_check->get_error_message();
			$this->mark_failed( $event_id, $meta, $err_msg );
			$this->cron_note_event( 'fb_publish_failed', array(
				'event_id'      => $event_id,
				'page_id'       => $page_id,
				'owner_user_id' => $owner_user_id,
				'reason'        => $err_code,
				'error'         => $err_msg,
			) );
			if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
				BizCity_Channel_File_Logger::write(
					BizCity_Channel_File_Logger::CH_FACEBOOK,
					BizCity_Channel_File_Logger::LEVEL_ERROR,
					'fb_publish_failed',
					'FB publish ownership denied: ' . $err_msg,
					array( 'event_id' => $event_id, 'page_id' => $page_id, 'owner_user_id' => $owner_user_id, 'reason' => $err_code )
				);
			}
			$this->cron_bump_counter( 'fb_failed' );
			return;
		}

		// Mark publishing (claim).
		$meta['fb_publish_status'] = 'publishing';
		unset( $meta['fb_error'] );
		$this->write_metadata( $event_id, $meta );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — reflect async publisher claim on the My Content artifact.
		if ( $content_artifact_id > 0 && class_exists( 'BizCity_Content_Artifact_Service' ) ) {
			BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'fb_publishing', array( '_bizcity_fb_publish_status' => 'publishing' ) );
			BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
				'stage' => 'fb_publishing', 'source' => 'channel_gateway', 'block_id' => 'BizCity_FB_Publisher', 'status' => 'start',
				'event_id' => $event_id, 'message' => 'Facebook publisher claimed scheduler event.', 'ctx' => array( 'page_id' => $page_id ),
			) );
		}

		do_action( 'bizcity_fb_post_publish_start', $event_id, $event );

		$result = $this->publish_to_graph( $page_id, $content, $image_urls, $owner_user_id );

		if ( is_wp_error( $result ) ) {
			$err_code = $result->get_error_code();
			$err_msg  = $result->get_error_message();
			$this->mark_failed( $event_id, $meta, $err_msg );
			if ( $content_artifact_id > 0 && class_exists( 'BizCity_Content_Artifact_Service' ) ) {
				BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'failed', array(
					'_bizcity_fb_publish_status' => 'failed',
					'_bizcity_error_code' => $err_code,
					'_bizcity_error_message' => $err_msg,
				) );
				BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
					'stage' => 'failed', 'source' => 'channel_gateway', 'block_id' => 'BizCity_FB_Publisher', 'status' => 'fail',
					'event_id' => $event_id, 'error_code' => $err_code, 'message' => $err_msg, 'ctx' => array( 'page_id' => $page_id ),
				) );
			}
			$this->cron_note_event( 'fb_publish_failed', array(
				'event_id'  => $event_id,
				'page_id'   => $page_id,
				'reason'    => $err_code,
				'error'     => $err_msg,
			) );
			// [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — R-CH-FILE-LOG
			if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
				BizCity_Channel_File_Logger::write(
					BizCity_Channel_File_Logger::CH_FACEBOOK,
					BizCity_Channel_File_Logger::LEVEL_ERROR,
					'fb_publish_failed',
					'FB publish failed: ' . $err_msg,
					array( 'event_id' => $event_id, 'page_id' => $page_id, 'reason' => $err_code )
				);
			}
			$this->cron_bump_counter( 'fb_failed' );
			return;
		}

		// Success.
		$meta['fb_publish_status'] = 'published';
		$meta['fb_post_id']        = (string) ( $result['post_id'] ?? '' );
		$meta['fb_permalink']      = (string) ( $result['permalink'] ?? '' );
		unset( $meta['fb_error'] );

		$this->write_metadata( $event_id, $meta, 'done' );
		if ( $content_artifact_id > 0 && class_exists( 'BizCity_Content_Artifact_Service' ) ) {
			BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'fb_published', array(
				'_bizcity_fb_publish_status' => 'published',
				'_bizcity_fb_post_id' => $meta['fb_post_id'],
				'_bizcity_fb_permalink' => $meta['fb_permalink'],
			) );
			BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
				'stage' => 'fb_published', 'source' => 'channel_gateway', 'block_id' => 'BizCity_FB_Publisher', 'status' => 'ok',
				'event_id' => $event_id, 'message' => 'Facebook post published.', 'ctx' => array( 'page_id' => $page_id, 'post_id' => $meta['fb_post_id'], 'permalink' => $meta['fb_permalink'] ),
			) );
		}

		$this->cron_note_event( 'fb_publish_ok', array(
			'event_id'  => $event_id,
			'page_id'   => $page_id,
			'post_id'   => $meta['fb_post_id'],
			'permalink' => $meta['fb_permalink'],
		) );
		// [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — R-CH-FILE-LOG
		if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_FACEBOOK,
				BizCity_Channel_File_Logger::LEVEL_INFO,
				'fb_publish_ok',
				'FB post published OK: ' . $meta['fb_post_id'],
				array( 'event_id' => $event_id, 'page_id' => $page_id, 'post_id' => $meta['fb_post_id'], 'permalink' => $meta['fb_permalink'] )
			);
		}
		$this->cron_bump_counter( 'fb_published' );

		// Audit log — reuse channel_messages table.
		if ( class_exists( 'BizCity_Channel_Messages' ) ) {
			BizCity_Channel_Messages::log_outbound( array(
				'platform'   => 'FACEBOOK',
				'chat_id'    => 'fb_page_' . $page_id,
				'event_type' => 'post',
				'body'       => $content,
				'message_id' => $meta['fb_post_id'],
				'status'     => 'sent',
				'error'      => '',
				'payload'    => array(
					'page_id'     => $page_id,
					'photo_url'   => $image,
					'photo_urls'  => $image_urls,
					'scheduler_event_id' => $event_id,
					'source'      => 'fb_publisher',
				),
			) );
		}

		do_action( 'bizcity_fb_post_published', $event_id, $meta['fb_post_id'], $meta['fb_permalink'] );
	}

	/**
	 * Publish to Graph API. Mirrors BizCity_Facebook_Page_REST::publish_post() internals.
	 *
	 * @return array{post_id:string,permalink:string}|WP_Error
	 */
	protected function publish_to_graph( string $page_id, string $message, array $photo_urls, int $owner_user_id = 0 ) {
		$token = $this->resolve_page_token( $page_id, $owner_user_id );
		if ( $token === '' ) {
			return new WP_Error( 'no_token', 'Khong tim thay page access token cho page_id ' . $page_id );
		}
		$photo_urls = array_values( array_filter( array_map( 'trim', $photo_urls ) ) );
		if ( count( $photo_urls ) > 1 ) {
			// [2026-07-21 Johnny Chu] R-AUTO-MULTI-ATTACH — Graph multi-photo posts require unpublished photo uploads then attached_media[] on /feed.
			$media_ids = array();
			foreach ( $photo_urls as $photo_url ) {
				$upload = $this->graph_post( 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/' . rawurlencode( $page_id ) . '/photos', array(
					'url'          => $photo_url,
					'published'    => 'false',
					'access_token' => $token,
				) );
				if ( is_wp_error( $upload ) ) { return $upload; }
				$mid = (string) ( $upload['id'] ?? '' );
				if ( $mid === '' ) { return new WP_Error( 'graph_empty', 'Graph returned no media_fbid for uploaded photo.' ); }
				$media_ids[] = $mid;
			}
			$body = array( 'message' => $message, 'access_token' => $token );
			foreach ( $media_ids as $idx => $media_id ) {
				$body[ 'attached_media[' . $idx . ']' ] = wp_json_encode( array( 'media_fbid' => $media_id ) );
			}
			$data = $this->graph_post( 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/' . rawurlencode( $page_id ) . '/feed', $body );
			if ( is_wp_error( $data ) ) { return $data; }
			$post_id = (string) ( $data['post_id'] ?? $data['id'] ?? '' );
			if ( $post_id === '' ) { return new WP_Error( 'graph_empty', 'Graph returned no post_id.' ); }
			return array( 'post_id' => $post_id, 'permalink' => $this->build_permalink( $post_id ) );
		}
		$photo_url = (string) ( $photo_urls[0] ?? '' );

		$endpoint = 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/'
			. rawurlencode( $page_id )
			. ( $photo_url !== '' ? '/photos' : '/feed' );

		$body = $photo_url !== ''
			? array( 'caption' => $message, 'url' => $photo_url, 'access_token' => $token )
			: array( 'message' => $message, 'access_token' => $token );

		$resp = wp_remote_post( $endpoint, array(
			'timeout' => 25,
			'body'    => $body,
		) );

		if ( is_wp_error( $resp ) ) {
			$msg = $resp->get_error_message();
			$code_str = (string) $resp->get_error_code();
			$reason = ( stripos( $msg, 'timed out' ) !== false || stripos( $msg, 'timeout' ) !== false || $code_str === 'http_request_timeout' )
				? 'timeout'
				: 'http_error';
			return new WP_Error( $reason, $msg );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$fb_err = is_array( $data ) ? ( $data['error'] ?? array() ) : array();
			$msg    = is_array( $fb_err ) && isset( $fb_err['message'] )
				? (string) $fb_err['message']
				: 'HTTP ' . $code;
			$fb_code = is_array( $fb_err ) ? (int) ( $fb_err['code'] ?? 0 ) : 0;
			$reason  = $this->classify_graph_error( $code, $fb_code, $msg );
			// Persist Graph payload into cron meta for forensic analysis.
			$this->cron_note_event( 'fb_graph_error_detail', array(
				'http_code'    => $code,
				'fb_code'      => $fb_code,
				'fb_subcode'   => is_array( $fb_err ) ? (int) ( $fb_err['error_subcode'] ?? 0 ) : 0,
				'fb_type'      => is_array( $fb_err ) ? (string) ( $fb_err['type'] ?? '' ) : '',
				'fb_trace_id'  => is_array( $fb_err ) ? (string) ( $fb_err['fbtrace_id'] ?? '' ) : '',
				'message'      => $msg,
			) );
			return new WP_Error( $reason, $msg );
		}

		$post_id = (string) ( $data['post_id'] ?? $data['id'] ?? '' );
		if ( $post_id === '' ) {
			return new WP_Error( 'graph_empty', 'Graph returned no post_id.' );
		}

		// Build permalink (best-effort — Graph doesn't always return URL on /feed).
		$permalink = '';
		if ( strpos( $post_id, '_' ) !== false ) {
			list( $pid, $sid ) = explode( '_', $post_id, 2 );
			$permalink         = 'https://www.facebook.com/' . $pid . '/posts/' . $sid;
		}

		return array(
			'post_id'   => $post_id,
			'permalink' => $permalink,
		);
	}

	protected function normalize_image_urls( array $meta ): array {
		// [2026-07-21 Johnny Chu] R-AUTO-MULTI-ATTACH — read canonical fb_image_urls[] with fb_image_url fallback.
		$urls = array();
		$value = $meta['fb_image_urls'] ?? array();
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value = is_array( $decoded ) ? $decoded : preg_split( '/[\r\n,]+/', $value );
		}
		foreach ( (array) $value as $url ) { $url = trim( (string) $url ); if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) ) { $urls[ $url ] = $url; } }
		$legacy = trim( (string) ( $meta['fb_image_url'] ?? '' ) );
		if ( $legacy !== '' && filter_var( $legacy, FILTER_VALIDATE_URL ) ) { $urls[ $legacy ] = $legacy; }
		return array_values( $urls );
	}

	protected function graph_post( string $endpoint, array $body ) {
		$resp = wp_remote_post( $endpoint, array( 'timeout' => 25, 'body' => $body ) );
		if ( is_wp_error( $resp ) ) {
			$msg = $resp->get_error_message();
			$code_str = (string) $resp->get_error_code();
			$reason = ( stripos( $msg, 'timed out' ) !== false || stripos( $msg, 'timeout' ) !== false || $code_str === 'http_request_timeout' ) ? 'timeout' : 'http_error';
			return new WP_Error( $reason, $msg );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$fb_err = is_array( $data ) ? ( $data['error'] ?? array() ) : array();
			$msg    = is_array( $fb_err ) && isset( $fb_err['message'] ) ? (string) $fb_err['message'] : 'HTTP ' . $code;
			$fb_code = is_array( $fb_err ) ? (int) ( $fb_err['code'] ?? 0 ) : 0;
			$reason  = $this->classify_graph_error( $code, $fb_code, $msg );
			$this->cron_note_event( 'fb_graph_error_detail', array(
				'http_code'   => $code,
				'fb_code'     => $fb_code,
				'fb_subcode'  => is_array( $fb_err ) ? (int) ( $fb_err['error_subcode'] ?? 0 ) : 0,
				'fb_type'     => is_array( $fb_err ) ? (string) ( $fb_err['type'] ?? '' ) : '',
				'fb_trace_id' => is_array( $fb_err ) ? (string) ( $fb_err['fbtrace_id'] ?? '' ) : '',
				'message'     => $msg,
			) );
			return new WP_Error( $reason, $msg );
		}
		return $data;
	}

	protected function build_permalink( string $post_id ): string {
		if ( strpos( $post_id, '_' ) !== false ) {
			list( $pid, $sid ) = explode( '_', $post_id, 2 );
			return 'https://www.facebook.com/' . $pid . '/posts/' . $sid;
		}
		return '';
	}

	/**
	 * Mark event failed. Retry flows can set status back to active and
	 * metadata.fb_publish_status back to pending.
	 */
	protected function mark_failed( int $event_id, array $meta, string $error ): void {
		$meta['fb_publish_status'] = 'failed';
		$meta['fb_error']          = $error;
		// [2026-07-21 Johnny Chu] PHASE-IMG-FIRST-FB-FIX — move scheduler event to failed so Completion Notifier can reply the failure to inbound channel.
		$this->write_metadata( $event_id, $meta, 'failed' );

		if ( class_exists( 'BizCity_Channel_Messages' ) ) {
			BizCity_Channel_Messages::log_outbound( array(
				'platform'   => 'FACEBOOK',
				'chat_id'    => 'fb_page_' . (string) ( $meta['fb_page_id'] ?? '' ),
				'event_type' => 'post',
				'body'       => (string) ( $meta['fb_content'] ?? '' ),
				'message_id' => '',
				'status'     => 'failed',
				'error'      => $error,
				'payload'    => array( 'scheduler_event_id' => $event_id, 'source' => 'fb_publisher' ),
			) );
		}

		do_action( 'bizcity_fb_post_failed', $event_id, $error );
	}

	/**
	 * Resolve page access token. Same lookup chain as BizCity_Facebook_Page_REST::resolve_page_token
	 * but accessible from cron context (no current user).
	 */
	protected function resolve_page_token( string $page_id, int $owner_user_id = 0 ): string {
		if ( $page_id === '' ) {
			return '';
		}
		// Bot DB.
		if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			$db  = BizCity_Facebook_Bot_Database::instance();
			$bot = null;
			if ( $owner_user_id > 0 && method_exists( $db, 'get_bot_by_user_page' ) ) {
				$bot = $db->get_bot_by_user_page( $owner_user_id, $page_id );
			}
			if ( ! $bot && method_exists( $db, 'get_bot_by_page_id' ) ) {
				$bot = $db->get_bot_by_page_id( $page_id );
			}
			if ( $bot && ! empty( $bot->page_access_token ) ) {
				return (string) $bot->page_access_token;
			}
		}
		// Legacy option.
		foreach ( (array) get_option( 'fb_pages_connected', array() ) as $p ) {
			if ( (string) ( $p['id'] ?? '' ) === $page_id && ! empty( $p['access_token'] ) ) {
				return (string) $p['access_token'];
			}
		}
		// Gateway account.
		if ( class_exists( 'BizCity_Integration_Registry' ) ) {
			$reg  = BizCity_Integration_Registry::instance();
			$accs = method_exists( $reg, 'get_channel_accounts' )
				? (array) $reg->get_channel_accounts( 'facebook_page' )
				: array();
			foreach ( $accs as $a ) {
				if ( (string) ( $a['page_id'] ?? '' ) !== $page_id ) {
					continue;
				}
				$integ = $reg->get( 'facebook_page' );
				if ( $integ && method_exists( $integ, 'set_account' ) && method_exists( $integ, 'get_decrypted_param' ) ) {
					$clone = clone $integ;
					$clone->set_account( $a );
					$tok = (string) $clone->get_decrypted_param( 'page_access_token' );
					if ( $tok !== '' ) {
						return $tok;
					}
				}
			}
		}
		return '';
	}

	/**
	 * [2026-07-15 Johnny Chu] PHASE-TWINWEB F4 — runtime page ownership guard.
	 *
	 * @return true|WP_Error
	 */
	protected function assert_page_owner_matches_event( string $page_id, int $owner_user_id ) {
		$page_id       = trim( $page_id );
		$owner_user_id = (int) $owner_user_id;

		if ( $page_id === '' ) {
			return new WP_Error( 'invalid_param', 'fb_post: thiếu page_id.' );
		}
		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			return new WP_Error( 'module_not_loaded', 'fb_post: Facebook bot database chưa load.' );
		}

		$db  = BizCity_Facebook_Bot_Database::instance();
		$bot = null;

		if ( $owner_user_id > 0 && method_exists( $db, 'get_bot_by_user_page' ) ) {
			$bot = $db->get_bot_by_user_page( $owner_user_id, $page_id );
		}
		if ( ! $bot && method_exists( $db, 'get_bot_by_page_id' ) ) {
			$bot = $db->get_bot_by_page_id( $page_id );
		}
		if ( ! $bot ) {
			return new WP_Error( 'not_found', 'fb_post: fanpage chưa kết nối hoặc không tồn tại.' );
		}

		$bot_owner = (int) ( is_object( $bot ) ? ( $bot->user_id ?? 0 ) : ( $bot['user_id'] ?? 0 ) );

		if ( $owner_user_id > 0 ) {
			if ( $bot_owner === $owner_user_id ) {
				return true;
			}
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — allow contributor/member selected site-shared admin page; avoid user_can() in cron.
			if ( $bot_owner <= 0 && ( $this->owner_has_manage_options_cap( $owner_user_id ) || $this->owner_can_use_site_shared_page( $owner_user_id, $page_id, $bot ) ) ) {
				return true;
			}
			return new WP_Error( 'permission_denied', 'fb_post: page không thuộc owner của event.' );
		}

		if ( $bot_owner > 0 ) {
			return new WP_Error( 'permission_denied', 'fb_post: event thiếu owner_user_id cho page user-owned.' );
		}

		return true;
	}

	protected function owner_has_manage_options_cap( int $owner_user_id ): bool {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — safe cron capability check without current_user_can()/user_can().
		if ( $owner_user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return false;
		}
		$user = get_userdata( $owner_user_id );
		if ( ! $user || ! is_object( $user ) ) {
			return false;
		}
		$allcaps = is_array( $user->allcaps ?? null ) ? $user->allcaps : array();
		if ( ! empty( $allcaps['manage_options'] ) ) {
			return true;
		}
		$roles = is_array( $user->roles ?? null ) ? $user->roles : array();
		return in_array( 'administrator', $roles, true );
	}

	protected function owner_can_use_site_shared_page( int $owner_user_id, string $page_id, $bot ): bool {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — cron mirror of automation block shared-page gate.
		if ( $owner_user_id <= 0 || $page_id === '' ) {
			return false;
		}
		$allowed = (bool) apply_filters( 'bizcity_twinweb_allow_member_publish_shared_fb_page', true, $owner_user_id, $page_id, $bot );
		if ( ! $allowed ) {
			return false;
		}
		// [2026-07-27 Johnny Chu] R-PERF — read My Channels once through direct-SQL user-meta cache.
		$settings = class_exists( 'BizCity_User_Meta_Cache' )
			? BizCity_User_Meta_Cache::get( $owner_user_id, 'bizcity_twinweb_mychannels', array() )
			: get_user_meta( $owner_user_id, 'bizcity_twinweb_mychannels', true );
		$selected = is_array( $settings ) ? trim( (string) ( $settings['selected_fb_page_id'] ?? '' ) ) : '';
		return $selected === '' || $selected === $page_id;
	}

	/**
	 * Update event metadata (+ optional status).
	 */
	protected function write_metadata( int $event_id, array $meta, string $new_status = '' ): void {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return;
		}
		$mgr     = BizCity_Scheduler_Manager::instance();
		$payload = array( 'metadata' => wp_json_encode( $meta ) );
		if ( $new_status !== '' ) {
			$payload['status'] = $new_status;
		}
		// $user_id = null → admin bypass (cron context, no current user).
		$mgr->update_event( $event_id, $payload, null );
	}

	/**
	 * Decode metadata column into array. Accepts already-decoded array or JSON string.
	 *
	 * @param mixed $raw
	 */
	protected function decode_metadata( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}

	/**
	 * Classify Graph error into stable reason buckets for analytics.
	 *
	 * Refs: https://developers.facebook.com/docs/graph-api/guides/error-handling/
	 */
	protected function classify_graph_error( int $http_code, int $fb_code, string $msg ): string {
		if ( in_array( $fb_code, array( 190, 102, 467 ), true ) ) {
			return 'token_invalid';
		}
		if ( in_array( $fb_code, array( 200, 10, 299 ), true ) ) {
			return 'permission_denied';
		}
		if ( in_array( $fb_code, array( 4, 17, 32, 613, 80004 ), true ) ) {
			return 'rate_limited';
		}
		if ( in_array( $fb_code, array( 1, 2, 506, 368 ), true ) || $http_code >= 500 ) {
			return 'fb_transient';
		}
		if ( $http_code === 400 ) {
			return 'invalid_param';
		}
		return 'graph_error';
	}

	/**
	 * R-CRON-META helpers. Silently no-op if core/cron unavailable or outside
	 * cron context (e.g. when publish_event is triggered by tests).
	 */
	protected function cron_note_event( string $name, array $data ): void {
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->note_event( $name, $data );
		}
	}

	protected function cron_bump_counter( string $key, int $by = 1 ): void {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) { return; }
		$mgr    = BizCity_Cron_Manager::instance();
		$run_id = $mgr->current_run_id();
		if ( ! $run_id ) { return; }
		// Use note() with a synthetic counters branch — merge_meta will deep-merge.
		// We can't atomically increment, but cron runs are single-threaded per hook.
		global $wpdb;
		$t   = $wpdb->prefix . BizCity_Cron_Manager::TABLE_RUNS;
		$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$t} WHERE id=%d", $run_id ) );
		$cur = $raw !== '' ? json_decode( $raw, true ) : array();
		if ( ! is_array( $cur ) ) { $cur = array(); }
		$prev = (int) ( $cur['counters'][ $key ] ?? 0 );
		$mgr->note( array( 'counters' => array( $key => $prev + $by ) ) );
	}
}
