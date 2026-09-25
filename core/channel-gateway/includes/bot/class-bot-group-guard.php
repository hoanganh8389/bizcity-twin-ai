<?php
/**
 * Bot Studio — group anti-spam guard (PHASE-0.60K K7, decision D-K5; 0.60E EA-6).
 *
 * Watches a Zalo group the bot's number sits in. When ONE member sends a burst of text (default 5 messages in 20 s) the guard posts ONE
 * warning that tags the group's REAL owner and deputies — "nghi bot lỗi/spam, nhờ xử lý giúp". Optionally (default OFF, and only when the
 * account has an `owner_uid`) it announces it will remove the member after a veto window and does so unless an admin speaks up.
 *
 * Rules kept from Libe-Zalo, each from a real incident there, and tightened where 0.60E EA-6.6 asks for more:
 *  - only text counts (bot's own messages, stickers and images do not); the window slides; a member is warned at most once per cooldown;
 *  - who is an admin comes from Zalo's own group info (creator + deputies) — never from what someone claims in chat;
 *  - Libe-Zalo exempts nobody. Here the account's `owner_uid`, an allow-listed UID, and the group's real creator/deputies are exempt;
 *  - a kick is only ever proposed for REPEATED content (a fast typist is not a bot), is announced first, is vetoed by ANY message from an
 *    admin or the owner inside the window, is re-checked immediately before it runs, and its result is read from Zalo (`errorMembers`), not assumed.
 *
 * Counting happens on `bizcity_channel_normalized` at priority -5, i.e. before Bot Studio decides whether it answers — the guard runs whether or
 * not the bot takes the turn, and never touches the claim. The warning is SENT once the CRM has persisted the message (only then does the group's
 * conversation id exist).
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 */

// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K7
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Group_Guard {

	const KICK_HOOK    = 'bizcity_bot_flood_kick';
	const PLATFORM     = 'ZALO_PERSONAL';
	const MAX_TS       = 100;
	const KEEP_TEXTS   = 5;

	/** @var callable|null test seam: fn(): int */
	public static $clock = null;
	/** @var callable|null test seam: fn(int $timestamp, string $hook, array $args): bool */
	public static $scheduler = null;
	/** @var callable|null test seam: fn(string $type, array $payload): void */
	public static $event_observer = null;

	/** @var array|null what on_normalized decided for the message in flight; consumed by on_persisted (same request). */
	private static $pending = null;

	public static function init(): void {
		add_action( 'bizcity_channel_normalized', array( __CLASS__, 'on_normalized' ), -5, 2 );
		add_action( 'bizcity_crm_message_persisted', array( __CLASS__, 'on_persisted' ), 9, 1 );
		add_action( self::KICK_HOOK, array( __CLASS__, 'on_kick_due' ), 10, 1 );
	}

	private static function now(): int {
		return is_callable( self::$clock ) ? (int) call_user_func( self::$clock ) : time();
	}

	private static function isolated(): bool {
		return defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI;
	}

	/* ══ pure detectors ═══════════════════════════════════════════════════ */

	/** @param int[] $timestamps message times of ONE sender in ONE group */
	public static function is_flood( array $timestamps, int $now, int $window_seconds, int $threshold ): bool {
		$in = 0;
		foreach ( $timestamps as $t ) {
			if ( (int) $t > $now - $window_seconds && (int) $t <= $now + 1 ) {
				$in++;
			}
		}
		return $in >= max( 2, $threshold );
	}

	/**
	 * "Is this the same message pasted over and over?" — at least half of the adjacent pairs share a common prefix of ≥ 60 % of the
	 * shorter text (Libe-Zalo constants 0.5 / 0.6). A short "ok"/"ừ" never counts (< 4 characters).
	 *
	 * @param string[] $texts oldest → newest
	 */
	public static function is_repeating( array $texts ): bool {
		$texts = array_values( array_map( static function ( $t ) { return mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', (string) $t ) ) ); }, $texts ) );
		if ( count( $texts ) < 3 ) {
			return false;
		}
		$pairs = 0;
		$same  = 0;
		for ( $i = 1; $i < count( $texts ); $i++ ) {
			$a = $texts[ $i - 1 ];
			$b = $texts[ $i ];
			$short = min( mb_strlen( $a ), mb_strlen( $b ) );
			$pairs++;
			if ( $short < 4 ) {
				continue;
			}
			$common = 0;
			while ( $common < $short && mb_substr( $a, $common, 1 ) === mb_substr( $b, $common, 1 ) ) {
				$common++;
			}
			if ( $common / $short >= 0.6 ) {
				$same++;
			}
		}
		return $pairs > 0 && $same / $pairs >= 0.5;
	}

	/* ══ beat 1: count ════════════════════════════════════════════════════ */

	public static function on_normalized( array $envelope, string $trigger_key = '' ): void {
		self::$pending = null;
		if ( strtoupper( (string) ( $envelope['platform'] ?? '' ) ) !== self::PLATFORM || self::isolated() ) {
			return;
		}
		if ( ! class_exists( 'BizCity_Bot_Turn_Claim' ) || ! class_exists( 'BizCity_Channel_Binding' ) || ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			return;
		}
		$raw_code = isset( $envelope['raw']['code'] ) ? sanitize_key( (string) $envelope['raw']['code'] ) : '';
		if ( '' !== $raw_code && BizCity_Bot_Turn_Claim::CODE !== $raw_code ) {
			return;
		}
		$thread = BizCity_Bot_Turn_Claim::thread_ref( $envelope );
		if ( 'group' !== $thread['chat_kind'] || '' === $thread['group_id'] || '' === $thread['sender_uid'] ) {
			return; // a private chat has no "group flood".
		}
		if ( ! empty( $envelope['is_self'] ) || ! empty( $envelope['raw']['is_self'] ) ) {
			return; // the bot's own number.
		}
		$text = trim( (string) ( $envelope['message_text_clean'] ?? $envelope['message'] ?? '' ) );
		if ( '' === $text ) {
			return; // stickers and photos are not flooding text.
		}
		$account_id = (string) ( $envelope['account_id'] ?? '' );
		$binding    = '' !== $account_id ? BizCity_Channel_Binding::resolve( self::PLATFORM, $account_id ) : null;
		if ( ! is_array( $binding ) ) {
			return;
		}
		$policy = BizCity_Bot_Turn_Claim::decode_office_hours( $binding['policy_json'] ?? '' );
		if ( empty( $policy['antispam_enabled'] ) ) {
			return;
		}
		$group  = $thread['group_id'];
		$sender = $thread['sender_uid'];
		$tuning = BizCity_Bot_Config_Repo::get_tuning();
		$now    = self::now();

		// A pending kick is vetoed by ANY message from the owner or a real admin of this group.
		self::maybe_veto( $account_id, $group, $sender, $policy );

		if ( self::is_configured_exempt( $sender, $policy ) ) {
			return;
		}
		$state = self::record( $account_id, $group, $sender, $text, $now, (int) $tuning['antispam_window_seconds'] );
		if ( ! self::is_flood( $state['ts'], $now, (int) $tuning['antispam_window_seconds'], (int) $tuning['antispam_threshold'] ) ) {
			return;
		}
		// One warning per (group, sender) per cooldown — stamped BEFORE anything is sent, so ten more messages cannot fire ten warnings.
		$warn_key = self::key( 'floodwarn', $account_id, $group, $sender );
		if ( get_transient( $warn_key ) ) {
			return;
		}
		$admins = BizCity_Bot_Zalo_Actions::group_admins( $account_id, $group );
		if ( is_array( $admins ) && ( $admins['creator'] === $sender || in_array( $sender, $admins['admins'], true ) ) ) {
			return; // the group's own creator/deputy is never flagged (EA-6.6).
		}
		set_transient( $warn_key, $now, max( 60, (int) $tuning['antispam_cooldown_minutes'] * 60 ) );
		$count = 0;
		foreach ( $state['ts'] as $t ) {
			if ( $t > $now - (int) $tuning['antispam_window_seconds'] ) {
				$count++;
			}
		}
		self::$pending = array(
			'type'       => 'warn',
			'account_id' => $account_id,
			'group_id'   => $group,
			'sender'     => $sender,
			'count'      => $count,
			'window'     => (int) $tuning['antispam_window_seconds'],
			'repeating'  => self::is_repeating( $state['texts'] ),
			'admins'     => is_array( $admins ) ? array_values( array_unique( array_filter( array_merge( array( $admins['creator'] ), $admins['admins'] ) ) ) ) : array(),
			'policy'     => array( 'auto_kick' => ! empty( $policy['antispam_auto_kick'] ), 'owner_uid' => trim( (string) ( $policy['owner_uid'] ?? '' ) ) ),
			'binding_id' => (int) ( $binding['id'] ?? 0 ),
			'character_id' => (int) ( $binding['character_id'] ?? 0 ),
		);
		self::emit( 'bot_flood_detected', array( 'group_ref' => substr( md5( $group ), 0, 8 ), 'count' => $count, 'window' => (int) $tuning['antispam_window_seconds'], 'repeating' => self::$pending['repeating'] ) );
	}

	/** @return array{ts:int[],texts:string[]} the sender's recent messages, trimmed to the window */
	private static function record( string $account_id, string $group, string $sender, string $text, int $now, int $window ): array {
		$key   = self::key( 'flood', $account_id, $group, $sender );
		$state = get_transient( $key );
		$state = is_array( $state ) ? $state : array( 'ts' => array(), 'texts' => array() );
		$ts    = array_values( array_filter( (array) $state['ts'], static function ( $t ) use ( $now, $window ) { return (int) $t > $now - $window; } ) );
		$ts[]  = $now;
		$texts = array_slice( array_merge( (array) $state['texts'], array( mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ), 0, 120 ) ) ), -self::KEEP_TEXTS );
		$state = array( 'ts' => array_slice( $ts, -self::MAX_TS ), 'texts' => $texts );
		// Not atomic on purpose: the threshold is soft (a miscount of 1–2 messages is harmless), and the warning itself is guarded above.
		set_transient( $key, $state, max( 60, $window * 3 ) );
		return $state;
	}

	private static function is_configured_exempt( string $sender, array $policy ): bool {
		$owner = trim( (string) ( $policy['owner_uid'] ?? '' ) );
		if ( '' !== $owner && hash_equals( $owner, $sender ) ) {
			return true;
		}
		return 'list' === (string) ( $policy['allowlist_mode'] ?? '' ) && in_array( $sender, array_map( 'strval', (array) ( $policy['allowlist_uids'] ?? array() ) ), true );
	}

	/* ══ beat 2: send ═════════════════════════════════════════════════════ */

	public static function on_persisted( $payload ): void {
		$p             = self::$pending;
		self::$pending = null;
		if ( null === $p || ! is_array( $payload ) || 'incoming' !== (string) ( $payload['direction'] ?? '' ) || self::isolated() ) {
			return;
		}
		$conversation_id = (int) ( $payload['conversation_id'] ?? 0 );
		if ( $conversation_id <= 0 || ! class_exists( 'BizCity_Bot_Turn_Runner' ) ) {
			return;
		}
		if ( 'cancel' === $p['type'] ) {
			self::say( $conversation_id, 'Đã HUỶ kick ' . self::name_of( $p ) . ' vì ' . ( '' !== $p['by_name'] ? $p['by_name'] : 'quản trị viên' ) . ' đã can thiệp.', array(), 'bot-flood-cancel-' . $p['group_id'] . '-' . $p['sender'] . '-' . floor( self::now() / 60 ), $p );
			self::emit( 'bot_kick_vetoed', array( 'group_ref' => substr( md5( $p['group_id'] ), 0, 8 ) ) );
			return;
		}

		// The warning, tagging the real owner/deputies (names come from the roster, never from the model or the chat).
		$roster  = BizCity_Bot_Zalo_Actions::roster( $p['account_id'], $p['group_id'] );
		$targets = array();
		foreach ( $p['admins'] as $uid ) {
			if ( isset( $roster[ $uid ] ) ) {
				$targets[] = array( 'uid' => $uid, 'name' => $roster[ $uid ] );
			}
		}
		$body = 'Phát hiện ' . self::name_of( $p, $roster ) . ' (' . $p['sender'] . ') đang gửi dồn dập (' . $p['count'] . ' tin trong ' . $p['window'] . ' giây), nghi bot lỗi/spam. Nhờ quản trị viên xử lý giúp.';
		$applied = class_exists( 'BizCity_Bot_Mentions' ) && ! empty( $targets ) ? BizCity_Bot_Mentions::apply( $body, $targets ) : array( 'text' => $body, 'mentions' => array() );
		$sent    = self::say( $conversation_id, $applied['text'], $applied['mentions'], 'bot-flood-' . $p['group_id'] . '-' . $p['sender'] . '-' . floor( self::now() / max( 60, (int) BizCity_Bot_Config_Repo::get_tuning()['antispam_cooldown_minutes'] * 60 ) ), $p );
		if ( $sent ) {
			self::emit( 'bot_flood_warned', array( 'group_ref' => substr( md5( $p['group_id'] ), 0, 8 ), 'tagged' => count( $targets ) ) );
		}

		// Auto-kick: off by default; needs an accountable owner; only for REPEATED content.
		if ( $sent && $p['policy']['auto_kick'] && '' !== $p['policy']['owner_uid'] && $p['repeating'] ) {
			self::propose_kick( $conversation_id, $p, $roster );
		}
	}

	private static function propose_kick( int $conversation_id, array $p, array $roster ): void {
		$pending_key = self::key( 'pendingkick', $p['account_id'], $p['group_id'], '' );
		if ( get_transient( $pending_key ) ) {
			return; // at most one kick waiting per group.
		}
		$veto = (int) BizCity_Bot_Config_Repo::get_tuning()['antispam_kick_veto_seconds'];
		set_transient( $pending_key, array( 'sender' => $p['sender'], 'at' => self::now(), 'admins' => $p['admins'], 'owner_uid' => $p['policy']['owner_uid'], 'name' => self::name_of( $p, $roster ) ), $veto + 120 );
		$args = array( 'account_id' => $p['account_id'], 'group_id' => $p['group_id'], 'sender' => $p['sender'], 'conversation_id' => $conversation_id );
		if ( ! self::schedule( self::now() + $veto, $args ) ) {
			delete_transient( $pending_key );
			return;
		}
		self::say( $conversation_id, 'Sắp tự động đưa ' . self::name_of( $p, $roster ) . ' ra khỏi nhóm sau ' . $veto . ' giây vì gửi tin lặp lại. Admin phản đối bằng cách nhắn gì đó trong nhóm để huỷ.', array(), 'bot-flood-kickwarn-' . $p['group_id'] . '-' . $p['sender'] . '-' . floor( self::now() / 60 ), $p );
		self::emit( 'bot_kick_scheduled', array( 'group_ref' => substr( md5( $p['group_id'] ), 0, 8 ), 'veto_seconds' => $veto ) );
	}

	/* ══ veto & kick ══════════════════════════════════════════════════════ */

	private static function maybe_veto( string $account_id, string $group, string $sender, array $policy ): void {
		$pending_key = self::key( 'pendingkick', $account_id, $group, '' );
		$kick        = get_transient( $pending_key );
		if ( ! is_array( $kick ) || $sender === (string) ( $kick['sender'] ?? '' ) ) {
			return;
		}
		$is_owner = '' !== trim( (string) ( $policy['owner_uid'] ?? '' ) ) && hash_equals( trim( (string) $policy['owner_uid'] ), $sender );
		$admins   = (array) ( $kick['admins'] ?? array() );
		if ( ! $is_owner && ! in_array( $sender, $admins, true ) ) {
			return; // a bystander's message is not a veto — only the people who could actually have decided.
		}
		delete_transient( $pending_key ); // the scheduled event re-checks this and finds it gone.
		$roster        = BizCity_Bot_Zalo_Actions::roster( $account_id, $group );
		self::$pending = array( 'type' => 'cancel', 'account_id' => $account_id, 'group_id' => $group, 'sender' => (string) $kick['sender'], 'by_name' => (string) ( $roster[ $sender ] ?? '' ), 'name' => (string) ( $kick['name'] ?? '' ) );
	}

	/** The scheduled kick. Re-checks the veto immediately before acting (race), reads Zalo's real result, reports it. */
	public static function on_kick_due( $args ): void {
		if ( self::isolated() || ! is_array( $args ) ) {
			return;
		}
		$account = (string) ( $args['account_id'] ?? '' );
		$group   = (string) ( $args['group_id'] ?? '' );
		$sender  = (string) ( $args['sender'] ?? '' );
		$conv    = (int) ( $args['conversation_id'] ?? 0 );
		$key     = self::key( 'pendingkick', $account, $group, '' );
		$kick    = get_transient( $key );
		if ( ! is_array( $kick ) || (string) ( $kick['sender'] ?? '' ) !== $sender ) {
			return; // vetoed (or replaced) while we waited — nothing to do.
		}
		delete_transient( $key ); // clear-before-act: a second firing of this event finds nothing.
		$res = BizCity_Bot_Zalo_Actions::raw_action( $account, 'kick_member', array( 'thread_id' => $group, 'thread_kind' => 'group', 'group_id' => $group, 'user_id' => $sender ) );
		$ok  = ! empty( $res['success'] );
		$p   = array( 'account_id' => $account, 'group_id' => $group, 'sender' => $sender, 'name' => (string) ( $kick['name'] ?? '' ) );
		if ( $conv > 0 ) {
			self::say( $conv, $ok ? 'Đã đưa ' . self::name_of( $p ) . ' ra khỏi nhóm.' : 'Không đưa được ' . self::name_of( $p ) . ' ra khỏi nhóm (' . sanitize_key( (string) ( $res['code'] ?? $res['error'] ?? 'lỗi' ) ) . ') — số này cần là trưởng/phó nhóm.', array(), 'bot-flood-kick-' . $group . '-' . $sender . '-' . floor( self::now() / 60 ), $p );
		}
		self::emit( $ok ? 'bot_kicked' : 'bot_kick_failed', array( 'group_ref' => substr( md5( $group ), 0, 8 ), 'code' => $ok ? '' : sanitize_key( (string) ( $res['code'] ?? $res['error'] ?? 'unknown' ) ) ) );
	}

	/* ══ plumbing ═════════════════════════════════════════════════════════ */

	private static function say( int $conversation_id, string $text, array $mentions, string $idem, array $ctx ): bool {
		$out = BizCity_Bot_Turn_Runner::send_media_message( $conversation_id, $text, 0, $idem, '', 'flood', $mentions );
		if ( ! empty( $out['sent'] ) && class_exists( 'BizCity_Bot_Turn_Claim' ) && class_exists( 'BizCity_CRM_Repository' ) && method_exists( 'BizCity_CRM_Repository', 'get_conversation_contact_id' ) ) {
			$contact = (int) BizCity_CRM_Repository::get_conversation_contact_id( $conversation_id );
			if ( $contact > 0 ) {
				BizCity_Bot_Turn_Claim::increment_today_count( $contact ); // an unprompted message counts toward the daily cap (EA-6.4).
			}
		}
		return ! empty( $out['sent'] );
	}

	/** Display name for the flagged member: the roster's, else the name we saw, else the uid. */
	private static function name_of( array $p, array $roster = array() ): string {
		$uid = (string) ( $p['sender'] ?? '' );
		return (string) ( $roster[ $uid ] ?? ( '' !== (string) ( $p['name'] ?? '' ) ? $p['name'] : ( '' !== $uid ? 'thành viên ' . $uid : 'thành viên' ) ) );
	}

	private static function key( string $kind, string $account_id, string $group, string $sender ): string {
		return 'bzbot_' . $kind . '_' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . '_' . md5( $account_id ) . '_' . md5( $group ) . ( '' !== $sender ? '_' . md5( $sender ) : '' );
	}

	private static function schedule( int $when, array $args ): bool {
		if ( is_callable( self::$scheduler ) ) {
			return false !== call_user_func( self::$scheduler, $when, self::KICK_HOOK, array( $args ) );
		}
		if ( self::isolated() ) {
			return false;
		}
		return true === wp_schedule_single_event( $when, self::KICK_HOOK, array( $args ) );
	}

	private static function emit( string $type, array $payload ): void {
		if ( is_callable( self::$event_observer ) ) {
			call_user_func( self::$event_observer, $type, $payload );
		}
		if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			try {
				BizCity_Twin_Event_Bus::dispatch( $type, $payload ); // counts and a group hash — never message text.
			} catch ( \Throwable $e ) {
				// the bus must never break the inbound path.
			}
		}
	}
}
