<?php
/**
 * Content Artifact Service — CPT-backed My Content trace for Automation/Twin GPT.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Automation
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Content_Artifact_Service {

	const POST_TYPE = 'bizcity_ai_content';
	const TRACE_LIMIT = 80;
	private static $did_init = false;
	private static $syncing_scheduler_events = array();

	public static function init(): void {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — register durable customer content artifact CPT.
		if ( self::$did_init ) { return; }
		self::$did_init = true;
		add_action( 'init', array( __CLASS__, 'register_cpt' ), 5 );
		// [2026-07-21 Johnny Chu] PHASE-CPT-PROJECTION — project scheduler fb/web post events into My Content CPT for Channel Gateway + Twin GPT parity.
		add_action( 'bizcity_scheduler_event_created', array( __CLASS__, 'on_scheduler_event_created' ), 20, 2 );
		add_action( 'bizcity_scheduler_event_updated', array( __CLASS__, 'on_scheduler_event_updated' ), 20, 3 );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — automation/webhook/cron may load after init; register immediately too.
		self::ensure_post_type_registered();
	}

	public static function register_cpt(): void {
		register_post_type( self::POST_TYPE, array(
			'labels' => array(
				'name'          => 'BizCity AI Content',
				'singular_name' => 'BizCity AI Content',
			),
			'public'              => false,
			'show_ui'             => false, // [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — avoid current_user_can() during early webhook/cron CPT registration.
			'show_in_menu'        => false,
			'supports'            => array( 'title', 'editor', 'excerpt', 'author', 'thumbnail' ),
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'query_var'           => false,
			'rewrite'             => false,
			'can_export'          => false,
		) );
	}

	public static function create_or_get_from_ctx( array $ctx, array $args = array() ): int {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — correlate blocks by automation run_id.
		self::ensure_post_type_registered();
		$run_id = (string) ( $ctx['_run_id'] ?? '' );
		if ( $run_id !== '' ) {
			$found = self::find_by_meta( '_bizcity_run_id', $run_id );
			if ( $found > 0 ) { return $found; }
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — channel-linked wp_user_id owns My Plan content, not the workflow creator.
		$trigger_owner = (int) ( $ctx['trigger']['wp_user_id'] ?? $ctx['wp_user_id'] ?? 0 );
		$owner = $trigger_owner > 0 ? $trigger_owner : (int) ( $ctx['_owner_user_id'] ?? get_current_user_id() );
		if ( $owner <= 0 ) { $owner = 0; }

		$subject = self::extract_subject( (string) ( $ctx['trigger']['text'] ?? '' ) );
		$title = (string) ( $args['title'] ?? '' );
		if ( $title === '' ) {
			$title = $subject !== '' ? $subject : 'AI content ' . gmdate( 'Y-m-d H:i' );
		}

		$post_id = wp_insert_post( array(
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'private',
			'post_author'  => $owner,
			'post_title'   => wp_trim_words( wp_strip_all_tags( $title ), 16, '' ),
			'post_content' => '',
			'post_excerpt' => '',
		), true );

		if ( is_wp_error( $post_id ) || (int) $post_id <= 0 ) {
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — expose artifact creation failure in PHP log instead of silently losing trace.
			$error = is_wp_error( $post_id ) ? $post_id->get_error_message() : 'wp_insert_post returned empty ID';
			error_log( '[BizCity][MyContent] create_artifact_failed run_id=' . $run_id . ' owner=' . $owner . ' error=' . $error );
			return 0;
		}

		$post_id = (int) $post_id;
		self::patch_meta( $post_id, array(
			'_bizcity_content_uuid'    => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'bizcity_content_', true ) ),
			'_bizcity_owner_user_id'   => $owner,
			'_bizcity_surface'         => (string) ( $args['surface'] ?? 'automation' ),
			'_bizcity_origin_platform' => (string) ( $ctx['trigger']['platform'] ?? $ctx['trigger']['inbound']['platform'] ?? '' ),
			'_bizcity_origin_chat_id'  => (string) ( $ctx['trigger']['chat_id'] ?? $ctx['trigger']['inbound']['chat_id'] ?? '' ),
			'_bizcity_workflow_id'     => (int) ( $ctx['_workflow_id'] ?? 0 ),
			'_bizcity_run_id'          => $run_id,
			'_bizcity_content_type'    => (string) ( $args['content_type'] ?? 'content' ),
			'_bizcity_subject'         => $subject,
			'_bizcity_stage'           => 'created',
			'_bizcity_fb_publish_status' => 'not_requested',
		) );
		self::append_trace( $post_id, array(
			'stage'   => 'created',
			'source'  => 'automation',
			'status'  => 'ok',
			'message' => 'Content artifact created.',
			'ctx'     => array( 'run_id' => $run_id, 'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ) ),
		) );
		return $post_id;
	}

	public static function mark_stage( int $post_id, string $stage, array $meta = array() ): void {
		if ( $post_id <= 0 ) { return; }
		$meta['_bizcity_stage'] = sanitize_key( $stage );
		self::patch_meta( $post_id, $meta );
	}

	public static function patch_meta( int $post_id, array $meta ): void {
		if ( $post_id <= 0 ) { return; }
		foreach ( $meta as $key => $value ) {
			if ( strpos( (string) $key, '_bizcity_' ) !== 0 ) { continue; }
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
			update_post_meta( $post_id, (string) $key, is_string( $value ) ? wp_kses_post( $value ) : $value );
		}
	}

	public static function append_trace( int $post_id, array $event ): void {
		if ( $post_id <= 0 ) { return; }
		$trace = self::decode_json_meta( $post_id, '_bizcity_trace' );
		if ( ! is_array( $trace ) ) { $trace = array(); }
		$entry = array(
			'ts'      => current_time( 'mysql' ),
			'stage'   => sanitize_key( (string) ( $event['stage'] ?? get_post_meta( $post_id, '_bizcity_stage', true ) ) ),
			'source'  => sanitize_key( (string) ( $event['source'] ?? 'automation' ) ),
			'status'  => sanitize_key( (string) ( $event['status'] ?? 'info' ) ),
			'message' => sanitize_text_field( (string) ( $event['message'] ?? '' ) ),
		);
		foreach ( array( 'workflow_id', 'run_id', 'node_id', 'block_id', 'event_id', 'reason', 'error_code' ) as $key ) {
			if ( isset( $event[ $key ] ) ) { $entry[ $key ] = is_scalar( $event[ $key ] ) ? (string) $event[ $key ] : ''; }
		}
		if ( isset( $event['ctx'] ) && is_array( $event['ctx'] ) ) {
			$entry['ctx'] = self::sanitize_context( $event['ctx'] );
		}
		$trace[] = $entry;
		if ( count( $trace ) > self::TRACE_LIMIT ) {
			$trace = array_slice( $trace, -1 * self::TRACE_LIMIT );
		}
		update_post_meta( $post_id, '_bizcity_trace', wp_json_encode( $trace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	public static function find_by_run( string $run_id ): int {
		return $run_id !== '' ? self::find_by_meta( '_bizcity_run_id', $run_id ) : 0;
	}

	public static function find_by_scheduler_event( int $event_id ): int {
		return $event_id > 0 ? self::find_by_meta( '_bizcity_scheduler_event_id', (string) $event_id ) : 0;
	}

	public static function backfill_recent_scheduler_events( int $owner_user_id = 0, int $limit = 30 ): int {
		// [2026-07-21 Johnny Chu] PHASE-CPT-PROJECTION — lazy backfill existing scheduler-only FB/Web posts into CPT for My Content visibility.
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) { return 0; }
		global $wpdb;
		$mgr = BizCity_Scheduler_Manager::instance();
		$table = str_replace( '`', '', $mgr->get_table() );
		if ( ! self::table_exists( $table ) ) { return 0; }

		$where = "event_type IN ('fb_post','web_post')";
		$args = array();
		if ( $owner_user_id > 0 ) {
			$where .= ' AND user_id = %d';
			$args[] = $owner_user_id;
		}
		$args[] = max( 1, min( 80, $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE {$where} ORDER BY updated_at DESC, id DESC LIMIT %d", $args ), ARRAY_A );
		$count = 0;
		foreach ( (array) $rows as $row ) {
			$meta = self::decode_raw_meta( $row['metadata'] ?? '' );
			if ( ! empty( $meta['content_id'] ) && (int) $meta['content_id'] > 0 && self::resolve_artifact_for_scheduler_event( (int) ( $row['id'] ?? 0 ), $meta ) > 0 ) {
				continue;
			}
			if ( self::sync_from_scheduler_event( $row, 'scheduler_backfill' ) > 0 ) { $count++; }
		}
		return $count;
	}

	public static function enrich_scheduler_events_for_rest( array $events ): array {
		// [2026-07-21 Johnny Chu] PHASE-CPT-PROJECTION — make Scheduler REST rows expose artifact IDs immediately after lazy projection.
		foreach ( $events as $idx => $event ) {
			$row = self::event_to_array( $event );
			if ( empty( $row ) ) { continue; }
			$event_type = (string) ( $row['event_type'] ?? '' );
			if ( ! in_array( $event_type, array( 'fb_post', 'web_post' ), true ) ) { continue; }
			$meta = self::decode_raw_meta( $row['metadata'] ?? '' );
			$post_id = self::resolve_artifact_for_scheduler_event( (int) ( $row['id'] ?? 0 ), $meta );
			if ( $post_id <= 0 ) {
				$post_id = self::sync_from_scheduler_event( $row, 'scheduler_rest_projection' );
				$meta = self::decode_raw_meta( $row['metadata'] ?? '' );
			}
			if ( $post_id > 0 ) {
				$meta['content_id'] = $post_id;
				$uuid = (string) get_post_meta( $post_id, '_bizcity_content_uuid', true );
				if ( $uuid !== '' ) { $meta['content_uuid'] = $uuid; }
				if ( is_array( $events[ $idx ] ) ) {
					$events[ $idx ]['metadata'] = $meta;
				} elseif ( is_object( $events[ $idx ] ) ) {
					$events[ $idx ]->metadata = $meta;
				}
			}
		}
		return $events;
	}

	public static function on_scheduler_event_created( $event, $data = array() ): void {
		// [2026-07-21 Johnny Chu] PHASE-CPT-PROJECTION — scheduler is the canonical source for FB/Web publishing events, not only automation blocks.
		unset( $data );
		self::sync_from_scheduler_event( $event, 'scheduler_created' );
	}

	public static function on_scheduler_event_updated( $event, $old = null, $changed = array() ): void {
		// [2026-07-21 Johnny Chu] PHASE-CPT-PROJECTION — keep artifact status/permalink/error aligned after publisher updates scheduler metadata.
		unset( $old, $changed );
		self::sync_from_scheduler_event( $event, 'scheduler_updated' );
	}

	public static function sync_from_scheduler_event( $event, string $source = 'scheduler' ): int {
		// [2026-07-21 Johnny Chu] PHASE-CPT-PROJECTION — upsert one CPT artifact per scheduler fb_post/web_post event.
		self::ensure_post_type_registered();
		$row = self::event_to_array( $event );
		if ( empty( $row ) ) { return 0; }

		$event_type = (string) ( $row['event_type'] ?? '' );
		if ( ! in_array( $event_type, array( 'fb_post', 'web_post' ), true ) ) {
			return 0;
		}

		$event_id = (int) ( $row['id'] ?? 0 );
		if ( $event_id > 0 && ! empty( self::$syncing_scheduler_events[ $event_id ] ) ) {
			return 0;
		}

		$meta = self::decode_raw_meta( $row['metadata'] ?? '' );
		$post_id = self::resolve_artifact_for_scheduler_event( $event_id, $meta );
		$content = self::scheduler_content( $event_type, $meta );
		$subject = self::scheduler_subject( $row, $meta, $content );
		$owner   = (int) ( $row['user_id'] ?? $meta['owner_user_id'] ?? 0 );

		if ( $post_id <= 0 ) {
			$post_id = wp_insert_post( array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'private',
				'post_author'  => max( 0, $owner ),
				'post_title'   => wp_trim_words( wp_strip_all_tags( $subject !== '' ? $subject : (string) ( $row['title'] ?? 'AI content' ) ), 16, '' ),
				'post_content' => $content,
				'post_excerpt' => wp_trim_words( wp_strip_all_tags( $content ), 28, '...' ),
			), true );
			if ( is_wp_error( $post_id ) || (int) $post_id <= 0 ) {
				$error = is_wp_error( $post_id ) ? $post_id->get_error_message() : 'wp_insert_post returned empty ID';
				error_log( '[BizCity][MyContent] scheduler_projection_failed event_id=' . $event_id . ' error=' . $error );
				return 0;
			}
			$post_id = (int) $post_id;
		}

		$stage = self::stage_from_scheduler_event( $row, $meta, $event_type );
		$uuid = (string) get_post_meta( $post_id, '_bizcity_content_uuid', true );
		if ( $uuid === '' ) {
			$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'bizcity_content_', true ) );
		}

		self::patch_meta( $post_id, array(
			'_bizcity_content_uuid'       => $uuid,
			'_bizcity_owner_user_id'      => $owner,
			'_bizcity_surface'            => 'scheduler',
			'_bizcity_origin_platform'    => (string) ( $meta['inbound']['platform'] ?? '' ),
			'_bizcity_origin_chat_id'     => (string) ( $meta['inbound']['chat_id'] ?? '' ),
			'_bizcity_workflow_id'        => (int) ( $row['workflow_id'] ?? $meta['_workflow']['workflow_id'] ?? 0 ),
			'_bizcity_run_id'             => (string) ( $row['related_id'] ?? $meta['_workflow']['run_id'] ?? '' ),
			'_bizcity_content_type'       => $event_type,
			'_bizcity_subject'            => $subject,
			'_bizcity_stage'              => $stage,
			'_bizcity_scheduler_event_id' => $event_id,
			'_bizcity_caption'            => $content,
			'_bizcity_image_url'          => (string) ( $meta['fb_image_url'] ?? $meta['web_image_url'] ?? $meta['image_url'] ?? '' ),
			'_bizcity_fb_page_id'         => (string) ( $meta['fb_page_id'] ?? '' ),
			'_bizcity_fb_page_name'       => (string) ( $meta['fb_page_name'] ?? '' ),
			'_bizcity_fb_publish_status'  => (string) ( $meta['fb_publish_status'] ?? 'not_requested' ),
			'_bizcity_fb_post_id'         => (string) ( $meta['fb_post_id'] ?? '' ),
			'_bizcity_fb_permalink'       => (string) ( $meta['fb_permalink'] ?? '' ),
			'_bizcity_wp_post_id'         => (int) ( $meta['web_post_id'] ?? 0 ),
			'_bizcity_wp_edit_url'        => (string) ( $meta['web_edit_url'] ?? '' ),
			'_bizcity_error_code'         => $stage === 'failed' ? (string) ( $meta['fb_error_code'] ?? $meta['web_error_code'] ?? 'publish_failed' ) : '',
			'_bizcity_error_message'      => $stage === 'failed' ? (string) ( $meta['fb_error'] ?? $meta['web_error'] ?? '' ) : '',
		) );

		wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => $content,
			'post_excerpt' => wp_trim_words( wp_strip_all_tags( $content ), 28, '...' ),
		) );

		self::append_trace( $post_id, array(
			'stage' => $stage, 'source' => $source, 'status' => $stage === 'failed' ? 'fail' : 'ok', 'event_id' => $event_id,
			'message' => 'Scheduler event projected to My Content.',
			'ctx' => array( 'event_type' => $event_type, 'scheduler_status' => (string) ( $row['status'] ?? '' ), 'content_id' => $post_id ),
		) );

		if ( $event_id > 0 && ( empty( $meta['content_id'] ) || (int) $meta['content_id'] !== $post_id ) && class_exists( 'BizCity_Scheduler_Manager' ) ) {
			self::$syncing_scheduler_events[ $event_id ] = true;
			$meta['content_id'] = $post_id;
			$meta['content_uuid'] = $uuid;
			BizCity_Scheduler_Manager::instance()->update_event( $event_id, array( 'metadata' => $meta ), null );
			unset( self::$syncing_scheduler_events[ $event_id ] );
		}

		return $post_id;
	}

	public static function sweep_stuck_recent( int $owner_user_id = 0, int $limit = 80 ): int {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — read-time stuck detector for My Content MVP.
		self::ensure_post_type_registered();
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'private', 'draft', 'publish' ),
			'posts_per_page' => max( 1, min( 120, $limit ) ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array( 'key' => '_bizcity_stage', 'value' => array( 'content_generating', 'image_generating', 'fb_scheduling', 'fb_pending', 'fb_publishing' ), 'compare' => 'IN' ),
			),
		);
		if ( $owner_user_id > 0 ) {
			$args['author'] = $owner_user_id;
			$args['meta_query'][] = array( 'key' => '_bizcity_owner_user_id', 'value' => (string) $owner_user_id );
		}
		$q = new WP_Query( $args );
		$count = 0;
		foreach ( (array) $q->posts as $post ) {
			if ( $post instanceof WP_Post && self::mark_stuck_if_needed( $post ) ) { $count++; }
		}
		return $count;
	}

	public static function mark_stuck_if_needed( WP_Post $post ): bool {
		$post_id = (int) $post->ID;
		$stage = (string) get_post_meta( $post_id, '_bizcity_stage', true );
		$minutes = self::age_minutes( $post->post_modified_gmt ?: $post->post_date_gmt );
		$reason = '';
		if ( $stage === 'content_generating' && $minutes >= 3 ) { $reason = 'content_timeout'; }
		elseif ( $stage === 'image_generating' && $minutes >= 3 ) { $reason = 'image_timeout'; }
		elseif ( $stage === 'fb_scheduling' && $minutes >= 1 ) { $reason = 'scheduler_event_missing'; }
		elseif ( $stage === 'fb_pending' && $minutes >= 10 ) { $reason = 'publisher_not_claimed'; }
		elseif ( $stage === 'fb_publishing' && $minutes >= 3 ) { $reason = 'graph_publish_timeout'; }
		if ( $reason === '' ) { return false; }
		self::mark_stage( $post_id, 'failed', array( '_bizcity_error_code' => $reason, '_bizcity_error_message' => self::reason_message( $reason ) ) );
		self::append_trace( $post_id, array(
			'stage' => 'failed',
			'source' => 'my_content_stuck_detector',
			'status' => 'fail',
			'error_code' => $reason,
			'message' => self::reason_message( $reason ),
			'ctx' => array( 'previous_stage' => $stage, 'age_min' => $minutes ),
		) );
		return true;
	}

	public static function normalize_for_rest( WP_Post $post ): array {
		self::ensure_post_type_registered();
		$meta = self::all_meta( (int) $post->ID );
		return array(
			'id'          => (int) $post->ID,
			'uuid'        => (string) ( $meta['_bizcity_content_uuid'] ?? '' ),
			'type'        => (string) ( $meta['_bizcity_content_type'] ?? 'content' ),
			'stage'       => (string) ( $meta['_bizcity_stage'] ?? 'created' ),
			'subject'     => (string) ( $meta['_bizcity_subject'] ?? '' ),
			'caption'     => (string) ( $meta['_bizcity_caption'] ?? $post->post_content ),
			'image_url'   => (string) ( $meta['_bizcity_image_url'] ?? '' ),
			'created_at'  => (string) $post->post_date,
			'updated_at'  => (string) $post->post_modified,
			'workflow_id' => (int) ( $meta['_bizcity_workflow_id'] ?? 0 ),
			'run_id'      => (string) ( $meta['_bizcity_run_id'] ?? '' ),
			'wp'          => array(
				'post_id'  => (int) ( $meta['_bizcity_wp_post_id'] ?? 0 ),
				'edit_url' => (string) ( $meta['_bizcity_wp_edit_url'] ?? '' ),
			),
			'fb'          => array(
				'page_id'   => (string) ( $meta['_bizcity_fb_page_id'] ?? '' ),
				'page_name' => (string) ( $meta['_bizcity_fb_page_name'] ?? '' ),
				'status'    => (string) ( $meta['_bizcity_fb_publish_status'] ?? 'not_requested' ),
				'event_id'  => (int) ( $meta['_bizcity_scheduler_event_id'] ?? 0 ),
				'post_id'   => (string) ( $meta['_bizcity_fb_post_id'] ?? '' ),
				'permalink' => (string) ( $meta['_bizcity_fb_permalink'] ?? '' ),
			),
			'error'       => array(
				'code'    => (string) ( $meta['_bizcity_error_code'] ?? '' ),
				'message' => (string) ( $meta['_bizcity_error_message'] ?? '' ),
			),
			'trace'       => self::decode_json_meta( (int) $post->ID, '_bizcity_trace' ),
		);
	}

	private static function find_by_meta( string $key, string $value ): int {
		self::ensure_post_type_registered();
		$q = new WP_Query( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'private', 'draft', 'publish' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array( 'key' => $key, 'value' => $value ),
			),
		) );
		return ! empty( $q->posts[0] ) ? (int) $q->posts[0] : 0;
	}

	private static function table_exists( string $table ): bool {
		// [2026-07-21 Johnny Chu] PHASE-CPT-PROJECTION — information_schema check, no SHOW TABLES on multisite.
		if ( function_exists( 'bizcity_tbl_exists' ) ) { return bizcity_tbl_exists( $table ); }
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table
		) );
	}

	private static function resolve_artifact_for_scheduler_event( int $event_id, array $meta ): int {
		// [2026-07-21 Johnny Chu] PHASE-CPT-PROJECTION — prefer explicit metadata.content_id, fallback event id correlation.
		$post_id = (int) ( $meta['content_id'] ?? 0 );
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post && $post->post_type === self::POST_TYPE ) {
				return $post_id;
			}
		}
		return $event_id > 0 ? self::find_by_scheduler_event( $event_id ) : 0;
	}

	private static function event_to_array( $event ): array {
		// [2026-07-21 Johnny Chu] PHASE-CPT-PROJECTION — accept scheduler rows as object or array.
		if ( is_array( $event ) ) { return $event; }
		if ( is_object( $event ) ) { return (array) $event; }
		return array();
	}

	private static function decode_raw_meta( $raw ): array {
		// [2026-07-21 Johnny Chu] PHASE-CPT-PROJECTION — decode scheduler metadata column without requiring a post id.
		if ( is_array( $raw ) ) { return $raw; }
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return array();
	}

	private static function scheduler_content( string $event_type, array $meta ): string {
		// [2026-07-21 Johnny Chu] PHASE-CPT-PROJECTION — normalize event metadata content into artifact body.
		if ( $event_type === 'fb_post' ) {
			return (string) ( $meta['fb_content'] ?? $meta['content'] ?? '' );
		}
		return (string) ( $meta['web_content'] ?? $meta['content'] ?? '' );
	}

	private static function scheduler_subject( array $row, array $meta, string $content ): string {
		// [2026-07-21 Johnny Chu] PHASE-CPT-PROJECTION — derive compact My Content subject from metadata/title/body.
		$subject = (string) ( $meta['subject'] ?? $meta['web_title'] ?? '' );
		if ( $subject === '' ) {
			$title = (string) ( $row['title'] ?? '' );
			$subject = preg_replace( '/^\[automation\]\s*(?:FB|Web)\s*post\s*→\s*/iu', '', $title );
		}
		if ( $subject === '' ) {
			$subject = wp_trim_words( wp_strip_all_tags( $content ), 12, '' );
		}
		return trim( (string) $subject );
	}

	private static function stage_from_scheduler_event( array $row, array $meta, string $event_type ): string {
		// [2026-07-21 Johnny Chu] PHASE-CPT-PROJECTION — map scheduler status + publish metadata into My Content stages.
		$status = (string) ( $row['status'] ?? '' );
		if ( $status === 'failed' ) { return 'failed'; }
		if ( $status === 'cancelled' ) { return 'cancelled'; }
		if ( $event_type === 'fb_post' ) {
			$pub = (string) ( $meta['fb_publish_status'] ?? '' );
			if ( $pub === 'failed' ) { return 'failed'; }
			if ( $pub === 'published' || ! empty( $meta['fb_post_id'] ) || $status === 'done' ) { return 'fb_published'; }
			if ( $pub === 'publishing' ) { return 'fb_publishing'; }
			return 'fb_pending';
		}
		$web = (string) ( $meta['web_publish_status'] ?? '' );
		if ( $web === 'failed' ) { return 'failed'; }
		if ( $web === 'published' || ! empty( $meta['web_post_id'] ) || $status === 'done' ) { return 'web_published'; }
		return 'content_ready';
	}

	private static function age_minutes( string $gmt ): int {
		$ts = $gmt !== '' ? strtotime( $gmt . ' UTC' ) : 0;
		if ( ! $ts ) { return 0; }
		return max( 0, (int) floor( ( time() - $ts ) / 60 ) );
	}

	private static function reason_message( string $reason ): string {
		$map = array(
			'content_timeout' => 'Tạo nội dung quá lâu, chưa có kết quả.',
			'image_timeout' => 'Tạo ảnh quá lâu, chưa có kết quả.',
			'scheduler_event_missing' => 'Chưa tạo được lịch đăng Facebook sau khi yêu cầu publish.',
			'publisher_not_claimed' => 'Lịch đăng Facebook đã tạo nhưng publisher chưa xử lý.',
			'graph_publish_timeout' => 'Publisher đang đăng Facebook quá lâu, cần kiểm tra Graph/API.',
		);
		return isset( $map[ $reason ] ) ? $map[ $reason ] : 'Pipeline bị kẹt, cần kiểm tra trace.';
	}

	private static function all_meta( int $post_id ): array {
		$raw = get_post_meta( $post_id );
		$out = array();
		foreach ( $raw as $key => $vals ) {
			$out[ $key ] = is_array( $vals ) && isset( $vals[0] ) ? maybe_unserialize( $vals[0] ) : '';
		}
		return $out;
	}

	private static function ensure_post_type_registered(): void {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — late bootstrap guard for webhook/cron execution paths.
		if ( function_exists( 'post_type_exists' ) && ! post_type_exists( self::POST_TYPE ) ) {
			self::register_cpt();
		}
	}

	private static function decode_json_meta( int $post_id, string $key ): array {
		$raw = (string) get_post_meta( $post_id, $key, true );
		$decoded = $raw !== '' ? json_decode( $raw, true ) : array();
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function sanitize_context( array $ctx ): array {
		$out = array();
		foreach ( $ctx as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( $key === '' || preg_match( '/token|secret|password|sql/i', $key ) ) { continue; }
			if ( is_scalar( $value ) || $value === null ) {
				$out[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
			}
		}
		return $out;
	}

	private static function extract_subject( string $text ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		$text = preg_replace( '/^(?:@?đăng\s+fb|@?dang\s+fb|@?post\s+fb|@?đăng\s+facebook|@?dang\s+facebook|\/fb)\s*/iu', '', $text );
		return trim( (string) $text );
	}
}
