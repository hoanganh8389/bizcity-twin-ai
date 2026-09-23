<?php
/**
 * CRM Contact Enrichment from Zalo Personal + birthday (PHASE-0.60B).
 *
 * Chain (doc 0.60B §4) — every link already has an owner, this file only wires them:
 *   incoming message persisted → contact + conversation (source_id = Zalo uid)
 *     → BizCity_Zalo_Bridge_Client::get_user_profile( bridge_account, uid )     ★ existing
 *     → normalize_profile()                                                     ☆ thin
 *     → BizCity_CRM_Repository::enrich_contact()  (fill-only-empty, merge attrs) ☆ thin, repository-owned
 *     → birthday newly known → ONE bizcity_crm_events row (event_type=contact_birthday)
 *
 * Seven rules (0.60B §4.1) enforced here or in the repository:
 *   1 never overwrite staff input · 2 never guess · 3 record source+time ·
 *   4 at most once per contact per day (BizCity_Cache, blog-scoped) ·
 *   5 never block the inbound path (runs on a one-shot cron) ·
 *   6 customer can withdraw (opt-out window) · 7 group threads never build a person.
 *
 * Q-B2 (which fields the bridge returns) could not be measured from a dev
 * machine; normalize_profile() therefore accepts every key spelling seen in the
 * bridge docs (displayName/display_name/name, avatar/avatar_url, gender,
 * sdob/dob/birthday) and records the raw key list under zalo_profile._keys so
 * the first real call documents itself.
 *
 * @package BizCity_Twin_CRM
 * @since PHASE-0.60B (2026-09-23)
 */

// [2026-09-23 04:10 PM Claude Fable 5.1] PHASE-0.60B C3/C4/C5 — enrichment chain, birthday event sync, bot context block.
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Contact_Enrichment' ) ) {
	return;
}

final class BizCity_CRM_Contact_Enrichment {

	const CACHE_GROUP       = 'crmenrich';
	const THROTTLE_TTL      = 86400; // rule 4: once per day per contact
	const MANUAL_TTL        = 600;   // "Làm mới từ Zalo" button: 10 minutes
	const DEGRADED_TTL      = 3600;  // bridge failed: retry in an hour, not per message
	const OPT_OUT_DAYS      = 90;    // N days without re-asking after a withdrawal (doc Q3 default)
	const CRON_HOOK         = 'bizcity_crm_contact_enrich';
	const EVENT_TYPE        = 'contact_birthday';
	const CONTEXT_MAX_CHARS = 900;

	/** @var callable|null test seam: fn(string $account_id, string $uid): array bridge payload */
	public static $profile_reader = null;

