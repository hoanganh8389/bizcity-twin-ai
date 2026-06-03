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
		}

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
			'quick_faq'           => $quick_faq,
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

		// 2) quick_faq — full replace semantics (mirrors save_quick_knowledge in admin menu).
		if ( isset( $params['quick_faq'] ) && is_array( $params['quick_faq'] ) ) {
			$result = self::save_quick_faq( $id, $params['quick_faq'] );
			$updated['quick_faq'] = $result;
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
			'updated_at'   => (string) ( $row['updated_at'] ?? '' ),
		);
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
