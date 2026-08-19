<?php
/**
 * Action: Capture to Notebook (Bridge)
 *
 * Direct workflow-level integration to
 * BizCity_KG_Channel_Notebook_Bridge::capture_batch(), so automation
 * scenarios can save text/files into KG notebooks without relying on
 * channel-specific "@notebook" listeners.
 *
 * [2026-07-26 Johnny Chu] PHASE-0.46 W5 — this block is for GENERIC,
 * non-interactive workflow scenarios (e.g. a cron/webhook workflow that
 * captures a fixed payload into a notebook). For interactive Zalo chat
 * capture, `@notebook`/`@ghichu` are now BOTH handled natively by
 * `BizCity_Zalobot_Notebook_Bridge_Listener` (which owns the "ask for more
 * files before saving" confirmation flow this block does NOT implement —
 * it saves immediately with whatever it is given). The legacy
 * `tpl_zalo_capture_to_notebook_v1` keyword workflow that used to invoke
 * this block for `@ghichu` is retired at runtime (see
 * `class-notebook-bridge-listener.php::maybe_disable_legacy_ghichu_workflow()`).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      PHASE-0.46 W2
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Capture_To_Notebook extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'action.capture_to_notebook'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		// [2026-07-24 Johnny Chu] PHASE-0.46 W2 — block metadata for direct workflow notebook capture.
		return array(
			'label'    => 'Lưu vào Notebook (Bridge)',
			'short'    => 'capture_to_notebook',
			'category' => 'state',
			'color'    => '#0ea5e9',
			'icon'     => 'book-open',
			'defaults' => array(
				'label'                      => 'capture_to_notebook',
				'user_id'                    => '',
				'title_hint'                 => '{{trigger.text}}',
				'content'                    => '{{trigger.text}}',
				'kind'                       => 'auto', // auto|text|image|audio|file
				'channel'                    => '',
				'day_key'                    => '',
				'include_trigger_media'      => 1,
				'include_pending_attachments'=> 1,
				'clear_pending_after_capture'=> 0,
			),
			'fields' => array(
				array( 'name' => 'label',                      'label' => 'Tên hiển thị',                  'type' => 'text' ),
				array( 'name' => 'user_id',                    'label' => 'WP User ID override (optional)', 'type' => 'text' ),
				array( 'name' => 'title_hint',                 'label' => 'Tiêu đề gợi ý (template)',      'type' => 'text' ),
				array( 'name' => 'content',                    'label' => 'Nội dung text (template)',       'type' => 'textarea' ),
				array( 'name' => 'kind',                       'label' => 'Loại capture',                   'type' => 'text' ),
				array( 'name' => 'channel',                    'label' => 'Channel override (optional)',    'type' => 'text' ),
				array( 'name' => 'day_key',                    'label' => 'Ngày Ymd (optional)',            'type' => 'text' ),
				array( 'name' => 'include_trigger_media',      'label' => 'Lấy media từ trigger',           'type' => 'checkbox' ),
				array( 'name' => 'include_pending_attachments','label' => 'Lấy media từ pending state',     'type' => 'checkbox' ),
				array( 'name' => 'clear_pending_after_capture','label' => 'Xóa pending state sau khi lưu',  'type' => 'checkbox' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-07-24 Johnny Chu] PHASE-0.46 W2 — execute capture_batch directly from automation action context.
		if ( ! class_exists( 'BizCity_KG_Channel_Notebook_Bridge' ) ) {
			// [2026-07-24 Johnny Chu] PHASE-0.46 W2 — reason bucket evidence for fail-closed bridge availability.
			$this->note_event( 'capture_to_notebook_failed', array(
				'reason' => 'bridge_unavailable',
				'code'   => 'notebook_bridge_unavailable',
			) );
			return new WP_Error( 'notebook_bridge_unavailable', 'Notebook bridge chưa sẵn sàng trên site này.' );
		}

		$trigger  = isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
		$chat_id  = (string) ( $trigger['conversation_chat_id'] ?? $trigger['chat_id'] ?? '' );
		if ( $chat_id === '' ) {
			$this->note_event( 'capture_to_notebook_failed', array(
				'reason' => 'chat_id_missing',
				'code'   => 'no_chat_id',
			) );
			return new WP_Error( 'no_chat_id', 'capture_to_notebook: trigger.chat_id rỗng.' );
		}

		$channel_override = $this->clean_resolved_text( (string) $this->resolve( $data['channel'] ?? '', $ctx ) );
		$platform_hint    = (string) ( $trigger['platform'] ?? $trigger['channel'] ?? '' );
		$channel          = $this->map_channel_code( $channel_override !== '' ? $channel_override : $platform_hint );
		if ( $channel === '' ) {
			$this->note_event( 'capture_to_notebook_failed', array(
				'reason' => 'channel_unresolved',
				'code'   => 'invalid_channel',
			) );
			return new WP_Error( 'invalid_channel', 'capture_to_notebook: không xác định được channel.' );
		}

		$user_override = (int) $this->resolve( $data['user_id'] ?? 0, $ctx );
		$user_id       = $this->resolve_owner_user_id( $ctx, $user_override );
		if ( $user_id <= 0 ) {
			$this->note_event( 'capture_to_notebook_failed', array(
				'reason' => 'owner_user_missing',
				'code'   => 'notebook_bridge_invalid_identity',
			) );
			return new WP_Error( 'notebook_bridge_invalid_identity', 'capture_to_notebook: thiếu user_id hợp lệ để lưu notebook.' );
		}

		$title_hint = $this->clean_resolved_text( (string) $this->resolve( $data['title_hint'] ?? '', $ctx ) );
		$content    = $this->clean_resolved_text( (string) $this->resolve( $data['content'] ?? '', $ctx ) );
		$day_key    = sanitize_key( $this->clean_resolved_text( (string) $this->resolve( $data['day_key'] ?? '', $ctx ) ) );
		$chat_kind  = sanitize_key( (string) ( $trigger['chat_kind'] ?? 'private' ) );
		$chat_kind  = $chat_kind === 'group' ? 'group' : 'private';

		$include_trigger_media = $this->to_bool( $data['include_trigger_media'] ?? 1 );
		$include_pending       = $this->to_bool( $data['include_pending_attachments'] ?? 1 );
		$clear_pending         = $this->to_bool( $data['clear_pending_after_capture'] ?? 0 );
		$kind_mode             = sanitize_key( (string) $this->resolve( $data['kind'] ?? 'auto', $ctx ) );
		if ( ! in_array( $kind_mode, array( 'auto', 'text', 'image', 'audio', 'file' ), true ) ) {
			$kind_mode = 'auto';
		}

		$attachments = array();

		if ( $include_trigger_media ) {
			$media_url = trim( (string) ( $trigger['media_url'] ?? '' ) );
			if ( $media_url !== '' ) {
				$attachments[] = array(
					'kind'       => sanitize_key( (string) ( $trigger['media_kind'] ?? 'image' ) ),
					'url'        => $media_url,
					'source_url' => $media_url,
					'message_id' => (string) ( $trigger['mid'] ?? $trigger['message_id'] ?? '' ),
				);
			}
		}

		if ( $include_pending && class_exists( 'BizCity_Automation_Pending_State' ) ) {
			$state = BizCity_Automation_Pending_State::get( $chat_id );
			$pending_items = is_array( $state['attachments'] ?? null ) ? $state['attachments'] : array();
			foreach ( $pending_items as $item ) {
				if ( is_array( $item ) ) {
					$attachments[] = $item;
				}
			}
			// [2026-07-26 Johnny Chu] PHASE-0.46 W5 R4 — defense-in-depth: ALSO
			// drain BizCity_KG_Channel_Notebook_Bridge's own inbox. A file sent
			// before this workflow's keyword trigger fires may have queued
			// ONLY there (the bridge's inbox mirrors into Pending_State on
			// write, but older/already-in-flight items or channels this
			// action targets directly may not have gone through that mirror).
			// Never drop a file the user already sent.
			if ( class_exists( 'BizCity_KG_Channel_Notebook_Bridge' ) ) {
				$bridge_chat_key = (string) ( $trigger['provider_chat_id'] ?? $chat_id );
				$bridge_items    = BizCity_KG_Channel_Notebook_Bridge::drain_pending_attachments( $channel, $bridge_chat_key );
				foreach ( $bridge_items as $item ) {
					if ( is_array( $item ) ) {
						$attachments[] = $item;
					}
				}
			}
		}

		$attachments = $this->dedup_attachments( $attachments );

		$items = array();

		// [2026-07-24 Johnny Chu] PHASE-0.46 W2 — explicit body with ':' keeps
		// text capture semantics; bare title-only scenarios can pass empty
		// content and rely on attachment-only items.
		if ( $kind_mode === 'auto' || $kind_mode === 'text' ) {
			if ( $content !== '' ) {
				$items[] = array(
					'kind'       => 'text',
					'content'    => $content,
					'title_hint' => $title_hint,
					'message_id' => (string) ( $trigger['mid'] ?? $trigger['message_id'] ?? '' ),
				);
			}
		}

		if ( $kind_mode === 'auto' || $kind_mode === 'image' || $kind_mode === 'audio' || $kind_mode === 'file' ) {
			foreach ( $attachments as $att ) {
				$att_kind = sanitize_key( (string) ( $att['kind'] ?? 'image' ) );
				if ( $kind_mode !== 'auto' ) {
					$att_kind = $kind_mode;
				}
				$items[] = array(
					'kind'       => $att_kind,
					'title_hint' => $title_hint,
					'message_id' => (string) ( $att['message_id'] ?? '' ),
					'attachment' => $att,
				);
			}
		}

		if ( empty( $items ) ) {
			$this->note_event( 'capture_to_notebook_failed', array(
				'reason' => 'empty_payload',
				'code'   => 'notebook_bridge_empty_batch',
			) );
			return new WP_Error( 'notebook_bridge_empty_batch', 'capture_to_notebook: không có nội dung hoặc tệp để lưu.' );
		}

		$inbound = isset( $trigger['inbound'] ) && is_array( $trigger['inbound'] ) ? $trigger['inbound'] : array(
			'platform'   => strtoupper( (string) ( $trigger['platform'] ?? '' ) ),
			'chat_id'    => (string) ( $trigger['chat_id'] ?? '' ),
			'user_id'    => (string) ( $trigger['user_id'] ?? '' ),
			'account_id' => (string) ( $trigger['account_id'] ?? '' ),
			'message_id' => (string) ( $trigger['mid'] ?? $trigger['message_id'] ?? '' ),
			'raw_text'   => (string) ( $trigger['raw_text'] ?? $trigger['text'] ?? '' ),
		);

		$base_envelope = array(
			'user_id'          => $user_id,
			'channel'          => $channel,
			'chat_id'          => $chat_id,
			'chat_kind'        => $chat_kind,
			'provider_chat_id' => (string) ( $trigger['provider_chat_id'] ?? $chat_id ),
			'scope_type'       => $chat_kind,
			'scope_id'         => (string) ( $trigger['provider_chat_id'] ?? $chat_id ),
			'title_hint'       => $title_hint,
			'day_key'          => $day_key,
			'inbound'          => $inbound,
		);

		$res = BizCity_KG_Channel_Notebook_Bridge::instance()->capture_batch( $base_envelope, $items );
		if ( is_wp_error( $res ) ) {
			$this->note_event( 'capture_to_notebook_failed', array(
				'reason' => 'capture_failed',
				'error'  => $res->get_error_message(),
				'code'   => (string) $res->get_error_code(),
			) );
			return $res;
		}

		$succeeded = (int) ( $res['succeeded'] ?? 0 );
		// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — queued cron-dispatch items count as accepted capture.
		$queued    = (int) ( $res['queued'] ?? 0 );
		if ( ( $succeeded + $queued ) <= 0 ) {
			$first_failed = '';
			if ( ! empty( $res['failed'] ) && is_array( $res['failed'] ) ) {
				$first_failed = (string) ( $res['failed'][0]['error'] ?? '' );
			}
			$msg = 'capture_to_notebook: tất cả mục đều lưu thất bại.';
			if ( $first_failed !== '' ) {
				$msg .= ' ' . $first_failed;
			}
			$err = new WP_Error( 'notebook_bridge_capture_all_failed', $msg );
			$this->note_event( 'capture_to_notebook_failed', array(
				'reason' => 'all_items_failed',
				'error'  => $msg,
				'code'   => 'notebook_bridge_capture_all_failed',
			) );
			return $err;
		}

		if ( $clear_pending && class_exists( 'BizCity_Automation_Pending_State' ) && ! empty( $attachments ) ) {
			BizCity_Automation_Pending_State::clear( $chat_id );
		}

		// [2026-08-13 Johnny Chu] PHASE-0.46 W6 — expose the canonical Zalo upload link after a direct workflow capture.
		$upload_url = $this->mint_zalo_upload_link( $ctx, $trigger, $user_id, (int) ( $res['notebook_id'] ?? 0 ), $chat_id );
		$upload_prompt = $upload_url !== '' ? 'Sếp có muốn upload thêm tài liệu (Word/Excel/PDF) không? Mở link trên để tải thêm, xong nhắn "không" hoặc "xong".' : '';

		$this->note_event( 'capture_to_notebook_ok', array(
			'notebook_id' => (int) ( $res['notebook_id'] ?? 0 ),
			'total'       => (int) ( $res['total'] ?? 0 ),
			'succeeded'   => $succeeded,
			'queued'      => $queued,
			'upload_link' => $upload_url !== '' ? 1 : 0,
		) );

		// [2026-07-25 Johnny Chu] PHASE-0.46 W4.6 — expose accepted file list
		// + first source id so downstream reply blocks can render richer
		// confirmations without re-parsing mixed trigger/pending payloads.
		$summary = $this->build_capture_item_summary( $items, $res );

		return array(
			'notebook_id'       => (int) ( $res['notebook_id'] ?? 0 ),
			'notebook_name'     => (string) ( $res['notebook_name'] ?? '' ),
			'notebook_created'  => ! empty( $res['notebook_created'] ),
			'batch_id'          => (string) ( $res['batch_id'] ?? '' ),
			'captured_total'    => (int) ( $res['total'] ?? 0 ),
			'captured_succeeded'=> $succeeded,
			'captured_queued'   => $queued,
			'failed'            => (array) ( $res['failed'] ?? array() ),
			'items'             => (array) ( $res['items'] ?? array() ),
			'accepted_file_count' => (int) ( $summary['file_count'] ?? 0 ),
			'accepted_files_text' => (string) ( $summary['files_text'] ?? '' ),
			'first_source_id'     => (int) ( $summary['first_source_id'] ?? 0 ),
			'upload_url'          => $upload_url,
			'upload_prompt'       => $upload_prompt,
		);
	}

	private function mint_zalo_upload_link( array $ctx, array $trigger, int $user_id, int $notebook_id, string $chat_id ): string {
		// [2026-08-13 Johnny Chu] PHASE-0.46 W6 — mint a bounded capability URL only for the Zalo Bot Zone 2 workflow.
		$platform = strtoupper( (string) ( $trigger['platform'] ?? $trigger['channel'] ?? '' ) );
		$channel  = $this->map_channel_code( $platform );
		if ( $channel !== 'zalobot' ) {
			return '';
		}
		if ( ! class_exists( 'BizCity_KG_Channel_Upload_Link_Service' ) || ! class_exists( 'BizCity_Zalobot_Upload_Link_Handler' ) ) {
			$this->note_event( 'capture_to_notebook_upload_link_skipped', array( 'reason' => 'upload_service_unavailable' ) );
			return '';
		}

		$provider_chat_id = trim( (string) ( $trigger['provider_chat_id'] ?? $trigger['conversation_chat_id'] ?? $chat_id ) );
		$bot_id           = (int) ( $trigger['account_id'] ?? $trigger['bot_id'] ?? 0 );
		if ( $provider_chat_id === '' || $bot_id <= 0 || $user_id <= 0 ) {
			$this->note_event( 'capture_to_notebook_upload_link_skipped', array( 'reason' => 'upload_identity_missing' ) );
			return '';
		}

		$chat_kind          = sanitize_key( (string) ( $trigger['chat_kind'] ?? 'private' ) );
		$chat_kind          = $chat_kind === 'group' ? 'group' : 'private';
		$automation_chat_id = 'zalobot_' . $bot_id . '_' . ( $chat_kind === 'group' ? 'group_' : 'private_' ) . $provider_chat_id;
		$mode               = 'pending';
		if ( class_exists( 'BizCity_KG_Channel_Notebook_Bridge' ) && method_exists( 'BizCity_KG_Channel_Notebook_Bridge', 'get_capture_session' ) ) {
			$session = BizCity_KG_Channel_Notebook_Bridge::get_capture_session( 'zalobot', $provider_chat_id );
			if ( is_array( $session ) && ! empty( $session['awaiting_more'] ) ) {
				$mode = 'session';
			}
		}

		$link = BizCity_KG_Channel_Upload_Link_Service::create( array(
			'channel'            => 'zalobot',
			'chat_key'           => $provider_chat_id,
			'provider_chat_id'   => $provider_chat_id,
			'bot_id'             => $bot_id,
			'wp_user_id'         => $user_id,
			'mode'               => $mode,
			'notebook_id'        => $notebook_id,
			'automation_chat_id' => $automation_chat_id,
		) );
		if ( is_wp_error( $link ) ) {
			$this->note_event( 'capture_to_notebook_upload_link_skipped', array( 'reason' => 'upload_link_create_failed', 'code' => (string) $link->get_error_code() ) );
			return '';
		}

		return (string) BizCity_Zalobot_Upload_Link_Handler::build_url( (string) ( $link['token'] ?? '' ) );
	}

	private function map_channel_code( string $input ): string {
		// [2026-07-24 Johnny Chu] PHASE-0.46 W2 — normalize channel/platform aliases to bridge channel codes.
		$raw = strtoupper( trim( $input ) );
		if ( $raw === '' ) {
			return '';
		}
		switch ( $raw ) {
			case 'ZALO_BOT':
			case 'ZALOBOT':
			case 'ZALO':
				return 'zalobot';
			case 'FB_MESS':
			case 'FACEBOOK':
			case 'MESSENGER':
				return 'messenger';
			case 'FB_FEED':
				return 'facebook';
			case 'WEBCHAT':
				return 'webchat';
			case 'TELEGRAM':
				return 'telegram';
			case 'TWINCHAT':
				return 'twinchat';
			case 'TWINWEB':
				return 'twinweb';
			default:
				return sanitize_key( strtolower( $raw ) );
		}
	}

	private function to_bool( $v ): bool {
		// [2026-07-24 Johnny Chu] PHASE-0.46 W2 — normalize checkbox/template values to strict booleans.
		if ( is_bool( $v ) ) { return $v; }
		if ( is_numeric( $v ) ) { return (int) $v !== 0; }
		$s = strtolower( trim( (string) $v ) );
		return in_array( $s, array( '1', 'true', 'yes', 'on' ), true );
	}

	private function dedup_attachments( array $items ): array {
		// [2026-07-24 Johnny Chu] PHASE-0.46 W2 — dedup by attachment_id/message_id/url before batch capture.
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) { continue; }
			$url = trim( (string) ( $item['url'] ?? $item['source_url'] ?? '' ) );
			if ( $url === '' && ! empty( $item['attachment_id'] ) ) {
				$key = 'attid:' . (int) $item['attachment_id'];
			} else {
				$mid = trim( (string) ( $item['message_id'] ?? '' ) );
				$key = $mid !== '' ? 'mid:' . $mid : 'url:' . $url;
			}
			if ( isset( $out[ $key ] ) ) { continue; }
			$out[ $key ] = array(
				'kind'          => sanitize_key( (string) ( $item['kind'] ?? 'image' ) ),
				'url'           => $url,
				'source_url'    => trim( (string) ( $item['source_url'] ?? $url ) ),
				'wp_url'        => trim( (string) ( $item['wp_url'] ?? '' ) ),
				'file_name'     => trim( (string) ( $item['file_name'] ?? '' ) ),
				'attachment_id' => (int) ( $item['attachment_id'] ?? 0 ),
				'message_id'    => trim( (string) ( $item['message_id'] ?? '' ) ),
			);
		}
		return array_values( $out );
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.6 — summarize accepted file rows
	 * for downstream user-facing replies (list of uploaded files + first source).
	 *
	 * @return array{file_count:int,files_text:string,first_source_id:int}
	 */
	private function build_capture_item_summary( array $items, array $res ): array {
		$accepted_indexes = array();
		$first_source_id  = 0;

		foreach ( (array) ( $res['items'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['index'] ) ) {
				$accepted_indexes[] = (int) $row['index'];
			}
			if ( $first_source_id <= 0 && ! empty( $row['source_id'] ) ) {
				$first_source_id = (int) $row['source_id'];
			}
		}
		foreach ( (array) ( $res['queued_jobs'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['index'] ) ) {
				$accepted_indexes[] = (int) $row['index'];
			}
			if ( $first_source_id <= 0 && ! empty( $row['source_id'] ) ) {
				$first_source_id = (int) $row['source_id'];
			}
		}

		$accepted_indexes = array_values( array_unique( array_filter( $accepted_indexes, static function ( $idx ) {
			return is_int( $idx ) && $idx >= 0;
		} ) ) );
		sort( $accepted_indexes );

		$lines = array();
		foreach ( $accepted_indexes as $idx ) {
			$item = isset( $items[ $idx ] ) && is_array( $items[ $idx ] ) ? $items[ $idx ] : array();
			$kind = sanitize_key( (string) ( $item['kind'] ?? '' ) );
			if ( $kind === 'text' ) {
				continue;
			}
			$att = isset( $item['attachment'] ) && is_array( $item['attachment'] ) ? $item['attachment'] : array();
			$name = trim( (string) ( $att['file_name'] ?? '' ) );
			if ( $name === '' ) {
				$url = trim( (string) ( $att['source_url'] ?? $att['url'] ?? '' ) );
				if ( $url !== '' ) {
					$path = (string) wp_parse_url( $url, PHP_URL_PATH );
					$name = basename( $path );
				}
			}
			if ( $name === '' ) {
				if ( $kind === 'image' ) {
					$name = 'Anh da gui';
				} elseif ( $kind === 'audio' ) {
					$name = 'Ghi am da gui';
				} else {
					$name = 'Tai lieu da gui';
				}
			}
			$lines[] = '- ' . sanitize_text_field( $name );
		}

		if ( empty( $lines ) ) {
			$files_text = '- (khong co tep dinh kem; chi luu ghi chu van ban)';
		} else {
			$files_text = implode( "\n", $lines );
		}

		return array(
			'file_count'      => count( $lines ),
			'files_text'      => $files_text,
			'first_source_id' => $first_source_id,
		);
	}

	private function clean_resolved_text( string $v ): string {
		// [2026-07-24 Johnny Chu] PHASE-0.46 W2 — discard unresolved {{token}} templates to avoid noisy persistence.
		$v = trim( $v );
		if ( $v !== '' && preg_match( '/^\{\{\s*[a-z0-9_.]+\s*\}\}$/i', $v ) ) {
			return '';
		}
		return $v;
	}
}
