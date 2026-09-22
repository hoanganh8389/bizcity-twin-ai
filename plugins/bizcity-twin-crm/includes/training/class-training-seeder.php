<?php
/**
 * BizCity CRM — Training Workspace Seeder (PHASE-0.57 T2/T3/T4).
 *
 * Ships a built-in "Hướng dẫn dùng Twin CRM" training notebook so a freshly
 * installed site has usable Brain Chat content instead of an empty KG.
 *
 * Flow: template JSON → training CPT posts (admin-editable) → KG notebook
 * source (BizCity_TwinChat_Sources_Service::ingest) → learning queue builds
 * the KG automatically via the existing `bizcity_twinchat_after_ingest` hook.
 *
 * Design doc: plugins/bizcity-twin-crm/docs/PHASE-0.57-TWINCHAT-TRAINING-WORKSPACE-SEED-TEMPLATE-UI-FIRST.md
 *
 * Known wave-1 simplification (documented, not a defect): the notebook is
 * created as an admin-owned `business_kb` notebook. It is NOT made readable
 * by every member of the site yet — that requires the workspace public
 * visibility API from PHASE-0.57A (still doc-first). Until 0.57A ships, the
 * notebook is manageable in `/twinchat/` by its owning admin like any other
 * business_kb notebook, and Brain Chat at `/gpt/` will only surface it once
 * 0.57A's readable_notebooks_where() treats it as public.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Training_Seeder', false ) ) {
	return;
}

final class BizCity_CRM_Training_Seeder {

	const TEMPLATE_ID      = 'twin-crm-training';
	const OPTION_ENABLED   = 'bzcrm_training_seed_enabled';
	const OPTION_STATE     = 'bzcrm_training_seed';
	const OPTION_SOURCE_MAP = 'bzcrm_training_source_map';
	const HOOK_RUN         = 'bzcrm_training_seed_run';
	const HOOK_KG_CHECK    = 'bzcrm_training_kg_check';
	const LOCK_TRANSIENT   = 'bzcrm_training_seed_lock';
	const KG_CHECK_DELAY_S = 600; // 10 phút — dự phòng nếu learning queue chưa chạy (§3.5).

	const DOC_MAX_CHARS = 8000;

	private static $template_cache = null;

	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_retry_click' ), 5 );
		add_action( 'admin_notices', array( __CLASS__, 'render_failed_notice' ) );

		add_action( self::HOOK_RUN, array( __CLASS__, 'run_seed' ), 10, 2 );
		add_action( self::HOOK_KG_CHECK, array( __CLASS__, 'check_kg_build' ), 10, 1 );

		// P4 — admin xoá notebook đào tạo thì không tự tạo lại.
		add_action( 'bizcity_kg_notebook_before_delete', array( __CLASS__, 'on_notebook_before_delete' ), 10, 1 );

		// T2-02 — đồng bộ khi admin lưu/xoá bài trong CPT.
		if ( class_exists( 'BizCity_CRM_Training_CPT' ) ) {
			add_action( 'save_post_' . BizCity_CRM_Training_CPT::POST_TYPE, array( __CLASS__, 'on_save_post' ), 20, 2 );
			add_action( 'trashed_post', array( __CLASS__, 'on_trashed_post' ), 10, 1 );
			add_action( 'before_delete_post', array( __CLASS__, 'on_trashed_post' ), 10, 1 );
		}
	}

	/* ───────────────────────── Trigger boundary (admin_init) ───────────────────────── */

	/**
	 * D57-4/D57-5/P3/P4 — cheap option-only checks first; only enqueues work,
	 * never ingests synchronously from admin_init.
	 */
	public static function maybe_seed(): void {
		// [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0.57 T3-01 — R-CLI-ASYNC-ISOLATION enqueue boundary guard.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return;
		}
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( ! get_option( self::OPTION_ENABLED, true ) ) { // D57-5, mặc định bật
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return; // cần một admin_id thật để chạy ingest dưới danh nghĩa (R-CH-IDMEM tiền lệ wp_set_current_user).
		}

		$state  = self::get_state();
		$status = (string) ( $state['status'] ?? '' );

		if ( 'deleted_by_admin' === $status ) { // P4
			return;
		}
		if ( in_array( $status, array( 'pending', 'building' ), true ) ) {
			return; // đã xếp hàng / đang chạy
		}
		if ( 'ready' === $status && ( $state['version'] ?? '' ) === self::template_version() ) {
			return; // đã đúng phiên bản, không có gì để làm
		}
		if ( 'failed' === $status && time() < (int) ( $state['next_retry_at'] ?? 0 ) ) {
			return; // trong thời gian backoff
		}

		self::enqueue_seed_run( get_current_user_id(), false );
	}

	/**
	 * Schedule boundary. Debounced by a short transient lock so concurrent
	 * admin_init hits (multiple tabs/requests) only queue one run.
	 */
	public static function enqueue_seed_run( int $admin_id, bool $force ): void {
		// [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0.57 T3-02 — R-CLI-ASYNC-ISOLATION schedule boundary guard.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return;
		}
		if ( ! $force && get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::LOCK_TRANSIENT, 1, force ? 30 : 300 );

		self::set_state( array( 'status' => 'pending' ) );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_RUN, array( $admin_id, $force ), 'bizcity-crm-training' );
		} else {
			wp_schedule_single_event( time() + 5, self::HOOK_RUN, array( $admin_id, $force ) );
		}
	}

	/* ───────────────────────── Worker (dispatcher + entry) ───────────────────────── */

	/**
	 * @param int  $admin_id Người kích hoạt seed (chạy dưới danh nghĩa admin này, §3.3).
	 * @param bool $force    true khi bấm "Khôi phục / Cập nhật" (D57-6) — bỏ qua khoá 'deleted_by_admin'.
	 */
	public static function run_seed( int $admin_id, bool $force = false ): array {
		// [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0.57 T3-03 — R-CLI-ASYNC-ISOLATION worker-entry guard (dispatcher + direct call both land here).
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return array( 'ok' => false, 'error' => 'diagnostics_cli_isolated' );
		}
		delete_transient( self::LOCK_TRANSIENT );

		$state = self::get_state();
		if ( ! $force && 'deleted_by_admin' === (string) ( $state['status'] ?? '' ) ) { // P4
			return array( 'ok' => false, 'error' => 'deleted_by_admin' );
		}

		self::set_state( array( 'status' => 'building', 'admin_user_id' => $admin_id ) );

		@set_time_limit( 0 );
		@ignore_user_abort( true );

		$prev_user = get_current_user_id();
		if ( $admin_id > 0 ) {
			wp_set_current_user( $admin_id );
		}

		try {
			if ( $admin_id <= 0 || ! user_can( $admin_id, 'manage_options' ) ) {
				throw new RuntimeException( 'invalid_admin' );
			}

			$template = self::load_template();
			if ( is_wp_error( $template ) ) {
				throw new RuntimeException( $template->get_error_message() );
			}

			$post_result = self::sync_template_to_posts( $template['documents'], $force );

			$notebook_id = self::ensure_notebook( $template['notebook'], $admin_id );
			if ( is_wp_error( $notebook_id ) ) {
				throw new RuntimeException( $notebook_id->get_error_message() );
			}

			$source_result = self::sync_notebook_sources( $notebook_id, $admin_id, $template['documents'] );
			// [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0.57 T4-05 — publish a notebook perspective embedding so the shared MPR selector can rank the built-in training layer.
			$embedding_result = self::ensure_perspective_embedding( $notebook_id, $admin_id, $template );
			$workspace_result = class_exists( 'BizCity_KG_Access' )
				? BizCity_KG_Access::ensure_workspace_for_notebook( $notebook_id, $admin_id, 'Đào tạo Twin CRM', BizCity_KG_Access::VISIBILITY_PUBLIC )
				: 0;

			self::set_state( array(
				'status'        => empty( $source_result['errors'] ) ? 'ready' : 'partial',
				'version'       => self::template_version(),
				'notebook_id'   => $notebook_id,
				'admin_user_id' => $admin_id,
				'error'         => empty( $source_result['errors'] ) ? '' : implode( '; ', $source_result['errors'] ),
				'retry_count'   => 0,
				'kg_status'     => 'pending',
				'embedding_status' => (string) ( $embedding_result['status'] ?? 'skipped' ),
				'embedding_error'  => (string) ( $embedding_result['error'] ?? '' ),
				'workspace_id'    => is_wp_error( $workspace_result ) ? 0 : (int) $workspace_result,
				'visibility'      => is_wp_error( $workspace_result ) ? 'private' : 'public',
			) );

			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + self::KG_CHECK_DELAY_S, self::HOOK_KG_CHECK, array( $notebook_id ), 'bizcity-crm-training' );
			} else {
				wp_schedule_single_event( time() + self::KG_CHECK_DELAY_S, self::HOOK_KG_CHECK, array( $notebook_id ) );
			}

			$result = array( 'ok' => true, 'notebook_id' => $notebook_id, 'posts' => $post_result, 'sources' => $source_result );
		} catch ( \Throwable $e ) {
			$retry_count = (int) ( $state['retry_count'] ?? 0 );
			self::set_state( array(
				'status'         => 'failed',
				'error'          => $e->getMessage(),
				'retry_count'    => $retry_count + 1,
				'next_retry_at'  => time() + self::backoff_seconds( $retry_count ),
			) );
			$result = array( 'ok' => false, 'error' => $e->getMessage() );
		}

		if ( $admin_id > 0 ) {
			wp_set_current_user( $prev_user );
		}

		return $result;
	}

	private static function backoff_seconds( int $retry_count ): int {
		if ( $retry_count <= 0 ) { return HOUR_IN_SECONDS; }
		if ( 1 === $retry_count ) { return 6 * HOUR_IN_SECONDS; }
		return DAY_IN_SECONDS;
	}

	/**
	 * §3.5 dự phòng — nếu sau KG_CHECK_DELAY_S phút notebook vẫn chưa có
	 * entity/relation nào (nghĩa là learning queue chưa chạy được), tự gọi
	 * extract_notebook_pending() dưới danh nghĩa admin đã lưu trong state.
	 */
	public static function check_kg_build( int $notebook_id ): void {
		// [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0.57 T4-03 — R-CLI-ASYNC-ISOLATION worker-entry guard.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return;
		}
		if ( $notebook_id <= 0 || ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return;
		}
		$state = self::get_state();
		if ( (int) ( $state['notebook_id'] ?? 0 ) !== $notebook_id ) {
			return; // notebook state đã đổi (bị xoá / seed lại) từ khi lịch chạy được đặt.
		}

		$stats = BizCity_KG_Notebook_Service::instance()->compute_stats( $notebook_id );
		$has_kg = ( (int) ( $stats['entities'] ?? 0 ) > 0 ) || ( (int) ( $stats['pending_triplets'] ?? 0 ) > 0 );

		if ( ! $has_kg && class_exists( 'BizCity_KG_Triplet_Extractor' ) ) {
			$admin_id  = (int) ( $state['admin_user_id'] ?? 0 );
			$prev_user = get_current_user_id();
			if ( $admin_id > 0 ) { wp_set_current_user( $admin_id ); }
			BizCity_KG_Triplet_Extractor::instance()->extract_notebook_pending( $notebook_id, 50, true );
			if ( $admin_id > 0 ) { wp_set_current_user( $prev_user ); }
			$stats = BizCity_KG_Notebook_Service::instance()->compute_stats( $notebook_id );
		}

		self::set_state( array(
			'kg_status'  => ( (int) ( $stats['entities'] ?? 0 ) > 0 ) ? 'ready' : 'pending',
			'kg_entities' => (int) ( $stats['entities'] ?? 0 ),
			'kg_relations' => (int) ( $stats['relations'] ?? 0 ),
		) );
	}

	/* ───────────────────────── T1: template loader + validator ───────────────────────── */

	private static function template_file(): string {
		return BIZCITY_CRM_DIR . '/data/training/twin-crm-training.json';
	}

	public static function template_version(): string {
		$tpl = self::load_template();
		return is_wp_error( $tpl ) ? '' : (string) ( $tpl['template_version'] ?? '' );
	}

	/**
	 * @return array{template_version:string,notebook:array,documents:array[]}|WP_Error
	 */
	public static function load_template() {
		if ( null !== self::$template_cache ) {
			return self::$template_cache;
		}
		$file = self::template_file();
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return new WP_Error( 'training_template_missing', 'Không đọc được file template đào tạo.' );
		}
		$raw = file_get_contents( $file );
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) || empty( $data['documents'] ) || ! is_array( $data['documents'] ) ) {
			return new WP_Error( 'training_template_invalid', 'File template đào tạo không hợp lệ (JSON lỗi hoặc thiếu documents).' );
		}

		$seen_ids = array();
		foreach ( $data['documents'] as $doc ) {
			$doc_id = sanitize_key( (string) ( $doc['doc_id'] ?? '' ) );
			if ( '' === $doc_id ) {
				return new WP_Error( 'training_template_invalid', 'Một tài liệu trong template thiếu doc_id.' );
			}
			if ( isset( $seen_ids[ $doc_id ] ) ) {
				return new WP_Error( 'training_template_invalid', 'doc_id trùng lặp trong template: ' . $doc_id );
			}
			$seen_ids[ $doc_id ] = true;
			$len = mb_strlen( (string) ( $doc['content_md'] ?? '' ) );
			if ( $len > self::DOC_MAX_CHARS ) {
				return new WP_Error( 'training_template_invalid', 'Tài liệu "' . $doc_id . '" vượt quá ' . self::DOC_MAX_CHARS . ' ký tự.' );
			}
		}

		self::$template_cache = array(
			'template_version' => (string) ( $data['template_version'] ?? '1.0.0' ),
			'notebook'          => is_array( $data['notebook'] ?? null ) ? $data['notebook'] : array(),
			'documents'         => $data['documents'],
		);
		return self::$template_cache;
	}

	/* ───────────────────────── T1-03 / T2: JSON → CPT ───────────────────────── */

	/**
	 * Idempotent, non-destructive: creates missing posts, updates posts that
	 * still match the last-synced template hash, and SKIPS posts the admin
	 * has edited manually since (compares live content hash vs the hash we
	 * last wrote — §3.2 (a)).
	 */
	public static function sync_template_to_posts( array $documents, bool $force = false ): array {
		$created = 0; $updated = 0; $skipped_edited = 0; $unchanged = 0; $restored = 0;

		foreach ( $documents as $doc ) {
			$doc_id  = sanitize_key( (string) ( $doc['doc_id'] ?? '' ) );
			$title   = sanitize_text_field( (string) ( $doc['title'] ?? $doc_id ) );
			$content = (string) ( $doc['content_md'] ?? '' );
			if ( '' === $doc_id ) { continue; }

			$post = BizCity_CRM_Training_CPT::find_by_doc_id( $doc_id );

			if ( ! $post ) {
				$post_id = wp_insert_post( array(
					'post_type'    => BizCity_CRM_Training_CPT::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => $content,
				), true );
				if ( is_wp_error( $post_id ) ) { continue; }
				update_post_meta( $post_id, BizCity_CRM_Training_CPT::META_DOC_ID, $doc_id );
				update_post_meta( $post_id, BizCity_CRM_Training_CPT::META_TEMPLATE_HASH, hash( 'sha256', $content ) );
				$created++;
				continue;
			}

			if ( 'trash' === $post->post_status ) {
				if ( ! $force ) {
					// [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0.57 T3-07a — không đụng bài admin cố tình trash trong dùng bình thường.
					continue;
				}
				// [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0.57 T3-07a — "Khôi phục" (D57-6) phục hồi đúng bài mẫu đã bị xoá về nội dung template.
				wp_untrash_post( $post->ID );
				wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'publish', 'post_content' => $content, 'post_title' => $title ) );
				update_post_meta( $post->ID, BizCity_CRM_Training_CPT::META_TEMPLATE_HASH, hash( 'sha256', $content ) );
				$restored++;
				continue;
			}

			$live_hash    = hash( 'sha256', (string) $post->post_content );
			$stored_hash  = (string) get_post_meta( $post->ID, BizCity_CRM_Training_CPT::META_TEMPLATE_HASH, true );
			$new_hash     = hash( 'sha256', $content );

			if ( '' !== $stored_hash && $live_hash !== $stored_hash ) {
				$skipped_edited++; // admin đã sửa tay — không ghi đè (§3.2).
				continue;
			}
			if ( $new_hash === $live_hash ) {
				$unchanged++;
				continue;
			}
			wp_update_post( array( 'ID' => $post->ID, 'post_content' => $content, 'post_title' => $title ) );
			update_post_meta( $post->ID, BizCity_CRM_Training_CPT::META_TEMPLATE_HASH, $new_hash );
			$updated++;
		}

		return compact( 'created', 'updated', 'skipped_edited', 'unchanged', 'restored' );
	}

	/* ───────────────────────── T2-01/T4-01: notebook lookup/create ───────────────────────── */

	/**
	 * @return int|WP_Error notebook id
	 */
	public static function ensure_notebook( array $notebook_tpl, int $admin_id ) {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) || ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return new WP_Error( 'kg_not_loaded', 'KG Hub chưa được nạp.' );
		}
		$db  = BizCity_KG_Database::instance();
		$tbl = $db->tbl_notebooks();

		// P2 — tìm theo settings.template_id trước khi tạo mới.
		$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE settings LIKE %s ORDER BY id ASC LIMIT 1",
			'%' . $wpdb->esc_like( '"template_id":"' . self::TEMPLATE_ID . '"' ) . '%'
		) );
		if ( $existing_id > 0 ) {
			return $existing_id;
		}

		$data = array(
			'name'           => (string) ( $notebook_tpl['title'] ?? 'Hướng dẫn dùng Twin CRM' ),
			'description'    => (string) ( $notebook_tpl['description'] ?? '' ),
			'notebook_scope' => 'business_kb',
			'settings'       => array(
				'template_id'      => self::TEMPLATE_ID,
				'template_version' => self::template_version(),
				'workspace_id'     => 'training',
				'tags'             => is_array( $notebook_tpl['tags'] ?? null ) ? $notebook_tpl['tags'] : array( 'training' ),
			),
		);

		$notebook = BizCity_KG_Notebook_Service::instance()->create( $data, $admin_id );
		if ( is_wp_error( $notebook ) ) {
			return $notebook;
		}
		return (int) ( $notebook['id'] ?? 0 );
	}

	/* ───────────────────────── T2: notebook sources ↔ CPT sync ───────────────────────── */

	public static function sync_notebook_sources( int $notebook_id, int $admin_id, array $documents ): array {
		if ( $notebook_id <= 0 || ! class_exists( 'BizCity_TwinChat_Sources_Service' ) ) {
			return array( 'created' => 0, 'updated' => 0, 'removed' => 0, 'unchanged' => 0, 'errors' => array( 'twinchat_sources_service_missing' ) );
		}
		$service = BizCity_TwinChat_Sources_Service::instance();
		$map     = get_option( self::OPTION_SOURCE_MAP, array() );
		if ( ! is_array( $map ) ) { $map = array(); }

		$created = 0; $updated = 0; $removed = 0; $unchanged = 0; $errors = array();

		foreach ( $documents as $doc ) {
			$doc_id = sanitize_key( (string) ( $doc['doc_id'] ?? '' ) );
			if ( '' === $doc_id ) { continue; }

			$post = BizCity_CRM_Training_CPT::find_by_doc_id( $doc_id );
			$is_publishable = $post && 'publish' === $post->post_status;

			if ( ! $is_publishable ) {
				// T2-02 — bài bị xoá/nháp ⇒ gỡ nguồn tương ứng.
				if ( isset( $map[ $doc_id ]['source_id'] ) ) {
					$service->delete_source( (int) $map[ $doc_id ]['source_id'] );
					unset( $map[ $doc_id ] );
					$removed++;
				}
				continue;
			}

			$title       = get_the_title( $post );
			$content     = (string) $post->post_content;
			$hash        = hash( 'sha256', $content );
			$existing    = isset( $map[ $doc_id ] ) && is_array( $map[ $doc_id ] ) ? $map[ $doc_id ] : null;
			$existing_source = ( $existing && (int) $existing['source_id'] > 0 ) ? $service->get_source( (int) $existing['source_id'] ) : null;

			if ( $existing_source && $existing['hash'] === $hash ) {
				$unchanged++;
				continue;
			}

			if ( $existing_source ) {
				$service->delete_source( (int) $existing['source_id'] );
			}

			$ingested = $service->ingest( $notebook_id, $admin_id, array(
				'type'     => 'text',
				'title'    => $title,
				'content'  => $content,
				'metadata' => array(
					'template_id'      => self::TEMPLATE_ID,
					'template_doc_id'  => $doc_id,
					'training_post_id' => $post->ID,
					'content_hash'     => $hash,
				),
			) );

			if ( is_wp_error( $ingested ) ) {
				$errors[] = $doc_id . ': ' . $ingested->get_error_message();
				continue;
			}

			$map[ $doc_id ] = array(
				'source_id' => (int) ( $ingested['source_id'] ?? 0 ),
				'post_id'   => $post->ID,
				'hash'      => $hash,
			);
			$existing_source ? $updated++ : $created++;
		}

		update_option( self::OPTION_SOURCE_MAP, $map, false );

		return compact( 'created', 'updated', 'removed', 'unchanged', 'errors' );
	}

	/**
	 * Build the notebook-level selector vector from stable, non-PII template
	 * metadata. This is deliberately public for diagnostics and future seeders.
	 *
	 * @return array{status:string,error?:string}
	 */
	public static function ensure_perspective_embedding( int $notebook_id, int $admin_id, array $template ): array {
		if ( $notebook_id <= 0 || $admin_id <= 0 || ! class_exists( 'BizCity_LLM_Client' ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return array( 'status' => 'skipped', 'error' => 'embedding_dependency_missing' );
		}
		$client = BizCity_LLM_Client::instance();
		if ( ! $client->is_ready() ) {
			return array( 'status' => 'failed', 'error' => 'embedding_gateway_not_ready' );
		}
		$parts = array( (string) ( $template['notebook']['title'] ?? 'Hướng dẫn dùng Twin CRM' ), (string) ( $template['notebook']['description'] ?? '' ) );
		foreach ( (array) ( $template['documents'] ?? array() ) as $document ) {
			$parts[] = (string) ( $document['title'] ?? '' );
			$parts[] = implode( ', ', array_map( 'sanitize_text_field', (array) ( $document['topics'] ?? array() ) ) );
		}
		$text = trim( preg_replace( '/\s+/', ' ', implode( ' — ', array_filter( $parts ) ) ) );
		try {
			$response = $client->embeddings( $text, array( 'purpose' => 'twinbrain_notebook_perspective' ) );
		} catch ( \Throwable $e ) {
			return array( 'status' => 'failed', 'error' => 'embedding_exception' );
		}
		$vector = is_array( $response ) && ! empty( $response['embeddings'][0] ) ? (array) $response['embeddings'][0] : array();
		if ( empty( $vector ) ) {
			return array( 'status' => 'failed', 'error' => 'embedding_empty' );
		}
		global $wpdb;
		$table = BizCity_KG_Database::instance()->tbl_notebooks();
		$keywords = array_merge(
			(array) ( $template['notebook']['tags'] ?? array() ),
			array( 'twin-crm', 'training', 'inbox', 'zalo' )
		);
		$keywords = array_values( array_unique( array_filter( array_map( 'sanitize_key', $keywords ) ) ) );
		$updated = $wpdb->update( $table, array(
			'perspective_label'     => sanitize_text_field( (string) ( $template['notebook']['title'] ?? 'Hướng dẫn dùng Twin CRM' ) ),
			'perspective_summary'   => sanitize_textarea_field( (string) ( $template['notebook']['description'] ?? '' ) ),
			'perspective_embedding' => wp_json_encode( array_values( $vector ) ),
			'topic_keywords'        => wp_json_encode( $keywords ),
			'last_summary_at'       => current_time( 'mysql', true ),
		), array( 'id' => $notebook_id ), array( '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
		return false === $updated ? array( 'status' => 'failed', 'error' => 'embedding_persist_failed' ) : array( 'status' => 'ready' );
	}

	/* ───────────────────────── Save/trash hooks (T2-02) ───────────────────────── */

	public static function on_save_post( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::request_resync();
	}

	public static function on_trashed_post( int $post_id ): void {
		if ( BizCity_CRM_Training_CPT::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		self::request_resync();
	}

	/**
	 * Đồng bộ lại (không tạo lại bài mẫu, không đụng notebook đã bị xoá — chỉ
	 * chạy sync_notebook_sources qua chính run_seed()) khi admin lưu/xoá một
	 * bài trong CPT. run_seed() tự tôn trọng trạng thái `deleted_by_admin`.
	 */
	private static function request_resync(): void {
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return;
		}
		$admin_id = get_current_user_id();
		if ( $admin_id <= 0 ) {
			$state = self::get_state();
			$admin_id = (int) ( $state['admin_user_id'] ?? 0 );
		}
		if ( $admin_id <= 0 ) { return; }
		self::enqueue_seed_run( $admin_id, false );
	}

	/* ───────────────────────── P4: notebook deletion ───────────────────────── */

	public static function on_notebook_before_delete( int $notebook_id ): void {
		$state = self::get_state();
		if ( (int) ( $state['notebook_id'] ?? 0 ) === $notebook_id && $notebook_id > 0 ) {
			self::set_state( array( 'status' => 'deleted_by_admin' ) );
		}
	}

	/* ───────────────────────── T3-06/T3-07a: settings UI hooks ───────────────────────── */

	public static function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, true );
	}

	public static function set_enabled( bool $enabled ): void {
		update_option( self::OPTION_ENABLED, $enabled, false );
	}

	/**
	 * "Khôi phục / Cập nhật" (D57-6) — chạy đồng bộ ngay (giống ingest() đồng bộ
	 * hiện có trong webhook), bỏ qua khoá deleted_by_admin và version-match.
	 */
	public static function restore_now( int $admin_id ): array {
		delete_transient( self::LOCK_TRANSIENT );
		self::$template_cache = null; // đọc lại file JSON (phòng khi vừa deploy bản mới).
		return self::run_seed( $admin_id, true );
	}

	/**
	 * [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0.57 T3-04 — chỉ XẾP HÀNG (P3), không ingest đồng bộ trong request admin_init này.
	 */
	public static function maybe_handle_retry_click(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) { return; }
		if ( empty( $_GET['bzcrm_training_retry'] ) || empty( $_GET['_wpnonce'] ) ) { return; }
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'bzcrm_training_retry' ) ) { return; }
		self::enqueue_seed_run( get_current_user_id(), true );
		wp_safe_redirect( remove_query_arg( array( 'bzcrm_training_retry', '_wpnonce' ) ) );
		exit;
	}

	public static function render_failed_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$state = self::get_state();
		if ( 'failed' !== (string) ( $state['status'] ?? '' ) ) { return; }
		$retry_url = wp_nonce_url( add_query_arg( 'bzcrm_training_retry', '1' ), 'bzcrm_training_retry' );
		echo '<div class="notice notice-warning is-dismissible"><p>'
			. esc_html__( 'Chưa tạo được tài liệu đào tạo: ', 'bizcity-twin-crm' )
			. esc_html( (string) ( $state['error'] ?? '' ) )
			. ' <a href="' . esc_url( $retry_url ) . '">' . esc_html__( 'Thử lại', 'bizcity-twin-crm' ) . '</a>'
			. '</p></div>';
	}

	/* ───────────────────────── State option ───────────────────────── */

	public static function get_state(): array {
		$state = get_option( self::OPTION_STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	private static function set_state( array $patch ): void {
		$state = self::get_state();
		$state = array_merge( $state, $patch );
		$state['updated_at'] = gmdate( 'c' );
		update_option( self::OPTION_STATE, $state, false );
	}
}
