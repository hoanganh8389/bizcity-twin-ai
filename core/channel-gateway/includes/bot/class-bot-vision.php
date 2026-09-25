<?php
/**
 * Bot Studio — "the bot can see the customer's photo" (PHASE-0.60K K2), `describe` mode only.
 *
 * The main turn runs through text-only paths (notebook prompts are flattened to strings), so pixels never reach it:
 * a vision model turns the photo into TEXT once, and that text — fenced as external data, because words inside a photo
 * can say "ignore your instructions" — becomes part of the turn. Ported from Libe-Zalo's `describe` mode:
 *
 *  - the description prompt demands VERBATIM text and numbers, and forbids inventing what is not visible;
 *  - a description cut off by the token limit (`finish_reason = length`) is used for THIS turn but never cached —
 *    a truncated description cached "lives for ever" and the bot keeps reading half a receipt;
 *  - a photo that cannot be fetched/read is reported to the model as exactly that — otherwise it invents a reason;
 *  - `read_image` lets the model look again with a specific question (count, small print, colours) — never cached.
 *
 * Privacy: the customer's photo is sent to the AI provider through the site's 1API key. Off by default (D-K3), per Guru,
 * behind an explicit warning. Nothing here logs a description, a URL or the image bytes.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 */

// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K2
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Vision {

	const DESCRIBE_PROMPT = 'Mô tả chi tiết ảnh này bằng tiếng Việt cho một AI khác không xem được ảnh. BẮT BUỘC: chép NGUYÊN VĂN mọi chữ và con số nhìn thấy (giá, số điện thoại, tên, ngày, mã đơn…), đúng chính tả và dấu; nói rõ bố cục (ai/cái gì ở đâu). Không suy diễn điều không nhìn thấy; chỗ mờ thì ghi "[không đọc rõ]".';
	const ASK_PROMPT      = 'Trả lời câu hỏi sau về ảnh bằng tiếng Việt, chính xác theo những gì nhìn thấy, không suy diễn; không thấy thì nói không thấy: ';
	const MAX_BYTES       = 8388608; // 8MB — same ceiling as Libe-Zalo's image download.
	const MAX_DESC        = 1500;
	const HISTORY_DESC    = 300;
	const READ_WINDOW     = 10;
	const MIMES           = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );

	/** @var callable|null test seam: fn(string $url): array{ok:bool,mime:string,data:string,error:string} */
	public static $fetch = null;
	/** @var callable|null test seam: fn(array $messages, array $opts): array LLM result */
	public static $llm = null;
	/** @var int test/diagnostic counter of vision calls actually made. */
	public static $calls = 0;

	/* ── which photos does this turn concern ───────────────────────────── */

	/**
	 * The customer photos of THIS turn: image attachments of the incoming rows after the bot's (or a staff member's) last
	 * reply, up to the message that started the turn. Newest `$limit` win; returned oldest → newest.
	 *
	 * @param array<int,array> $messages CRM rows, oldest → newest, each maybe carrying `attachments`.
	 * @return array<int,array{attachment_id:int,message_id:int,url:string,meta:array}>
	 */
	public static function turn_images( array $messages, int $upto_message_id, int $limit ): array {
		if ( $limit <= 0 ) {
			return array();
		}
		$found = array();
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			$row = $messages[ $i ];
			$id  = (int) ( $row['id'] ?? 0 );
			if ( $upto_message_id > 0 && $id > $upto_message_id ) {
				continue; // a message that arrived after this turn was claimed belongs to the next turn.
			}
			$type = (string) ( $row['message_type'] ?? '' );
			if ( 'outgoing' === $type ) {
				break; // the bot/staff already answered everything before this point.
			}
			if ( 'incoming' !== $type ) {
				continue;
			}
			foreach ( array_reverse( self::image_rows( $row ) ) as $img ) {
				$found[] = $img;
			}
		}
		return array_slice( array_reverse( $found ), -$limit );
	}

	/** @return array<int,array{attachment_id:int,message_id:int,url:string,meta:array}> */
	private static function image_rows( array $row ): array {
		$out = array();
		foreach ( (array) ( $row['attachments'] ?? array() ) as $att ) {
			if ( ! is_array( $att ) || 'image' !== (string) ( $att['file_type'] ?? '' ) ) {
				continue;
			}
			$url = (string) ( $att['data_url'] ?? $att['file_url'] ?? $att['url'] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			$out[] = array(
				'attachment_id' => (int) ( $att['id'] ?? 0 ),
				'message_id'    => (int) ( $row['id'] ?? 0 ),
				'url'           => $url,
				'meta'          => self::meta( $att['meta_json'] ?? ( $att['meta'] ?? null ) ),
			);
		}
		return $out;
	}

	private static function meta( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		return is_array( $decoded ) ? $decoded : array();
	}

	/** The cached description of an attachment, or ''. */
	public static function cached_desc( array $meta ): string {
		return trim( (string) ( $meta['vision']['desc'] ?? '' ) );
	}

	/* ── describe (once, cached) ───────────────────────────────────────── */

	/**
	 * @param array{attachment_id:int,url:string,meta:array} $img
	 * @return array{ok:bool,text:string,truncated:bool,cached:bool,error:string}
	 */
	public static function describe( array $img ): array {
		$cached = self::cached_desc( (array) ( $img['meta'] ?? array() ) );
		if ( '' !== $cached ) {
			return array( 'ok' => true, 'text' => $cached, 'truncated' => false, 'cached' => true, 'error' => '' );
		}
		$res = self::look( (string) $img['url'], self::DESCRIBE_PROMPT );
		if ( ! $res['ok'] ) {
			return array( 'ok' => false, 'text' => '', 'truncated' => false, 'cached' => false, 'error' => $res['error'] );
		}
		$text = self::cut( $res['text'], self::MAX_DESC );
		// Never persist a cut-off description — see the class docblock.
		if ( ! $res['truncated'] && (int) ( $img['attachment_id'] ?? 0 ) > 0 ) {
			self::persist( (int) $img['attachment_id'], $text, $res['model'] );
		}
		return array( 'ok' => true, 'text' => $text, 'truncated' => $res['truncated'], 'cached' => false, 'error' => '' );
	}

	private static function persist( int $attachment_id, string $text, string $model ): void {
		if ( ! class_exists( 'BizCity_CRM_Repository' ) || ! method_exists( 'BizCity_CRM_Repository', 'update_attachment_meta' ) ) {
			return;
		}
		try {
			BizCity_CRM_Repository::update_attachment_meta( $attachment_id, array( 'vision' => array( 'desc' => $text, 'model' => $model, 'at' => time(), 'truncated' => false ) ) );
		} catch ( \Throwable $e ) {
			// a cache miss next time costs one more vision call; it must never cost the customer's reply.
		}
	}

	/* ── read_image (ask a specific question, never cached) ────────────── */

	/**
	 * @param array<int,array> $messages CRM rows of THIS conversation (oldest → newest).
	 * @return array{ok:bool,content:string,error:string}
	 */
	public static function answer( array $messages, string $question, int $index ): array {
		$question = trim( $question );
		if ( '' === $question ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'question_required' );
		}
		$all = array();
		foreach ( $messages as $row ) {
			if ( 'incoming' === (string) ( $row['message_type'] ?? '' ) ) {
				foreach ( self::image_rows( $row ) as $img ) {
					$all[] = $img;
				}
			}
		}
		$all = array_reverse( array_slice( $all, -self::READ_WINDOW ) ); // index 1 = newest.
		if ( empty( $all ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'no_image' );
		}
		$index = max( 1, $index );
		if ( ! isset( $all[ $index - 1 ] ) ) {
			return array( 'ok' => false, 'content' => '', 'error' => 'index_out_of_range' );
		}
		$res = self::look( (string) $all[ $index - 1 ]['url'], self::ASK_PROMPT . mb_substr( $question, 0, 500 ) );
		if ( ! $res['ok'] ) {
			return array( 'ok' => false, 'content' => '', 'error' => $res['error'] );
		}
		return array( 'ok' => true, 'content' => BizCity_Bot_Tools::fence( 'Trả lời từ ảnh #' . $index . ' khách đã gửi', self::cut( $res['text'], self::MAX_DESC ) ), 'error' => '' );
	}

	/* ── the context builder's view of an old photo ────────────────────── */

	/** `[Ảnh: …]` for an image-only customer row whose description is cached; '' otherwise (unknown never becomes invented). */
	public static function history_note( array $row ): string {
		foreach ( self::image_rows( $row ) as $img ) {
			$desc = self::cached_desc( $img['meta'] );
			if ( '' !== $desc ) {
				return '[Ảnh: ' . self::cut( preg_replace( '/\s+/u', ' ', $desc ), self::HISTORY_DESC ) . ']';
			}
		}
		return '';
	}

	/* ── fetch + call ──────────────────────────────────────────────────── */

	/** @return array{ok:bool,text:string,truncated:bool,model:string,error:string} */
	private static function look( string $url, string $prompt ): array {
		$img = self::fetch( $url );
		if ( ! $img['ok'] ) {
			return array( 'ok' => false, 'text' => '', 'truncated' => false, 'model' => '', 'error' => $img['error'] );
		}
		self::$calls++;
		$messages = array( array(
			'role'    => 'user',
			'content' => array(
				array( 'type' => 'text', 'text' => $prompt ),
				array( 'type' => 'image_url', 'image_url' => array( 'url' => 'data:' . $img['mime'] . ';base64,' . base64_encode( $img['data'] ) ) ),
			),
		) );
		$opts = array( 'purpose' => 'vision', 'temperature' => 0.1, 'max_tokens' => 1024, 'timeout' => 30 );
		try {
			if ( is_callable( self::$llm ) ) {
				$res = call_user_func( self::$llm, $messages, $opts );
			} elseif ( class_exists( 'BizCity_LLM_Client' ) ) {
				$client = BizCity_LLM_Client::instance();
				if ( method_exists( $client, 'get_model' ) ) {
					$opts['model'] = (string) $client->get_model( 'vision' );
				}
				$res = $client->chat( $messages, $opts );
			} else {
				return array( 'ok' => false, 'text' => '', 'truncated' => false, 'model' => '', 'error' => 'vision_unavailable' );
			}
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'text' => '', 'truncated' => false, 'model' => '', 'error' => 'vision_exception' );
		}
		$text = is_array( $res ) && ! empty( $res['success'] ) ? trim( (string) ( $res['message'] ?? '' ) ) : '';
		if ( '' === $text ) {
			return array( 'ok' => false, 'text' => '', 'truncated' => false, 'model' => '', 'error' => 'vision_empty' );
		}
		return array( 'ok' => true, 'text' => $text, 'truncated' => 'length' === (string) ( $res['finish_reason'] ?? '' ), 'model' => (string) ( $res['model'] ?? '' ), 'error' => '' );
	}

	/** @return array{ok:bool,mime:string,data:string,error:string} */
	public static function fetch( string $url ): array {
		if ( is_callable( self::$fetch ) ) {
			return (array) call_user_func( self::$fetch, $url );
		}
		$fail = static function ( string $e ) { return array( 'ok' => false, 'mime' => '', 'data' => '', 'error' => $e ); };
		if ( ! preg_match( '#^https?://#i', $url ) || ! function_exists( 'wp_safe_remote_get' ) ) {
			return $fail( 'image_download_failed' );
		}
		// wp_safe_* refuses private/loopback hosts (SSRF), which a customer-controlled URL could otherwise aim at.
		$response = wp_safe_remote_get( $url, array( 'timeout' => 15, 'limit_response_size' => self::MAX_BYTES + 1 ) );
		if ( is_wp_error( $response ) ) {
			return $fail( 'image_download_failed' );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 401, 403, 404, 410 ), true ) ) {
			return $fail( 'image_expired' ); // Zalo CDN links expire.
		}
		if ( 200 !== $code ) {
			return $fail( 'image_download_failed' );
		}
		$data = (string) wp_remote_retrieve_body( $response );
		if ( '' === $data ) {
			return $fail( 'image_download_failed' );
		}
		if ( strlen( $data ) > self::MAX_BYTES ) {
			return $fail( 'image_too_large' );
		}
		$info = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $data ) : false;
		$mime = is_array( $info ) ? (string) ( $info['mime'] ?? '' ) : '';
		if ( ! in_array( $mime, self::MIMES, true ) ) {
			return $fail( 'not_an_image' ); // the CDN can answer 200 with an error page; the bytes decide, not the URL.
		}
		return array( 'ok' => true, 'mime' => $mime, 'data' => $data, 'error' => '' );
	}

	private static function cut( string $s, int $max ): string {
		return mb_strlen( $s ) > $max ? rtrim( mb_substr( $s, 0, $max - 1 ) ) . '…' : $s;
	}
}
