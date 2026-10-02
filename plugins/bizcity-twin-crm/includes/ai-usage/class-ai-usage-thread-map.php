<?php
/**
 * PHASE-0.85 §K2 — nối `(account_ref, thread_id)` của zalo-hub sang hội thoại
 * CRM đã có (cho `by_customer`/`turns` gắn `conversation_id` để bấm mở đúng
 * hội thoại). Chỉ ĐỌC, không tạo hội thoại mới.
 *
 * Dựa đúng kết luận K2.0 (2026-09-30, chỉ đọc):
 * - inbox của một số zalo_hub là `{prefix}bizcity_crm_inboxes` với
 *   `channel_type = 'zalo_personal'` và `channel_ref_id = <account_ref>`
 *   (`class-zalo-inbound-emitter.php:187` — "bridge account_id acts as inbox
 *   ref key", đây là ràng buộc UNIQUE `uniq_channel_ref` sẵn có, không cần tạo).
 * - `source_id` của `contact_inboxes`: 1-1 = CHÍNH `thread_id` (cell dùng uid
 *   Zalo của khách làm cả `thread_id` lẫn `from_user_id` cho luồng 1-1 -
 *   `class-zalo-inbound-emitter.php:194`); nhóm = `'group:' . thread_id`
 *   (`class-adapter-zalo-personal.php:39`). Cell (zalo-hub `/wp/brain/usage`)
 *   KHÔNG gắn tiền tố `group:` - K2 tự thử CẢ HAI dạng trong CÙNG một câu
 *   `IN(...)`, UNIQUE KEY `uniq_inbox_source (inbox_id, source_id)` đảm bảo
 *   nhiều nhất một trong hai khớp.
 * - Một `contact_inbox_id` có thể ra NHIỀU `conversations` (đóng lượt cũ, mở
 *   lượt mới) - chọn đúng MỘT: `ORDER BY (status='open') DESC, last_activity_at DESC`,
 *   lấy dòng ĐẦU TIÊN mỗi `source_id` (không GROUP BY - PHP tự chọn, tránh
 *   ONLY_FULL_GROUP_BY).
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.85 2026-09-30
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_AI_Usage_Thread_Map', false ) ) {
	return;
}

final class BizCity_CRM_AI_Usage_Thread_Map {

	/**
	 * @param string   $account_ref
	 * @param string[] $thread_ids
	 * @return array<string,array{conversation_id:int,contact_label:string}|null> khoá theo ĐÚNG `thread_id` đưa vào
	 */
	public static function map( string $account_ref, array $thread_ids ): array {
		$thread_ids = array_values( array_unique( array_filter( array_map( 'strval', $thread_ids ), static function ( $t ) { return '' !== $t; } ) ) );
		$out = array();
		foreach ( $thread_ids as $t ) { $out[ $t ] = null; }
		if ( '' === $account_ref || ! $thread_ids ) {
			return $out;
		}

		global $wpdb;
		$inbox_id = self::inbox_id_of( $account_ref );
		if ( null === $inbox_id ) {
			return $out; // số này chưa nối với inbox CRM nào - mọi thread trả null, không phải lỗi
		}

		// Mỗi thread có 2 ứng viên source_id (1-1 hoặc nhóm) - tra CẢ HAI trong MỘT câu IN(...).
		$candidates    = array();
		$candidate_of  = array(); // source_id => thread_id gốc
		foreach ( $thread_ids as $t ) {
			$candidates[]                = $t;
			$candidate_of[ $t ]          = $t;
			$grp                         = 'group:' . $t;
			$candidates[]                = $grp;
			$candidate_of[ $grp ]        = $t;
		}

		$ci    = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$conv  = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$ct    = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$place = implode( ', ', array_fill( 0, count( $candidates ), '%s' ) );
		$sql   = "SELECT ci.source_id, c.id AS conversation_id, c.status, c.last_activity_at, ctb.name AS contact_label
		          FROM `{$ci}` ci
		          LEFT JOIN `{$conv}` c ON c.contact_inbox_id = ci.id
		          LEFT JOIN `{$ct}` ctb ON ctb.id = ci.contact_id
		          WHERE ci.inbox_id = %d AND ci.source_id IN ({$place})
		          ORDER BY (c.status = 'open') DESC, c.last_activity_at DESC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $inbox_id ), $candidates ) ), ARRAY_A );

		$seen = array(); // thread_id đã có kết quả (dòng ĐẦU tiên/tốt nhất thắng, theo ORDER BY ở trên)
		foreach ( (array) $rows as $row ) {
			$thread_id = $candidate_of[ $row['source_id'] ] ?? null;
			if ( null === $thread_id || isset( $seen[ $thread_id ] ) || empty( $row['conversation_id'] ) ) {
				continue;
			}
			$seen[ $thread_id ] = true;
			$out[ $thread_id ]  = array(
				'conversation_id' => (int) $row['conversation_id'],
				'contact_label'   => ( '' !== (string) $row['contact_label'] ) ? (string) $row['contact_label'] : 'Khách chưa có tên',
			);
		}
		return $out;
	}

	private static function inbox_id_of( string $account_ref ): ?int {
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$id    = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `{$table}` WHERE channel_type = 'zalo_personal' AND channel_ref_id = %s LIMIT 1",
			$account_ref
		) );
		return ( null !== $id && '' !== $id ) ? (int) $id : null;
	}
}