	public static function init(): void {
		add_action( 'bizcity_crm_message_persisted', array( __CLASS__, 'on_persisted' ), 20, 1 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'on_cron' ), 10, 2 );
		add_action( 'bizcity_scheduler_register_adapters', array( __CLASS__, 'register_adapter' ), 20 );
		add_action( 'bizcity_scheduler_reminder_fire', array( __CLASS__, 'on_reminder_fire' ), 10, 1 );
	}

	public static function register_adapter(): void {
		if ( class_exists( 'BizCity_Scheduler_Adapter_Registry' ) && class_exists( 'BizCity_Scheduler_Adapter_Contact_Birthday' ) ) {
			BizCity_Scheduler_Adapter_Registry::register( new BizCity_Scheduler_Adapter_Contact_Birthday() );
		}
	}

	/* ── inbound hook → one-shot cron (rule 5) ───────────────────────── */

	public static function on_persisted( array $payload ): void {
		if ( 'incoming' !== (string) ( $payload['direction'] ?? '' ) || 'zalo_personal' !== (string) ( $payload['adapter_code'] ?? '' ) ) {
			return;
		}
		$contact_id      = (int) ( $payload['contact_id'] ?? 0 );
		$conversation_id = (int) ( $payload['conversation_id'] ?? 0 );
		if ( $contact_id <= 0 || $conversation_id <= 0 || self::is_throttled( $contact_id ) ) {
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::CRON_HOOK, array( $contact_id, $conversation_id ) ) ) {
			return;
		}
		wp_schedule_single_event( time() + 2, self::CRON_HOOK, array( $contact_id, $conversation_id ) );
	}

	public static function on_cron( $contact_id, $conversation_id ): void {
		self::enrich_from_zalo( (int) $contact_id, (int) $conversation_id, 'auto' );
	}

	/* ── the chain ────────────────────────────────────────────────────── */

	/**
	 * @param string $trigger 'auto' (message) | 'manual' (drawer button)
	 * @return array{status:string,reason:string,updated:array,birthday_set:bool}
	 */
	public static function enrich_from_zalo( int $contact_id, int $conversation_id, string $trigger = 'auto' ): array {
		$out = array( 'status' => 'skipped', 'reason' => '', 'updated' => array(), 'birthday_set' => false );
		if ( $contact_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			$out['reason'] = 'module_not_loaded';
			return $out;
		}
		if ( self::is_throttled( $contact_id ) ) {
			$out['reason'] = 'throttled';
			return $out;
		}
		$contact = BizCity_CRM_Repository::get_contact( $contact_id );
		if ( ! is_array( $contact ) || ! empty( $contact['deleted_at'] ) ) {
			$out['reason'] = 'contact_missing';
			return $out;
		}
		$attrs = self::decode( $contact['additional_attributes'] ?? '' );
		$opt_out_until = (string) ( $attrs['enrichment_opt_out_until'] ?? '' );
		if ( $opt_out_until !== '' && strtotime( $opt_out_until ) > time() ) {
			$out['reason'] = 'opted_out';
			return $out; // rule 6
		}
		$conversation = $conversation_id > 0 ? BizCity_CRM_Repository::get_conversation( $conversation_id ) : null;
		$source_id    = is_array( $conversation ) ? (string) ( $conversation['source_id'] ?? '' ) : '';
		if ( $source_id === '' || strpos( $source_id, 'group:' ) === 0 ) {
			$out['reason'] = $source_id === '' ? 'source_missing' : 'group_thread'; // rule 7
			return $out;
		}
		$inbox = BizCity_CRM_Repository::get_inbox( (int) ( $conversation['inbox_id'] ?? 0 ) );
		if ( ! is_array( $inbox ) || 'zalo_personal' !== strtolower( (string) ( $inbox['channel_type'] ?? '' ) ) ) {
			$out['reason'] = 'not_zalo_personal';
			return $out;
		}
		$account_id = (string) ( $inbox['channel_ref_id'] ?? '' );

		$profile = self::read_profile( $account_id, $source_id );
		if ( empty( $profile['success'] ) ) {
			self::throttle( $contact_id, self::DEGRADED_TTL );
			$out['status'] = 'degraded';
			$out['reason'] = (string) ( $profile['code'] ?? $profile['_degraded'] ?? 'bridge_failed' );
			return $out;
		}
		$norm = self::normalize_profile( $profile );
		$data = array(
			'name'                  => $norm['name'],
			'avatar_url'            => $norm['avatar_url'],
			'additional_attributes' => array(
				'zalo_profile' => array(
					'display_name' => $norm['name'],
					'gender'       => $norm['gender'],
					'avatar_url'   => $norm['avatar_url'],
					'_keys'        => $norm['keys'],
					'_source'      => 'zalo_bridge',
					'_at'          => gmdate( 'c' ),
					'_trigger'     => $trigger,
				),
			),
		);
		if ( $norm['birthday'] !== '' || $norm['birthday_md'] !== '' ) {
			$data['birthday']      = $norm['birthday'];
			$data['birthday_md']   = $norm['birthday_md'];
			$data['birthday_meta'] = array( 'source' => 'zalo_bridge', 'at' => gmdate( 'c' ) );
		}
		$res = BizCity_CRM_Repository::enrich_contact( $contact_id, $data );
		self::throttle( $contact_id, 'manual' === $trigger ? self::MANUAL_TTL : self::THROTTLE_TTL );
		if ( ! empty( $res['birthday_set'] ) ) {
			self::sync_birthday_event( $contact_id );
		}
		return array( 'status' => 'ok', 'reason' => '', 'updated' => (array) ( $res['updated'] ?? array() ), 'birthday_set' => ! empty( $res['birthday_set'] ) );
	}

	/**
	 * Bridge payload → flat, source-neutral envelope. Pure (unit-tested).
	 *
	 * @return array{name:string,avatar_url:string,gender:string,birthday:string,birthday_md:string,keys:array}
	 */
	public static function normalize_profile( array $profile ): array {
		$p = isset( $profile['profile'] ) && is_array( $profile['profile'] ) ? $profile['profile'] : ( isset( $profile['user'] ) && is_array( $profile['user'] ) ? $profile['user'] : $profile );
		$pick = static function ( array $row, array $keys ): string {
			foreach ( $keys as $k ) {
				if ( isset( $row[ $k ] ) && is_scalar( $row[ $k ] ) && trim( (string) $row[ $k ] ) !== '' ) {
					return trim( (string) $row[ $k ] );
				}
			}
			return '';
		};
		$name   = sanitize_text_field( $pick( $p, array( 'display_name', 'displayName', 'zaloName', 'zalo_name', 'name' ) ) );
		$avatar = esc_url_raw( $pick( $p, array( 'avatar_url', 'avatar', 'avatarUrl', 'picture' ) ) );
		$gender_raw = strtolower( $pick( $p, array( 'gender', 'sex' ) ) );
		$gender = '';
		if ( in_array( $gender_raw, array( '0', 'male', 'nam', 'm' ), true ) ) {
			$gender = 'male';
		} elseif ( in_array( $gender_raw, array( '1', 'female', 'nữ', 'nu', 'f' ), true ) ) {
			$gender = 'female';
		}
		$dob_raw  = $pick( $p, array( 'sdob', 'dob', 'birthday', 'birth_date', 'birthdate' ) );
		$birthday = '';
		$md       = '';
		if ( $dob_raw !== '' ) {
			if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $dob_raw, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				$birthday = $dob_raw;
				$md       = $m[2] . '-' . $m[3];
			} elseif ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dob_raw, $m ) && checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) ) {
				$birthday = sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] ); // Zalo sdob = d/m/Y
				$md       = sprintf( '%02d-%02d', $m[2], $m[1] );
			} elseif ( preg_match( '/^(\d{1,2})\/(\d{1,2})$/', $dob_raw, $m ) && checkdate( (int) $m[2], (int) $m[1], 2000 ) ) {
				$md = sprintf( '%02d-%02d', $m[2], $m[1] ); // day/month only — year unknown, never guessed (rule 2)
			}
		}
		return array(
			'name'        => $name,
			'avatar_url'  => $avatar,
			'gender'      => $gender,
			'birthday'    => $birthday,
			'birthday_md' => $md,
			'keys'        => array_values( array_map( 'strval', array_keys( $p ) ) ),
		);
	}

	/* ── birthday write paths (bot / staff / customer) ───────────────── */

	/**
	 * Store a birthday through the repository. `$date` = Y-m-d, or '' with `$md` (MM-DD) when the year is unknown.
	 * Enrichment/customer sources fill only an empty slot; staff (`meta.force`) may overwrite.
	 */
	public static function set_birthday( int $contact_id, string $date, string $time = '', array $meta = array() ): bool {
		if ( $contact_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return false;
		}
		$md = '';
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			$md = $m[2] . '-' . $m[3];
		} elseif ( preg_match( '/^(\d{2})-(\d{2})$/', $date, $m ) ) {
			$md   = $date;
			$date = '';
		} elseif ( $date !== '' ) {
			return false;
		}
		$force = ! empty( $meta['force'] );
		$attrs = array( 'birthday_meta' => array( 'source' => (string) ( $meta['source'] ?? 'unknown' ), 'at' => (string) ( $meta['at'] ?? gmdate( 'c' ) ), 'message_id' => (int) ( $meta['message_id'] ?? 0 ) ) );
		if ( $time !== '' ) {
			$attrs['birth_time'] = $time;
		}
		$ok = BizCity_CRM_Repository::set_contact_birthday( $contact_id, $date, $md, $force, $attrs );
		if ( $ok ) {
			self::sync_birthday_event( $contact_id );
		}
		return $ok;
	}

	/** Customer withdrew: drop enrichment + birthday, and do not re-ask for N days (rule 6). */
	public static function clear_enrichment( int $contact_id, int $opt_out_days = self::OPT_OUT_DAYS ): bool {
		if ( $contact_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return false;
		}
		$until = gmdate( 'c', time() + max( 1, $opt_out_days ) * 86400 );
		$ok    = BizCity_CRM_Repository::clear_contact_enrichment( $contact_id, $until );
		if ( $ok ) {
			self::cancel_birthday_event( $contact_id );
			self::throttle( $contact_id, max( 1, $opt_out_days ) * 86400 );
		}
		return $ok;
	}

	/* ── bot context block (0.60B §6) ────────────────────────────────── */

	/** Facts with sources; empty slots are stated, never omitted; capped. */
	public static function context_block( int $contact_id ): string {
		if ( $contact_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return '';
		}
		$contact = BizCity_CRM_Repository::get_contact( $contact_id );
		if ( ! is_array( $contact ) ) {
			return '';
		}
		return self::render_context_block( $contact );
	}

	/** Pure renderer (unit-tested) — never receives data of another contact by construction. */
	public static function render_context_block( array $contact ): string {
		$attrs   = self::decode( $contact['additional_attributes'] ?? '' );
		$zalo    = isset( $attrs['zalo_profile'] ) && is_array( $attrs['zalo_profile'] ) ? $attrs['zalo_profile'] : array();
		$bmeta   = isset( $attrs['birthday_meta'] ) && is_array( $attrs['birthday_meta'] ) ? $attrs['birthday_meta'] : array();
		$name    = trim( (string) ( $contact['name'] ?? '' ) );
		$name_src = $name !== '' ? ( isset( $zalo['display_name'] ) && $zalo['display_name'] === $name ? 'hồ sơ Zalo' : 'nhân viên nhập' ) : '';
		$lines   = array( 'Người đang nhắn (dữ liệu CRM, không phải khách tự khai trong tin này):' );
		$lines[] = '- Tên: ' . ( $name !== '' ? $name . ' (nguồn: ' . $name_src . ')' : 'chưa có' );
		if ( ! empty( $zalo['gender'] ) ) {
			$lines[] = '- Giới tính: ' . ( 'male' === $zalo['gender'] ? 'nam' : 'nữ' ) . ' (nguồn: hồ sơ Zalo)';
		}
		$birthday = (string) ( $contact['birthday'] ?? '' );
		$md       = (string) ( $contact['birthday_md'] ?? '' );
		$src_map  = array( 'zalo_bridge' => 'hồ sơ Zalo', 'customer_stated' => 'khách nói trong chat', 'staff' => 'nhân viên nhập' );
		$bsrc     = $src_map[ (string) ( $bmeta['source'] ?? '' ) ] ?? 'CRM';
		if ( $birthday !== '' && $birthday !== '0000-00-00' && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $birthday, $m ) ) {
			$lines[] = '- Sinh nhật: ' . $m[3] . '/' . $m[2] . '/' . $m[1] . ' (nguồn: ' . $bsrc . ')';
		} elseif ( $md !== '' && preg_match( '/^(\d{2})-(\d{2})$/', $md, $m ) ) {
			$lines[] = '- Sinh nhật: ' . $m[2] . '/' . $m[1] . ' (nguồn: ' . $bsrc . '; chưa có năm sinh)';
		} else {
			$lines[] = '- Sinh nhật: chưa có';
		}
		if ( ! empty( $attrs['birth_time'] ) ) {
			$lines[] = '- Giờ sinh: ' . (string) $attrs['birth_time'];
		}
		$tags = self::decode( $contact['tags_json'] ?? '' );
		if ( ! empty( $tags ) ) {
			$lines[] = '- Nhãn: ' . implode( ', ', array_slice( array_map( 'strval', $tags ), 0, 6 ) );
		}
		if ( ! empty( $contact['segment'] ) ) {
			$lines[] = '- Phân khúc: ' . (string) $contact['segment'];
		}
		$block = implode( "\n", $lines );
		return mb_strlen( $block ) > self::CONTEXT_MAX_CHARS ? mb_substr( $block, 0, self::CONTEXT_MAX_CHARS - 1 ) . '…' : $block;
	}

	/* ── birthday event (Scheduler owns the cron) ────────────────────── */

	/**
	 * Next occurrence of MM-DD from today in the site timezone; 29/02 → 28/02 on non-leap years (doc §5.2).
	 *
	 * @return string Y-m-d
	 */
	public static function next_occurrence( string $md, ?int $now_ts = null, string $tz_name = '' ): string {
		if ( ! preg_match( '/^(\d{2})-(\d{2})$/', $md, $m ) ) {
			return '';
		}
		$month = (int) $m[1];
		$day   = (int) $m[2];
		$tz_name = $tz_name !== '' ? $tz_name : ( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC' );
		try {
			$tz = new \DateTimeZone( $tz_name );
		} catch ( \Throwable $e ) {
			$tz = new \DateTimeZone( 'UTC' );
		}
		$now  = $now_ts ? ( new \DateTime( '@' . $now_ts ) )->setTimezone( $tz ) : new \DateTime( 'now', $tz );
		$year = (int) $now->format( 'Y' );
		for ( $i = 0; $i < 2; $i++ ) {
			$y = $year + $i;
			$d = $day;
			if ( 2 === $month && 29 === $day && ! checkdate( 2, 29, $y ) ) {
				$d = 28;
			}
			$candidate = sprintf( '%04d-%02d-%02d', $y, $month, $d );
			if ( $candidate >= $now->format( 'Y-m-d' ) ) {
				return $candidate;
			}
		}
		return sprintf( '%04d-%02d-%02d', $year + 1, $month, $day );
	}

	/** Exactly one active event per contact; a changed birthday UPDATES it (C4.3). */
	public static function sync_birthday_event( int $contact_id ): int {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return 0;
		}
		$contact = BizCity_CRM_Repository::get_contact( $contact_id );
		if ( ! is_array( $contact ) || ! empty( $contact['deleted_at'] ) ) {
			return 0;
		}
		$md = (string) ( $contact['birthday_md'] ?? '' );
		if ( $md === '' && preg_match( '/^\d{4}-(\d{2})-(\d{2})$/', (string) ( $contact['birthday'] ?? '' ), $m ) ) {
			$md = $m[1] . '-' . $m[2];
		}
		if ( $md === '' ) {
			self::cancel_birthday_event( $contact_id );
			return 0;
		}
		$date  = self::next_occurrence( $md );
		$title = '🎂 Sinh nhật khách: ' . ( trim( (string) $contact['name'] ) !== '' ? (string) $contact['name'] : ( 'liên hệ #' . $contact_id ) );
		$owner = (int) ( $contact['owner_id'] ?? 0 );
		$mgr   = BizCity_Scheduler_Manager::instance();
		$data  = array(
			'user_id'      => $owner,
			'title'        => $title,
			'description'  => 'Nhắc nhân viên phụ trách; không tự nhắn khách trừ khi bật riêng.',
			'start_at'     => $date . ' 09:00:00',
			'end_at'       => $date . ' 09:30:00',
			'status'       => 'active',
			'source'       => 'crm_inbox',
			'event_type'   => self::EVENT_TYPE,
			'contact_id'   => $contact_id,
			'reminder_min' => 60,
			'metadata'     => array( 'contact_id' => $contact_id, 'birthday_md' => $md, 'notify_staff' => true, 'notify_customer' => false ),
		);
		$existing = self::find_active_event( $contact_id );
		if ( $existing > 0 ) {
			$mgr->update_event( $existing, array( 'title' => $title, 'start_at' => $data['start_at'], 'end_at' => $data['end_at'], 'metadata' => $data['metadata'] ), 0 );
			return $existing;
		}
		$id = $mgr->create_event( $data );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	public static function cancel_birthday_event( int $contact_id ): void {
		$existing = self::find_active_event( $contact_id );
		if ( $existing > 0 && class_exists( 'BizCity_Scheduler_Manager' ) ) {
			BizCity_Scheduler_Manager::instance()->update_event( $existing, array( 'status' => 'cancelled' ), 0 );
		}
	}

	public static function find_active_event( int $contact_id ): int {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) || ! method_exists( 'BizCity_Scheduler_Manager', 'get_table' ) ) {
			return 0;
		}
		global $wpdb;
		$table = BizCity_Scheduler_Manager::instance()->get_table();
		if ( ! $table ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_type = %s AND contact_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1", self::EVENT_TYPE, $contact_id ) );
	}

	/** Reminder fired by the Scheduler cron: notify staff (default), optionally the customer, then roll to next year. */
	public static function on_reminder_fire( $event ): void {
		if ( ! is_array( $event ) || self::EVENT_TYPE !== (string) ( $event['event_type'] ?? '' ) ) {
			return;
		}
		$meta       = is_array( $event['metadata'] ?? null ) ? $event['metadata'] : self::decode( $event['metadata'] ?? '' );
		$contact_id = (int) ( $event['contact_id'] ?? $meta['contact_id'] ?? 0 );
		$evidence   = array( 'event_id' => (int) ( $event['id'] ?? 0 ), 'contact_id' => $contact_id, 'reason' => '' );
		$contact    = $contact_id > 0 && class_exists( 'BizCity_CRM_Repository' ) ? BizCity_CRM_Repository::get_contact( $contact_id ) : null;
		if ( ! is_array( $contact ) || ! empty( $contact['deleted_at'] ) ) {
			$evidence['reason'] = 'contact_missing_or_deleted';
			self::note_cron( 'contact_birthday_skip', $evidence );
			return;
		}
		// Staff reminder = an internal note in the latest conversation (never leaves the site).
		$conversations = BizCity_CRM_Repository::list_conversations_for_contact( $contact_id, 1 );
		$conv          = ! empty( $conversations ) ? $conversations[0] : null;
		if ( is_array( $conv ) && ! empty( $meta['notify_staff'] ) ) {
			BizCity_CRM_Repository::insert_message( array(
				'conversation_id' => (int) $conv['id'],
				'inbox_id'        => (int) $conv['inbox_id'],
				'content'         => '🎂 Hôm nay là sinh nhật khách ' . (string) $contact['name'] . '. Gợi ý: gửi lời chúc / ưu đãi nhỏ.',
				'content_type'    => 'text',
				'message_type'    => 'private_note',
				'sender_type'     => 'system',
				'sender_id'       => 0,
				'status'          => 'note',
				'responder_kind'  => 'system',
			) );
			$evidence['staff_noted'] = true;
		}
		do_action( 'bizcity_crm_contact_birthday', $contact_id, $event );
		// Proactive greeting to the customer is OFF by default; when on, it counts against the bot's daily cap (0.60B §5.1, 0.60A B5.7).
		if ( ! empty( $meta['notify_customer'] ) && is_array( $conv ) && class_exists( 'BizCity_CRM_Outbound_Dispatcher' ) ) {
			$cap_ok = true;
			if ( class_exists( 'BizCity_Bot_Turn_Claim' ) && class_exists( 'BizCity_Bot_Config_Repo' ) ) {
				$cap_ok = BizCity_Bot_Turn_Claim::today_count( $contact_id ) < (int) BizCity_Bot_Config_Repo::get_tuning()['daily_message_cap'];
			}
			if ( $cap_ok ) {
				$greeting = (string) apply_filters( 'bizcity_crm_birthday_greeting', 'Chúc mừng sinh nhật ' . (string) $contact['name'] . '! Chúc bạn một ngày thật vui 🎉', $contact, $event );
				$env = BizCity_CRM_Outbound_Dispatcher::dispatch( array(
					'conversation_id' => (int) $conv['id'],
					'content'         => $greeting,
					'content_type'    => 'text',
					'idempotency_key' => 'bday-' . md5( $contact_id . '|' . (string) ( $event['start_at'] ?? '' ) ),
					'request_hash'    => md5( $greeting ),
					'actor'           => 'system',
					'system_source'   => 'automation',
					'responder_kind'  => 'auto',
				) );
				$evidence['customer_sent'] = is_array( $env ) && 'failed' !== (string) ( $env['outcome'] ?? 'failed' );
				if ( ! empty( $evidence['customer_sent'] ) && class_exists( 'BizCity_Bot_Turn_Claim' ) ) {
					BizCity_Bot_Turn_Claim::increment_today_count( $contact_id );
				}
			} else {
				$evidence['reason'] = 'daily_cap_reached';
			}
		}
		// Roll forward: close this one, open next year's (the Scheduler marks the fired one reminder_sent).
		if ( class_exists( 'BizCity_Scheduler_Manager' ) ) {
			BizCity_Scheduler_Manager::instance()->update_event( (int) $event['id'], array( 'status' => 'done' ), 0 );
			$evidence['next_event_id'] = self::sync_birthday_event( $contact_id );
		}
		self::note_cron( 'contact_birthday_fired', $evidence );
	}

	/* ── helpers ──────────────────────────────────────────────────────── */

	private static function read_profile( string $account_id, string $uid ): array {
		if ( is_callable( self::$profile_reader ) ) {
			return (array) call_user_func( self::$profile_reader, $account_id, $uid );
		}
		if ( $account_id === '' || $uid === '' || ! class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
			return array( 'success' => false, 'code' => 'bridge_unavailable' );
		}
		try {
			return (array) BizCity_Zalo_Bridge_Client::instance()->get_user_profile( $account_id, $uid );
		} catch ( \Throwable $e ) {
			return array( 'success' => false, 'code' => 'bridge_exception' );
		}
	}

	public static function is_throttled( int $contact_id ): bool {
		if ( class_exists( 'BizCity_Cache' ) ) {
			return (bool) BizCity_Cache::get( self::CACHE_GROUP, self::throttle_key( $contact_id ) );
		}
		return (bool) get_transient( 'crmenrich_' . self::throttle_key( $contact_id ) );
	}

	public static function throttle( int $contact_id, int $ttl ): void {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, self::throttle_key( $contact_id ), time(), $ttl );
			return;
		}
		set_transient( 'crmenrich_' . self::throttle_key( $contact_id ), time(), $ttl );
	}

	public static function release_throttle( int $contact_id ): void {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::delete( self::CACHE_GROUP, self::throttle_key( $contact_id ) );
			return;
		}
		delete_transient( 'crmenrich_' . self::throttle_key( $contact_id ) );
	}

	private static function throttle_key( int $contact_id ): string {
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		return 'enrich_' . $blog . '_' . $contact_id;
	}

	private static function note_cron( string $event, array $payload ): void {
		if ( class_exists( 'BizCity_Cron_Manager' ) && method_exists( 'BizCity_Cron_Manager', 'instance' ) ) {
			try {
				BizCity_Cron_Manager::instance()->note_event( $event, $payload ); // R-CRON-META reason bucket
			} catch ( \Throwable $e ) {
				// evidence must never break the reminder.
			}
		}
	}

	public static function decode( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && $raw !== '' ) {
			$d = json_decode( $raw, true );
			if ( is_array( $d ) ) {
				return $d;
			}
		}
		return array();
	}
}
