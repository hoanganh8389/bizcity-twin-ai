<?php
/**
 * Web surfaces of the Twin Agent — TwinChat (PHASE-0.87 CL-6) and Twin GPT `/gpt/` (CL-7) as adapters of the cell's
 * `runAgentTurn` (R-TWIN-AGENT-AXIS R-TAA-1/13, contract twin-agent-turn@1.2.0, fixtures taa/turn.twinchat.json + turn.gpt.json).
 *
 *   A1 intake     the surface hands over {user_id, session_id, text, history (site-owned, oldest first), notebook_id};
 *                 this class builds the envelope — the user is get_current_user_id() of the caller, never a body field.
 *   A2 principal  role + modes from server facts only: the user's principal on a zalo-hub number of this site
 *                 (BizCity_Zalo_Agent_Principals: owner = the number's owner_user_id with "UID chủ tài khoản" set, staff =
 *                 staff_principals row), modes = agent-mode-access@1, `user_hash` = the value the Zalo bundle carries.
 *                 No principal ⇒ customer, modes [] and no hash (no owner tools / packs).
 *   A3 delivery   the Hub relay `POST bizcity/v1/zalo-hub/twin-agent/turn` streams the cell's SSE frames; they are passed
 *                 to the browser byte for byte (see "transition bridge" below).
 *   A4 record     the cell writes the twin record (`turn-complete`, CL-5); the site keeps only its web transcript (the
 *                 surface's message store) from the `final_done` frame. No owner-capture from the web.
 *
 * Cut-over flag per surface (R-TAA §8 step 4), option `bizcity_twin_web_turn_path_<surface>` = auto|node|php, default auto:
 *   php  ⇒ the PHP pipeline answers, unchanged (the documented way back);
 *   node ⇒ always this path (errors are shown, no fallback);
 *   auto ⇒ this path when the site has a zalo-hub number, knows its key_id and the Hub has the route; an old Hub
 *          (404 rest_no_route) is remembered for 10 minutes; a Hub/cell that cannot take the turn before the first
 *          frame (unreachable, 5xx, number not on the Hub, old cell) falls back to php for that turn. Staff go out as role
 *          `staff` (twin-agent-turn@1.3, fixture taa/turn.twinchat.staff.json — the cell accepts it since 2026-10-01); an
 *          older cell that rejects the 1.3 envelope (400) sends that staff turn back to php in auto. Way back: filter
 *          `bizcity_twin_web_turn_staff_role` ⇒ false (staff go out as customer, and auto keeps them on php).
 *
 * Which number answers (`account_id`): the first zalo-hub number where the user is the active owner principal, else the
 * first where they are an active staff principal (with ≥ 1 mode), else the site's default number (option
 * `bizcity_twin_web_turn_account` when it is a zalo-hub number of this site, else the first zalo-hub number).
 *
 * TRANSITION BRIDGE — remove at NV-3: the site PHP reads the Hub SSE with cURL and writes it to the browser unchanged.
 * Allowed by R-PROVIDER-FLOW §6 (v2.6, owner decision D87-STREAM 2026-10-01: web turns may cross site PHP + Hub as a
 * byte-passthrough bridge until NV-3 is live) — no LLM call, no prompt building, no
 * post-processing of the answer here. The final design is the browser opening `stream_url` on the public stream host
 * with a single-use ticket (PHASE-0.84 RT-N2…N4, NV-1…NV-4).
 *
 * // @axis twin-agent-axis@1 surface twinchat,gpt
 *
 * @package BizCity_Twin_AI
 * @since   PHASE-0.87 CL-6/CL-7 (2026-10-01)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Web_Turn', false ) ) {
	return;
}

final class BizCity_Twin_Web_Turn {

	const CONTRACT       = 'twin-agent-turn@1.2.0';
	const EFFORTS        = array( 'fast', 'balanced', 'high', 'deep' ); // UI Nhanh/Vừa/Cao/Sâu — the cell maps + clamps (off…high)
	const CONTRACT_STAFF = 'twin-agent-turn@1.3.0'; // role `staff` exists from 1.3 (owner/customer turns stay 1.2 for older cells)
	const AXIS           = 'twin-agent-axis@1';
	const SURFACES       = array( 'twinchat', 'gpt' );
	const FLAGS          = array( 'auto', 'node', 'php' );
	const FLAG_DEFAULT   = 'auto';
	const OPTION_PREFIX  = 'bizcity_twin_web_turn_path_';
	const OPTION_ACCOUNT = 'bizcity_twin_web_turn_account';
	const FILTER_STAFF   = 'bizcity_twin_web_turn_staff_role';
	const HUB_PATH       = '/zalo-hub/twin-agent/turn';
	const NO_ROUTE_KEY   = 'bizcity_twin_web_turn_no_route';
	const NO_ROUTE_TTL   = 600;
	const KEY_ID_KEY     = 'bizcity_twin_web_turn_key_id';
	const KEY_ID_TTL     = 43200;
	const HISTORY_MAX    = 20;
	const TEXT_MAX       = 8000;
	const PARTS_MAX      = 20; // cell envelope: ≤ 20 parts (1 text + ≤ 19 images)
	const TIMEOUT        = 310; // Hub relay allows 300 s per turn
	const ERR_BODY_MAX   = 65536;

	/** Vietnamese R-ERROR-UX texts of the site side: code ⇒ [status, message, hint]. */
	const ERRORS = array(
		'no_zalo_hub_number' => array( 409, 'Website chưa có số Zalo nào chạy trên Zalo Hub để trả lời.', 'Kết nối một số ở Bot Studio (Zalo Hub), hoặc chuyển cách trả lời web về "php".' ),
		'hub_key_unknown'    => array( 503, 'Chưa xác định được API key của website ở BizCity Hub.', 'Kiểm tra API key BizCity trong phần cài đặt rồi thử lại.' ),
		'hub_route_missing'  => array( 501, 'BizCity Hub chưa hỗ trợ trả lời web bằng Agent.', 'Báo quản trị nền tảng cập nhật Hub, hoặc chuyển cách trả lời web về "php".' ),
		'hub_unreachable'    => array( 502, 'Không kết nối được BizCity Hub để trả lời.', 'Thử lại sau ít giây.' ),
		'session_forbidden'  => array( 403, 'Phiên chat này không thuộc tài khoản của bạn.', 'Mở một cuộc trò chuyện mới rồi hỏi lại.' ),
		'empty_message'      => array( 400, 'Tin nhắn không được để trống.', 'Nhập câu hỏi rồi gửi lại.' ),
		'stream_interrupted' => array( 502, 'Luồng trả lời bị ngắt trước khi hoàn tất.', 'Gửi lại câu hỏi.' ),
	);

	/**
	 * Test seams: option(name, default) · accounts(): list<{bridge_id}> · principals(bridge_id): list · user_hash(user_id) ·
	 * key_id(): int · target(): ?{url,key,headers} · guru_ref(bridge_id): string · trace_id(): string · now(): int ·
	 * transport(target, body, on_bytes(chunk, status, content_type): bool): {ok, status} · out(bytes) · open() · staff_supported(): bool
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	/* ── decision ─────────────────────────────────────────────────── */

	/** auto|node|php for one surface (unknown value ⇒ default). */
	public static function flag( string $surface ): string {
		$v = strtolower( trim( (string) self::option( self::OPTION_PREFIX . $surface, self::FLAG_DEFAULT ) ) );
		if ( function_exists( 'apply_filters' ) ) {
			$v = (string) apply_filters( 'bizcity_twin_web_turn_path', $v, $surface );
		}
		return in_array( $v, self::FLAGS, true ) ? $v : self::FLAG_DEFAULT;
	}

	/**
	 * Which path answers this turn. Node ⇒ also carries the resolved principal and key_id.
	 *
	 * @return array{path:string,reason:string,flag:string,error?:string,principal?:array,key_id?:int}
	 */
	public static function decide( string $surface, int $user_id ): array {
		$flag = self::flag( $surface );
		$php  = static function ( string $reason ) use ( $flag ): array { return array( 'path' => 'php', 'reason' => $reason, 'flag' => $flag ); };
		$err  = static function ( string $code ) use ( $flag ): array { return array( 'path' => 'error', 'reason' => $code, 'flag' => $flag, 'error' => $code ); };
		if ( ! in_array( $surface, self::SURFACES, true ) || 'php' === $flag ) {
			return $php( 'flag_php' );
		}
		if ( $user_id <= 0 ) {
			return $php( 'guest' ); // guests keep the PHP surface rules (/gpt/ guest quota, profile chat)
		}
		$accounts = self::accounts();
		if ( ! $accounts ) {
			return 'node' === $flag ? $err( 'no_zalo_hub_number' ) : $php( 'no_zalo_hub_number' );
		}
		if ( 'auto' === $flag && self::route_missing() ) {
			return $php( 'hub_route_missing' );
		}
		$key_id = self::key_id();
		if ( $key_id <= 0 || null === self::target() ) {
			return 'node' === $flag ? $err( 'hub_key_unknown' ) : $php( 'hub_key_unknown' );
		}
		$principal = self::principal( $user_id, $accounts );
		if ( 'auto' === $flag && 'staff' === $principal['kind'] && ! self::staff_supported() ) {
			return $php( 'staff_role_unsupported' );
		}
		return array( 'path' => 'node', 'reason' => 'node', 'flag' => $flag, 'principal' => $principal, 'key_id' => $key_id );
	}

	/**
	 * The user's principal on this site's zalo-hub numbers and the number that answers (rule in the file header).
	 *
	 * @param string[] $accounts bridge ids
	 * @return array{kind:string,account_id:string,role:string,role_evidence:string,modes:string[],user_hash:string}
	 */
	public static function principal( int $user_id, array $accounts ): array {
		$owner = null;
		$staff = null;
		foreach ( $accounts as $bridge ) {
			foreach ( self::principals( (string) $bridge ) as $p ) {
				if ( empty( $p['active'] ) || (int) ( $p['user_id'] ?? 0 ) !== $user_id || empty( $p['modes'] ) || '' === (string) ( $p['user_hash'] ?? '' ) ) {
					continue; // no mode ⇒ customer, like a staff entry without modes on Zalo (turn.zalo.staff.json)
				}
				if ( 'owner' === ( $p['role'] ?? '' ) && null === $owner ) {
					$owner = array( (string) $bridge, $p );
				} elseif ( 'staff' === ( $p['role'] ?? '' ) && null === $staff ) {
					$staff = array( (string) $bridge, $p );
				}
			}
		}
		if ( $owner ) {
			return self::as_role( 'owner', $owner[0], 'owner', 'wp_principal', $owner[1] );
		}
		if ( $staff ) {
			// [2026-10-01 Claude Opus 5.5] PHASE-0.87 W2 web — the cell's web turn accepts `staff` (twin-agent-turn@1.3). With the
			// filter off, staff go out as customer (fail closed, never widened to owner).
			return self::staff_supported()
				? self::as_role( 'staff', $staff[0], 'staff', 'wp_principal', $staff[1] )
				: self::as_role( 'staff', $staff[0], 'customer', 'default_customer', array() );
		}
		return self::as_role( 'none', self::default_account( $accounts ), 'customer', 'default_customer', array() );
	}

	/* ── envelope (A1 + A2) ───────────────────────────────────────── */

	/**
	 * twin-agent-turn@1.2.0 envelope. `$turn` = {user_id, session_id, text, history[{role, content|text}], notebook_id?, thread_key?, effort?, images?: list<url>}.
	 */
	public static function envelope( string $surface, array $turn, array $principal, int $key_id ): array {
		$user_id = (int) $turn['user_id'];
		$ref     = substr( self::user_hash( $user_id ), 0, 16 );
		$who     = array( 'kind' => 'wp_user', 'ref' => $ref, 'wp_user_id' => $user_id );
		if ( in_array( $principal['role'], array( 'owner', 'staff' ), true ) && '' !== $principal['user_hash'] ) {
			$who['user_hash'] = $principal['user_hash'];
		}
		$session = array( 'session_id' => self::session_id( (string) ( $turn['session_id'] ?? '' ) ) );
		if ( ! empty( $turn['thread_key'] ) ) {
			$session['thread_key'] = substr( (string) $turn['thread_key'], 0, 200 );
		}
		$env = array(
			'contract'      => 'staff' === $principal['role'] ? self::CONTRACT_STAFF : self::CONTRACT,
			'axis'          => self::AXIS,
			'trace_id'      => self::trace_id(),
			'surface'       => $surface,
			'tenant'        => array( 'key_id' => $key_id, 'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1 ),
			'account_id'    => (string) $principal['account_id'],
			'principal'     => $who,
			'role'          => $principal['role'],
			'role_evidence' => $principal['role_evidence'],
			'modes'         => array_values( $principal['modes'] ),
			'session'       => $session,
			'parts'         => self::parts( (string) $turn['text'], (array) ( $turn['images'] ?? array() ) ),
			'history'       => self::history( (array) ( $turn['history'] ?? array() ) ),
		);
		if ( (int) ( $turn['notebook_id'] ?? 0 ) > 0 ) {
			$env['focus'] = array( 'notebook_id' => (int) $turn['notebook_id'] );
		}
		// [2026-10-01 Claude Opus 5.5] PHASE-0.87 — per-turn reasoning level (additive, optional; owner 2026-10-01). Only the 4 UI
		// values leave the site; the cell maps them to thinking (off/low/medium/high) and clamps to the agent ceiling, the model
		// and the tenant plan — so a customer may pick any of them and still never exceeds the ceiling.
		$effort = (string) ( $turn['effort'] ?? '' );
		if ( in_array( $effort, self::EFFORTS, true ) ) {
			$env['effort'] = $effort;
		}
		$guru = self::guru_ref( (string) $principal['account_id'] );
		if ( '' !== $guru ) {
			$env['guru_ref'] = $guru;
		}
		$env['sent_at'] = gmdate( 'Y-m-d\TH:i:s\Z', self::now() );
		return $env;
	}

	/**
	 * [2026-10-01 Claude Opus 5.5] PHASE-0.87 — text part, then one image part per absolute http(s) URL the cell can fetch
	 * (cell partSchema: image url ≤ 2000, ≤ 20 parts). Other URLs are skipped, never rewritten.
	 */
	public static function parts( string $text, array $images ): array {
		$parts = array( array( 'type' => 'text', 'text' => self::clip( $text ) ) );
		foreach ( $images as $url ) {
			$url = trim( (string) $url );
			if ( count( $parts ) < self::PARTS_MAX && strlen( $url ) <= 2000 && preg_match( '#^https?://[^\s/?\#]+\S*$#i', $url ) ) {
				$parts[] = array( 'type' => 'image', 'url' => $url );
			}
		}
		return $parts;
	}

	/**
	 * Owned attachment rows {mime_type, url} ⇒ image URLs for `$turn['images']`, or null when the turn must stay on php: any
	 * non-image (pdf/docx/audio…), a URL the cell cannot fetch (not absolute http(s)), or more images than the parts cap.
	 */
	public static function image_urls( array $attachments ): ?array {
		$urls = array();
		foreach ( $attachments as $a ) {
			$a   = is_array( $a ) ? $a : array();
			$url = trim( (string) ( $a['url'] ?? '' ) );
			if ( 0 !== strpos( strtolower( (string) ( $a['mime_type'] ?? '' ) ), 'image/' ) || 2 !== count( self::parts( '', array( $url ) ) ) ) {
				return null;
			}
			$urls[] = $url;
		}
		return count( $urls ) < self::PARTS_MAX ? $urls : null;
	}

	/** Recent turns, oldest first, ≤ HISTORY_MAX, text only (cell schema: role user|assistant, text ≤ 8000). */
	public static function history( array $rows ): array {
		$out = array();
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$role = (string) ( $r['role'] ?? '' );
			$text = trim( (string) ( $r['text'] ?? $r['content'] ?? '' ) );
			if ( '' === $text || ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
				continue;
			}
			$out[] = array( 'role' => $role, 'text' => self::clip( $text ) );
		}
		return array_slice( $out, -self::HISTORY_MAX );
	}

	/* ── run ──────────────────────────────────────────────────────── */

	/**
	 * Answer one web turn through the Hub when the flag says so.
	 *
	 * `$turn['persist']` = callable( array $final_done, string $trace_id ): int — writes the site's web transcript (user +
	 * assistant rows) once the cell's `final_done` frame arrived; returns the assistant row id (0 = none).
	 *
	 * @return string|WP_REST_Response 'php' (caller runs its PHP pipeline) · 'done' (SSE written, caller must exit) · error before any byte
	 */
	public static function run( string $surface, array $turn ) {
		$user_id = (int) ( $turn['user_id'] ?? 0 );
		$d       = self::decide( $surface, $user_id );
		self::trace( $surface, $d );
		if ( 'php' === $d['path'] ) {
			return 'php';
		}
		if ( 'error' === $d['path'] ) {
			return self::error( $d['error'] );
		}
		if ( '' === trim( (string) ( $turn['text'] ?? '' ) ) ) {
			return self::error( 'empty_message' );
		}
		$env = self::envelope( $surface, $turn, $d['principal'], (int) $d['key_id'] );
		return self::pipe( $env, $d['flag'], isset( $turn['persist'] ) && is_callable( $turn['persist'] ) ? $turn['persist'] : null );
	}

	/**
	 * TRANSITION BRIDGE (remove at NV-3): Hub SSE ⇒ browser, frame by frame, bytes unchanged. Nothing is written to the
	 * browser before the first SSE frame arrived, so a Hub/cell that cannot take the turn can still fall back to php.
	 *
	 * @return string|WP_REST_Response
	 */
	public static function pipe( array $env, string $flag, $persist = null ) {
		$target = self::target();
		if ( null === $target ) {
			return 'auto' === $flag ? 'php' : self::error( 'hub_key_unknown' );
		}
		$st = (object) array( 'opened' => false, 'buf' => '', 'err' => '', 'ended' => false, 'fallback' => false, 'status' => 0, 'aborted' => false, 'staff' => 'staff' === ( $env['role'] ?? '' ) );
		$on_bytes = static function ( string $chunk, int $status, string $ctype ) use ( $st, $flag, $persist, $env ): bool {
			$st->status = $status;
			if ( ! $st->opened ) {
				if ( 200 !== $status || false === stripos( $ctype, 'event-stream' ) || '' !== $st->err ) {
					$st->err = substr( $st->err . $chunk, 0, self::ERR_BODY_MAX );
					return true;
				}
				$st->buf .= $chunk;
				$pos = strpos( $st->buf, "\n\n" );
				if ( false === $pos ) {
					if ( strlen( $st->buf ) > self::ERR_BODY_MAX ) {
						$st->err = substr( $st->buf, 0, self::ERR_BODY_MAX );
						$st->buf = '';
					}
					return true;
				}
				$first = self::parse( substr( $st->buf, 0, $pos ) );
				if ( null === $first ) {          // not SSE (an old cell answering JSON through the Hub's SSE pipe)
					$st->err = substr( $st->buf, 0, self::ERR_BODY_MAX );
					$st->buf = '';
					return true;
				}
				if ( 'auto' === $flag && 'error' === $first['event'] && 'cell_unreachable' === (string) ( $first['data']['code'] ?? '' ) ) {
					$st->fallback = true;           // the Hub could not reach the cell: nothing was answered yet
					$st->buf      = '';
					return false;
				}
				self::open();
				$st->opened = true;
			} else {
				$st->buf .= $chunk;
			}
			self::forward( $st, $persist, (string) $env['trace_id'] );
			if ( function_exists( 'connection_aborted' ) && connection_aborted() ) {
				$st->aborted = true;
				return false;                       // the browser left ⇒ drop the Hub stream ⇒ the cell sees the disconnect
			}
			return true;
		};
		$res = self::transport( $target, (string) self::json( $env ), $on_bytes );
		if ( ! $st->opened ) {
			if ( '' !== $st->buf ) {
				$st->err = substr( $st->buf, 0, self::ERR_BODY_MAX );
			}
			return self::upstream_failure( $st, (int) ( $res['status'] ?? $st->status ), $flag );
		}
		if ( '' !== $st->buf ) {
			self::out( $st->buf );
			$st->buf = '';
		}
		if ( ! $st->ended && ! $st->aborted ) {
			$e = self::ERRORS['stream_interrupted'];
			self::out( self::frame( 'error', array( 'trace_id' => (string) $env['trace_id'], 'code' => 'stream_interrupted', 'message' => $e[1], 'hint' => $e[2], 'help_code' => 'S87-WEB-SITE-STREAM', 'retryable' => true ) ) );
			self::out( self::frame( 'end', array() ) );
		}
		return 'done';
	}

	/** Write every complete frame; on `final_done` record the transcript (A4, site side) right after forwarding it. */
	private static function forward( $st, $persist, string $trace_id ): void {
		while ( false !== ( $pos = strpos( $st->buf, "\n\n" ) ) ) {
			$raw     = substr( $st->buf, 0, $pos + 2 );
			$st->buf = (string) substr( $st->buf, $pos + 2 );
			self::out( $raw );
			$f = self::parse( substr( $raw, 0, -2 ) );
			if ( ! $f ) {
				continue;
			}
			if ( 'final_done' === $f['event'] && $persist ) {
				$id = 0;
				try {
					$id = (int) call_user_func( $persist, (array) $f['data'], $trace_id );
				} catch ( \Throwable $e ) {
					$id = 0; // the answer is already on screen; a failed transcript write must not break the stream
				}
				if ( $id > 0 ) {
					self::out( self::frame( 'assistant_persisted', array( 'message_id' => $id ) ) ); // lets TwinChat pin the row
				}
			} elseif ( 'end' === $f['event'] ) {
				$st->ended = true;
			}
		}
	}

	/** @return string|WP_REST_Response */
	private static function upstream_failure( $st, int $status, string $flag ) {
		$auto = 'auto' === $flag;
		if ( $st->fallback ) {
			return 'php';
		}
		$data = json_decode( trim( (string) $st->err ), true );
		$data = is_array( $data ) ? $data : array();
		$code = (string) ( $data['code'] ?? '' );
		if ( 404 === $status && 'rest_no_route' === $code ) {
			self::remember_route_missing();
			return $auto ? 'php' : self::error( 'hub_route_missing' );
		}
		if ( 'tenant_mismatch' === $code && function_exists( 'delete_transient' ) ) {
			delete_transient( self::KEY_ID_KEY ); // the site key changed since key_id was cached: learn it again next turn
		}
		// A staff turn on an older cell (no twin-agent-turn@1.3) is refused with 400 before any frame: php answers it instead.
		$old_cell_staff  = 400 === $status && ! empty( $st->staff );
		$retry_elsewhere = 0 === $status || $status >= 500 || ( 404 === $status && 'account_not_found' === $code ) || 200 === $status || 'tenant_mismatch' === $code || $old_cell_staff;
		if ( $auto && $retry_elsewhere ) {
			return 'php';
		}
		if ( '' !== $code && isset( $data['message'] ) && $status >= 400 ) {
			// The Hub already answers R-ERROR-UX in Vietnamese (S87-WEB-*): pass it on unchanged.
			return self::response( $status, $code, (string) $data['message'], (string) ( $data['hint'] ?? '' ), (string) ( $data['help_code'] ?? $code ) );
		}
		return self::error( 'hub_unreachable' );
	}

	/* ── SSE helpers ──────────────────────────────────────────────── */

	/** One SSE block ⇒ {event, data} or null when it is not SSE. Comment-only blocks are SSE (event ''). */
	public static function parse( string $block ): ?array {
		$event = '';
		$data  = '';
		$seen  = false;
		foreach ( preg_split( '/\r?\n/', $block ) as $line ) {
			if ( '' === $line ) {
				continue;
			}
			if ( 0 === strpos( $line, 'event:' ) ) {
				$event = trim( substr( $line, 6 ) );
			} elseif ( 0 === strpos( $line, 'data:' ) ) {
				$data .= ltrim( substr( $line, 5 ) );
			} elseif ( 0 !== strpos( $line, ':' ) && 0 !== strpos( $line, 'id:' ) && 0 !== strpos( $line, 'retry:' ) ) {
				return null;
			}
			$seen = true;
		}
		if ( ! $seen ) {
			return null;
		}
		$decoded = '' === $data ? array() : json_decode( $data, true );
		return array( 'event' => $event, 'data' => is_array( $decoded ) ? $decoded : array() );
	}

	public static function frame( string $event, array $data ): string {
		return 'event: ' . $event . "\ndata: " . self::json( (object) $data ) . "\n\n";
	}

	/* ── errors (R-ERROR-UX) ──────────────────────────────────────── */

	public static function error( string $code ): WP_REST_Response {
		$e = self::ERRORS[ $code ] ?? self::ERRORS['hub_unreachable'];
		return self::response( $e[0], $code, $e[1], $e[2], 'S87-WEB-SITE-' . strtoupper( str_replace( '_', '-', $code ) ) );
	}

	private static function response( int $status, string $code, string $message, string $hint, string $help_code ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code ), $status );
	}

	/* ── helpers ──────────────────────────────────────────────────── */

	private static function as_role( string $kind, string $account, string $role, string $evidence, array $p ): array {
		$owner_like = in_array( $role, array( 'owner', 'staff' ), true );
		return array(
			'kind'          => $kind,
			'account_id'    => $account,
			'role'          => $role,
			'role_evidence' => $evidence,
			'modes'         => $owner_like ? array_values( array_map( 'strval', (array) ( $p['modes'] ?? array() ) ) ) : array(),
			'user_hash'     => $owner_like ? strtolower( (string) ( $p['user_hash'] ?? '' ) ) : '',
		);
	}

	private static function default_account( array $accounts ): string {
		$pick = (string) self::option( self::OPTION_ACCOUNT, '' );
		return '' !== $pick && in_array( $pick, $accounts, true ) ? $pick : (string) reset( $accounts );
	}

	/** The cell accepts session ids `[A-Za-z0-9._:-]{1,100}`; anything else is replaced by a stable hash. */
	public static function session_id( string $sid ): string {
		$sid = trim( $sid );
		return preg_match( '/^[A-Za-z0-9._:-]{1,100}$/', $sid ) ? $sid : 's_' . substr( sha1( '' === $sid ? self::trace_id() : $sid ), 0, 32 );
	}

	private static function clip( string $s ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, self::TEXT_MAX ) : substr( $s, 0, self::TEXT_MAX );
	}

	private static function json( $v ): string {
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		return (string) ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $v, $flags ) : json_encode( $v, $flags ) );
	}

	private static function route_missing(): bool {
		return function_exists( 'get_transient' ) && (bool) get_transient( self::NO_ROUTE_KEY );
	}

	private static function remember_route_missing(): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::NO_ROUTE_KEY, 1, self::NO_ROUTE_TTL );
		}
	}

	/** Trace of the decision only — never a UID, a hash or the message (R-DDV, R-PRIVACY). */
	private static function trace( string $surface, array $d ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'bizcity_twin_web_turn_decided', array( 'surface' => $surface, 'path' => $d['path'], 'reason' => $d['reason'], 'flag' => $d['flag'], 'role' => isset( $d['principal'] ) ? $d['principal']['role'] : '' ) );
		}
	}

	/* ── readers (server facts) ───────────────────────────────────── */

	private static function option( string $name, $default ) {
		if ( isset( self::$readers['option'] ) ) {
			return call_user_func( self::$readers['option'], $name, $default );
		}
		return function_exists( 'get_option' ) ? get_option( $name, $default ) : $default;
	}

	/** @return string[] zalo-hub bridge ids of this site */
	private static function accounts(): array {
		$rows = isset( self::$readers['accounts'] )
			? (array) call_user_func( self::$readers['accounts'] )
			: ( class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) ? BizCity_Zalo_Hub_Config_Sync::accounts() : array() );
		$out = array();
		foreach ( $rows as $r ) {
			$id = is_array( $r ) ? (string) ( $r['bridge_id'] ?? '' ) : (string) $r;
			if ( '' !== $id ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function principals( string $bridge ): array {
		if ( isset( self::$readers['principals'] ) ) {
			return (array) call_user_func( self::$readers['principals'], $bridge );
		}
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::principals( $bridge ) : array();
	}

	private static function user_hash( int $user_id ): string {
		if ( isset( self::$readers['user_hash'] ) ) {
			return (string) call_user_func( self::$readers['user_hash'], $user_id );
		}
		return class_exists( 'BizCity_Agent_Mode_Access' ) ? BizCity_Agent_Mode_Access::user_hash( $user_id ) : '';
	}

	private static function staff_supported(): bool {
		if ( isset( self::$readers['staff_supported'] ) ) {
			return (bool) call_user_func( self::$readers['staff_supported'] );
		}
		return ! function_exists( 'apply_filters' ) || (bool) apply_filters( self::FILTER_STAFF, true );
	}

	/** This blog's 1API key id at the Hub (cached 12 h; learnt from the Hub's /zalo-personal-bridge/health). */
	private static function key_id(): int {
		if ( isset( self::$readers['key_id'] ) ) {
			return (int) call_user_func( self::$readers['key_id'] );
		}
		$cached = function_exists( 'get_transient' ) ? (int) get_transient( self::KEY_ID_KEY ) : 0;
		if ( $cached > 0 || ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ) {
			return $cached;
		}
		$h  = BizCity_Zalo_Personal_Hub_Client::instance()->health();
		$id = (int) ( $h['key_id'] ?? 0 );
		if ( $id > 0 && function_exists( 'set_transient' ) ) {
			set_transient( self::KEY_ID_KEY, $id, self::KEY_ID_TTL );
		}
		return $id;
	}

	private static function target(): ?array {
		if ( isset( self::$readers['target'] ) ) {
			$t = call_user_func( self::$readers['target'] );
			return is_array( $t ) ? $t : null;
		}
		return class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) && method_exists( 'BizCity_Zalo_Personal_Hub_Client', 'stream_target' )
			? BizCity_Zalo_Personal_Hub_Client::instance()->stream_target( self::HUB_PATH )
			: null;
	}

	private static function guru_ref( string $bridge ): string {
		if ( isset( self::$readers['guru_ref'] ) ) {
			return (string) call_user_func( self::$readers['guru_ref'], $bridge );
		}
		$b  = class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::resolve( 'ZALO_PERSONAL', $bridge ) : null;
		$id = is_array( $b ) ? (int) ( $b['character_id'] ?? 0 ) : 0;
		return $id > 0 ? 'guru:' . $id : '';
	}

	private static function trace_id(): string {
		if ( isset( self::$readers['trace_id'] ) ) {
			return (string) call_user_func( self::$readers['trace_id'] );
		}
		return 'TW' . strtoupper( bin2hex( random_bytes( 12 ) ) );
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	/* ── transport (transition bridge) ────────────────────────────── */

	/** @return array{ok:bool,status:int} */
	private static function transport( array $target, string $body, callable $on_bytes ): array {
		if ( isset( self::$readers['transport'] ) ) {
			return (array) call_user_func( self::$readers['transport'], $target, $body, $on_bytes );
		}
		if ( ! function_exists( 'curl_init' ) ) {
			return array( 'ok' => false, 'status' => 0 );
		}
		$status  = 0;
		$ctype   = '';
		$headers = array(
			'Authorization: Bearer ' . (string) $target['key'],
			'Content-Type: application/json',
			'Accept: text/event-stream',
			'X-Site-URL: ' . ( function_exists( 'home_url' ) ? home_url() : '' ),
		);
		foreach ( (array) ( $target['headers'] ?? array() ) as $k => $v ) {
			$headers[] = $k . ': ' . $v;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::TIMEOUT + 10 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		ignore_user_abort( true ); // keep running long enough to write the transcript; aborts are detected per chunk
		$ch = curl_init( (string) $target['url'] );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => self::TIMEOUT,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_HEADERFUNCTION => static function ( $ch, $line ) use ( &$status, &$ctype ) {
				if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
					$status = (int) $m[1];
					$ctype  = '';
				} elseif ( 0 === stripos( $line, 'content-type:' ) ) {
					$ctype = trim( substr( $line, 13 ) );
				}
				return strlen( $line );
			},
			CURLOPT_WRITEFUNCTION  => static function ( $ch, $chunk ) use ( &$status, &$ctype, $on_bytes ) {
				return $on_bytes( (string) $chunk, (int) $status, (string) $ctype ) ? strlen( $chunk ) : 0;
			},
		) );
		$ok = curl_exec( $ch );
		curl_close( $ch );
		return array( 'ok' => false !== $ok, 'status' => (int) $status );
	}

	private static function open(): void {
		if ( isset( self::$readers['open'] ) ) {
			call_user_func( self::$readers['open'] );
			return;
		}
		if ( ! headers_sent() ) {
			header_remove( 'Content-Type' );
			header_remove( 'Content-Length' );
			header( 'Content-Type: text/event-stream; charset=UTF-8' );
			header( 'Cache-Control: no-cache, no-store, no-transform, must-revalidate' );
			header( 'X-Accel-Buffering: no' );
			header( 'Content-Encoding: none' );
		}
		while ( ob_get_level() > 0 ) {
			@ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		// Same 4 KB primer as the TwinChat PHP stream (proxies that wait for a minimum body before flushing).
		self::out( ": sse-open\n" . str_repeat( ' ', 4096 ) . "\n\n" );
	}

	private static function out( string $bytes ): void {
		if ( isset( self::$readers['out'] ) ) {
			call_user_func( self::$readers['out'], $bytes );
			return;
		}
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput -- SSE bytes from the cell (unchanged) or site frames built above
		flush();
	}
}
