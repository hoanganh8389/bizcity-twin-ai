<?php
/**
 * BizCity_Zalobot_Upload_Link_Handler
 *
 * Public, NO-LOGIN, capability-URL page: `/zalo-upload/{token}/`. Renders a
 * mobile-friendly upload form (multi-file + drag-drop) and, on submit,
 * uploads straight to the WP Media Library then mounts each file into the
 * SAME pending-inbox / open-session transient state the native Zalo capture
 * flow already uses (`BizCity_KG_Channel_Notebook_Bridge`), so a file that
 * arrived via `message.unsupported.received` (no downloadable Zalo URL) can
 * still be captured end-to-end.
 *
 * Token lifecycle is fully owned by `BizCity_KG_Channel_Upload_Link_Service`
 * (channel-agnostic, transient-only, no schema). This class only:
 *   1. Registers the rewrite rule / query var / template_redirect handler.
 *   2. Renders GET (consumes one "open" from the token's budget).
 *   3. Handles POST (validates + uploads + mounts + confirms via Zalo).
 *
 * SECURITY — see class-kg-channel-upload-link-service.php header +
 * docs/roadmaps/PHASE-0.46-CHANNEL-NOTEBOOK-BRIDGE.md §Wave 6 for the full
 * "instant link vs login-required" tradeoff writeup. This handler NEVER
 * trusts any client-supplied identity (user_id/chat_id/bot_id) — everything
 * is resolved from the server-side token record only.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\Bizcity_Zalo_Bot
 * @since      PHASE-0.46 Wave 6
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalobot_Upload_Link_Handler', false ) ) {
	return;
}

// [2026-07-26 Johnny Chu] PHASE-0.46 W6 / R-CR — register the new rewrite
// rule with the central flush registry at FILE-LOAD TIME instead of calling
// flush_rewrite_rules() from an init hook. ONE admin_init flush covers every
// module's version bump, so bumping this string is the ONLY thing needed
// when this route's rewrite pattern ever changes.
if ( class_exists( 'BizCity_Rewrite_Flush_Registry' ) ) {
	BizCity_Rewrite_Flush_Registry::register( 'bizcity-zalo-bot-upload-link', 'PHASE-0.46-W6-1' );
}

class BizCity_Zalobot_Upload_Link_Handler {

	const SLUG        = 'zalo-upload';
	const QUERY_TOKEN = 'bizcity_zalo_upload_token';
	const NONCE_ACTION_PREFIX = 'bizcity_zalo_upload_';

	/** Max size per file, in bytes — filterable. */
	const MAX_FILE_SIZE = 26214400; // 25MB

	/** Max files accepted in one POST — filterable. */
	const MAX_FILES_PER_UPLOAD = 10;

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_action( 'init', array( __CLASS__, 'early_route' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ) );
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX — pre-flush fallback.
	 *
	 * On fresh deploys before rewrite rules are flushed, direct requests to
	 * `/zalo-upload/{token}/` would 404. Mirror the established
	 * class-tool-zalo-page.php pattern: detect path early and force-route
	 * template_redirect to this handler.
	 */
	public static function early_route(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		if ( $path === '' ) {
			return;
		}
		if ( ! preg_match( '#^' . preg_quote( self::SLUG, '#' ) . '/([a-f0-9]{48})/?$#', $path, $m ) ) {
			return;
		}
		set_query_var( self::QUERY_TOKEN, (string) $m[1] );
		add_action( 'template_redirect', static function (): void {
			BizCity_Zalobot_Upload_Link_Handler::maybe_handle();
		}, 0 );
	}

	public static function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^' . self::SLUG . '/([a-f0-9]{48})/?$',
			'index.php?' . self::QUERY_TOKEN . '=$matches[1]',
			'top'
		);
	}

	public static function add_query_var( $vars ) {
		if ( ! in_array( self::QUERY_TOKEN, $vars, true ) ) {
			$vars[] = self::QUERY_TOKEN;
		}
		return $vars;
	}

	/**
	 * Build the full instant upload link URL for a freshly-minted token.
	 */
	public static function build_url( string $token ): string {
		return home_url( '/' . self::SLUG . '/' . $token . '/' );
	}

	public static function maybe_handle(): void {
		$token = (string) get_query_var( self::QUERY_TOKEN );
		if ( $token === '' ) {
			return;
		}
		if ( ! class_exists( 'BizCity_KG_Channel_Upload_Link_Service' ) ) {
			self::render_message_page( 'Dịch vụ TwinNote chưa sẵn sàng trên site này.', array() );
			exit;
		}
		if ( ! class_exists( 'BizCity_KG_Channel_Notebook_Bridge' ) ) {
			// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — fail closed
			// instead of calling the session bridge when its loader is unavailable.
			self::render_message_page( 'Kết nối TwinNote chưa sẵn sàng trên site này.', array(), true );
			exit;
		}

		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		if ( $method === 'POST' ) {
			self::handle_post( $token );
		} else {
			self::handle_get( $token );
		}
		exit;
	}

	/* ─────────────────────────────── GET ─────────────────────────────── */

	private static function handle_get( string $token ): void {
		$record = BizCity_KG_Channel_Upload_Link_Service::resolve_and_consume_open( $token );
		if ( is_wp_error( $record ) ) {
			self::render_message_page( $record->get_error_message(), array(), true );
			return;
		}
		self::render_upload_form( $token, $record );
	}

	/* ─────────────────────────────── POST ────────────────────────────── */

	private static function handle_post( string $token ): void {
		if ( ! isset( $_POST['bizcity_zalo_upload_nonce'] )
			|| ! wp_verify_nonce( (string) $_POST['bizcity_zalo_upload_nonce'], self::NONCE_ACTION_PREFIX . $token )
		) {
			self::render_message_page( 'Phiên tải lên không hợp lệ hoặc đã hết hạn — vui lòng mở lại link mới nếu cần.', array(), true );
			return;
		}

		$record = BizCity_KG_Channel_Upload_Link_Service::peek( $token );
		if ( is_wp_error( $record ) ) {
			self::render_message_page( $record->get_error_message(), array(), true );
			return;
		}

		$files = self::normalize_files_input( $_FILES['bizcity_files'] ?? null );
		if ( empty( $files ) ) {
			self::render_message_page( 'Bạn chưa chọn file nào để tải lên.', array(), true, $token );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$allowed_mimes = self::allowed_mimes();
		$max_size      = (int) apply_filters( 'bizcity_zalo_upload_link_max_file_size', self::MAX_FILE_SIZE );
		$max_files     = (int) apply_filters( 'bizcity_zalo_upload_link_max_files', self::MAX_FILES_PER_UPLOAD );
		$files         = array_slice( $files, 0, max( 1, $max_files ) );

		$accepted = array();
		$rejected = array();
		$channel  = (string) ( $record['channel'] ?? '' );
		$chat_key = (string) ( $record['chat_key'] ?? '' );
		$mode     = (string) ( $record['mode'] ?? 'pending' );
		$auto_cid = (string) ( $record['automation_chat_id'] ?? '' );
		$had_session_fallback = false;

		foreach ( $files as $file ) {
			if ( ! empty( $file['error'] ) && (int) $file['error'] !== UPLOAD_ERR_OK ) {
				$rejected[] = array( 'name' => (string) ( $file['name'] ?? '' ), 'reason' => 'upload_error' );
				continue;
			}
			if ( (int) ( $file['size'] ?? 0 ) > $max_size ) {
				$rejected[] = array( 'name' => (string) ( $file['name'] ?? '' ), 'reason' => 'too_large' );
				continue;
			}

			$upload = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => $allowed_mimes ) );
			if ( ! empty( $upload['error'] ) ) {
				$rejected[] = array( 'name' => (string) ( $file['name'] ?? '' ), 'reason' => (string) $upload['error'] );
				continue;
			}

			$attach_id = wp_insert_attachment( array(
				'post_mime_type' => $upload['type'],
				'post_title'     => sanitize_file_name( (string) ( $file['name'] ?? 'zalo-upload' ) ),
				'post_status'    => 'inherit',
			), $upload['file'] );
			if ( is_wp_error( $attach_id ) || ! $attach_id ) {
				$rejected[] = array( 'name' => (string) ( $file['name'] ?? '' ), 'reason' => 'insert_failed' );
				continue;
			}
			wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $upload['file'] ) );

			// [2026-07-26 Johnny Chu] PHASE-0.46 W6 — Requirement #4: stamp the
			// owning notebook id (when already known, e.g. session mode with an
			// eagerly-created notebook) directly here too — belt & suspenders
			// alongside the centralized tagging in build_ingest_payload() at
			// finalize time, in case finalize is ever skipped/queued differently.
			if ( ! empty( $record['notebook_id'] ) ) {
				update_post_meta( $attach_id, '_bizcity_kg_notebook_id', (int) $record['notebook_id'] );
			}

			$attachment = array(
				'kind'          => self::kind_from_mime( (string) $upload['type'] ),
				'url'           => (string) wp_get_attachment_url( $attach_id ),
				'source_url'    => (string) wp_get_attachment_url( $attach_id ),
				'attachment_id' => (int) $attach_id,
				'mime'          => (string) $upload['type'],
				'file_name'     => sanitize_file_name( (string) ( $file['name'] ?? '' ) ),
				'message_id'    => 'upload_link_' . $attach_id,
			);

			if ( $mode === 'session' ) {
				$session_after = BizCity_KG_Channel_Notebook_Bridge::append_session_attachment( $channel, $chat_key, $attachment, BizCity_KG_Channel_Notebook_Bridge::PENDING_TTL_DEFAULT, $auto_cid );
				if ( ! is_array( $session_after ) ) {
					// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX — session may
					// expire while user is on upload page. Fallback to pending inbox
					// instead of silently dropping the file.
					BizCity_KG_Channel_Notebook_Bridge::queue_pending_attachment( $channel, $chat_key, $attachment, BizCity_KG_Channel_Notebook_Bridge::PENDING_TTL_DEFAULT, $auto_cid );
					$attachment['mount_mode'] = 'pending_fallback';
					$had_session_fallback = true;
				} else {
					$attachment['mount_mode'] = 'session';
				}
			} else {
				BizCity_KG_Channel_Notebook_Bridge::queue_pending_attachment( $channel, $chat_key, $attachment, BizCity_KG_Channel_Notebook_Bridge::PENDING_TTL_DEFAULT, $auto_cid );
				$attachment['mount_mode'] = 'pending';
			}

			$accepted[] = $attachment;
		}

		if ( ! empty( $accepted ) ) {
			BizCity_KG_Channel_Upload_Link_Service::record_upload( $token, count( $accepted ) );
			self::send_zalo_confirmation( $record, $accepted, $mode, $had_session_fallback );
			self::log_db_event( $record, 'upload_link.upload_ok', array(
				'count'    => count( $accepted ),
				'rejected' => count( $rejected ),
				'mode'     => $mode,
				'session_fallback' => $had_session_fallback ? 1 : 0,
			) );
			// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX — close token after
			// first successful upload submission to reduce replay window.
			BizCity_KG_Channel_Upload_Link_Service::invalidate( $token );
		} else {
			self::log_db_event( $record, 'upload_link.upload_failed', array(
				'rejected' => count( $rejected ),
				'mode'     => $mode,
			) );
		}

		self::render_result_page( $accepted, $rejected );
	}

	/* ─────────────────────────────── helpers ─────────────────────────── */

	private static function normalize_files_input( $files ): array {
		if ( ! is_array( $files ) || empty( $files['name'] ) ) {
			return array();
		}
		if ( ! is_array( $files['name'] ) ) {
			return array( $files ); // single-file submission
		}
		$out = array();
		foreach ( $files['name'] as $i => $name ) {
			if ( $name === '' ) {
				continue;
			}
			$out[] = array(
				'name'     => $name,
				'type'     => $files['type'][ $i ] ?? '',
				'tmp_name' => $files['tmp_name'][ $i ] ?? '',
				'error'    => $files['error'][ $i ] ?? UPLOAD_ERR_NO_FILE,
				'size'     => $files['size'][ $i ] ?? 0,
			);
		}
		return $out;
	}

	private static function allowed_mimes(): array {
		return (array) apply_filters( 'bizcity_zalo_upload_link_allowed_mimes', array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
			'pdf'          => 'application/pdf',
			'doc'          => 'application/msword',
			'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'          => 'application/vnd.ms-excel',
			'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt'          => 'application/vnd.ms-powerpoint',
			'pptx'         => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'txt'          => 'text/plain',
			'md'           => 'text/markdown',
			'mp3'          => 'audio/mpeg',
			'm4a'          => 'audio/mp4',
			'ogg'          => 'audio/ogg',
			'wav'          => 'audio/wav',
			'webm'         => 'audio/webm',
		) );
	}

	private static function kind_from_mime( string $mime ): string {
		if ( strpos( $mime, 'image/' ) === 0 ) {
			return 'image';
		}
		if ( strpos( $mime, 'audio/' ) === 0 ) {
			return 'audio';
		}
		return 'file';
	}

	private static function send_zalo_confirmation( array $record, array $accepted_files, string $mode, bool $had_session_fallback = false ): void {
		$bot_id = (int) ( $record['bot_id'] ?? 0 );
		$chat_id = (string) ( $record['provider_chat_id'] ?? $record['chat_key'] ?? '' );
		if ( $bot_id <= 0 || $chat_id === '' || ! function_exists( 'bizcity_get_zalo_bot_api' ) ) {
			return;
		}
		$api = bizcity_get_zalo_bot_api( $bot_id );
		if ( ! $api || ! method_exists( $api, 'send_message' ) ) {
			return;
		}
		$count = count( $accepted_files );
		$media_urls = array();
		foreach ( $accepted_files as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$url = trim( (string) ( $row['url'] ?? $row['source_url'] ?? '' ) );
			if ( $url !== '' ) {
				$media_urls[] = $url;
			}
		}
		$media_urls = array_values( array_unique( $media_urls ) );

		if ( $mode === 'session' && ! $had_session_fallback ) {
			$text = sprintf(
				"✅ Đã nhận %d file bạn vừa tải lên qua link — em đã gắn vào TwinNote đang mở.\nSếp gửi tiếp file khác, hoặc nhắn \"không\"/\"xong\" để em lưu lại.",
				$count
			);
		} elseif ( $mode === 'session' && $had_session_fallback ) {
			$text = sprintf(
				"✅ Đã nhận %d file bạn vừa tải lên qua link.\nPhiên TwinNote trước đó đã hết hạn nên em chuyển file vào hàng đợi mới. Sếp nhắn \"@ghichu <tiêu đề>\" để lưu tiếp nhé.",
				$count
			);
		} else {
			$text = sprintf(
				"✅ Đã nhận %d file bạn vừa tải lên qua link.\nSếp nhắn \"@ghichu <tiêu đề>\" (hoặc \"@notebook <tiêu đề>\") để em lưu vào TwinNote nhé.",
				$count
			);
		}
		if ( ! empty( $media_urls ) ) {
			// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-6 — include the real
			// WP Media URL(s) in the confirmation so user can verify exactly which
			// uploaded file was mounted into session/pending.
			$text .= "\n\n🔗 File đã tải:";
			$limit = min( 3, count( $media_urls ) );
			for ( $i = 0; $i < $limit; $i++ ) {
				$text .= "\n" . $media_urls[ $i ];
			}
			if ( count( $media_urls ) > $limit ) {
				$text .= sprintf( "\n(+%d file khác)", count( $media_urls ) - $limit );
			}
		}
		$result = $api->send_message( $chat_id, $text );
		if ( is_wp_error( $result ) && class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			BizCity_Zalo_Bot_Database::instance()->log_event( $bot_id, 'upload_link.confirm_failed', array(
				'chat_id'    => $chat_id,
				'error_code' => $result->get_error_code(),
				'error_msg'  => $result->get_error_message(),
			), $chat_id, '', '', '' );
		}
	}

	private static function log_db_event( array $record, string $event_name, array $data ): void {
		if ( ! class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			return;
		}
		$bot_id  = (int) ( $record['bot_id'] ?? 0 );
		$chat_id = (string) ( $record['provider_chat_id'] ?? $record['chat_key'] ?? '' );
		if ( $bot_id <= 0 ) {
			return;
		}
		BizCity_Zalo_Bot_Database::instance()->log_event( $bot_id, $event_name, $data, $chat_id, '', '', '' );
	}

	/* ─────────────────────────────── views ───────────────────────────── */

	private static function page_shell( string $title, string $body ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<meta name="robots" content="noindex, nofollow">';
		echo '<title>' . esc_html( $title ) . '</title><style>';
		echo 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f3f4f6;margin:0;padding:24px 16px;color:#111827}';
		echo '.card{max-width:480px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.08);padding:24px}';
		echo 'h1{font-size:18px;margin:0 0 12px}p{font-size:14px;line-height:1.5;color:#374151}';
		echo '#dz{border:2px dashed #9ca3af;border-radius:12px;padding:28px 16px;text-align:center;cursor:pointer;transition:.15s;margin:16px 0}';
		echo '#dz.drag{border-color:#2563eb;background:#eff6ff}';
		echo '#dz span{display:block;font-size:13px;color:#6b7280;margin-top:6px}';
		echo '#fmeta{font-size:12px;color:#4b5563;margin:8px 0 0}';
		echo '#fmeta strong{color:#111827}';
		echo '#ferror{font-size:12px;color:#b91c1c;margin:6px 0 0;display:none}';
		echo '#flist{font-size:13px;color:#111827;margin:10px 0 0;text-align:left}';
		echo '.fchip{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#f9fafb;margin-bottom:8px}';
		echo '.fchip-main{min-width:0;flex:1}';
		echo '.fchip-name{font-weight:600;line-height:1.35;word-break:break-word}';
		echo '.fchip-sub{font-size:12px;color:#6b7280;margin-top:2px}';
		echo '.fchip-badge{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px;margin-top:5px}';
		echo '.fchip-ok{background:#dcfce7;color:#166534}';
		echo '.fchip-bad{background:#fee2e2;color:#991b1b}';
		echo '.fchip-remove{width:auto;margin-top:0;flex:0 0 auto;border:0;background:#fff;color:#6b7280;border-radius:8px;padding:2px 8px;cursor:pointer;font-size:12px;line-height:1.6}';
		echo '.fchip-remove:hover{background:#f3f4f6;color:#111827}';
		echo '.fhelper{font-size:12px;color:#6b7280;margin-top:8px;line-height:1.45}';
		echo '#bzsubmit{width:100%;background:#16a34a;color:#fff;border:0;border-radius:10px;padding:12px;font-size:15px;font-weight:600;cursor:pointer;margin-top:14px}';
		echo '#bzsubmit:disabled{background:#9ca3af}';
		echo '.meta{font-size:12px;color:#9ca3af;margin-top:16px;text-align:center}';
		echo '</style></head><body><div class="card">' . $body . '</div></body></html>';
	}

	private static function render_message_page( string $message, array $extra = array(), bool $is_error = false, string $retry_token = '' ): void {
		$icon = $is_error ? '⚠️' : 'ℹ️';
		$body = '<h1>' . $icon . ' TwinNote</h1><p>' . esc_html( $message ) . '</p>';
		self::page_shell( 'TwinNote — Tải lên', $body );
	}

	private static function render_upload_form( string $token, array $record ): void {
		$action     = esc_url( self::build_url( $token ) );
		$nonce      = wp_create_nonce( self::NONCE_ACTION_PREFIX . $token );
		$opens_left = (int) ( $record['opens_remaining'] ?? 0 );
		$max_size   = (int) apply_filters( 'bizcity_zalo_upload_link_max_file_size', self::MAX_FILE_SIZE );
		$max_files  = (int) apply_filters( 'bizcity_zalo_upload_link_max_files', self::MAX_FILES_PER_UPLOAD );
		$allow_exts = self::allowed_extensions();
		$accept_csv = implode( ',', array_map( static function ( string $ext ): string {
			return '.' . ltrim( strtolower( $ext ), '.' );
		}, $allow_exts ) );
		$allow_csv  = implode( ',', $allow_exts );
		$mode_note  = ( ( $record['mode'] ?? 'pending' ) === 'session' )
			? 'File sẽ được gắn trực tiếp vào TwinNote bạn đang mở.'
			: 'Sau khi tải lên, nhắn "@ghichu &lt;tiêu đề&gt;" trong Zalo để lưu vào TwinNote.';

		ob_start();
		?>
		<h1>📎 Tải file lên TwinNote</h1>
		<p><?php echo esc_html( $mode_note ); ?></p>
		<form method="post" enctype="multipart/form-data" action="<?php echo $action; ?>" id="bzform">
			<input type="hidden" name="bizcity_zalo_upload_nonce" value="<?php echo esc_attr( $nonce ); ?>">
			<div id="dz">
				<strong>Chạm để chọn file</strong>
				<span>Kéo-thả file trực tiếp từ Desktop vào đây</span>
			</div>
			<input type="file" name="bizcity_files[]" id="finput" multiple hidden accept="<?php echo esc_attr( $accept_csv ); ?>">
			<div id="fmeta"><strong>Chưa có file nào được chọn</strong></div>
			<div id="ferror"></div>
			<div id="flist"></div>
			<p class="fhelper">Hỗ trợ: <?php echo esc_html( strtoupper( $allow_csv ) ); ?> · tối đa <?php echo (int) $max_files; ?> file/lần · tối đa <?php echo esc_html( size_format( $max_size ) ); ?>/file.</p>
			<button type="submit" id="bzsubmit" disabled>Tải lên</button>
		</form>
		<p class="meta">Link này chỉ dùng được thêm <?php echo (int) $opens_left; ?> lần mở nữa và sẽ tự hết hạn — không chia sẻ cho người khác.</p>
		<script>
		(function(){
			var dz = document.getElementById('dz');
			var input = document.getElementById('finput');
			var meta = document.getElementById('fmeta');
			var err = document.getElementById('ferror');
			var list = document.getElementById('flist');
			var submitBtn = document.getElementById('bzsubmit');
			var maxSize = <?php echo (int) $max_size; ?>;
			var maxFiles = <?php echo (int) $max_files; ?>;
			var allowExt = '<?php echo esc_js( $allow_csv ); ?>'.split(',').filter(Boolean);
			var selected = [];

			function bytes(n){
				if (!n || n < 1024) return (n || 0) + ' B';
				if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
				return (n / (1024 * 1024)).toFixed(1) + ' MB';
			}

			function extOf(name){
				var p = (name || '').toLowerCase().split('.');
				return p.length > 1 ? p[p.length - 1] : '';
			}

			function validate(file){
				var ext = extOf(file.name);
				if (!ext || allowExt.indexOf(ext) === -1) {
					return 'Định dạng không hỗ trợ';
				}
				if ((file.size || 0) > maxSize) {
					return 'Vượt quá ' + bytes(maxSize);
				}
				return '';
			}

			function syncInput(){
				if (typeof DataTransfer === 'undefined') {
					return;
				}
				var dt = new DataTransfer();
				for (var i = 0; i < selected.length; i++) {
					dt.items.add(selected[i]);
				}
				input.files = dt.files;
			}

			function render(){
				list.innerHTML = '';
				err.style.display = 'none';
				err.textContent = '';
				var hasInvalid = false;
				if (!selected.length) {
					meta.innerHTML = '<strong>Chưa có file nào được chọn</strong>';
					submitBtn.disabled = true;
					return;
				}

				meta.innerHTML = '<strong>' + selected.length + ' file đã chọn</strong> · có thể bấm x để bỏ từng file';
				for (var i = 0; i < selected.length; i++) {
					var f = selected[i];
					var reason = validate(f);
					if (reason) { hasInvalid = true; }
					var row = document.createElement('div');
					row.className = 'fchip';
					var left = document.createElement('div');
					left.className = 'fchip-main';
					var name = document.createElement('div');
					name.className = 'fchip-name';
					name.textContent = '📄 ' + f.name;
					var sub = document.createElement('div');
					sub.className = 'fchip-sub';
					sub.textContent = bytes(f.size || 0);
					var badge = document.createElement('span');
					badge.className = 'fchip-badge ' + (reason ? 'fchip-bad' : 'fchip-ok');
					badge.textContent = reason ? reason : 'Hợp lệ';
					left.appendChild(name);
					left.appendChild(sub);
					left.appendChild(badge);

					var rm = document.createElement('button');
					rm.type = 'button';
					rm.className = 'fchip-remove';
					rm.textContent = 'Xóa';
					rm.setAttribute('data-idx', String(i));
					rm.addEventListener('click', function(ev){
						var idx = parseInt(ev.currentTarget.getAttribute('data-idx') || '-1', 10);
						if (idx >= 0 && idx < selected.length) {
							selected.splice(idx, 1);
							syncInput();
							render();
						}
					});

					row.appendChild(left);
					row.appendChild(rm);
					list.appendChild(row);
				}

				submitBtn.disabled = hasInvalid || selected.length === 0;
				if (hasInvalid) {
					err.textContent = 'Có file chưa hợp lệ. Hãy xóa file lỗi trước khi tải lên.';
					err.style.display = 'block';
				}
			}

			function addFiles(fileList){
				if (!fileList || !fileList.length) { return; }
				for (var i = 0; i < fileList.length; i++) {
					selected.push(fileList[i]);
				}
				if (selected.length > maxFiles) {
					selected = selected.slice(0, maxFiles);
					err.textContent = 'Mỗi lần chỉ nhận tối đa ' + maxFiles + ' file.';
					err.style.display = 'block';
				}
				syncInput();
				render();
			}

			dz.addEventListener('click', function(){ input.click(); });
			input.addEventListener('change', function(){ addFiles(input.files); });
			['dragover','dragenter'].forEach(function(ev){
				dz.addEventListener(ev, function(e){ e.preventDefault(); dz.classList.add('drag'); });
			});
			['dragleave','drop'].forEach(function(ev){
				dz.addEventListener(ev, function(e){ e.preventDefault(); dz.classList.remove('drag'); });
			});
			dz.addEventListener('drop', function(e){
				if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length){
					addFiles(e.dataTransfer.files);
				}
			});
			render();
		})();
		</script>
		<?php
		$body = ob_get_clean();
		self::page_shell( 'TwinNote — Tải lên', $body );
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-5 — expose normalized
	 * extension list so upload form can validate and preview client-side.
	 *
	 * @return string[]
	 */
	private static function allowed_extensions(): array {
		$mimes = self::allowed_mimes();
		$exts  = array();
		foreach ( array_keys( $mimes ) as $mask ) {
			$parts = explode( '|', (string) $mask );
			foreach ( $parts as $ext ) {
				$ext = strtolower( trim( (string) $ext ) );
				if ( $ext !== '' && ! in_array( $ext, $exts, true ) ) {
					$exts[] = $ext;
				}
			}
		}
		return $exts;
	}

	private static function render_result_page( array $accepted, array $rejected ): void {
		$body  = '<h1>✅ Đã tải lên xong</h1>';
		$body .= '<p>Đã nhận <strong>' . count( $accepted ) . '</strong> file.';
		if ( ! empty( $rejected ) ) {
			$body .= ' (' . count( $rejected ) . ' file bị từ chối — sai định dạng hoặc quá dung lượng.)';
		}
		$body .= '</p>';

		if ( ! empty( $accepted ) ) {
			$body .= '<div id="flist">';
			foreach ( $accepted as $file ) {
				$name = sanitize_file_name( (string) ( $file['file_name'] ?? '' ) );
				if ( $name === '' ) {
					$name = 'tap-tin-da-tai-len';
				}
				$mode = self::mount_mode_label( (string) ( $file['mount_mode'] ?? '' ) );
				$body .= '<div class="fchip">';
				$body .= '<div class="fchip-main">';
				$body .= '<div class="fchip-name">📄 ' . esc_html( $name ) . '</div>';
				$body .= '<div class="fchip-sub">Cách gắn vào phiên: <strong>' . esc_html( $mode ) . '</strong></div>';
				$body .= '</div>';
				$body .= '<span class="fchip-badge fchip-ok">Đã nhận</span>';
				$body .= '</div>';
			}
			$body .= '</div>';
		}

		if ( ! empty( $rejected ) ) {
			$body .= '<div id="flist">';
			foreach ( $rejected as $file ) {
				$name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
				if ( $name === '' ) {
					$name = 'tap-tin-khong-xac-dinh';
				}
				$reason = self::reject_reason_label( (string) ( $file['reason'] ?? '' ) );
				$body .= '<div class="fchip">';
				$body .= '<div class="fchip-main">';
				$body .= '<div class="fchip-name">📄 ' . esc_html( $name ) . '</div>';
				$body .= '<div class="fchip-sub">Lý do: <strong>' . esc_html( $reason ) . '</strong></div>';
				$body .= '</div>';
				$body .= '<span class="fchip-badge fchip-bad">Bị từ chối</span>';
				$body .= '</div>';
			}
			$body .= '</div>';
		}

		$body .= '<p>Bạn có thể quay lại Zalo để tiếp tục — TwinNote đã báo xác nhận trong đoạn chat.</p>';
		self::page_shell( 'TwinNote — Đã tải lên', $body );
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-5 — explain mounted mode
	 * clearly in result page so user knows whether files were bound to an open
	 * session or queued as pending fallback.
	 */
	private static function mount_mode_label( string $mode ): string {
		$mode = sanitize_key( $mode );
		if ( $mode === 'session' ) {
			return 'session (TwinNote đang mở)';
		}
		if ( $mode === 'pending_fallback' ) {
			return 'pending_fallback (phiên đã hết hạn)';
		}
		return 'pending (chờ @ghichu/@notebook)';
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-5 — human labels for
	 * rejected upload reasons.
	 */
	private static function reject_reason_label( string $reason ): string {
		$reason = trim( $reason );
		if ( $reason === 'too_large' ) {
			return 'Vượt quá dung lượng cho phép';
		}
		if ( $reason === 'upload_error' ) {
			return 'Lỗi upload từ thiết bị';
		}
		if ( $reason === 'insert_failed' ) {
			return 'Không lưu được vào thư viện media';
		}
		if ( $reason === '' ) {
			return 'Không xác định';
		}
		return $reason;
	}
}
