<?php
/**
 * Bot Studio — asynchronous music/video jobs (PHASE-0.60K K3, decisions D-K1 / D-K7).
 *
 * Music takes 40–60 s and video minutes; a turn is capped at 90 s and the customer is already waiting for a reply. So the tool does NOT
 * generate anything: it validates, books a job, and the turn answers "đang tạo, khoảng 1 phút nữa gửi". The job runs in its own
 * WP-Cron event, generates the file, stores it for the conversation's system owner, and sends it through the CRM outbound dispatcher
 * (so it has a CRM row, obeys the ownership rule and counts toward the daily proactive cap). A failed job sends ONE honest message —
 * the customer was told "sắp gửi" and must not be left waiting.
 *
 * Storage (D-K1): one `wp_option` per job (autoload = no) plus a small index option — no table, no DDL, and no ownership tangle with
 * the TwinWeb artifact-job table. Jobs older than 48 h are dropped whenever a new one is queued. The job holds ids, a bounded prompt
 * and counters — never a key, a URL with a token, or the customer's message.
 *
 * Every entry point is guarded by BIZCITY_DIAGNOSTICS_CLI (R-CLI-ASYNC-ISOLATION): a diagnostics run must never book or execute a real,
 * paid generation and message a real customer.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 */

// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K3
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Media_Jobs {

	const CRON_HOOK     = 'bizcity_bot_media_job';
	const OPT_PREFIX    = 'bzbot_media_job_';
	const INDEX_OPTION  = 'bzbot_media_jobs_index';
	const INDEX_MAX     = 200;
	const KEEP_SECONDS  = 172800; // 48 h
	const FIRST_DELAY   = 5;
	const POLL_DELAY    = 30;     // WP-Cron is not second-accurate; 30 s is the honest cadence.
	const MAX_POLLS     = 20;     // ≈ 10 minutes of video.
	const RUNNING_STALE = 300;    // a "running" job untouched this long may be taken over (a crashed worker).
	const ATTACH_MAX    = 26214400; // 25 MB — the outbound ceiling for an MP4 (dispatcher K0-4).
	const RATIOS        = array( '16:9', '9:16', '1:1' );

	/** kind => [tool, tuning key, default per hour, label]. */
	const KINDS = array(
		'music' => array( 'create_music', 'music_max_per_hour', 5, 'bài nhạc' ),
		'video' => array( 'create_video', 'video_max_per_hour', 3, 'video' ),
	);

	/** @var callable|null test seam: fn(int $timestamp, string $hook, array $args): bool */
	public static $scheduler = null;
	/** @var callable|null test seam: fn(int $character_id, string $prompt) returning an array or a WP_Error — BizCity_Bot_Media_Client::generate_music() */
	public static $music = null;
	/** @var object|null test seam: submit(string $prompt, array $options): array · get_status(string $task_id): array */
	public static $video = null;
	/** @var callable|null test seam: fn(string $binary, string $filename, string $mime, int $owner): int attachment id */
	public static $saver = null;
	/** @var callable|null test seam: fn(string $url, string $filename, int $owner): array{id:int,bytes:int,url:string,error:string} */
	public static $downloader = null;
	/** @var callable|null test seam: fn(string $type, array $payload): void */
	public static $event_observer = null;

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'on_cron' ), 10, 1 );
	}

	private static function isolated(): bool {
		return defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI;
	}

	/* ── queue (called from the tool, inside a turn) ───────────────────── */

	/**
	 * @param array{prompt?:string,duration?:int,aspect_ratio?:string} $args
	 * @return array{ok:bool,content:string,error:string,job_id?:string}
	 */
	public static function queue( string $kind, array $args, array $claim, int $owner_user_id ): array {
		if ( ! isset( self::KINDS[ $kind ] ) ) {
			return self::err( 'tool_unknown' );
		}
		if ( self::isolated() ) {
			return self::err( 'diagnostics_async_isolated' );
		}
		$prompt          = trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $args['prompt'] ?? $args['query'] ?? '' ) ) );
		$conversation_id = (int) ( $claim['conversation_id'] ?? 0 );
		$character_id    = (int) ( $claim['character_id'] ?? 0 );
		if ( mb_strlen( $prompt ) < 3 ) {
			return self::err( 'prompt_required' );
		}
		if ( $conversation_id <= 0 || $character_id <= 0 || $owner_user_id <= 0 ) {
			return self::err( 'invalid_param' ); // no owner ⇒ a file could never be sent: do not spend anything.
		}
		$limit = (int) ( BizCity_Bot_Config_Repo::get_tuning()[ self::KINDS[ $kind ][1] ] ?? self::KINDS[ $kind ][2] );
		if ( $limit <= 0 ) {
			return self::err( 'media_disabled' );
		}
		if ( count( self::recent( $kind, $conversation_id ) ) >= $limit ) {
			return self::err( 'rate_limited' );
		}

		$job_id = bin2hex( random_bytes( 6 ) );
		$job    = array(
			'job_id'          => $job_id,
			'kind'            => $kind,
			'status'          => 'queued',
			'character_id'    => $character_id,
			'conversation_id' => $conversation_id,
			'contact_id'      => (int) ( $claim['contact_id'] ?? 0 ),
			'owner_user_id'   => $owner_user_id,
			'trace_id'        => (string) ( $claim['trace_id'] ?? '' ),
			'prompt'          => mb_substr( $prompt, 0, 500 ),
			'params'          => 'video' === $kind ? self::video_params( $args, $character_id ) : array(),
			'task_id'         => '',
			'polls'           => 0,
			'created_at'      => time(),
			'updated_at'      => time(),
		);
		self::store( $job );
		self::index_add( $job_id );
		if ( ! self::schedule( time() + self::FIRST_DELAY, $job_id ) ) {
			self::forget( $job_id );
			return self::err( 'schedule_rejected' );
		}
		self::stamp( $kind, $conversation_id );
		self::emit( 'bot_media_job_queued', array( 'job_id' => $job_id, 'kind' => $kind, 'conversation_id' => $conversation_id, 'trace_id' => $job['trace_id'] ) );

		$label = self::KINDS[ $kind ][3];
		return array(
			'ok'      => true,
			'content' => BizCity_Bot_Tools::fence( 'Việc đang chạy nền', 'Đã bắt đầu tạo ' . $label . ' — khoảng ' . ( 'music' === $kind ? '1 phút' : 'vài phút' ) . ' nữa file sẽ được gửi vào cuộc chat này. Hãy báo khách đúng như vậy; KHÔNG nói là đã gửi và KHÔNG hứa giờ chính xác.', false ),
			'error'   => '',
			'job_id'  => $job_id,
		);
	}

	private static function video_params( array $args, int $character_id ): array {
		$cfg   = (array) ( BizCity_Bot_Config_Repo::get( $character_id )['media']['video'] ?? array() );
		$dur   = (int) ( $args['duration'] ?? $cfg['duration'] ?? 5 );
		$ratio = (string) ( $args['aspect_ratio'] ?? $cfg['aspect_ratio'] ?? '16:9' );
		return array(
			'model'        => (string) ( $cfg['model'] ?? '' ),
			'duration'     => max( BizCity_Bot_Config_Repo::VIDEO_DURATION_MIN, min( BizCity_Bot_Config_Repo::VIDEO_DURATION_MAX, $dur ) ),
			'aspect_ratio' => in_array( $ratio, self::RATIOS, true ) ? $ratio : '16:9',
			'with_audio'   => ! empty( $cfg['with_audio'] ),
		);
	}

	/* ── worker ────────────────────────────────────────────────────────── */

	public static function on_cron( $job_id ): void {
		self::run( (string) $job_id );
	}

	/** One step of one job. Idempotent: a job that is done, failed or being run by someone else is left alone. */
	public static function run( string $job_id ): void {
		if ( self::isolated() ) {
			return;
		}
		$job = self::load( $job_id );
		if ( null === $job || ! in_array( $job['status'], array( 'queued', 'polling', 'running' ), true ) ) {
			return;
		}
		if ( 'running' === $job['status'] && time() - (int) $job['updated_at'] < self::RUNNING_STALE ) {
			return; // cron fired twice: the first worker is still on it.
		}
		$prior          = $job['status'];
		$job['status']  = 'running';
		$job['updated_at'] = time();
		self::store( $job );
		try {
			'music' === $job['kind'] ? self::run_music( $job ) : self::run_video( $job, 'running' === $prior ? 'polling' : $prior );
		} catch ( \Throwable $e ) {
			self::fail( $job, 'exception' );
		}
	}

	private static function run_music( array $job ): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 240 );
		}
		$res = is_callable( self::$music ) ? call_user_func( self::$music, (int) $job['character_id'], (string) $job['prompt'] ) : BizCity_Bot_Media_Client::generate_music( (int) $job['character_id'], (string) $job['prompt'] );
		if ( is_wp_error( $res ) ) {
			self::fail( $job, (string) $res->get_error_code() );
			return;
		}
		$filename = 'nhac-' . gmdate( 'Ymd-His' ) . '.' . (string) $res['ext'];
		$id       = self::save( (string) $res['binary'], $filename, (string) $res['mime'], (int) $job['owner_user_id'] );
		if ( $id <= 0 ) {
			self::fail( $job, 'save_failed' );
			return;
		}
		self::deliver( $job, $id, '', 'file' );
	}

	private static function run_video( array $job, string $prior ): void {
		$client = is_object( self::$video ) ? self::$video : ( class_exists( 'BizCity_Video_Client' ) ? BizCity_Video_Client::instance() : null );
		if ( null === $client ) {
			self::fail( $job, 'video_client_missing' );
			return;
		}
		if ( '' === (string) $job['task_id'] ) {
			$sub = (array) $client->submit( (string) $job['prompt'], array_filter( (array) $job['params'], static function ( $v ) { return '' !== $v && null !== $v; } ) + array( 'trace_id' => (string) $job['trace_id'], 'idempotency_key' => 'bot-video-' . $job['job_id'] ) );
			if ( empty( $sub['success'] ) || '' === (string) ( $sub['task_id'] ?? '' ) ) {
				self::fail( $job, self::bucket( (string) ( $sub['error_code'] ?? $sub['code'] ?? '' ) ) );
				return;
			}
			$job['task_id'] = (string) $sub['task_id'];
			self::wait( $job );
			return;
		}
		$st  = (array) $client->get_status( (string) $job['task_id'] );
		$url = (string) ( $st['result_url'] ?? '' );
		if ( '' !== $url ) {
			$got = self::download( $url, 'video-' . gmdate( 'Ymd-His' ) . '.mp4', (int) $job['owner_user_id'] );
			if ( (int) $got['id'] <= 0 ) {
				self::fail( $job, (string) ( $got['error'] ?: 'download_failed' ) );
				return;
			}
			// Over the outbound ceiling ⇒ send the link, never a silent drop.
			if ( (int) $got['bytes'] > self::ATTACH_MAX ) {
				self::deliver( $job, 0, 'Video đã tạo xong, anh/chị xem tại: ' . (string) $got['url'], 'text' );
				return;
			}
			self::deliver( $job, (int) $got['id'], '', 'file' );
			return;
		}
		if ( in_array( strtolower( (string) ( $st['status'] ?? '' ) ), array( 'failed', 'error', 'canceled', 'cancelled' ), true ) ) {
			self::fail( $job, self::bucket( (string) ( $st['error_code'] ?? $st['code'] ?? '' ) ) );
			return;
		}
		$job['polls'] = (int) $job['polls'] + 1;
		if ( $job['polls'] >= self::MAX_POLLS ) {
			self::fail( $job, 'timeout' );
			return;
		}
		self::wait( $job );
	}

	/** Not done yet: park the job and look again in POLL_DELAY seconds. */
	private static function wait( array $job ): void {
		$job['status']     = 'polling';
		$job['updated_at'] = time();
		self::store( $job );
		if ( ! self::schedule( time() + self::POLL_DELAY, (string) $job['job_id'] ) ) {
			self::fail( $job, 'schedule_rejected' );
		}
	}

	/* ── outcome ───────────────────────────────────────────────────────── */

	private static function deliver( array $job, int $attachment_id, string $text, string $kind ): void {
		$res = BizCity_Bot_Turn_Runner::send_media_message(
			(int) $job['conversation_id'],
			$text,
			$attachment_id,
			'bot-media-' . $job['job_id'],
			(string) $job['trace_id'],
			(string) $job['kind']
		);
		if ( empty( $res['sent'] ) ) {
			self::fail( $job, 'send_failed', false ); // the file exists but never reached the customer: tell them it failed, do not re-send.
			return;
		}
		if ( (int) $job['contact_id'] > 0 && class_exists( 'BizCity_Bot_Turn_Claim' ) ) {
			BizCity_Bot_Turn_Claim::increment_today_count( (int) $job['contact_id'] ); // a proactive message counts toward the daily cap.
		}
		$job['status']     = 'done';
		$job['updated_at'] = time();
		$job['attachment_id'] = $attachment_id;
		self::store( $job );
		self::emit( 'bot_media_job_completed', array( 'job_id' => $job['job_id'], 'kind' => $job['kind'], 'conversation_id' => (int) $job['conversation_id'], 'trace_id' => (string) $job['trace_id'], 'polls' => (int) $job['polls'] ) );
	}

	/**
	 * D-K7 — ONE short honest message. The customer was told "sắp gửi"; silence would be a broken promise. The message carries no
	 * provider detail. Its idempotency key is per job, so a retried cron cannot send it twice.
	 */
	private static function fail( array $job, string $bucket, bool $tell = true ): void {
		$job['status']        = 'failed';
		$job['updated_at']    = time();
		$job['reason_bucket'] = '' !== $bucket ? $bucket : 'unknown';
		self::store( $job );
		self::emit( 'bot_media_job_failed', array( 'job_id' => $job['job_id'], 'kind' => $job['kind'], 'conversation_id' => (int) $job['conversation_id'], 'trace_id' => (string) $job['trace_id'], 'reason_bucket' => $job['reason_bucket'], 'polls' => (int) ( $job['polls'] ?? 0 ) ) );
		if ( $tell && class_exists( 'BizCity_Bot_Turn_Runner' ) ) {
			BizCity_Bot_Turn_Runner::send_media_message(
				(int) $job['conversation_id'],
				'Dạ, em chưa tạo được ' . self::KINDS[ $job['kind'] ][3] . ' lúc này (lỗi nhà cung cấp). Anh/chị thử lại sau giúp em ạ.',
				0,
				'bot-media-fail-' . $job['job_id'],
				(string) $job['trace_id'],
				(string) $job['kind']
			);
		}
	}

	/** Provider codes are bounded to a bucket — the log/event never carries free text. */
	private static function bucket( string $code ): string {
		$code = sanitize_key( $code );
		return '' === $code ? 'provider_error' : substr( $code, 0, 40 );
	}

	/* ── files ─────────────────────────────────────────────────────────── */

	private static function save( string $binary, string $filename, string $mime, int $owner ): int {
		if ( is_callable( self::$saver ) ) {
			return (int) call_user_func( self::$saver, $binary, $filename, $mime, $owner );
		}
		if ( '' === $binary || ! function_exists( 'wp_upload_bits' ) ) {
			return 0;
		}
		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		$upload = wp_upload_bits( $filename, null, $binary );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return 0;
		}
		$id = wp_insert_attachment( array( 'post_mime_type' => $mime, 'post_title' => sanitize_text_field( pathinfo( $filename, PATHINFO_FILENAME ) ), 'post_content' => '', 'post_status' => 'inherit', 'post_author' => $owner ), $upload['file'] );
		return ( ! $id || is_wp_error( $id ) ) ? 0 : (int) $id;
	}

	/** @return array{id:int,bytes:int,url:string,error:string} */
	private static function download( string $url, string $filename, int $owner ): array {
		if ( is_callable( self::$downloader ) ) {
			return (array) call_user_func( self::$downloader, $url, $filename, $owner );
		}
		$fail = static function ( string $e ): array { return array( 'id' => 0, 'bytes' => 0, 'url' => '', 'error' => $e ); };
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$tmp = download_url( $url, 300 );
		if ( is_wp_error( $tmp ) ) {
			return $fail( 'download_failed' );
		}
		$bytes = (int) @filesize( $tmp );
		$id    = media_handle_sideload( array( 'name' => $filename, 'tmp_name' => $tmp ), 0, null, array( 'test_form' => false ) );
		if ( is_wp_error( $id ) || (int) $id <= 0 ) {
			@unlink( $tmp );
			return $fail( 'save_failed' );
		}
		// media_handle_sideload() runs as the current user (none, in cron); the dispatcher's ownership rule is a strict `===`.
		wp_update_post( array( 'ID' => (int) $id, 'post_author' => $owner ) );
		return array( 'id' => (int) $id, 'bytes' => $bytes, 'url' => (string) wp_get_attachment_url( (int) $id ), 'error' => '' );
	}

	/* ── store (one option per job) ────────────────────────────────────── */

	public static function load( string $job_id ): ?array {
		if ( ! preg_match( '/^[0-9a-f]{12}$/', $job_id ) ) {
			return null;
		}
		$job = get_option( self::OPT_PREFIX . $job_id, null );
		return is_array( $job ) && isset( $job['job_id'], $job['kind'], $job['status'] ) ? $job : null;
	}

	private static function store( array $job ): void {
		update_option( self::OPT_PREFIX . $job['job_id'], $job, false ); // autoload = no.
	}

	private static function forget( string $job_id ): void {
		delete_option( self::OPT_PREFIX . $job_id );
		$idx = array_values( array_diff( (array) get_option( self::INDEX_OPTION, array() ), array( $job_id ) ) );
		update_option( self::INDEX_OPTION, $idx, false );
	}

	/** Newest last; capped; anything older than 48 h is deleted (its option too). */
	private static function index_add( string $job_id ): void {
		$idx = array_values( array_filter( (array) get_option( self::INDEX_OPTION, array() ), 'is_string' ) );
		$idx[] = $job_id;
		$keep  = array();
		foreach ( $idx as $id ) {
			$j = self::load( $id );
			if ( null === $j || time() - (int) $j['created_at'] > self::KEEP_SECONDS ) {
				delete_option( self::OPT_PREFIX . $id );
				continue;
			}
			$keep[] = $id;
		}
		while ( count( $keep ) > self::INDEX_MAX ) {
			delete_option( self::OPT_PREFIX . array_shift( $keep ) );
		}
		update_option( self::INDEX_OPTION, $keep, false );
	}

	/** Job counts by status, for the diagnostics probe. @return array{total:int,active:int,overdue:int} */
	public static function health(): array {
		$total = $active = $overdue = 0;
		foreach ( (array) get_option( self::INDEX_OPTION, array() ) as $id ) {
			$j = is_string( $id ) ? self::load( $id ) : null;
			if ( null === $j ) {
				continue;
			}
			$total++;
			if ( in_array( $j['status'], array( 'queued', 'polling', 'running' ), true ) ) {
				$active++;
				if ( time() - (int) $j['updated_at'] > 900 ) {
					$overdue++; // a job nobody has touched for 15 min: WP-Cron is not firing it.
				}
			}
		}
		return array( 'total' => $total, 'active' => $active, 'overdue' => $overdue );
	}

	/* ── hourly ceiling per (kind, conversation) ───────────────────────── */

	private static function rate_key( string $kind, int $conversation_id ): string {
		return 'bzbot_mediarate_' . $kind . '_' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . '_' . $conversation_id;
	}

	/** @return int[] timestamps within the last hour */
	private static function recent( string $kind, int $conversation_id ): array {
		return array_values( array_filter( (array) get_transient( self::rate_key( $kind, $conversation_id ) ), static function ( $t ) { return (int) $t > time() - 3600; } ) );
	}

	private static function stamp( string $kind, int $conversation_id ): void {
		$recent   = self::recent( $kind, $conversation_id );
		$recent[] = time();
		set_transient( self::rate_key( $kind, $conversation_id ), array_slice( $recent, -30 ), 3700 );
	}

	/* ── plumbing ──────────────────────────────────────────────────────── */

	private static function schedule( int $when, string $job_id ): bool {
		if ( is_callable( self::$scheduler ) ) {
			return false !== call_user_func( self::$scheduler, $when, self::CRON_HOOK, array( $job_id ) );
		}
		if ( self::isolated() ) {
			return true;
		}
		return true === wp_schedule_single_event( $when, self::CRON_HOOK, array( $job_id ) );
	}

	private static function emit( string $type, array $payload ): void {
		if ( is_callable( self::$event_observer ) ) {
			call_user_func( self::$event_observer, $type, $payload );
		}
		if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			try {
				BizCity_Twin_Event_Bus::dispatch( $type, $payload );
			} catch ( \Throwable $e ) {
				// the bus must never break a job.
			}
		}
	}

	private static function err( string $code ): array {
		return array( 'ok' => false, 'content' => '', 'error' => $code );
	}
}
