<?php
/**
 * Action Block: personal_save_finance
 *
 * Ghi giao dịch thu/chi vào bizcity_personal_finance_entries scoped theo user_id.
 *
 * Dùng trong automation workflow:
 *   trigger.zalo_inbound (keyword "chi:" / "thu:") → action.personal_save_finance → action.reply_zalo
 *
 * Input fields:
 *   - kind        : string  'expense' | 'income' (default = expense)
 *   - amount      : string  Số tiền (50k=50000, 1.5tr=1500000, hoặc số nguyên)
 *   - title       : string  Tiêu đề giao dịch
 *   - note        : string  Ghi chú (optional)
 *   - category_id : int     0 = auto-pick 'Khác' category
 *   - occurred_at : string  ISO / strtotime (default = now)
 *   - source      : string  zalo_bot | admin | automation
 *   - user_id     : int     0 = current_user_id()
 *
 * Output (ctx key = node_id):
 *   - entry_id    : int
 *   - kind        : string
 *   - amount      : int
 *   - title       : string
 *   - balance_today: string  (tổng ngày nếu tính được, else empty)
 *
 * Architecture: PHASE-HOME-ARCH §3 — action block only, no direct channel hook.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\BizCityPersonal\Blocks
 * @since      2026-06-24 (PHASE-HOME-ARCH v1.0)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Personal_Action_Save_Finance extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'action.personal_save_finance'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Personal — Ghi Thu/Chi',
			'short'    => 'personal_finance',
			'category' => 'personal',
			'color'    => '#16a34a',
			'icon'     => 'Wallet',
			'plugin'   => 'bizcity-personal',
			'defaults' => array(
				'label'       => 'Ghi giao dịch tài chính',
				'kind'        => 'expense',
				'amount'      => '{{trigger.text}}',
				'title'       => '{{trigger.text}}',
				'note'        => '',
				'category_id' => 0,
				'occurred_at' => '',
				'source'      => 'automation',
				'user_id'     => 0,
			),
			'fields'   => array(
				array( 'name' => 'label',       'label' => 'Tên hiển thị',          'type' => 'text' ),
				array( 'name' => 'kind',        'label' => 'Loại',                  'type' => 'select', 'options' => array( 'expense', 'income' ) ),
				array( 'name' => 'amount',      'label' => 'Số tiền (50k/1.5tr/raw)', 'type' => 'text' ),
				array( 'name' => 'title',       'label' => 'Tiêu đề',              'type' => 'text' ),
				array( 'name' => 'note',        'label' => 'Ghi chú',              'type' => 'textarea' ),
				array( 'name' => 'category_id', 'label' => 'Category ID (0=auto)', 'type' => 'number' ),
				array( 'name' => 'occurred_at', 'label' => 'Thời gian (rỗng=now)', 'type' => 'text' ),
				array( 'name' => 'user_id',     'label' => 'User ID (0=current)',  'type' => 'number' ),
				array( 'name' => 'source',      'label' => 'Nguồn',               'type' => 'text' ),
			),
		);
	}

	/**
	 * @param array $ctx
	 * @param array $data
	 * @return array|WP_Error
	 */
	public function execute( array $ctx, array $data ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME-ARCH — save finance entry; no direct channel hook.

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_personal_finance_entries';

		// Table existence check (R-SHOW-TABLES compliant)
		if ( ! $this->_table_exists( $table ) ) {
			return new WP_Error( 'personal_finance_no_table', 'Bảng finance_entries chưa tạo. Kích hoạt lại plugin.' );
		}

		// Resolve user_id
		$user_id = (int) ( $data['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			$user_id = (int) ( $ctx['trigger']['user_id'] ?? 0 );
		}
		if ( $user_id <= 0 ) {
			$user_id = (int) get_current_user_id();
		}

		$kind         = in_array( (string) ( $data['kind'] ?? 'expense' ), array( 'expense', 'income' ), true )
			? (string) $data['kind'] : 'expense';
		$raw_amount   = (string) $this->resolve( $data['amount'] ?? '', $ctx );
		$amount       = $this->_parse_vnd( $raw_amount );
		$title        = sanitize_text_field( (string) $this->resolve( $data['title'] ?? '', $ctx ) );
		$note         = sanitize_textarea_field( (string) $this->resolve( $data['note'] ?? '', $ctx ) );
		$category_id  = (int) ( $data['category_id'] ?? 0 );
		$source       = sanitize_key( (string) $this->resolve( $data['source'] ?? 'automation', $ctx ) );

		$occurred_raw = (string) $this->resolve( $data['occurred_at'] ?? '', $ctx );
		$occurred_ts  = $occurred_raw !== '' ? strtotime( $occurred_raw ) : time();
		if ( ! $occurred_ts ) {
			$occurred_ts = time();
		}
		$occurred_at = date( 'Y-m-d H:i:s', $occurred_ts );

		if ( $amount <= 0 ) {
			return new WP_Error( 'personal_finance_invalid_amount', 'Số tiền không hợp lệ: ' . esc_html( $raw_amount ) );
		}

		if ( $title === '' ) {
			$title = ( $kind === 'expense' ? 'Chi ' : 'Thu ' ) . number_format( $amount ) . 'đ';
		}

		// Auto-pick category_id for 'Khác' if 0
		if ( $category_id <= 0 ) {
			$category_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bizcity_personal_finance_categories
				 WHERE user_id = %d AND kind = %s ORDER BY sort_order ASC LIMIT 1",
				$user_id, $kind
			) );
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'     => $user_id,
				'category_id' => $category_id,
				'kind'        => $kind,
				'amount_vnd'  => $amount,
				'title'       => $title,
				'note'        => $note,
				'occurred_at' => $occurred_at,
				'source'      => $source,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'personal_finance_insert_failed', 'Không ghi được giao dịch: ' . $wpdb->last_error );
		}

		$entry_id = (int) $wpdb->insert_id;

		// Flush finance cache (R-CACHE)
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( 'bzpersonal_finance_' . $user_id );
		}

		$this->debug( 'Saved finance entry_id=' . $entry_id . ' kind=' . $kind . ' amount=' . $amount );

		return array(
			'entry_id' => $entry_id,
			'kind'     => $kind,
			'amount'   => $amount,
			'title'    => $title,
			'occurred_at' => $occurred_at,
			'user_id'  => $user_id,
		);
	}

	/**
	 * Parse amount string: 50k → 50000, 1.5tr → 1500000, 200000 → 200000
	 *
	 * @param string $raw
	 * @return int
	 */
	private function _parse_vnd( string $raw ): int {
		$raw = trim( strtolower( str_replace( array( ',', ' ', 'đ', 'vnd' ), '', $raw ) ) );
		if ( substr( $raw, -2 ) === 'tr' ) {
			return (int) round( (float) substr( $raw, 0, -2 ) * 1000000 );
		}
		if ( substr( $raw, -1 ) === 'k' ) {
			return (int) round( (float) substr( $raw, 0, -1 ) * 1000 );
		}
		if ( substr( $raw, -1 ) === 'm' ) {
			return (int) round( (float) substr( $raw, 0, -1 ) * 1000000 );
		}
		return (int) preg_replace( '/[^0-9]/', '', $raw );
	}

	/**
	 * Table existence check — dual cache (R-SHOW-TABLES compliant).
	 *
	 * @param string $table_name Full table name (with prefix)
	 * @return bool
	 */
	private function _table_exists( string $table_name ): bool {
		static $s = array();
		if ( isset( $s[ $table_name ] ) ) {
			return $s[ $table_name ];
		}
		$ck      = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table_name );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			global $wpdb;
			$present = (int) (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$table_name
				)
			);
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		$s[ $table_name ] = (bool) $present;
		return $s[ $table_name ];
	}
}
