<?php
/**
 * Action Block: personal_save_journal
 *
 * Upsert nhật ký ngày (bizcity_personal_journal) scoped theo user_id.
 *
 * Dùng trong automation workflow:
 *   trigger.zalo_inbound (keyword "nhật ký:") → action.personal_save_journal → action.reply_zalo
 *   cron 21h → action.reply_zalo (hỏi) → user trả lời → action.personal_save_journal
 *
 * Input fields:
 *   - content     : string  Nội dung nhật ký (hỗ trợ {{token}})
 *   - mood        : string  happy|sad|neutral|excited|tired|grateful|anxious|peaceful (default = neutral)
 *   - entry_date  : string  YYYY-MM-DD (default = hôm nay)
 *   - user_id     : int     0 = current_user_id()
 *   - ingest_kg   : bool    1 = ingest vào KG Hub (default = 0)
 *
 * Output:
 *   - journal_id  : int
 *   - entry_date  : string
 *   - action      : 'created' | 'updated'
 *
 * Architecture: PHASE-HOME-ARCH §3 — action block only, no direct channel hook.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\BizCityPersonal\Blocks
 * @since      2026-06-24 (PHASE-HOME-ARCH v1.0)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Personal_Action_Save_Journal extends BizCity_Automation_Block_Base {

	const VALID_MOODS = array( 'happy', 'sad', 'neutral', 'excited', 'tired', 'grateful', 'anxious', 'peaceful' );

	public function id(): string   { return 'action.personal_save_journal'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Personal — Ghi Nhật Ký',
			'short'    => 'personal_journal',
			'category' => 'personal',
			'color'    => '#9333ea',
			'icon'     => 'BookOpen',
			'plugin'   => 'bizcity-personal',
			'defaults' => array(
				'label'      => 'Ghi nhật ký hôm nay',
				'content'    => '{{trigger.text}}',
				'mood'       => 'neutral',
				'entry_date' => '',
				'user_id'    => 0,
				'ingest_kg'  => 0,
			),
			'fields'   => array(
				array( 'name' => 'label',      'label' => 'Tên hiển thị',                       'type' => 'text' ),
				array( 'name' => 'content',    'label' => 'Nội dung nhật ký',                    'type' => 'textarea' ),
				array( 'name' => 'mood',       'label' => 'Trạng thái cảm xúc',                 'type' => 'select', 'options' => self::VALID_MOODS ),
				array( 'name' => 'entry_date', 'label' => 'Ngày (YYYY-MM-DD, rỗng=hôm nay)',   'type' => 'text' ),
				array( 'name' => 'user_id',    'label' => 'User ID (0=current)',                'type' => 'number' ),
				array( 'name' => 'ingest_kg',  'label' => 'Gửi vào KG Hub (0/1)',              'type' => 'number' ),
			),
		);
	}

	/**
	 * @param array $ctx
	 * @param array $data
	 * @return array|WP_Error
	 */
	public function execute( array $ctx, array $data ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME-ARCH — upsert journal; no direct channel hook.

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_personal_journal';

		if ( ! $this->_table_exists( $table ) ) {
			return new WP_Error( 'personal_journal_no_table', 'Bảng journal chưa tạo. Kích hoạt lại plugin.' );
		}

		// Resolve user_id
		$user_id = (int) ( $data['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			$user_id = (int) ( $ctx['trigger']['user_id'] ?? 0 );
		}
		if ( $user_id <= 0 ) {
			$user_id = (int) get_current_user_id();
		}

		$content    = sanitize_textarea_field( (string) $this->resolve( $data['content'] ?? '', $ctx ) );
		$mood_raw   = (string) $this->resolve( $data['mood'] ?? 'neutral', $ctx );
		$mood       = in_array( $mood_raw, self::VALID_MOODS, true ) ? $mood_raw : 'neutral';
		$ingest_kg  = (bool) ( $data['ingest_kg'] ?? false );

		$entry_date_raw = (string) $this->resolve( $data['entry_date'] ?? '', $ctx );
		if ( $entry_date_raw !== '' ) {
			$ts = strtotime( $entry_date_raw );
			$entry_date = $ts ? date( 'Y-m-d', $ts ) : date( 'Y-m-d' );
		} else {
			$entry_date = date( 'Y-m-d' );
		}

		if ( $content === '' ) {
			return new WP_Error( 'personal_journal_empty', 'Nội dung nhật ký không được rỗng.' );
		}

		$now    = current_time( 'mysql' );
		$action = 'created';

		// Check existing entry for this user+date
		$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE user_id = %d AND entry_date = %s LIMIT 1",
			$user_id, $entry_date
		) );

		if ( $existing_id > 0 ) {
			// Update
			$wpdb->update(
				$table,
				array( 'content' => $content, 'mood' => $mood, 'updated_at' => $now ),
				array( 'id' => $existing_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			$journal_id = $existing_id;
			$action     = 'updated';
		} else {
			// Insert
			$wpdb->insert(
				$table,
				array(
					'user_id'    => $user_id,
					'entry_date' => $entry_date,
					'content'    => $content,
					'mood'       => $mood,
					'created_at' => $now,
					'updated_at' => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s' )
			);
			$journal_id = (int) $wpdb->insert_id;
		}

		if ( ! $journal_id ) {
			return new WP_Error( 'personal_journal_failed', 'Không ghi được nhật ký: ' . $wpdb->last_error );
		}

		// Flush cache (R-CACHE)
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( 'bzpersonal_journal_' . $user_id );
		}

		// Optional KG ingest (fail-OPEN per R-GW-8)
		$kg_passage_id = 0;
		if ( $ingest_kg && class_exists( 'BizCity_KG' ) ) {
			try {
				$kg_result = BizCity_KG::upsert_passage( array(
					'source_id'  => 0,
					'content'    => '[Journal ' . $entry_date . '] ' . $content,
					'meta'       => array( 'journal_id' => $journal_id, 'user_id' => $user_id, 'mood' => $mood ),
					'created_by' => $user_id,
				) );
				if ( ! is_wp_error( $kg_result ) && isset( $kg_result['passage_id'] ) ) {
					$kg_passage_id = (int) $kg_result['passage_id'];
					$wpdb->update( $table, array( 'kg_passage_id' => $kg_passage_id ), array( 'id' => $journal_id ), array( '%d' ), array( '%d' ) );
				}
			} catch ( Exception $e ) {
				$this->debug( 'KG ingest failed (non-fatal): ' . $e->getMessage() );
			}
		}

		$this->debug( 'Saved journal_id=' . $journal_id . ' date=' . $entry_date . ' action=' . $action );

		return array(
			'journal_id'    => $journal_id,
			'entry_date'    => $entry_date,
			'action'        => $action,
			'mood'          => $mood,
			'kg_passage_id' => $kg_passage_id,
			'user_id'       => $user_id,
		);
	}

	/**
	 * Table existence check — dual cache (R-SHOW-TABLES compliant).
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
