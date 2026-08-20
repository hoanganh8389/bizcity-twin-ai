<?php
/**
 * Action Block: personal_save_note
 *
 * Lưu ghi chú vào notebook (bizcity_personal_notebook_pages) scoped theo user_id.
 * File .md được ghi vào uploads/bizcity-personal/{blog_id}/notebooks/
 *
 * Dùng trong automation workflow:
 *   trigger.zalo_inbound (keyword "note:", "ghi:") → action.personal_save_note → action.reply_zalo
 *   Telegram bot → action.personal_save_note (notebook_id=auto) → action.reply_telegram
 *
 * Input fields:
 *   - content      : string  Nội dung ghi chú (hỗ trợ {{trigger.text}})
 *   - title        : string  Tiêu đề (mặc định = 60 ký tự đầu nội dung)
 *   - notebook_id  : int     0 = tìm/tạo notebook mặc định của user
 *   - tags         : string  Comma-separated tags (vd: "meeting, project-x")
 *   - mood         : string  happy|focused|neutral|... (default = '')
 *   - ingest_kg    : bool    1 = ingest vào KG Hub (default = 0)
 *   - user_id      : int     0 = current_user_id()
 *
 * Output:
 *   - page_id      : int
 *   - notebook_id  : int
 *   - file_path    : string  Relative path to .md file
 *   - action       : 'created'
 *
 * Architecture: PHASE-HOME-NOTEBOOKS §3 — action block only, no direct channel hook.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\BizCityPersonal\Blocks
 * @since      2026-06-24 (PHASE-HOME-NOTEBOOKS v1.0)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Personal_Action_Save_Note extends BizCity_Automation_Block_Base {

	// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS — automation block to save notes

	public function id()   { return 'action.personal_save_note'; }
	public function kind() { return 'action'; }

	public function meta() {
		return array(
			'label'    => 'Personal — Lưu Ghi Chú',
			'short'    => 'personal_note',
			'category' => 'personal',
			'color'    => '#3b82f6',
			'icon'     => 'StickyNote',
			'plugin'   => 'bizcity-personal',
			'defaults' => array(
				'label'       => 'Lưu ghi chú',
				'content'     => '{{trigger.text}}',
				'title'       => '',
				'notebook_id' => 0,
				'tags'        => '',
				'mood'        => '',
				'ingest_kg'   => 0,
				'user_id'     => 0,
			),
			'fields' => array(
				array( 'name' => 'label',       'label' => 'Tên hiển thị',                        'type' => 'text' ),
				array( 'name' => 'content',     'label' => 'Nội dung ghi chú',                    'type' => 'textarea' ),
				array( 'name' => 'title',       'label' => 'Tiêu đề (rỗng=tự động)',              'type' => 'text' ),
				array( 'name' => 'notebook_id', 'label' => 'Notebook ID (0=mặc định)',            'type' => 'number' ),
				array( 'name' => 'tags',        'label' => 'Tags (comma-separated)',              'type' => 'text' ),
				array( 'name' => 'mood',        'label' => 'Mood (happy/focused/neutral/...)',    'type' => 'text' ),
				array( 'name' => 'ingest_kg',   'label' => 'Gửi vào KG Hub (0/1)',               'type' => 'number' ),
				array( 'name' => 'user_id',     'label' => 'User ID (0=current)',                 'type' => 'number' ),
			),
		);
	}

	/**
	 * @param array $ctx
	 * @param array $data
	 * @return array|WP_Error
	 */
	public function execute( array $ctx, array $data ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS — save note to notebook page

		if ( ! class_exists( 'BizCity_Personal_Notebook_File_Store' ) ) {
			return new WP_Error( 'personal_note_no_class', 'BizCity_Personal_Notebook_File_Store chưa load.' );
		}

		global $wpdb;
		$pt = $wpdb->prefix . 'bizcity_personal_notebook_pages';
		$nt = $wpdb->prefix . 'bizcity_personal_notebooks';

		if ( ! $this->_table_exists( $pt ) || ! $this->_table_exists( $nt ) ) {
			return new WP_Error( 'personal_note_no_table', 'Bảng notebooks chưa tạo. Kích hoạt lại plugin.' );
		}

		// ── Resolve user_id ──────────────────────────────────────────────────
		$user_id = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
		if ( ! $user_id ) { $user_id = (int) get_current_user_id(); }
		if ( ! $user_id ) {
			return new WP_Error( 'personal_note_no_user', 'Không xác định được user_id.' );
		}

		// ── Resolve content ──────────────────────────────────────────────────
		$content = $this->render( $ctx, isset( $data['content'] ) ? (string) $data['content'] : '{{trigger.text}}' );
		$content = trim( $content );
		if ( ! $content ) {
			return new WP_Error( 'personal_note_empty', 'Nội dung ghi chú không được rỗng.' );
		}

		// ── Resolve title ────────────────────────────────────────────────────
		$raw_title = $this->render( $ctx, isset( $data['title'] ) ? (string) $data['title'] : '' );
		$raw_title = trim( $raw_title );
		if ( ! $raw_title ) {
			// Auto-generate title from first 60 chars of content
			$plain     = preg_replace( '/^#{1,6}\s*/m', '', $content );
			$plain     = trim( preg_replace( '/\s+/', ' ', $plain ) );
			$raw_title = mb_substr( $plain, 0, 60 ) ?: 'Ghi chú mới';
		}
		$title = sanitize_text_field( $raw_title );

		// ── Resolve notebook_id ──────────────────────────────────────────────
		$notebook_id = isset( $data['notebook_id'] ) ? (int) $data['notebook_id'] : 0;
		if ( ! $notebook_id ) {
			// Find or create default notebook for user
			$notebook_id = $this->get_or_create_default_notebook( $user_id );
		} else {
			// Verify notebook ownership
			$nb_check = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$nt} WHERE id = %d AND user_id = %d",
				$notebook_id, $user_id
			) );
			if ( ! $nb_check ) {
				$notebook_id = $this->get_or_create_default_notebook( $user_id );
			}
		}
		if ( ! $notebook_id ) {
			return new WP_Error( 'personal_note_no_notebook', 'Không tìm thấy notebook.' );
		}

		// ── Tags, mood, ingest ───────────────────────────────────────────────
		$raw_tags = isset( $data['tags'] ) ? (string) $data['tags'] : '';
		$tags     = $raw_tags ? array_values( array_filter( array_map( 'sanitize_text_field', explode( ',', $raw_tags ) ) ) ) : array();
		$mood     = sanitize_text_field( isset( $data['mood'] ) ? (string) $data['mood'] : '' );
		$ingest   = ! empty( $data['ingest_kg'] );

		// ── Insert DB row ────────────────────────────────────────────────────
		$excerpt    = BizCity_Personal_Notebook_File_Store::make_excerpt( $content );
		$word_count = BizCity_Personal_Notebook_File_Store::word_count( $content );

		$row = array(
			'notebook_id'  => $notebook_id,
			'user_id'      => $user_id,
			'title'        => $title,
			'content'      => $content,
			'excerpt'      => $excerpt,
			'tags'         => wp_json_encode( $tags ),
			'mood'         => $mood,
			'word_count'   => $word_count,
			'kg_source_id' => null,
			'file_path'    => '',
		);
		$wpdb->insert( $pt, $row );
		$page_id = (int) $wpdb->insert_id;
		if ( ! $page_id ) {
			return new WP_Error( 'personal_note_db', 'Không thể lưu ghi chú vào DB.' );
		}

		// ── Write .md file ───────────────────────────────────────────────────
		$file_path = BizCity_Personal_Notebook_File_Store::write_page(
			$page_id, $title, $content,
			array( 'notebook_id' => $notebook_id, 'user_id' => $user_id, 'tags' => $tags, 'mood' => $mood )
		);
		if ( $file_path ) {
			$wpdb->update( $pt, array( 'file_path' => $file_path ), array( 'id' => $page_id ) );
		}

		// ── Update page_count ────────────────────────────────────────────────
		$cnt = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$pt} WHERE notebook_id = %d AND user_id = %d",
			$notebook_id, $user_id
		) );
		$wpdb->update( $nt, array( 'page_count' => $cnt ), array( 'id' => $notebook_id ) );

		// ── Optional KG ingest ───────────────────────────────────────────────
		$kg_id = 0;
		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — use personal KG service directly (scope_type='personal_notebook')
		if ( $ingest && class_exists( 'BizCity_Personal_KG_Service' ) ) {
			try {
				$result = BizCity_Personal_KG_Service::instance()->ingest(
					$notebook_id,
					$user_id,
					array(
						'type'        => 'text',
						'title'       => $final_title,
						'content'     => $content,
						'source_meta' => array( 'page_id' => $page_id, 'notebook_id' => $notebook_id ),
					)
				);
				if ( ! is_wp_error( $result ) ) {
					$kg_id = (int) ( isset( $result['source_id'] ) ? $result['source_id'] : 0 );
					if ( $kg_id ) {
						$wpdb->update( $pt, array( 'kg_source_id' => $kg_id ), array( 'id' => $page_id ) );
					}
				}
			} catch ( Exception $e ) {
				error_log( '[bizcity-personal] Notebook block KG ingest error: ' . $e->getMessage() );
				// Fail-OPEN: continue without KG
			}
		}

		return array(
			'page_id'     => $page_id,
			'notebook_id' => $notebook_id,
			'file_path'   => $file_path ?: '',
			'kg_source_id' => $kg_id ?: null,
			'action'      => 'created',
		);
	}

	/**
	 * Find or create the default notebook for a user.
	 *
	 * @param int $user_id
	 * @return int  notebook_id or 0 on failure
	 */
	private function get_or_create_default_notebook( $user_id ) {
		global $wpdb;
		$nt = $wpdb->prefix . 'bizcity_personal_notebooks';

		// 1. Look for existing default
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$nt} WHERE user_id = %d AND is_default = 1 LIMIT 1",
			$user_id
		) );
		if ( $id ) { return $id; }

		// 2. Look for first notebook
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$nt} WHERE user_id = %d ORDER BY sort_order ASC, created_at ASC LIMIT 1",
			$user_id
		) );
		if ( $id ) { return $id; }

		// 3. Create default notebook
		$wpdb->insert( $nt, array(
			'user_id'    => $user_id,
			'title'      => 'Ghi chú',
			'description' => 'Notebook mặc định — tạo tự động bởi automation',
			'icon'       => '📓',
			'color'      => '#6366f1',
			'is_default' => 1,
			'page_count' => 0,
			'sort_order' => 0,
		) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Dual-cache table existence check (R-SHOW-TABLES compliant).
	 * Inherited from BizCity_Automation_Block_Base — only needed as fallback.
	 *
	 * @param string $table_name Full table name with prefix
	 * @return bool
	 */
	private function _table_exists( $table_name ) {
		static $s = array();
		if ( isset( $s[ $table_name ] ) ) { return $s[ $table_name ]; }
		$ck      = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table_name );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			global $wpdb;
			$present = (int) (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table_name
			) );
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		$s[ $table_name ] = (bool) $present;
		return $s[ $table_name ];
	}
}
