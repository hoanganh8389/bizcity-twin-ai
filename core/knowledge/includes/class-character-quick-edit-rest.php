<?php
/**
 * REST: Guru (Character) Quick-Edit surface.
 *
 * Designed for the Channel Gateway SPA — exposes a focused subset of the
 * full character-edit admin page so operators can tweak prompt / tone /
 * quick FAQ / attached notebooks inline from a dialog sheet without
 * jumping into wp-admin.
 *
 *   GET  /bizcity-knowledge/v1/characters/{id}/quick-edit
 *   POST /bizcity-knowledge/v1/characters/{id}/quick-edit
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @since      Phase 0.36 (2026-05-24)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Character_Quick_Edit_REST {

	const NS = 'bizcity-knowledge/v1';

	/** Stable wrapper used to round-trip a "tone" field through system_prompt. */
	const TONE_OPEN  = '<!-- BIZCITY_TONE_START -->';
	const TONE_CLOSE = '<!-- BIZCITY_TONE_END -->';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/characters/(?P<id>\d+)/quick-edit',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_payload' ),
					'permission_callback' => array( __CLASS__, 'can_edit' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_payload' ),
					'permission_callback' => array( __CLASS__, 'can_edit' ),
				),
			)
		);
	}

	public static function can_edit(): bool {
		return current_user_can( 'manage_options' );
	}

	/* ────────────────────────── GET ────────────────────────── */

	public static function get_payload( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		if ( $id <= 0 || ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return new WP_Error( 'invalid_id', 'invalid character id', array( 'status' => 400 ) );
		}

		$db        = BizCity_Knowledge_Database::instance();
		$character = $db->get_character( $id );
		if ( ! $character ) {
			return new WP_Error( 'not_found', 'character not found', array( 'status' => 404 ) );
		}

		$system_prompt = isset( $character->system_prompt ) ? (string) $character->system_prompt : '';
		$tone          = self::extract_tone( $system_prompt );
		$prompt_body   = self::strip_tone( $system_prompt );
		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W1 — expose runtime fields with server caps for Quick Sheet Tab B.
		$settings      = self::decode_settings( $character->settings ?? '' );
		$bounds        = self::runtime_bounds();
		$temperature   = isset( $settings['temperature'] )
			? (float) $settings['temperature']
			: ( isset( $character->creativity_level ) ? (float) $character->creativity_level : 0.7 );
		$max_tokens_raw = isset( $settings['max_tokens'] )
			? (int) $settings['max_tokens']
			: ( isset( $character->max_tokens ) ? (int) $character->max_tokens : 1000 );
		$temperature   = max( (float) $bounds['temperature']['min'], min( (float) $bounds['temperature']['max'], $temperature ) );
		$max_tokens    = max( (int) $bounds['max_output_tokens']['min'], min( (int) $bounds['max_output_tokens']['max'], $max_tokens_raw ) );

		// Quick FAQ rows live in bizcity_knowledge_sources where source_type='quick_faq'.
		$quick_faq = array();
		$sources   = $db->get_knowledge_sources( $id );
		if ( is_array( $sources ) ) {
			foreach ( $sources as $src ) {
				if ( ( $src->source_type ?? '' ) !== 'quick_faq' ) {
					continue;
				}
				$raw   = isset( $src->content ) ? (string) $src->content : '';
				$json  = $raw !== '' ? json_decode( $raw, true ) : null;
				$title = is_array( $json ) && isset( $json['title'] ) ? (string) $json['title'] : (string) ( $src->source_name ?? '' );
				$body  = is_array( $json ) && isset( $json['content'] ) ? (string) $json['content'] : $raw;
				$quick_faq[] = array(
					'id'      => (int) $src->id,
					'title'   => $title,
					'content' => $body,
				);
			}
		}

		$notebooks_attached  = array();
		$notebooks_available = array();
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			global $wpdb;
			$tbl_nb              = BizCity_KG_Database::instance()->tbl_notebooks();
			$notebooks_attached  = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, description, updated_at
				   FROM {$tbl_nb}
				  WHERE character_id = %d
				  ORDER BY updated_at DESC
				  LIMIT 200",
				$id
			), ARRAY_A ) ?: array();

			$notebooks_available = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, character_id, updated_at
				   FROM {$tbl_nb}
				  WHERE ( character_id IS NULL OR character_id = 0 OR character_id != %d )
				  ORDER BY updated_at DESC
				  LIMIT 200",
				$id
			), ARRAY_A ) ?: array();
			// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W1 — enrich attached notebooks with KG stats for Tab D bridge.
			$notebooks_attached = self::enrich_notebooks_with_stats( $notebooks_attached );
		}

		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W1 — expose quick training sources/status for Tab C.
		$quick_training = self::build_training_payload( $id );

		return rest_ensure_response( array(
			'success'             => true,
			'character'           => array(
				'id'            => (int) $character->id,
				'name'          => (string) $character->name,
				'slug'          => (string) ( $character->slug ?? '' ),
				'avatar'        => (string) ( $character->avatar ?? '' ),
				'system_prompt' => $prompt_body,
				'tone'          => $tone,
				'edit_url'      => admin_url( 'admin.php?page=bizcity-knowledge-character-edit&id=' . $id ),
			),
			'runtime'             => array(
				'temperature'       => $temperature,
				'max_output_tokens' => $max_tokens,
			),
			'runtime_bounds'      => $bounds,
			'quick_faq'           => $quick_faq,
			'quick_training'      => $quick_training,
			'notebooks_attached'  => array_map( array( __CLASS__, 'normalize_nb' ), $notebooks_attached ),
			'notebooks_available' => array_map( array( __CLASS__, 'normalize_nb' ), $notebooks_available ),
		) );
	}

	/* ────────────────────────── POST ────────────────────────── */

	public static function save_payload( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		if ( $id <= 0 || ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return new WP_Error( 'invalid_id', 'invalid character id', array( 'status' => 400 ) );
		}

		$db        = BizCity_Knowledge_Database::instance();
		$character = $db->get_character( $id );
		if ( ! $character ) {
			return new WP_Error( 'not_found', 'character not found', array( 'status' => 404 ) );
		}

		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $req->get_params();
		}

		$updated = array();
		$settings = self::decode_settings( $character->settings ?? '' );

		// 1) system_prompt + tone — round-trip via marker block.
		$has_prompt = array_key_exists( 'system_prompt', $params );
		$has_tone   = array_key_exists( 'tone', $params );
		if ( $has_prompt || $has_tone ) {
			$prompt_body = $has_prompt
				? wp_kses_post( (string) $params['system_prompt'] )
				: self::strip_tone( (string) ( $character->system_prompt ?? '' ) );
			$tone_value  = $has_tone
				? sanitize_textarea_field( (string) $params['tone'] )
				: self::extract_tone( (string) ( $character->system_prompt ?? '' ) );

			$merged = self::merge_prompt_and_tone( $prompt_body, $tone_value );
			$db->update_character( $id, array( 'system_prompt' => $merged ) );
			$updated['system_prompt'] = true;
		}

		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W1 — save bounded runtime fields from Quick Sheet Tab B.
		if ( isset( $params['runtime'] ) && is_array( $params['runtime'] ) ) {
			$runtime = (array) $params['runtime'];
			$bounds  = self::runtime_bounds();

			if ( array_key_exists( 'temperature', $runtime ) ) {
				$temperature = (float) $runtime['temperature'];
				$temperature = max( (float) $bounds['temperature']['min'], min( (float) $bounds['temperature']['max'], $temperature ) );
				$db->update_character( $id, array( 'creativity_level' => $temperature ) );
				$settings['temperature'] = $temperature;
				$updated['runtime_temperature'] = $temperature;
			}

			if ( array_key_exists( 'max_output_tokens', $runtime ) ) {
				$max_tokens = (int) $runtime['max_output_tokens'];
				$max_tokens = max( (int) $bounds['max_output_tokens']['min'], min( (int) $bounds['max_output_tokens']['max'], $max_tokens ) );
				$settings['max_tokens'] = $max_tokens;
				$updated['runtime_max_output_tokens'] = $max_tokens;
				// Update nullable max_tokens column when schema has it; keep settings fallback always.
				if ( isset( $character->max_tokens ) ) {
					$db->update_character( $id, array( 'max_tokens' => $max_tokens ) );
				}
			}

			$db->update_character( $id, array( 'settings' => wp_json_encode( $settings, JSON_UNESCAPED_UNICODE ) ) );
		}

		// 2) quick_faq — full replace semantics (mirrors save_quick_knowledge in admin menu).
		if ( isset( $params['quick_faq'] ) && is_array( $params['quick_faq'] ) ) {
			$result = self::save_quick_faq( $id, $params['quick_faq'] );
			$updated['quick_faq'] = $result;
		}

		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W1 — handle Quick Training ingest/retry via canonical Knowledge Fabric.
		if ( isset( $params['quick_training'] ) && is_array( $params['quick_training'] ) ) {
			$updated['quick_training'] = self::handle_quick_training_actions( $id, (array) $params['quick_training'] );
		}

		// 3) Notebook attach (idempotent).
		if ( isset( $params['attach_notebook_ids'] ) && is_array( $params['attach_notebook_ids'] ) && class_exists( 'BizCity_KG_Database' ) ) {
			global $wpdb;
			$tbl_nb  = BizCity_KG_Database::instance()->tbl_notebooks();
			$ids_in  = array_filter( array_map( 'intval', $params['attach_notebook_ids'] ) );
			$attached = array();
			foreach ( $ids_in as $nb_id ) {
				$wpdb->update( $tbl_nb, array( 'character_id' => $id ), array( 'id' => $nb_id ) );
				$attached[] = (int) $nb_id;
			}
			$updated['attached_notebooks'] = $attached;
		}

		// 4) Notebook detach (only when notebook is currently bound to THIS character).
		if ( isset( $params['detach_notebook_ids'] ) && is_array( $params['detach_notebook_ids'] ) && class_exists( 'BizCity_KG_Database' ) ) {
			global $wpdb;
			$tbl_nb   = BizCity_KG_Database::instance()->tbl_notebooks();
			$ids_in   = array_filter( array_map( 'intval', $params['detach_notebook_ids'] ) );
			$detached = array();
			foreach ( $ids_in as $nb_id ) {
				$wpdb->update( $tbl_nb, array( 'character_id' => null ), array( 'id' => $nb_id, 'character_id' => $id ) );
				$detached[] = (int) $nb_id;
			}
			$updated['detached_notebooks'] = $detached;
		}

		// Return a fresh payload so the SPA can refresh local state in one round-trip.
		$fresh = self::get_payload( $req );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}
		$data            = $fresh->get_data();
		$data['updated'] = $updated;
		return rest_ensure_response( $data );
	}

	/* ────────────────────────── Helpers ────────────────────────── */

	private static function normalize_nb( $row ) {
		if ( ! is_array( $row ) ) {
			$row = (array) $row;
		}
		return array(
			'id'           => (int) ( $row['id'] ?? 0 ),
			'name'         => (string) ( $row['name'] ?? '' ),
			'description'  => (string) ( $row['description'] ?? '' ),
			'character_id' => isset( $row['character_id'] ) ? (int) $row['character_id'] : 0,
			'sources_count'=> isset( $row['sources_count'] ) ? (int) $row['sources_count'] : 0,
			'passages_count'=> isset( $row['passages_count'] ) ? (int) $row['passages_count'] : 0,
			'health'       => (string) ( $row['health'] ?? 'unknown' ),
			'last_indexed_at' => (string) ( $row['last_indexed_at'] ?? '' ),
			'updated_at'   => (string) ( $row['updated_at'] ?? '' ),
		);
	}

	/**
	 * Add stats badges for attached notebooks in Quick Sheet Tab D.
	 *
	 * @param array $rows
	 * @return array
	 */
	private static function enrich_notebooks_with_stats( $rows ) {
		if ( ! is_array( $rows ) || empty( $rows ) || ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return is_array( $rows ) ? $rows : array();
		}

		$service = BizCity_KG_Notebook_Service::instance();
		foreach ( $rows as $idx => $row ) {
			$nb_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $nb_id <= 0 ) {
				continue;
			}
			$nb = $service->get( $nb_id );
			if ( ! is_array( $nb ) ) {
				continue;
			}
			$stats = isset( $nb['stats'] ) && is_array( $nb['stats'] ) ? $nb['stats'] : array();
			$sources_count  = (int) ( $stats['sources'] ?? 0 );
			$passages_count = (int) ( $stats['passages'] ?? 0 );

			$rows[ $idx ]['sources_count']  = $sources_count;
			$rows[ $idx ]['passages_count'] = $passages_count;
			$rows[ $idx ]['last_indexed_at'] = (string) ( $row['updated_at'] ?? '' );
			$rows[ $idx ]['health'] = $passages_count > 0 ? 'ready' : ( $sources_count > 0 ? 'processing' : 'empty' );
		}

		return $rows;
	}

	/**
	 * Build Quick Training payload for Tab C.
	 *
	 * @param int $character_id
	 * @return array
	 */
	private static function build_training_payload( $character_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_knowledge_sources';
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, source_type, source_name, source_url, chunks_count, status, error_message, last_synced_at, created_at, updated_at
			   FROM {$table}
			  WHERE character_id = %d AND source_type != 'quick_faq'
			  ORDER BY updated_at DESC
			  LIMIT 50",
			(int) $character_id
		), ARRAY_A ) ?: array();

		$summary = array(
			'total'      => 0,
			'ready'      => 0,
			'processing' => 0,
			'pending'    => 0,
			'failed'     => 0,
		);
		$items       = array();
		$last_trained = '';

		foreach ( $rows as $row ) {
			$raw_status = (string) ( $row['status'] ?? '' );
			$status     = self::map_training_status( $raw_status );
			$summary['total']++;
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}

			$last_synced = (string) ( $row['last_synced_at'] ?? '' );
			if ( $last_synced !== '' && strcmp( $last_synced, $last_trained ) > 0 ) {
				$last_trained = $last_synced;
			}

			$items[] = array(
				'id'            => (int) ( $row['id'] ?? 0 ),
				'source_type'   => (string) ( $row['source_type'] ?? '' ),
				'source_name'   => (string) ( $row['source_name'] ?? '' ),
				'source_url'    => (string) ( $row['source_url'] ?? '' ),
				'chunks_count'  => (int) ( $row['chunks_count'] ?? 0 ),
				'status'        => $status,
				'raw_status'    => $raw_status,
				'error_message' => (string) ( $row['error_message'] ?? '' ),
				'last_synced_at'=> $last_synced,
				'created_at'    => (string) ( $row['created_at'] ?? '' ),
				'updated_at'    => (string) ( $row['updated_at'] ?? '' ),
			);
		}

		return array(
			'sources'       => $items,
			'summary'       => $summary,
			'last_trained_at' => $last_trained,
			'source_types'  => array( 'text', 'url', 'file', 'manual' ),
		);
	}

	/**
	 * Normalize storage status enum for UI display.
	 *
	 * @param string $status
	 * @return string
	 */
	private static function map_training_status( $status ) {
		$status = sanitize_key( (string) $status );
		if ( $status === 'error' ) {
			return 'failed';
		}
		if ( in_array( $status, array( 'pending', 'processing', 'ready', 'failed' ), true ) ) {
			return $status;
		}
		return 'pending';
	}

	/**
	 * Handle Quick Training actions from Tab C.
	 *
	 * @param int   $character_id
	 * @param array $actions
	 * @return array
	 */
	private static function handle_quick_training_actions( $character_id, $actions ) {
		$out = array(
			'ingested' => array(),
			'retried'  => array(),
			'failed'   => array(),
		);

		if ( isset( $actions['ingest'] ) && is_array( $actions['ingest'] ) ) {
			$ingest = self::ingest_training_source( (int) $character_id, (array) $actions['ingest'] );
			if ( is_wp_error( $ingest ) ) {
				$out['failed'][] = array(
					'code'    => (string) $ingest->get_error_code(),
					'message' => (string) $ingest->get_error_message(),
				);
			} else {
				$out['ingested'][] = $ingest;
			}
		}

		if ( isset( $actions['retry_source_ids'] ) && is_array( $actions['retry_source_ids'] ) ) {
			$retry_ids = array_filter( array_map( 'intval', $actions['retry_source_ids'] ) );
			foreach ( $retry_ids as $source_id ) {
				$retry = self::retry_training_source( (int) $character_id, (int) $source_id );
				if ( is_wp_error( $retry ) ) {
					$out['failed'][] = array(
						'source_id' => (int) $source_id,
						'code'      => (string) $retry->get_error_code(),
						'message'   => (string) $retry->get_error_message(),
					);
				} else {
					$out['retried'][] = $retry;
				}
			}
		}

		return $out;
	}

	/**
	 * Ingest one training source via canonical Knowledge Fabric.
	 *
	 * @param int   $character_id
	 * @param array $ingest
	 * @return array|WP_Error
	 */
	private static function ingest_training_source( $character_id, $ingest ) {
		if ( ! class_exists( 'BizCity_Knowledge_Fabric' ) ) {
			return new WP_Error( 'fabric_missing', 'Knowledge Fabric chưa load.' );
		}

		$source_type = isset( $ingest['source_type'] ) ? sanitize_key( (string) $ingest['source_type'] ) : 'text';
		if ( ! in_array( $source_type, array( 'text', 'url', 'file', 'manual' ), true ) ) {
			$source_type = 'text';
		}

		$params = array(
			'source_type'  => $source_type,
			'scope'        => 'agent',
			'character_id' => (int) $character_id,
			'user_id'      => (int) get_current_user_id(),
			'source_name'  => isset( $ingest['source_name'] ) ? sanitize_text_field( (string) $ingest['source_name'] ) : '',
			'scrape_type'  => isset( $ingest['scrape_type'] ) ? sanitize_key( (string) $ingest['scrape_type'] ) : 'simple_html',
		);

		if ( $source_type === 'url' ) {
			$params['url'] = isset( $ingest['url'] ) ? esc_url_raw( (string) $ingest['url'] ) : '';
		} elseif ( $source_type === 'file' ) {
			$params['attachment_id'] = isset( $ingest['attachment_id'] ) ? (int) $ingest['attachment_id'] : 0;
		} else {
			$params['content'] = isset( $ingest['content'] ) ? sanitize_textarea_field( (string) $ingest['content'] ) : '';
		}

		$result = BizCity_Knowledge_Fabric::instance()->ingest( $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'source_id'    => (int) ( $result['source_id'] ?? 0 ),
			'source_name'  => (string) ( $result['source_name'] ?? '' ),
			'source_type'  => (string) $source_type,
			'chunks_count' => (int) ( $result['chunks_count'] ?? 0 ),
			'status'       => 'ready',
		);
	}

	/**
	 * Retry a failed source by re-ingesting from stored source data.
	 *
	 * @param int $character_id
	 * @param int $source_id
	 * @return array|WP_Error
	 */
	private static function retry_training_source( $character_id, $source_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_knowledge_sources';

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d AND character_id = %d LIMIT 1",
			(int) $source_id,
			(int) $character_id
		), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return new WP_Error( 'source_not_found', 'Không tìm thấy source để retry.' );
		}

		$settings = array();
		if ( ! empty( $row['settings'] ) && is_string( $row['settings'] ) ) {
			$decoded = json_decode( $row['settings'], true );
			if ( is_array( $decoded ) ) {
				$settings = $decoded;
			}
		}

		$ingest = array(
			'source_type'   => (string) ( $row['source_type'] ?? 'text' ),
			'source_name'   => (string) ( $row['source_name'] ?? '' ),
			'url'           => (string) ( $row['source_url'] ?? '' ),
			'content'       => (string) ( $row['content'] ?? '' ),
			'attachment_id' => isset( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0,
			'scrape_type'   => isset( $settings['scrape_type'] ) ? (string) $settings['scrape_type'] : 'simple_html',
		);

		$reingest = self::ingest_training_source( (int) $character_id, $ingest );
		if ( is_wp_error( $reingest ) ) {
			return $reingest;
		}

		if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
			BizCity_Knowledge_Database::instance()->delete_source_and_chunks( (int) $source_id );
		}

		return array(
			'old_source_id' => (int) $source_id,
			'new_source_id' => (int) ( $reingest['source_id'] ?? 0 ),
			'status'        => 'ready',
		);
	}

	/**
	 * Decode character settings JSON to associative array.
	 *
	 * @param mixed $settings_raw Raw settings value.
	 * @return array
	 */
	private static function decode_settings( $settings_raw ) {
		if ( is_array( $settings_raw ) ) {
			return $settings_raw;
		}
		if ( is_string( $settings_raw ) && $settings_raw !== '' ) {
			$decoded = json_decode( $settings_raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}

	/**
	 * Runtime bounds for quick-edit sheet.
	 *
	 * @return array
	 */
	private static function runtime_bounds() {
		$bounds = array(
			'temperature' => array(
				'min' => 0.0,
				'max' => 1.0,
			),
			'max_output_tokens' => array(
				'min' => 128,
				'max' => 4096,
			),
		);
		$filtered = apply_filters( 'bizcity_character_quick_edit_runtime_bounds', $bounds );
		return is_array( $filtered ) ? $filtered : $bounds;
	}

	private static function extract_tone( $system_prompt ) {
		if ( strpos( $system_prompt, self::TONE_OPEN ) === false ) {
			return '';
		}
		$pattern = '/' . preg_quote( self::TONE_OPEN, '/' ) . '\s*(?:##\s*[^\n]*\n)?([\s\S]*?)\s*' . preg_quote( self::TONE_CLOSE, '/' ) . '/';
		if ( preg_match( $pattern, $system_prompt, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	private static function strip_tone( $system_prompt ) {
		if ( strpos( $system_prompt, self::TONE_OPEN ) === false ) {
			return $system_prompt;
		}
		$pattern = '/\n*' . preg_quote( self::TONE_OPEN, '/' ) . '[\s\S]*?' . preg_quote( self::TONE_CLOSE, '/' ) . '\n*/';
		$stripped = preg_replace( $pattern, '', $system_prompt );
		return is_string( $stripped ) ? rtrim( $stripped ) : $system_prompt;
	}

	private static function merge_prompt_and_tone( $prompt_body, $tone_value ) {
		$prompt_body = rtrim( (string) $prompt_body );
		$tone_value  = trim( (string) $tone_value );
		if ( $tone_value === '' ) {
			return $prompt_body;
		}
		return $prompt_body
			. "\n\n" . self::TONE_OPEN
			. "\n## Giọng điệu\n" . $tone_value . "\n"
			. self::TONE_CLOSE . "\n";
	}

	/**
	 * Replace-all save for quick FAQ rows attached to a character.
	 *
	 * Mirrors BizCity_Knowledge_Admin_Menu::save_quick_knowledge() but keeps a
	 * tight contract for the SPA: any row with `id` present + still in the
	 * incoming list = update; missing id = insert; rows previously present
	 * but absent from the payload = delete.
	 *
	 * @param int   $character_id
	 * @param array $entries  list of {id?, title, content}
	 * @return array{created:int[],updated:int[],deleted:int[]}
	 */
	private static function save_quick_faq( $character_id, $entries ) {
		global $wpdb;
		$table        = $wpdb->prefix . 'bizcity_knowledge_sources';
		$submitted    = array();
		$out          = array( 'created' => array(), 'updated' => array(), 'deleted' => array() );
		$character_id = (int) $character_id;

		foreach ( $entries as $entry ) {
			$title = isset( $entry['title'] ) ? sanitize_text_field( (string) $entry['title'] ) : '';
			$body  = isset( $entry['content'] ) ? sanitize_textarea_field( (string) $entry['content'] ) : '';
			if ( $title === '' && $body === '' ) {
				continue;
			}
			$source_id = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
			$content   = wp_json_encode( array( 'title' => $title, 'content' => $body ), JSON_UNESCAPED_UNICODE );

			if ( $source_id > 0 ) {
				$wpdb->update(
					$table,
					array(
						'content'      => $content,
						'content_hash' => md5( $content ),
						'source_name'  => $title !== '' ? $title : 'Quick Knowledge',
						'status'       => 'ready',
					),
					array( 'id' => $source_id, 'character_id' => $character_id ),
					array( '%s', '%s', '%s', '%s' ),
					array( '%d', '%d' )
				);
				$submitted[]       = $source_id;
				$out['updated'][]  = $source_id;
			} else {
				$wpdb->insert(
					$table,
					array(
						'character_id' => $character_id,
						'source_type'  => 'quick_faq',
						'source_name'  => $title !== '' ? $title : 'Quick Knowledge',
						'content'      => $content,
						'content_hash' => md5( $content ),
						'status'       => 'ready',
						'created_at'   => current_time( 'mysql' ),
						'updated_at'   => current_time( 'mysql' ),
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
				$new_id            = (int) $wpdb->insert_id;
				if ( $new_id > 0 ) {
					$submitted[]      = $new_id;
					$out['created'][] = $new_id;
				}
			}
		}

		// Delete previously existing quick_faq rows for this character that
		// are NOT in the submitted set.
		$existing = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE character_id = %d AND source_type = 'quick_faq'",
			$character_id
		) );
		$existing = array_map( 'intval', (array) $existing );
		$to_delete = array_values( array_diff( $existing, $submitted ) );
		if ( ! empty( $to_delete ) ) {
			$ids_sql = implode( ',', array_map( 'intval', $to_delete ) );
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids_sql})" );
			$out['deleted'] = $to_delete;
		}

		return $out;
	}
}

BizCity_Character_Quick_Edit_REST::init();
