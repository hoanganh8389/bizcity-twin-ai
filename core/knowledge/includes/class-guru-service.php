<?php
/**
 * Bizcity Twin AI — Guru (character) configuration service.
 *
 * Model layer for the Guru editor on `/twinkg/` (CORE-REDUCTION-WP-09 §17, step T5a).
 * Owns profile read / partial update, delete, duplicate, slug check, the model list,
 * per-row quick FAQ (T5b) and export / import (T5c).
 * `BizCity_Guru_Admin_REST` is the thin controller over it; the legacy AJAX handlers in
 * class-admin-menu.php are NOT rewired here — they retire in WP-08 H6.
 *
 * Fixes carried over from the legacy handlers (WP-09 §17.1):
 *   - partial update: keys the caller did not send are never written (the legacy save
 *     reset model_id / greeting_messages / capabilities on every save);
 *   - values are unslashed and validated (status ENUM, creativity 0..1, max_tokens);
 *   - duplicate goes through create_character() so caches and Guru policy invalidate,
 *     and copies the policy columns + stamps a fresh guru_uuid.
 *
 * System prompt / tone / runtime / quick training / notebooks stay on the v1 quick-edit
 * REST (class-character-quick-edit-rest.php); they are not duplicated here.
 *
 * PHP 7.4 compatible — no match, no enums, no nullsafe, no readonly.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @since      2026-09-24
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Knowledge_Guru_Service {

	const STATUSES   = array( 'draft', 'active', 'published', 'archived' );
	const MIN_ROLES  = array( '', 'subscriber', 'contributor', 'author', 'editor', 'administrator' );
	const MIN_PLANS  = array( '', 'free', 'plus', 'pro' );
	const MAX_TOKENS_CEIL  = 32000;
	const MODELS_TRANSIENT = 'bizcity_knowledge_openrouter_models';

	/** Columns copied by duplicate(); identity, counters and publishing state are not. */
	const DUPLICATE_COLUMNS = array(
		'avatar', 'description', 'system_prompt', 'model_id', 'creativity_level', 'max_tokens',
		'greeting_messages', 'capabilities', 'industries', 'variables_schema', 'settings',
		'allowed_verticals', 'notebook_policy', 'min_role', 'min_plan',
	);

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ── R1 — admin list ─────────────────────────────────────────────────── */

	/**
	 * @param array $args { search, status, page, per_page }
	 * @return array { items[], total, page, per_page }
	 */
	public function list_admin( array $args ): array {
		global $wpdb;
		$chars   = $wpdb->prefix . 'bizcity_characters';
		$sources = $wpdb->prefix . 'bizcity_knowledge_sources';

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$status   = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$search   = trim( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );

		$where  = array( '1=1' );
		$values = array();
		if ( in_array( $status, self::STATUSES, true ) ) {
			$where[]  = 'c.status = %s';
			$values[] = $status;
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(c.name LIKE %s OR c.slug LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}
		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$chars} c WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $values ? $wpdb->prepare( $count_sql, ...$values ) : $count_sql );

		$list_values   = $values;
		$list_values[] = $per_page;
		$list_values[] = ( $page - 1 ) * $per_page;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.name, c.slug, c.avatar, c.description, c.status, c.updated_at,
			        (SELECT COUNT(*) FROM {$sources} s WHERE s.character_id = c.id) AS sources_count
			 FROM {$chars} c WHERE {$where_sql}
			 ORDER BY c.updated_at DESC, c.id DESC LIMIT %d OFFSET %d",
			...$list_values
		), ARRAY_A ) ?: array();

		$items = array();
		foreach ( $rows as $r ) {
			$items[] = array(
				'id'            => (int) $r['id'],
				'name'          => (string) $r['name'],
				'slug'          => (string) $r['slug'],
				'avatar'        => (string) $r['avatar'],
				'description'   => wp_trim_words( (string) $r['description'], 30 ),
				'status'        => (string) $r['status'],
				'sources_count' => (int) $r['sources_count'],
				'updated_at'    => (string) $r['updated_at'],
			);
		}

		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page );
	}

	/* ── R2 — profile read ───────────────────────────────────────────────── */

	/** @return array|WP_Error */
	public function get_profile( int $id ) {
		$row = $this->load( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$settings = $this->decode_json( $row['settings'] ?? '', array() );
		$max      = isset( $row['max_tokens'] ) && '' !== (string) $row['max_tokens'] ? (int) $row['max_tokens'] : null;

		return array(
			'id'                  => (int) $row['id'],
			'guru_uuid'           => (string) ( $row['guru_uuid'] ?? '' ),
			'name'                => (string) $row['name'],
			'slug'                => (string) $row['slug'],
			'avatar'              => (string) ( $row['avatar'] ?? '' ),
			'description'         => (string) ( $row['description'] ?? '' ),
			'status'              => (string) ( $row['status'] ?? 'draft' ),
			'model_id'            => (string) ( $row['model_id'] ?? '' ),
			'creativity_level'    => (float) ( $row['creativity_level'] ?? 0.7 ),
			'max_tokens'          => $max,
			'greeting_messages'   => $this->decode_json( $row['greeting_messages'] ?? '', array() ),
			'capabilities'        => $this->decode_json( $row['capabilities'] ?? '', array() ),
			'notebook_policy'     => (string) ( $row['notebook_policy'] ?? 'augment' ),
			'min_role'            => (string) ( $row['min_role'] ?? '' ),
			'min_plan'            => (string) ( $row['min_plan'] ?? '' ),
			'persona_provider_id' => (string) ( $settings['provider_id'] ?? '' ),
			'updated_at'          => (string) ( $row['updated_at'] ?? '' ),
			// Where this Guru answers (Bot Studio / Channel Gateway bindings) — read-only here;
			// bindings are edited in Bot Studio, never from the KG editor.
			'channels'            => $this->channels_for( (int) $row['id'] ),
			'options'             => array(
				'statuses'  => self::STATUSES,
				'min_roles' => self::MIN_ROLES,
				'min_plans' => self::MIN_PLANS,
			),
			/**
			 * Filter: bizcity_knowledge_guru_profile_extensions
			 *
			 * REST-era replacement of the PHP action `bizcity_knowledge_character_meta_rows`
			 * (WP-09 §17.4). Return sections the editor renders generically:
			 * [ { key, title, fields: [ { name, type: text|textarea|select|chips, label, options?, value } ] } ].
			 * Values posted back arrive in the `$data` of `bizcity_knowledge_character_saved`.
			 *
			 * @param array $sections
			 * @param int   $id
			 */
			'extensions'          => array_values( (array) apply_filters( 'bizcity_knowledge_guru_profile_extensions', array(), (int) $row['id'] ) ),
		);
	}

	/* ── R3 — partial update ─────────────────────────────────────────────── */

	/**
	 * @param int   $id
	 * @param array $input Decoded JSON body (already unslashed by WP REST). Only present keys are written.
	 * @return array|WP_Error The fresh profile.
	 */
	public function patch_profile( int $id, array $input ) {
		$row = $this->load( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$data = array();

		if ( array_key_exists( 'name', $input ) ) {
			$name = trim( sanitize_text_field( (string) $input['name'] ) );
			if ( '' === $name ) {
				return $this->error( 'invalid_name', 'Tên Guru không được để trống.', 422 );
			}
			$data['name'] = $name;
		}
		if ( array_key_exists( 'slug', $input ) ) {
			$slug = sanitize_title( (string) $input['slug'] );
			if ( '' === $slug ) {
				return $this->error( 'invalid_slug', 'Slug không được để trống.', 422 );
			}
			if ( $this->slug_taken( $slug, $id ) ) {
				return $this->error( 'slug_taken', 'Slug đã được dùng bởi Guru khác.', 409 );
			}
			$data['slug'] = $slug;
		}
		if ( array_key_exists( 'avatar', $input ) ) {
			$data['avatar'] = esc_url_raw( (string) $input['avatar'] );
		}
		if ( array_key_exists( 'description', $input ) ) {
			$data['description'] = sanitize_textarea_field( (string) $input['description'] );
		}
		if ( array_key_exists( 'status', $input ) ) {
			$status = sanitize_key( (string) $input['status'] );
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				return $this->error( 'invalid_status', 'Trạng thái không hợp lệ.', 422 );
			}
			$data['status'] = $status;
		}
		if ( array_key_exists( 'model_id', $input ) ) {
			$data['model_id'] = sanitize_text_field( (string) $input['model_id'] );
		}
		if ( array_key_exists( 'creativity_level', $input ) ) {
			$data['creativity_level'] = max( 0.0, min( 1.0, round( (float) $input['creativity_level'], 2 ) ) );
		}
		if ( array_key_exists( 'max_tokens', $input ) ) {
			$mt = null === $input['max_tokens'] || '' === $input['max_tokens'] ? 0 : (int) $input['max_tokens'];
			$data['max_tokens'] = $mt < 1 ? null : min( self::MAX_TOKENS_CEIL, $mt );
		}
		if ( array_key_exists( 'greeting_messages', $input ) ) {
			if ( ! is_array( $input['greeting_messages'] ) ) {
				return $this->error( 'invalid_greeting_messages', 'greeting_messages phải là mảng.', 422 );
			}
			$data['greeting_messages'] = wp_json_encode( $this->sanitize_deep( array_values( $input['greeting_messages'] ) ), JSON_UNESCAPED_UNICODE );
		}
		if ( array_key_exists( 'capabilities', $input ) ) {
			if ( ! is_array( $input['capabilities'] ) ) {
				return $this->error( 'invalid_capabilities', 'capabilities phải là mảng.', 422 );
			}
			$data['capabilities'] = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $input['capabilities'] ) ), 'strlen' ) );
		}
		foreach ( array( 'notebook_policy' => array( 'augment', 'restrict' ), 'min_role' => self::MIN_ROLES, 'min_plan' => self::MIN_PLANS ) as $key => $allowed ) {
			if ( array_key_exists( $key, $input ) ) {
				$value = sanitize_key( (string) $input[ $key ] );
				if ( ! in_array( $value, $allowed, true ) ) {
					return $this->error( 'invalid_' . $key, $key . ' không hợp lệ.', 422 );
				}
				$data[ $key ] = $value;
			}
		}
		if ( array_key_exists( 'persona_provider_id', $input ) ) {
			// Same merge rule as the legacy save (settings JSON keeps unrelated keys).
			$settings    = $this->decode_json( $row['settings'] ?? '', array() );
			$provider_id = sanitize_key( (string) $input['persona_provider_id'] );
			unset( $settings['provider_id_pending'] );
			if ( '' === $provider_id ) {
				unset( $settings['provider_id'] );
			} else {
				$settings['provider_id'] = $provider_id;
				if ( class_exists( 'BizCity_Persona_Registry' ) && ! BizCity_Persona_Registry::instance()->get( $provider_id ) ) {
					$settings['provider_id_pending'] = $provider_id;
				}
			}
			$data['settings'] = $settings;
		}

		// Bot Studio's quick-edit reads runtime from settings.temperature / settings.max_tokens
		// FIRST and only falls back to the columns (BizCity_Character_Quick_Edit_REST::get_payload),
		// so a column-only write would leave both editors showing different values. Mirror them.
		if ( array_key_exists( 'creativity_level', $data ) || array_key_exists( 'max_tokens', $data ) ) {
			$settings = isset( $data['settings'] ) ? $data['settings'] : $this->decode_json( $row['settings'] ?? '', array() );
			if ( array_key_exists( 'creativity_level', $data ) ) {
				$settings['temperature'] = $data['creativity_level'];
			}
			if ( array_key_exists( 'max_tokens', $data ) ) {
				if ( null === $data['max_tokens'] ) {
					unset( $settings['max_tokens'] );
				} else {
					$settings['max_tokens'] = $data['max_tokens'];
				}
			}
			$data['settings'] = $settings;
		}

		if ( $data ) {
			$result = BizCity_Knowledge_Database::instance()->update_character( $id, $data );
			if ( is_wp_error( $result ) ) {
				return $this->error( 'db_error', $result->get_error_message(), 500 );
			}
		}

		// Extension values (WP-09 §17.4) travel to listeners exactly as the legacy form
		// posted them — flat keys in $data — but unslashed and scalar/array only.
		$hook_data = $data;
		if ( isset( $input['extensions'] ) && is_array( $input['extensions'] ) ) {
			foreach ( $input['extensions'] as $name => $value ) {
				$name = sanitize_key( (string) $name );
				if ( '' !== $name && ! array_key_exists( $name, $hook_data ) && ( is_scalar( $value ) || is_array( $value ) ) ) {
					$hook_data[ $name ] = $value;
				}
			}
		}

		if ( $hook_data ) {
			/** Documented in class-admin-menu.php::ajax_save_character(). */
			do_action( 'bizcity_knowledge_character_saved', $id, $hook_data );
		}

		return $this->get_profile( $id );
	}

	/* ── R4 — delete ─────────────────────────────────────────────────────── */

	/** @return array|WP_Error */
	public function delete( int $id ) {
		$row = $this->load( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		global $wpdb;

		// A Guru that still answers a channel must be replaced in Bot Studio first; deleting it
		// here would leave the binding pointing at a missing character (CRM Guru_Resolver and
		// the bot turn runner would then answer with no persona and no notebooks).
		$active = array_values( array_filter( $this->channels_for( $id ), static function ( $c ) {
			return ! empty( $c['active'] );
		} ) );
		if ( $active ) {
			$labels = array_map( static function ( $c ) {
				return $c['platform'] . ' · ' . $c['account_id'];
			}, $active );
			return new WP_Error(
				'guru_on_channel',
				'Guru đang trực kênh: ' . implode( ', ', $labels ) . '. Đổi Guru cho các kênh này trong Bot Studio trước khi xoá.',
				array( 'status' => 409, 'channels' => $active )
			);
		}

		// Knowledge links go through the canonical attachment table (the same writer Bot Studio
		// uses: BizCity_KG_Database::detach_guru), then the legacy character_id column.
		// Notebooks keep their data; they only stop pointing at a Guru that no longer exists.
		$detached = 0;
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$kg_db = BizCity_KG_Database::instance();
			$uuid  = strtolower( (string) ( $row['guru_uuid'] ?? '' ) );
			if ( '' !== $uuid ) {
				foreach ( $this->attached_notebook_ids( $uuid, 0 ) as $nb_id ) {
					$res       = $kg_db->detach_guru( $nb_id, $uuid );
					$detached += is_array( $res ) ? (int) ( $res['deleted'] ?? 0 ) : 0;
				}
			}
			$detached += (int) $wpdb->update( $kg_db->tbl_notebooks(), array( 'character_id' => null ), array( 'character_id' => $id ) );
		}

		$deleted = BizCity_Knowledge_Database::instance()->delete_character( $id );
		if ( false === $deleted ) {
			return $this->error( 'db_error', (string) $wpdb->last_error, 500 );
		}
		if ( class_exists( 'BizCity_TwinBrain_Guru_Policy' ) ) {
			BizCity_TwinBrain_Guru_Policy::invalidate( $id );
		}

		/**
		 * Fires after a Guru row and its knowledge sources/chunks were deleted.
		 *
		 * @param int   $id  Character id.
		 * @param array $row The deleted character row.
		 */
		do_action( 'bizcity_knowledge_character_deleted', $id, $row );

		return array( 'ok' => true, 'id' => $id, 'notebooks_detached' => $detached );
	}

	/* ── R5 — duplicate ──────────────────────────────────────────────────── */

	/** @return array|WP_Error { id, name, slug, sources_copied, chunks_copied } */
	public function duplicate( int $id ) {
		$row = $this->load( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$db = BizCity_Knowledge_Database::instance();

		$data = array(
			'name'   => $row['name'] . ' (Copy)',
			'slug'   => $this->unique_slug( ( '' !== (string) $row['slug'] ? $row['slug'] : sanitize_title( $row['name'] ) ) . '-copy' ),
			'status' => 'draft',
		);
		foreach ( self::DUPLICATE_COLUMNS as $col ) {
			if ( array_key_exists( $col, $row ) ) {
				$data[ $col ] = $row[ $col ];
			}
		}
		// A copy is a new Guru: its own canonical id, never the original's. Only set when the
		// PHASE-0.21 column exists (the row read above tells us), since create_character()
		// inserts every key it is given.
		if ( array_key_exists( 'guru_uuid', $row ) ) {
			$data['guru_uuid'] = wp_generate_uuid4();
		}

		$new_id = $db->create_character( $data );
		if ( is_wp_error( $new_id ) ) {
			return $this->error( 'db_error', $new_id->get_error_message(), 500 );
		}
		$new_id = (int) $new_id;

		list( $sources_copied, $chunks_copied ) = $this->copy_knowledge( $id, $new_id );

		// The copy answers from the same notebooks: re-attach them to the new guru_uuid through
		// the canonical writer (it enforces the "public Guru ⇒ no personal notebook" rule).
		// Channel bindings are NOT copied — a draft copy never starts answering a channel.
		$notebooks_attached = 0;
		$notebook_errors    = array();
		if ( ! empty( $data['guru_uuid'] ) && class_exists( 'BizCity_KG_Database' ) ) {
			$kg_db = BizCity_KG_Database::instance();
			foreach ( $this->attached_notebook_ids( strtolower( (string) ( $row['guru_uuid'] ?? '' ) ), $id ) as $nb_id ) {
				$res = $kg_db->attach_guru( $nb_id, $data['guru_uuid'], array( 'source' => 'self', 'attached_by' => get_current_user_id() ) );
				if ( is_wp_error( $res ) ) {
					$notebook_errors[] = $res->get_error_message();
				} else {
					$notebooks_attached++;
				}
			}
		}

		do_action( 'bizcity_knowledge_character_saved', $new_id, array_merge( $data, array( 'duplicated_from' => $id ) ) );

		return array(
			'id'                 => $new_id,
			'name'               => $data['name'],
			'slug'               => $data['slug'],
			'sources_copied'     => $sources_copied,
			'chunks_copied'      => $chunks_copied,
			'notebooks_attached' => $notebooks_attached,
			'notebook_errors'    => $notebook_errors,
		);
	}

	/* ── R6 — slug check ─────────────────────────────────────────────────── */

	/** @return array|WP_Error { exists, slug, suggested_slug } */
	public function slug_check( string $name, string $slug, int $exclude_id = 0 ) {
		$slug = sanitize_title( '' !== trim( $slug ) ? $slug : $name );
		if ( '' === $slug ) {
			return $this->error( 'invalid_slug', 'Cần tên hoặc slug để kiểm tra.', 422 );
		}
		$exists = $this->slug_taken( $slug, $exclude_id );
		return array(
			'exists'         => $exists,
			'slug'           => $slug,
			'suggested_slug' => $exists ? $this->unique_slug( $slug, $exclude_id ) : $slug,
		);
	}

	/* ── R7 — models (gateway only, R-GW-8) ──────────────────────────────── */

	/** @return array|WP_Error { models[] } */
	public function models() {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return $this->error( 'gateway_missing', 'BizCity_LLM_Client chưa được nạp.', 503 );
		}
		$client = BizCity_LLM_Client::instance();
		if ( ! $client->is_ready() ) {
			return $this->error( 'gateway_not_ready', 'BizCity API key chưa cấu hình (gateway).', 503 );
		}

		$cached = get_transient( self::MODELS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return array( 'models' => $cached );
		}

		$raw = $client->get_available_models();
		if ( empty( $raw ) || ! is_array( $raw ) ) {
			return $this->error( 'gateway_bad_response', 'Gateway không trả về danh sách model.', 502 );
		}
		$models = array();
		foreach ( $raw as $m ) {
			if ( ! is_array( $m ) || empty( $m['id'] ) ) {
				continue;
			}
			$models[] = array(
				'id'             => (string) $m['id'],
				'name'           => (string) ( $m['name'] ?? $m['id'] ),
				'description'    => (string) ( $m['description'] ?? '' ),
				'context_length' => (int) ( $m['context_length'] ?? 0 ),
				'pricing'        => array(
					'prompt'     => $m['pricing']['prompt'] ?? 0,
					'completion' => $m['pricing']['completion'] ?? 0,
				),
			);
		}
		// Same key and lifetime as the legacy handler, so both share one cache.
		set_transient( self::MODELS_TRANSIENT, $models, HOUR_IN_SECONDS );
		return array( 'models' => $models );
	}

	/* ── R8 — quick FAQ, one row at a time ───────────────────────────────── */

	/**
	 * Create or update one quick-FAQ row. Same row shape as the v1 quick-edit full-replace
	 * (BizCity_Character_Quick_Edit_REST::save_quick_faq) that Bot Studio uses, so both
	 * editors read and write one set of rows.
	 *
	 * @return array|WP_Error { id, op: created|updated, title, content }
	 */
	public function quick_faq_upsert( int $id, int $source_id, string $title, string $content ) {
		$row = $this->load( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$title   = sanitize_text_field( $title );
		$content = sanitize_textarea_field( $content );
		if ( '' === $title && '' === $content ) {
			return $this->error( 'empty_row', 'Cần tiêu đề hoặc nội dung.', 422 );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_knowledge_sources';
		$json  = wp_json_encode( array( 'title' => $title, 'content' => $content ), JSON_UNESCAPED_UNICODE );
		$now   = current_time( 'mysql' );
		$cols  = array(
			'content'      => $json,
			'content_hash' => md5( $json ),
			'source_name'  => '' !== $title ? $title : 'Quick Knowledge',
			'status'       => 'ready',
			'updated_at'   => $now,
		);

		if ( $source_id > 0 ) {
			$owned = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE id = %d AND character_id = %d AND source_type = 'quick_faq'",
				$source_id,
				$id
			) );
			if ( ! $owned ) {
				return $this->error( 'faq_not_found', 'Không tìm thấy dòng FAQ của Guru này.', 404 );
			}
			if ( false === $wpdb->update( $table, $cols, array( 'id' => $source_id ) ) ) {
				return $this->error( 'db_error', (string) $wpdb->last_error, 500 );
			}
			return array( 'id' => $source_id, 'op' => 'updated', 'title' => $title, 'content' => $content );
		}

		$cols['character_id'] = $id;
		$cols['source_type']  = 'quick_faq';
		$cols['created_at']   = $now;
		if ( false === $wpdb->insert( $table, $cols ) || ! $wpdb->insert_id ) {
			return $this->error( 'db_error', (string) $wpdb->last_error, 500 );
		}
		return array( 'id' => (int) $wpdb->insert_id, 'op' => 'created', 'title' => $title, 'content' => $content );
	}

	/** @return array|WP_Error { id, deleted } */
	public function quick_faq_delete( int $id, int $source_id ) {
		$row = $this->load( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		global $wpdb;
		$deleted = (int) $wpdb->delete(
			$wpdb->prefix . 'bizcity_knowledge_sources',
			array( 'id' => $source_id, 'character_id' => $id, 'source_type' => 'quick_faq' )
		);
		if ( 0 === $deleted ) {
			return $this->error( 'faq_not_found', 'Không tìm thấy dòng FAQ của Guru này.', 404 );
		}
		return array( 'id' => $source_id, 'deleted' => $deleted );
	}

	/* ── R9 — export / import (T5-D4: never embeddings) ─────────────────── */

	const EXPORT_FORMAT      = 'bizcity-guru';
	const EXPORT_VERSION     = '2.0';
	const IMPORT_MAX_SOURCES = 200;
	const IMPORT_MAX_FAQ     = 500;
	const IMPORT_MAX_CHARS   = 500000;

	/** Profile keys an import may apply to an EXISTING Guru (identity — name/slug — never). */
	const IMPORT_PROFILE_KEYS = array(
		'avatar', 'description', 'model_id', 'greeting_messages', 'capabilities',
		'notebook_policy', 'min_role', 'min_plan', 'persona_provider_id',
	);

	/**
	 * Portable Guru file: profile, prompt, runtime, quick FAQ and the extracted text of every
	 * other source. No chunks and no embeddings (T5-D4) — import rebuilds them. Attached
	 * notebooks are listed for information only: they are shared objects, never re-created.
	 *
	 * @return array|WP_Error { filename, data }
	 */
	public function export( int $id ) {
		$row = $this->load( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$profile  = $this->get_profile( $id );
		$settings = $this->decode_json( $row['settings'] ?? '', array() );
		global $wpdb;

		$faq     = array();
		$sources = array();
		$rows    = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, source_type, source_name, source_url, content FROM {$wpdb->prefix}bizcity_knowledge_sources WHERE character_id = %d ORDER BY id ASC",
			$id
		), ARRAY_A ) ?: array();
		foreach ( $rows as $src ) {
			if ( 'quick_faq' === $src['source_type'] ) {
				$json  = $this->decode_json( $src['content'] ?? '', array() );
				$faq[] = array(
					'title'   => (string) ( $json['title'] ?? $src['source_name'] ?? '' ),
					'content' => (string) ( $json['content'] ?? ( $json ? '' : (string) $src['content'] ) ),
				);
				continue;
			}
			if ( '' === trim( (string) $src['content'] ) ) {
				continue; // nothing to rebuild from (e.g. a URL row that never finished crawling)
			}
			$sources[] = array(
				'source_type' => (string) $src['source_type'],
				'source_name' => (string) $src['source_name'],
				'source_url'  => (string) $src['source_url'],
				'content'     => (string) $src['content'],
			);
		}

		$notebooks = array();
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$ids = $this->attached_notebook_ids( strtolower( (string) ( $row['guru_uuid'] ?? '' ) ), $id );
			if ( $ids ) {
				$tbl       = BizCity_KG_Database::instance()->tbl_notebooks();
				$in        = implode( ',', array_map( 'intval', $ids ) );
				$notebooks = $wpdb->get_results( "SELECT name, notebook_scope FROM {$tbl} WHERE id IN ({$in})", ARRAY_A ) ?: array();
			}
		}

		$data = array(
			'format'      => self::EXPORT_FORMAT,
			'version'     => self::EXPORT_VERSION,
			'exported_at' => gmdate( 'c' ),
			'guru'        => array(
				'name'                => $profile['name'],
				'slug'                => $profile['slug'],
				'avatar'              => $profile['avatar'],
				'description'         => $profile['description'],
				'system_prompt'       => (string) ( $row['system_prompt'] ?? '' ),
				'model_id'            => $profile['model_id'],
				'greeting_messages'   => $profile['greeting_messages'],
				'capabilities'        => $profile['capabilities'],
				'notebook_policy'     => $profile['notebook_policy'],
				'min_role'            => $profile['min_role'],
				'min_plan'            => $profile['min_plan'],
				'persona_provider_id' => $profile['persona_provider_id'],
				'runtime'             => array(
					'temperature' => isset( $settings['temperature'] ) ? (float) $settings['temperature'] : $profile['creativity_level'],
					'max_tokens'  => isset( $settings['max_tokens'] ) ? (int) $settings['max_tokens'] : $profile['max_tokens'],
				),
			),
			'quick_faq'   => $faq,
			'sources'     => $sources,
			'notebooks'   => $notebooks,
		);

		return array(
			'filename' => 'guru-' . ( '' !== $profile['slug'] ? $profile['slug'] : $id ) . '-' . gmdate( 'Ymd' ) . '.json',
			'data'     => $data,
		);
	}

	/**
	 * Import a Guru file into an existing Guru.
	 *
	 * Quick FAQ rows are written directly (same shape as R8). Every other source becomes a
	 * `manual` row with status `pending` and its text as content — no chunks, no embeddings.
	 * The caller then processes each pending id through v1 quick-edit
	 * `quick_training.retry_source_ids` (Bot Studio's writer → Knowledge Fabric chunk + embed),
	 * one small request per source, so a large import never runs into a proxy timeout.
	 *
	 * Accepts this service's format (`bizcity-guru` 2.0) and the legacy AJAX export (1.0).
	 *
	 * @param array $opts { overwrite: bool, apply_profile: bool }
	 * @return array|WP_Error { faq_created, pending_source_ids[], skipped, profile_applied, removed }
	 */
	public function import( int $id, $payload, array $opts ) {
		$row = $this->load( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$parsed = $this->normalize_import( $payload );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_knowledge_sources';

		// Profile first: a validation error (422) must abort BEFORE `overwrite` deletes anything.
		$profile_applied = false;
		if ( ! empty( $opts['apply_profile'] ) && $parsed['guru'] ) {
			$g     = $parsed['guru'];
			$patch = array_intersect_key( $g, array_flip( self::IMPORT_PROFILE_KEYS ) );
			if ( isset( $g['runtime']['temperature'] ) ) {
				$patch['creativity_level'] = $g['runtime']['temperature'];
			}
			if ( array_key_exists( 'max_tokens', $g['runtime'] ?? array() ) ) {
				$patch['max_tokens'] = $g['runtime']['max_tokens'];
			}
			$result = $this->patch_profile( $id, $patch );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( isset( $g['system_prompt'] ) && '' !== trim( (string) $g['system_prompt'] ) ) {
				// Raw column value, tone marker block included — the format quick-edit reads.
				BizCity_Knowledge_Database::instance()->update_character( $id, array( 'system_prompt' => wp_kses_post( (string) $g['system_prompt'] ) ) );
			}
			$profile_applied = true;
		}

		$removed = 0;
		if ( ! empty( $opts['overwrite'] ) ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE character_id = %d", $id ) ) ?: array();
			$db  = BizCity_Knowledge_Database::instance();
			foreach ( $ids as $source_id ) {
				$db->delete_source_and_chunks( (int) $source_id );
				$removed++;
			}
		}

		$faq_created = 0;
		foreach ( $parsed['quick_faq'] as $faq ) {
			$res = $this->quick_faq_upsert( $id, 0, (string) $faq['title'], (string) $faq['content'] );
			if ( ! is_wp_error( $res ) ) {
				$faq_created++;
			}
		}

		$pending = array();
		$skipped = 0;
		$now     = current_time( 'mysql' );
		foreach ( $parsed['sources'] as $src ) {
			$content = sanitize_textarea_field( (string) $src['content'] );
			if ( '' === trim( $content ) ) {
				$skipped++;
				continue;
			}
			$ok = $wpdb->insert( $table, array(
				'character_id' => $id,
				'user_id'      => get_current_user_id(),
				'scope'        => 'agent',
				'source_type'  => 'manual',
				'source_name'  => mb_substr( sanitize_text_field( (string) $src['source_name'] ), 0, 255 ),
				'source_url'   => esc_url_raw( (string) $src['source_url'] ),
				'content'      => $content,
				'content_hash' => md5( $content ),
				'status'       => 'pending',
				'settings'     => wp_json_encode( array( 'ingested_by' => 'guru_import', 'original_type' => sanitize_key( (string) $src['source_type'] ) ) ),
				'created_at'   => $now,
				'updated_at'   => $now,
			) );
			if ( false === $ok || ! $wpdb->insert_id ) {
				$skipped++;
				continue;
			}
			$pending[] = (int) $wpdb->insert_id;
		}

		/**
		 * Fires after a Guru file was imported (before the pending sources are embedded).
		 *
		 * @param int   $id
		 * @param array $summary { faq_created, pending_source_ids, skipped, profile_applied, removed }
		 */
		$summary = array(
			'faq_created'        => $faq_created,
			'pending_source_ids' => $pending,
			'skipped'            => $skipped,
			'profile_applied'    => $profile_applied,
			'removed'            => $removed,
		);
		do_action( 'bizcity_knowledge_guru_imported', $id, $summary );
		return $summary;
	}

	/**
	 * Validate + normalize an import payload (2.0 or legacy 1.0) into
	 * { guru: array|null, quick_faq: [{title, content}], sources: [{source_type, source_name, source_url, content}] }.
	 *
	 * @return array|WP_Error
	 */
	private function normalize_import( $payload ) {
		if ( is_string( $payload ) ) {
			$payload = json_decode( $payload, true );
		}
		if ( ! is_array( $payload ) ) {
			return $this->error( 'invalid_import', 'File import không phải JSON hợp lệ.', 422 );
		}

		$guru    = null;
		$faq     = array();
		$sources = array();

		if ( self::EXPORT_FORMAT === ( $payload['format'] ?? '' ) ) {
			$guru = is_array( $payload['guru'] ?? null ) ? $payload['guru'] : null;
			foreach ( (array) ( $payload['quick_faq'] ?? array() ) as $f ) {
				if ( is_array( $f ) ) {
					$faq[] = array( 'title' => (string) ( $f['title'] ?? '' ), 'content' => (string) ( $f['content'] ?? '' ) );
				}
			}
			foreach ( (array) ( $payload['sources'] ?? array() ) as $s ) {
				if ( is_array( $s ) ) {
					$sources[] = $s;
				}
			}
		} elseif ( isset( $payload['knowledge_sources'] ) || isset( $payload['character'] ) ) {
			// Legacy ajax_export_knowledge (version 1.0). Chunks and embeddings are ignored.
			if ( is_array( $payload['character'] ?? null ) ) {
				$c    = $payload['character'];
				$guru = array(
					'avatar'            => $c['avatar'] ?? '',
					'description'       => $c['description'] ?? '',
					'system_prompt'     => $c['system_prompt'] ?? '',
					'model_id'          => $c['model_id'] ?? '',
					'greeting_messages' => $this->decode_json( $c['greeting_messages'] ?? '', array() ),
					'capabilities'      => $this->decode_json( $c['capabilities'] ?? '', array() ),
					'runtime'           => isset( $c['creativity_level'] ) ? array( 'temperature' => (float) $c['creativity_level'] ) : array(),
				);
			}
			foreach ( (array) ( $payload['knowledge_sources'] ?? array() ) as $s ) {
				if ( ! is_array( $s ) ) {
					continue;
				}
				$type = (string) ( $s['source_type'] ?? '' );
				$json = $this->decode_json( $s['content'] ?? '', array() );
				if ( 'quick_faq' === $type ) {
					$faq[] = array( 'title' => (string) ( $json['title'] ?? $s['source_name'] ?? '' ), 'content' => (string) ( $json['content'] ?? '' ) );
				} elseif ( 'manual' === $type && isset( $json['question'] ) ) {
					$faq[] = array( 'title' => (string) $json['question'], 'content' => (string) ( $json['answer'] ?? '' ) );
				} else {
					$sources[] = array(
						'source_type' => $type,
						'source_name' => (string) ( $s['source_name'] ?? '' ),
						'source_url'  => (string) ( $s['source_url'] ?? '' ),
						'content'     => (string) ( $s['content'] ?? '' ),
					);
				}
			}
		} else {
			return $this->error( 'invalid_import', 'Không nhận ra định dạng file Guru.', 422 );
		}

		if ( count( $sources ) > self::IMPORT_MAX_SOURCES || count( $faq ) > self::IMPORT_MAX_FAQ ) {
			return $this->error( 'import_too_large', sprintf( 'Tối đa %d nguồn và %d dòng FAQ mỗi lần import.', self::IMPORT_MAX_SOURCES, self::IMPORT_MAX_FAQ ), 413 );
		}
		foreach ( $sources as $i => $s ) {
			$sources[ $i ] = array(
				'source_type' => (string) ( $s['source_type'] ?? '' ),
				'source_name' => (string) ( $s['source_name'] ?? '' ),
				'source_url'  => (string) ( $s['source_url'] ?? '' ),
				'content'     => (string) ( $s['content'] ?? '' ),
			);
			if ( mb_strlen( $sources[ $i ]['content'] ) > self::IMPORT_MAX_CHARS ) {
				return $this->error( 'import_too_large', sprintf( 'Nguồn "%s" vượt %d ký tự.', $sources[ $i ]['source_name'], self::IMPORT_MAX_CHARS ), 413 );
			}
		}

		return array( 'guru' => $guru, 'quick_faq' => $faq, 'sources' => $sources );
	}

	/* ── helpers ─────────────────────────────────────────────────────────── */

	/**
	 * Channel bindings (Bot Studio) in which this Guru answers — as the primary Guru, or as a
	 * `guru` slot of a round-robin pool. Blog-scoped through BizCity_Channel_Binding::all().
	 *
	 * @return array[] { binding_id, platform, account_id, mode, active, role: primary|pool }
	 */
	private function channels_for( int $id ): array {
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) BizCity_Channel_Binding::all() as $b ) {
			$role = null;
			if ( (int) ( $b['character_id'] ?? 0 ) === $id ) {
				$role = 'primary';
			} else {
				$pool = ! empty( $b['responder_pool_json'] ) ? json_decode( (string) $b['responder_pool_json'], true ) : array();
				foreach ( is_array( $pool ) ? $pool : array() as $slot ) {
					if ( is_array( $slot ) && 'guru' === (string) ( $slot['kind'] ?? 'guru' ) && (int) ( $slot['id'] ?? 0 ) === $id ) {
						$role = 'pool';
						break;
					}
				}
			}
			if ( null === $role ) {
				continue;
			}
			$out[] = array(
				'binding_id' => (int) ( $b['id'] ?? 0 ),
				'platform'   => (string) ( $b['platform'] ?? '' ),
				'account_id' => (string) ( $b['account_id'] ?? '' ),
				'mode'       => (string) ( $b['mode'] ?? '' ),
				'active'     => 1 === (int) ( $b['status'] ?? 0 ),
				'role'       => $role,
			);
		}
		return $out;
	}

	/**
	 * Notebook ids attached to a Guru: canonical attachment rows by guru_uuid, falling back to
	 * the legacy `kg_notebooks.character_id` link while the backfill is incomplete — the same
	 * read order as BizCity_Character_Quick_Edit_REST::get_payload().
	 *
	 * @param string $uuid         Lower-case guru_uuid ('' skips the canonical read).
	 * @param int    $character_id Legacy fallback id (0 = no fallback).
	 * @return int[]
	 */
	private function attached_notebook_ids( string $uuid, int $character_id ): array {
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}
		global $wpdb;
		$kg_db = BizCity_KG_Database::instance();
		$ids   = array();
		if ( '' !== $uuid ) {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT notebook_id FROM {$kg_db->tbl_notebook_character_attachments()} WHERE guru_uuid = %s",
				$uuid
			) ) ?: array();
		}
		if ( ! $ids && $character_id > 0 ) {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$kg_db->tbl_notebooks()} WHERE character_id = %d",
				$character_id
			) ) ?: array();
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/** @return array|WP_Error Raw character row. */
	private function load( int $id ) {
		if ( $id <= 0 ) {
			return $this->error( 'invalid_id', 'ID Guru không hợp lệ.', 400 );
		}
		if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return $this->error( 'module_not_loaded', 'Knowledge database chưa sẵn sàng.', 503 );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bizcity_characters WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return $this->error( 'not_found', 'Không tìm thấy Guru.', 404 );
		}
		return $row;
	}

	private function slug_taken( string $slug, int $exclude_id = 0 ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bizcity_characters WHERE slug = %s AND id <> %d",
			$slug,
			$exclude_id
		) ) > 0;
	}

	private function unique_slug( string $base, int $exclude_id = 0 ): string {
		$base = sanitize_title( $base );
		if ( ! $this->slug_taken( $base, $exclude_id ) ) {
			return $base;
		}
		for ( $i = 2; $i <= 100; $i++ ) {
			if ( ! $this->slug_taken( $base . '-' . $i, $exclude_id ) ) {
				return $base . '-' . $i;
			}
		}
		return $base . '-' . time();
	}

	/**
	 * Copy every knowledge source and chunk row of $from to $to, column for column except
	 * identity/ownership/timestamps, so scope columns added by later schema versions follow.
	 *
	 * @return int[] [ sources_copied, chunks_copied ]
	 */
	private function copy_knowledge( int $from, int $to ): array {
		global $wpdb;
		$src_tbl   = $wpdb->prefix . 'bizcity_knowledge_sources';
		$chunk_tbl = $wpdb->prefix . 'bizcity_knowledge_chunks';
		$now       = current_time( 'mysql' );
		$sources   = 0;
		$chunks    = 0;

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$src_tbl} WHERE character_id = %d ORDER BY id ASC", $from ), ARRAY_A ) ?: array();
		foreach ( $rows as $src ) {
			$old_source_id = (int) $src['id'];
			unset( $src['id'], $src['updated_at'] );
			$src['character_id'] = $to;
			$src['created_at']   = $now;
			if ( false === $wpdb->insert( $src_tbl, $src ) || ! $wpdb->insert_id ) {
				continue;
			}
			$new_source_id = (int) $wpdb->insert_id;
			$sources++;

			$chunk_rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$chunk_tbl} WHERE source_id = %d ORDER BY chunk_index ASC", $old_source_id ), ARRAY_A ) ?: array();
			foreach ( $chunk_rows as $chunk ) {
				unset( $chunk['id'] );
				$chunk['source_id']    = $new_source_id;
				$chunk['character_id'] = $to;
				$chunk['created_at']   = $now;
				if ( false !== $wpdb->insert( $chunk_tbl, $chunk ) ) {
					$chunks++;
				}
			}
		}
		return array( $sources, $chunks );
	}

	private function decode_json( $raw, $fallback ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		return is_array( $decoded ) ? $decoded : $fallback;
	}

	private function sanitize_deep( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ is_int( $k ) ? $k : sanitize_key( (string) $k ) ] = $this->sanitize_deep( $v );
			}
			return $out;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		return sanitize_textarea_field( (string) $value );
	}

	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
