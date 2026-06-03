<?php
/**
 * Channel Gateway — Web Post Publisher
 *
 * Bridge between core/scheduler and WordPress wp_insert_post().
 *
 * Listens to `bizcity_scheduler_reminder_fire` (fired by scheduler cron). When
 * the event's `event_type` is `web_post`, it pulls title/content/image from
 * metadata, creates a WP post owned by `event.user_id`, then writes the
 * result back to the event's metadata.
 *
 * Contract (event_type='web_post' metadata fields — see
 * core/diagnostics/changelog/core.scheduler.json v3.2.0):
 *   web_title          Required. Post title. Falls back to event.title.
 *   web_content        Required. Post body (HTML allowed; passed through
 *                       wp_kses_post in case caller didn't sanitise).
 *   web_excerpt        Optional. Post excerpt.
 *   web_status         Optional. publish|draft|pending|future (default publish).
 *   web_category_ids   Optional. Array of term IDs.
 *   web_tag_names      Optional. Array of tag slugs/names (wp_set_post_tags).
 *   web_image_url      Optional. Featured image URL (sideloaded).
 *   web_notebook_id    Optional. Notebook used for content generation (audit).
 *   web_skeleton_version Optional. KG skeleton version snapshot at compose.
 *   web_publish_status  pending|publishing|published|failed|cancelled
 *   web_post_id         Filled after publish.
 *   web_permalink       Filled after publish.
 *   web_edit_link       Filled after publish.
 *   web_error           Filled on failure.
 *
 * Idempotency: skip if metadata.web_post_id already set, or if status !== 'active',
 * or if web_publish_status not in (pending, failed).
 *
 * R-DCL compliant: contract addition in core.scheduler.json v3.2.0. No schema delta.
 * R-CRON-META compliant: web_publish_attempt / web_publish_ok / web_publish_failed.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.5.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Web_Post_Publisher {

	const HOOK_PRIORITY = 25; // After FB (20) so a single cron tick handles both deterministically.

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function init(): void {
		add_action(
			'bizcity_scheduler_reminder_fire',
			array( self::instance(), 'on_reminder_fire' ),
			self::HOOK_PRIORITY,
			1
		);
	}

	/**
	 * Reminder callback. Returns silently for non-web_post events.
	 *
	 * @param array|object $event Event row.
	 */
	public function on_reminder_fire( $event ): void {
		$event = is_object( $event ) ? (array) $event : (array) $event;
		if ( empty( $event['id'] ) ) {
			return;
		}
		if ( ( $event['event_type'] ?? '' ) !== 'web_post' ) {
			return;
		}
		if ( ( $event['status'] ?? '' ) !== 'active' ) {
			return;
		}

		$meta = $this->decode_metadata( $event['metadata'] ?? '' );

		// Idempotency guards.
		if ( ! empty( $meta['web_post_id'] ) ) {
			return;
		}
		$publish_status = (string) ( $meta['web_publish_status'] ?? 'pending' );
		if ( ! in_array( $publish_status, array( 'pending', 'failed' ), true ) ) {
			return;
		}

		$event_id = (int) $event['id'];
		$author   = (int) ( $event['user_id'] ?? 0 );
		$title    = (string) ( $meta['web_title'] ?? ( $event['title'] ?? '' ) );
		$content  = (string) ( $meta['web_content'] ?? '' );
		$image    = (string) ( $meta['web_image_url'] ?? '' );

		$this->cron_note_event( 'web_publish_attempt', array(
			'event_id'    => $event_id,
			'author'      => $author,
			'title_len'   => strlen( $title ),
			'content_len' => strlen( $content ),
			'has_image'   => $image !== '',
			'notebook_id' => (int) ( $meta['web_notebook_id'] ?? 0 ),
			'prev_status' => $publish_status,
			'is_retry'    => $publish_status === 'failed',
		) );

		if ( $title === '' || $content === '' ) {
			$err = 'Missing web_title or web_content in metadata.';
			$this->mark_failed( $event_id, $meta, $err );
			$this->cron_note_event( 'web_publish_failed', array(
				'event_id' => $event_id,
				'reason'   => 'invalid_metadata',
				'error'    => $err,
			) );
			$this->cron_bump_counter( 'web_failed' );
			return;
		}

		if ( $author <= 0 ) {
			$err = 'Missing user_id on scheduler event — cannot determine post_author.';
			$this->mark_failed( $event_id, $meta, $err );
			$this->cron_note_event( 'web_publish_failed', array(
				'event_id' => $event_id,
				'reason'   => 'invalid_param',
				'error'    => $err,
			) );
			$this->cron_bump_counter( 'web_failed' );
			return;
		}

		// Mark publishing (claim).
		$meta['web_publish_status'] = 'publishing';
		unset( $meta['web_error'] );
		$this->write_metadata( $event_id, $meta );

		do_action( 'bizcity_web_post_publish_start', $event_id, $event );

		$result = $this->insert_post( $author, $title, $content, $meta );

		if ( is_wp_error( $result ) ) {
			$err_code = $result->get_error_code();
			$err_msg  = $result->get_error_message();
			$this->mark_failed( $event_id, $meta, $err_msg );
			$this->cron_note_event( 'web_publish_failed', array(
				'event_id' => $event_id,
				'reason'   => $err_code ?: 'wp_insert_error',
				'error'    => $err_msg,
			) );
			$this->cron_bump_counter( 'web_failed' );
			return;
		}

		$post_id   = (int) $result['post_id'];
		$permalink = (string) $result['permalink'];
		$edit_link = (string) $result['edit_link'];

		// Sideload featured image (best-effort).
		if ( $image !== '' ) {
			$thumb = $this->sideload_featured_image( $post_id, $image );
			if ( is_wp_error( $thumb ) ) {
				$this->cron_note_event( 'web_image_sideload_failed', array(
					'event_id' => $event_id,
					'post_id'  => $post_id,
					'reason'   => $thumb->get_error_code() ?: 'sideload_error',
					'error'    => $thumb->get_error_message(),
				) );
			}
		}

		$meta['web_publish_status'] = 'published';
		$meta['web_post_id']        = $post_id;
		$meta['web_permalink']      = $permalink;
		$meta['web_edit_link']      = $edit_link;
		unset( $meta['web_error'] );

		$this->write_metadata( $event_id, $meta, 'done' );

		$this->cron_note_event( 'web_publish_ok', array(
			'event_id'  => $event_id,
			'post_id'   => $post_id,
			'permalink' => $permalink,
		) );
		$this->cron_bump_counter( 'web_published' );

		do_action( 'bizcity_web_post_published', $event_id, $post_id, $permalink );
	}

	/**
	 * Insert WP post. Returns {post_id, permalink, edit_link} or WP_Error.
	 */
	protected function insert_post( int $author, string $title, string $content, array $meta ) {
		$status_in = (string) ( $meta['web_status'] ?? 'publish' );
		$allowed   = array( 'publish', 'draft', 'pending', 'future', 'private' );
		$status    = in_array( $status_in, $allowed, true ) ? $status_in : 'publish';

		$postarr = array(
			'post_title'   => wp_strip_all_tags( $title ),
			'post_content' => wp_kses_post( $content ),
			'post_status'  => $status,
			'post_author'  => $author,
			'post_type'    => 'post',
		);
		if ( ! empty( $meta['web_excerpt'] ) ) {
			$postarr['post_excerpt'] = wp_kses_post( (string) $meta['web_excerpt'] );
		}
		if ( ! empty( $meta['web_category_ids'] ) && is_array( $meta['web_category_ids'] ) ) {
			$postarr['post_category'] = array_map( 'intval', $meta['web_category_ids'] );
		}

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( ! $post_id ) {
			return new WP_Error( 'wp_insert_empty', 'wp_insert_post returned 0.' );
		}

		// Tags (separate call so wp_insert_post errors don't drop tag attach).
		if ( ! empty( $meta['web_tag_names'] ) && is_array( $meta['web_tag_names'] ) ) {
			wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $meta['web_tag_names'] ), false );
		}

		// Audit meta — link back to scheduler row + KG skeleton snapshot.
		update_post_meta( $post_id, '_bizcity_scheduler_event_id', (int) ( $meta['_event_id'] ?? 0 ) );
		if ( ! empty( $meta['web_notebook_id'] ) ) {
			update_post_meta( $post_id, '_bizcity_kg_notebook_id', (int) $meta['web_notebook_id'] );
		}
		if ( ! empty( $meta['web_skeleton_version'] ) ) {
			update_post_meta( $post_id, '_bizcity_kg_skeleton_version', (int) $meta['web_skeleton_version'] );
		}

		return array(
			'post_id'   => (int) $post_id,
			'permalink' => (string) get_permalink( $post_id ),
			'edit_link' => (string) get_edit_post_link( $post_id, 'raw' ),
		);
	}

	/**
	 * Sideload image URL → media library → set as featured image. Returns
	 * attachment ID or WP_Error.
	 */
	protected function sideload_featured_image( int $post_id, string $url ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$attachment_id = media_sideload_image( $url, $post_id, null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		set_post_thumbnail( $post_id, (int) $attachment_id );
		return (int) $attachment_id;
	}

	/**
	 * Mark event failed but keep status='active' so admin can retry by editing
	 * metadata.web_publish_status back to 'pending'.
	 */
	protected function mark_failed( int $event_id, array $meta, string $error ): void {
		$meta['web_publish_status'] = 'failed';
		$meta['web_error']          = $error;
		$this->write_metadata( $event_id, $meta );
		do_action( 'bizcity_web_post_failed', $event_id, $error );
	}

	/**
	 * Persist metadata back to the scheduler row. Optionally bump status.
	 */
	protected function write_metadata( int $event_id, array $meta, string $event_status = '' ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_crm_events';
		$data  = array(
			'metadata'   => wp_json_encode( $meta ),
			'updated_at' => current_time( 'mysql' ),
		);
		$fmt   = array( '%s', '%s' );
		if ( $event_status !== '' ) {
			$data['status'] = $event_status;
			$fmt[]          = '%s';
		}
		$wpdb->update( $table, $data, array( 'id' => $event_id ), $fmt, array( '%d' ) );
	}

	protected function decode_metadata( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}

	/**
	 * R-CRON-META helpers — silent no-op if core/cron unavailable.
	 */
	protected function cron_note_event( string $name, array $data ): void {
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->note_event( $name, $data );
		}
	}

	protected function cron_bump_counter( string $key, int $by = 1 ): void {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) { return; }
		$mgr    = BizCity_Cron_Manager::instance();
		$run_id = $mgr->current_run_id();
		if ( ! $run_id ) { return; }
		global $wpdb;
		$t   = $wpdb->prefix . BizCity_Cron_Manager::TABLE_RUNS;
		$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$t} WHERE id=%d", $run_id ) );
		$cur = $raw !== '' ? json_decode( $raw, true ) : array();
		if ( ! is_array( $cur ) ) { $cur = array(); }
		$prev = (int) ( $cur['counters'][ $key ] ?? 0 );
		$mgr->note( array( 'counters' => array( $key => $prev + $by ) ) );
	}
}
