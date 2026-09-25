<?php
/**
 * BizCity_KG_Channel_Progress_Notifier — 3-step channel capture progress replies.
 *
 * Step 1 ("Đã lưu, đang xử lý") is sent inline by each channel listener right
 * after `BizCity_KG_Channel_Notebook_Bridge::capture()` returns — no hook needed.
 *
 * This class covers the two async steps:
 *   Step 2 — chunk+embedding finished  → `bizcity_kg_source_embedded` hook
 *            (fired by BizCity_TwinChat_Sources_Service::ingest()).
 *   Step 3 — KG graph learning finished → `bizcity_kg_extraction_batch_done`
 *            hook (fired per-notebook by the triplet extractor sweep/cron).
 *            NOTE: an earlier roadmap draft assumed a `event='complete'`
 *            source-progress event row already existed — verified during this
 *            implementation that no such call site exists in the
 *            codebase. Step 3 therefore derives "done" directly from
 *            `wp_bizcity_kg_passages.extraction_status` for this source
 *            (total > 0 AND every row is done|skipped), which is the real,
 *            already-existing signal.
 *
 * Only fires for sources that carry `metadata.inbound{}` (i.e. were created
 * through the channel capture bridge) AND have not already been notified for
 * that step — idempotency flags (`progress_step2_sent`, `progress_step3_sent`)
 * are persisted on the source's own `metadata` JSON column (no schema change).
 *
 * [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.7 — multi-file batch capture.
 * `capture_batch()` stamps `metadata.batch_id`/`batch_total` on every item.
 * For sync-ingested items, listener still sends ONE combined "đã tải + đã
 * embedding" reply right after `capture_batch()` returns. For async batch
 * items (cron-dispatch), this notifier now emits ONE extra batch-level step-2
 * message when the FIRST embedded chunk lands, then waits for step-3 as usual.
 * Step 3 (KG learning) remains async (cron sweep): batch items wait until
 * every sibling reaches `progress_step3_ready`, then
 * `maybe_finalize_batch_step3()` sends exactly ONE grouped "đã học xong"
 * message for the whole batch.
 *
 * See docs/roadmaps/PHASE-0.46-CHANNEL-NOTEBOOK-BRIDGE.md §2.5.
 *
 * [2026-07-24 Johnny Chu] PHASE-0.46 W1/W5 PREP — initial implementation.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KGHub
 * @since      PHASE-0.46
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_KG_Channel_Progress_Notifier', false ) ) {
	return;
}

class BizCity_KG_Channel_Progress_Notifier {

	public static function bind(): void {
		add_action( 'bizcity_kg_source_embedded', array( __CLASS__, 'on_embedded' ), 10, 4 );
		add_action( 'bizcity_kg_extraction_batch_done', array( __CLASS__, 'on_batch_done' ), 20, 1 );
	}

	/**
	 * Step 2 — chunk+embedding finished for a source.
	 *
	 * @param int $source_id   webchat_sources.id (legacy id returned by ingest()).
	 * @param int $notebook_id
	 * @param int $user_id
	 * @param int $chunk_count
	 */
	public static function on_embedded( $source_id, $notebook_id, $user_id, $chunk_count ): void {
		$source_id = (int) $source_id;
		if ( $source_id <= 0 || ! class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
			return;
		}
		$db  = BizCity_TwinChat_Sources_Database::instance();
		$row = $db->get_source( $source_id );
		if ( ! $row ) {
			return;
		}
		$meta = self::decode_metadata( $row );
		if ( empty( $meta['inbound'] ) || ! empty( $meta['progress_step2_sent'] ) ) {
			return;
		}

		// [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.7 — batch items skip the
		// per-source step-2 message: `ingest()` is confirmed synchronous
		// (set_time_limit(0) — no cron hop), so by the time
		// `capture_batch()` returns, every item is ALREADY embedded. The
		// listener sends ONE combined "đã tải + đã embedding" reply right
		// after that call instead of one message per file here.
		if ( ! empty( $meta['batch_id'] ) ) {
			$meta['progress_step2_sent']    = true;
			$meta['progress_step2_sent_at'] = current_time( 'mysql', true );
			$db->update_source( $source_id, array( 'metadata' => wp_json_encode( $meta ) ) );
			// [2026-07-25 Johnny Chu] PHASE-0.46 W4.6 — async batch items now
			// notify once when the FIRST chunk embedding event lands (instead of
			// waiting silently until full KG extraction step-3 completes).
			self::maybe_notify_batch_first_embed( $db, (int) $notebook_id, $source_id, $chunk_count, $meta );
			return;
		}

		$chunk_count = (int) $chunk_count;
		// [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.5 — modality-aware step 2 copy
		// ("đã phiên âm"/"đã OCR" instead of a generic "embedding xong nội dung"
		// for every kind). Falls back to the original generic wording when
		// `kind` is absent (sources captured before this field existed).
		$text = sprintf(
			self::step2_template( (string) ( $meta['kind'] ?? '' ) ),
			max( 1, $chunk_count )
		);
		self::send_channel_reply( (array) $meta['inbound'], $text );

		$meta['progress_step2_sent']    = true;
		$meta['progress_step2_sent_at'] = current_time( 'mysql', true );
		$db->update_source( $source_id, array( 'metadata' => wp_json_encode( $meta ) ) );
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.6 — send exactly one batch-level
	 * "first embedded chunk" progress message per batch_id.
	 */
	private static function maybe_notify_batch_first_embed( $db, int $notebook_id, int $source_id, int $chunk_count, array $meta ): void {
		$batch_id = (string) ( $meta['batch_id'] ?? '' );
		if ( $batch_id === '' || $notebook_id <= 0 || ! method_exists( $db, 'table_sources' ) ) {
			return;
		}

		global $wpdb;
		$tbl  = $db->table_sources();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, metadata FROM {$tbl}
			  WHERE project_id = %s
			    AND metadata LIKE %s
			  ORDER BY id ASC",
			(string) $notebook_id,
			'%' . $wpdb->esc_like( '"batch_id":"' . $batch_id . '"' ) . '%'
		), ARRAY_A );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return;
		}

		$decoded_rows = array();
		$inbound      = array();
		$batch_total  = 0;
		foreach ( $rows as $r ) {
			$id = (int) ( $r['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$d = json_decode( (string) ( $r['metadata'] ?? '' ), true );
			$d = is_array( $d ) ? $d : array();
			if ( ! empty( $d['batch_first_embed_sent'] ) ) {
				return;
			}
			if ( empty( $inbound ) && ! empty( $d['inbound'] ) && is_array( $d['inbound'] ) ) {
				$inbound = $d['inbound'];
			}
			if ( $batch_total <= 0 && ! empty( $d['batch_total'] ) ) {
				$batch_total = (int) $d['batch_total'];
			}
			$decoded_rows[ $id ] = $d;
		}
		if ( empty( $decoded_rows ) ) {
			return;
		}
		if ( empty( $inbound ) && ! empty( $meta['inbound'] ) && is_array( $meta['inbound'] ) ) {
			$inbound = $meta['inbound'];
		}
		if ( empty( $inbound ) ) {
			return;
		}
		if ( $batch_total <= 0 ) {
			$batch_total = count( $decoded_rows );
		}

		$link = self::build_learning_share_link( $notebook_id, $source_id );
		$text = sprintf(
			'🧩 Đã embedding xong chunk đầu tiên (%d đoạn) cho lô %d mục. Twin GPT đang học tiếp...',
			max( 1, (int) $chunk_count ),
			max( 1, $batch_total )
		);
		if ( $link !== '' ) {
			$text .= "\n🔎 Theo dõi learning log: " . $link;
		}
		self::send_channel_reply( (array) $inbound, $text );

		foreach ( $decoded_rows as $id => $d ) {
			$d['batch_first_embed_sent']           = true;
			$d['batch_first_embed_sent_at']        = current_time( 'mysql', true );
			$d['batch_first_embed_source_id']      = $source_id;
			$d['batch_first_embed_source_chunks']  = max( 1, (int) $chunk_count );
			$db->update_source( (int) $id, array( 'metadata' => wp_json_encode( $d ) ) );
		}
	}

	/**
	 * Step 3 — per-notebook batch tick from the triplet extractor. Scans
	 * bridge-captured sources in THIS notebook only (cheap, scoped query)
	 * and notifies any that just reached full extraction_status=done|skipped.
	 *
	 * @param array $args { notebook_id:int, processed, total_triplets, errors, remaining, time_exceeded, elapsed_s }
	 */
	public static function on_batch_done( $args ): void {
		if ( ! is_array( $args ) ) {
			return;
		}
		$notebook_id = (int) ( $args['notebook_id'] ?? 0 );
		if ( $notebook_id <= 0 || ! class_exists( 'BizCity_TwinChat_Sources_Database' ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return;
		}

		global $wpdb;
		$db  = BizCity_TwinChat_Sources_Database::instance();
		$tbl = $db->table_sources();

		// Scoped to this notebook only — cheap even on busy multi-tenant sites.
		// PHP re-filters for "not yet step3-notified" below since expressing a
		// "metadata does NOT contain progress_step3_sent:true" condition as a
		// LIKE clause is fragile (JSON key ordering isn't guaranteed).
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, metadata FROM {$tbl}
			  WHERE project_id = %s
			    AND metadata LIKE %s
			  ORDER BY id DESC
			  LIMIT 25",
			(string) $notebook_id,
			'%' . $wpdb->esc_like( '"inbound"' ) . '%'
		), ARRAY_A );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return;
		}

		$kg_db          = BizCity_KG_Database::instance();
		$tbl_passages   = $kg_db->tbl_passages();

		foreach ( $rows as $row ) {
			$decoded = json_decode( (string) ( $row['metadata'] ?? '' ), true );
			$meta    = is_array( $decoded ) ? $decoded : array();
			if ( empty( $meta['inbound'] ) || ! empty( $meta['progress_step3_sent'] ) ) {
				continue;
			}
			$kg_source_id = (int) ( $meta['kg_source_id'] ?? 0 );
			if ( $kg_source_id <= 0 ) {
				continue; // captured before this field existed, or ingest didn't return kg_source_id — skip rather than guess.
			}

			$counts = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS total,
				        SUM( CASE WHEN extraction_status IN ('done','skipped') THEN 1 ELSE 0 END ) AS done_ct
				   FROM {$tbl_passages}
				  WHERE notebook_id = %d AND source_id = %d",
				$notebook_id,
				$kg_source_id
			), ARRAY_A );
			$total = (int) ( $counts['total'] ?? 0 );
			$done  = (int) ( $counts['done_ct'] ?? 0 );
			if ( $total <= 0 || $done < $total ) {
				continue; // still learning, or no passages yet — wait for a later tick.
			}

			// [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.7 — batch items wait for
			// EVERY sibling source (same batch_id) to reach this milestone, then
			// send exactly ONE grouped message instead of one per file.
			$batch_id = (string) ( $meta['batch_id'] ?? '' );
			if ( $batch_id !== '' ) {
				if ( empty( $meta['progress_step3_ready'] ) ) {
					$meta['progress_step3_ready']    = true;
					$meta['progress_step3_ready_at'] = current_time( 'mysql', true );
					$db->update_source( (int) $row['id'], array( 'metadata' => wp_json_encode( $meta ) ) );
				}
				self::maybe_finalize_batch_step3( $db, $tbl, $notebook_id, $batch_id );
				continue;
			}

			// [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.5 — modality-aware step 3 copy.
			self::send_channel_reply( (array) $meta['inbound'], self::step3_text( (string) ( $meta['kind'] ?? '' ) ) );

			$meta['progress_step3_sent']    = true;
			$meta['progress_step3_sent_at'] = current_time( 'mysql', true );
			$db->update_source( (int) $row['id'], array( 'metadata' => wp_json_encode( $meta ) ) );
		}
	}

	/**
	 * [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.5 — step 2 sprintf template per
	 * modality. Batch items never reach this (see on_embedded() early-return
	 * above), so this only ever renders for single, non-batch captures.
	 */
	private static function step2_template( string $kind ): string {
		switch ( $kind ) {
			case 'audio':
				return '🎤 Đã phiên âm & embedding xong nội dung ghi âm (%d đoạn). Đang dựng đồ thị tri thức...';
			case 'image':
				return '🖼️ Đã nhận diện & embedding xong nội dung ảnh (%d đoạn). Đang dựng đồ thị tri thức...';
			case 'file':
				return '📄 Đã trích xuất & embedding xong nội dung tài liệu (%d đoạn). Đang dựng đồ thị tri thức...';
			default:
				return '🧩 Đã embedding xong nội dung (%d đoạn). Đang dựng đồ thị tri thức...';
		}
	}

	/**
	 * [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.5 — step 3 text per modality
	 * (single/non-batch captures only — grouped batch messages stay generic
	 * since a batch can mix kinds).
	 */
	private static function step3_text( string $kind ): string {
		switch ( $kind ) {
			case 'audio':
				return '✅ Đã học xong đồ thị tri thức từ ghi âm này. Bạn có thể hỏi Twin GPT tóm tắt hoặc tạo văn bản từ ghi chú này.';
			case 'image':
				return '✅ Đã học xong đồ thị tri thức từ ảnh này. Bạn có thể hỏi Twin GPT tóm tắt hoặc tạo văn bản từ ghi chú này.';
			case 'file':
				return '✅ Đã học xong đồ thị tri thức từ tài liệu này. Bạn có thể hỏi Twin GPT tóm tắt hoặc tạo văn bản từ ghi chú này.';
			default:
				return '✅ Đã học xong đồ thị tri thức. Bạn có thể hỏi Twin GPT tóm tắt hoặc tạo văn bản từ ghi chú này.';
		}
	}

	/**
	 * Send exactly ONE "đã học xong" reply per batch, once every sibling
	 * source sharing `batch_id` has reached `progress_step3_ready`. Safe to
	 * call multiple times per tick (early-returns once already sent, or while
	 * any sibling is still learning).
	 */
	private static function maybe_finalize_batch_step3( $db, string $tbl, int $notebook_id, string $batch_id ): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, metadata FROM {$tbl}
			  WHERE project_id = %s
			    AND metadata LIKE %s
			  ORDER BY id ASC",
			(string) $notebook_id,
			'%' . $wpdb->esc_like( '"batch_id":"' . $batch_id . '"' ) . '%'
		), ARRAY_A );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return;
		}

		$decoded = array();
		$inbound = array();
		foreach ( $rows as $r ) {
			$d = json_decode( (string) ( $r['metadata'] ?? '' ), true );
			$d = is_array( $d ) ? $d : array();
			if ( ! empty( $d['progress_step3_sent'] ) ) {
				return; // an earlier tick already sent the grouped message for this batch.
			}
			if ( empty( $d['progress_step3_ready'] ) ) {
				return; // at least one sibling hasn't reached the milestone yet — wait.
			}
			if ( empty( $inbound ) && ! empty( $d['inbound'] ) ) {
				$inbound = (array) $d['inbound'];
			}
			$decoded[ (int) $r['id'] ] = $d;
		}
		if ( empty( $inbound ) || empty( $decoded ) ) {
			return;
		}

		self::send_channel_reply( $inbound, sprintf(
			'✅ Đã học xong đồ thị tri thức cho %d mục vừa gửi. Bạn có thể hỏi Twin GPT tóm tắt hoặc tạo văn bản từ ghi chú này.',
			count( $decoded )
		) );
		foreach ( $decoded as $id => $d ) {
			$d['progress_step3_sent']    = true;
			$d['progress_step3_sent_at'] = current_time( 'mysql', true );
			$db->update_source( $id, array( 'metadata' => wp_json_encode( $d ) ) );
		}
	}

	/**
	 * Best-effort reply dispatch using the `inbound{}` block persisted at
	 * capture time. Zalo Bot is dispatched directly (matches the exact send
	 * path the capture listener itself uses); other platforms fall back to
	 * the generic Gateway Sender when their chat_id is already prefixed.
	 */
	private static function send_channel_reply( array $inbound, string $text ): void {
		$platform = strtoupper( (string) ( $inbound['platform'] ?? '' ) );
		$chat_id  = (string) ( $inbound['chat_id'] ?? '' );
		if ( $chat_id === '' ) {
			return;
		}

		if ( $platform === 'ZALOBOT' && function_exists( 'bizcity_get_zalo_bot_api' ) ) {
			$bot_id = (int) ( $inbound['account_id'] ?? 0 );
			if ( $bot_id <= 0 ) {
				return;
			}
			$api = bizcity_get_zalo_bot_api( $bot_id );
			if ( $api ) {
				$api->send_message( $chat_id, $text );
			}
			return;
		}

		// Generic fallback for future channels once their chat_id carries the
		// standard adapter prefix (e.g. 'telegram_123_456') — best-effort only.
		if ( class_exists( 'BizCity_Gateway_Sender' ) ) {
			BizCity_Gateway_Sender::instance()->send( $chat_id, $text );
		}
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.6 — source-scoped public learning
	 * log URL for async progress notifications (best-effort).
	 */
	private static function build_learning_share_link( int $notebook_id, int $source_id ): string {
		if ( $notebook_id <= 0 || $source_id <= 0 || ! class_exists( 'BizCity_TwinChat_Learning_Share_Adapter' ) ) {
			return '';
		}
		$link = BizCity_TwinChat_Learning_Share_Adapter::instance()->create_link( $notebook_id, $source_id, array(
			'ttl_s' => 30 * DAY_IN_SECONDS,
		) );
		if ( is_wp_error( $link ) || ! is_array( $link ) ) {
			return '';
		}
		return (string) ( $link['url'] ?? '' );
	}

	private static function decode_metadata( array $row ): array {
		if ( empty( $row['metadata'] ) ) {
			return array();
		}
		$decoded = json_decode( (string) $row['metadata'], true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
