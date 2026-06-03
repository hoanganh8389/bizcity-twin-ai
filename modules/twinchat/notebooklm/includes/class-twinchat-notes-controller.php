<?php
/**
 * Bizcity TwinChat — Notes (Pin Request) REST Controller
 *
 * Phase 0.7 Wave B1 — surfaces pinned notes endpoints for TwinChat.
 * Notes persist to bizcity_memory_notes via BCN_Notes (Companion Notebook).
 *
 *   POST   /messages/(?P<id>\d+)/pin   → pin a chat message as a note
 *   POST   /notes                      → create a manual note
 *   GET    /notes                      → list notes for a notebook
 *   PATCH  /notes/(?P<id>\d+)          → update a note
 *   DELETE /notes/(?P<id>\d+)          → delete a note
 *
 * Project-id convention: TwinChat scope is mapped to `tc_<notebook_id>` so it
 * coexists with Companion Notebook projects without collision.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Notes
 * @since      Phase 0.7
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Notes_Controller {

	const PROJECT_PREFIX = 'tc_';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes() {
		$ns = defined( 'BIZCITY_TWINCHAT_REST_NS' ) ? BIZCITY_TWINCHAT_REST_NS : 'bizcity-twinchat/v1';

		register_rest_route( $ns, '/messages/(?P<id>\d+)/pin', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ $this, 'check_logged_in' ],
			'callback'            => [ $this, 'pin_message' ],
			'args'                => [
				'id'           => [ 'type' => 'integer', 'required' => true ],
				'notebook_id'  => [ 'type' => 'integer', 'required' => true ],
				'content'      => [ 'type' => 'string' ],
				'title'        => [ 'type' => 'string' ],
				'session_id'   => [ 'type' => 'string' ],
			],
		] );

		register_rest_route( $ns, '/notes', [
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => [ $this, 'check_logged_in' ],
				'callback'            => [ $this, 'create_note' ],
				'args'                => [
					'notebook_id' => [ 'type' => 'integer', 'required' => true ],
					'title'       => [ 'type' => 'string' ],
					'content'     => [ 'type' => 'string', 'required' => true ],
					'note_type'   => [ 'type' => 'string', 'default' => 'manual' ],
					'session_id'  => [ 'type' => 'string' ],
					'message_id'  => [ 'type' => 'integer' ],
				],
			],
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => [ $this, 'check_logged_in' ],
				'callback'            => [ $this, 'list_notes' ],
				'args'                => [
					// notebook_id optional — omit (or pass 0) to fetch ALL notes for
					// the current user (Ask Brain / home context).
					'notebook_id' => [ 'type' => 'integer', 'required' => false, 'default' => 0 ],
					'limit'       => [ 'type' => 'integer', 'default' => 50 ],
					'q'           => [ 'type' => 'string' ],
				],
			],
		] );

		register_rest_route( $ns, '/notes/(?P<id>\d+)', [
			[
				'methods'             => 'PATCH',
				'permission_callback' => [ $this, 'check_logged_in' ],
				'callback'            => [ $this, 'update_note' ],
			],
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => [ $this, 'check_logged_in' ],
				'callback'            => [ $this, 'delete_note' ],
			],
		] );
	}

	// ── Permissions ─────────────────────────────────────────────────────

	public function check_logged_in() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Must be logged in', [ 'status' => 401 ] );
		}
		return true;
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	private function project_id_from_notebook( $notebook_id ) {
		return self::PROJECT_PREFIX . (int) $notebook_id;
	}

	private function notes_service() {
		// Prefer the self-contained TwinChat service (no longer depends on the
		// archived Companion Notebook plugin). Fall back to BCN_Notes only if
		// the legacy plugin is still loaded — keeps backward compatibility.
		if ( class_exists( 'BizCity_TwinChat_Notes_Service' ) ) {
			return new BizCity_TwinChat_Notes_Service();
		}
		if ( class_exists( 'BCN_Notes' ) ) {
			return new BCN_Notes();
		}
		return new WP_Error( 'notes_unavailable', 'Notes service unavailable', [ 'status' => 500 ] );
	}

	// ── Handlers ────────────────────────────────────────────────────────

	public function pin_message( WP_REST_Request $req ) {
		$svc = $this->notes_service();
		if ( is_wp_error( $svc ) ) return $svc;

		$notebook_id = (int) $req->get_param( 'notebook_id' );
		$message_id  = (int) $req['id'];
		$content     = (string) $req->get_param( 'content' );
		$title       = (string) $req->get_param( 'title' );
		$session_id  = (string) $req->get_param( 'session_id' );

		if ( $notebook_id <= 0 ) {
			return new WP_Error( 'invalid_notebook', 'notebook_id required', [ 'status' => 400 ] );
		}
		if ( $content === '' ) {
			return new WP_Error( 'empty_content', 'content required (TwinChat pins use client-supplied content)', [ 'status' => 400 ] );
		}

		// TwinChat messages live in a different table than BCN_Notes::pin_from_message expects,
		// so we always create directly using the supplied content.
		$content_clean = trim( preg_replace( '/\n?---\n?💡[\s\S]*$/u', '', $content ) );
		if ( $title === '' ) {
			$title = mb_substr( wp_strip_all_tags( $content_clean ), 0, 80 ) ?: 'Pinned chat';
		}

		$project_id = $this->project_id_from_notebook( $notebook_id );

		// ── Dedup ──────────────────────────────────────────────────────
		// Same (project_id, message_id) already pinned? Return existing instead
		// of creating a duplicate (user may click 📌 multiple times).
		if ( $message_id > 0 ) {
			global $wpdb;
			$table = $wpdb->prefix . 'bizcity_memory_notes';
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE project_id = %s AND message_id = %d AND note_type = %s ORDER BY id DESC LIMIT 1",
				$project_id, $message_id, 'chat_pinned'
			) );
			if ( $existing ) {
				// Sprint 5.3 — re-pin click on already-pinned message: still emit
				// the v2 event (mode=duplicate) so timeline / observers see the action.
				$this->dispatch_note_pinned_event( (int) $existing, $message_id, $session_id, $notebook_id, 'duplicate' );
				// Phase 6.6 S2.1 — still nudge skeleton service so debounce window
				// resets even on duplicate pins (user signal = "this matters").
				$this->fire_notes_pinned_action( $notebook_id, (int) $existing );
				return rest_ensure_response( [
					'ok'         => true,
					'note_id'    => (int) $existing,
					'marker'     => '[note:' . (int) $existing . ']',
					'duplicated' => true,
				] );
			}
		}

		$id = $svc->create( [
			'project_id' => $project_id,
			'session_id' => $session_id,
			'message_id' => $message_id,
			'title'      => $title,
			'content'    => $content_clean,
			'note_type'  => 'chat_pinned',
		] );

		if ( is_wp_error( $id ) ) return $id;

		// Sprint 5.3 — emit note_pinned v2 event so timeline + memory observers
		// see fresh pins. Failure-tolerant: pin success is the source of truth.
		$this->dispatch_note_pinned_event( (int) $id, $message_id, $session_id, $notebook_id, 'manual' );

		// Phase 6.6 S2.1 — trigger debounced (10s) skeleton rebuild so pinned
		// notes flow into the next reflection pass with priority. R-SK-DOC §15.1.
		$this->fire_notes_pinned_action( $notebook_id, (int) $id );

		return rest_ensure_response( [
			'ok'      => true,
			'note_id' => (int) $id,
			'marker'  => '[note:' . (int) $id . ']',
		] );
	}

	/**
	 * Sprint 5.3 — dispatch `note_pinned` Twin Event Stream v2 envelope.
	 * Failure-tolerant: never let event-stream issues break the REST response.
	 */
	private function dispatch_note_pinned_event( int $note_id, int $message_id, string $session_id, int $notebook_id, string $mode ): void {
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			return;
		}
		try {
			BizCity_Twin_Event_Bus::dispatch_v2(
				'note_pinned',
				[
					'note_id'    => $note_id,
					'message_id' => $message_id > 0 ? 'tc_' . $message_id : '',
					'mode'       => $mode,
				],
				[
					'session_id'      => $session_id,
					'conversation_id' => $session_id,
					'user_id'         => get_current_user_id(),
					'event_source'    => 'twinchat',
				]
			);
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat][notes] dispatch_note_pinned_event failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Phase 6.6 S2.1 — fire `bizcity_kg_notebook_notes_pinned` action so the
	 * KG Skeleton Service can debounce-rebuild (R-SK-DOC §15.1).
	 *
	 * Failure-tolerant: never let listener errors break the pin REST response.
	 */
	private function fire_notes_pinned_action( int $notebook_id, int $note_id ): void {
		if ( $notebook_id <= 0 ) {
			return;
		}
		try {
			do_action(
				'bizcity_kg_notebook_notes_pinned',
				$notebook_id,
				get_current_user_id(),
				$note_id
			);
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat][notes] fire_notes_pinned_action failed: ' . $e->getMessage() );
		}
	}

	public function create_note( WP_REST_Request $req ) {
		$svc = $this->notes_service();
		if ( is_wp_error( $svc ) ) return $svc;

		$notebook_id = (int) $req->get_param( 'notebook_id' );
		if ( $notebook_id <= 0 ) {
			return new WP_Error( 'invalid_notebook', 'notebook_id required', [ 'status' => 400 ] );
		}

		$id = $svc->create( [
			'project_id' => $this->project_id_from_notebook( $notebook_id ),
			'session_id' => (string) $req->get_param( 'session_id' ),
			'message_id' => (int) $req->get_param( 'message_id' ),
			'title'      => (string) $req->get_param( 'title' ),
			'content'    => (string) $req->get_param( 'content' ),
			'note_type'  => (string) $req->get_param( 'note_type' ),
		] );

		if ( is_wp_error( $id ) ) return $id;

		return rest_ensure_response( [
			'ok'      => true,
			'note_id' => (int) $id,
			'marker'  => '[note:' . (int) $id . ']',
		] );
	}

	public function list_notes( WP_REST_Request $req ) {
		$svc = $this->notes_service();
		if ( is_wp_error( $svc ) ) return $svc;

		$notebook_id = (int) $req->get_param( 'notebook_id' );
		$limit       = max( 1, min( 500, (int) $req->get_param( 'limit' ) ?: 50 ) );
		$q           = trim( (string) $req->get_param( 'q' ) );

		// notebook_id == 0 → Ask Brain / home context: return ALL notes for user.
		if ( $notebook_id <= 0 ) {
			if ( method_exists( $svc, 'get_all_by_user' ) ) {
				$rows = $svc->get_all_by_user( get_current_user_id(), $limit );
			} else {
				$rows = [];
			}
		} else {
			$pid  = $this->project_id_from_notebook( $notebook_id );
			$rows = $q !== ''
				? $svc->search_by_keyword( $pid, $q, $limit )
				: $svc->get_by_project( $pid );
		}

		if ( ! is_array( $rows ) ) $rows = [];
		$rows = array_slice( $rows, 0, $limit );

		$out = array_map( static function ( $r ) {
			return [
				'id'         => (int) ( $r->id ?? 0 ),
				// Sprint 5.3 fix — expose message_id so FE can hydrate the
				// "already pinned" state of chat bubbles after F5 (otherwise
				// the pin button re-appears and creates duplicate notes).
				'message_id' => (int) ( $r->message_id ?? 0 ),
				'title'      => (string) ( $r->title ?? '' ),
				'content'    => (string) ( $r->content ?? '' ),
				'note_type'  => (string) ( $r->note_type ?? 'manual' ),
				'is_starred' => (int) ( $r->is_starred ?? 0 ),
				'created_at' => (string) ( $r->created_at ?? '' ),
				'updated_at' => (string) ( $r->updated_at ?? '' ),
				'marker'     => '[note:' . (int) ( $r->id ?? 0 ) . ']',
			];
		}, $rows );

		return rest_ensure_response( [
			'ok'    => true,
			'count' => count( $out ),
			'notes' => $out,
		] );
	}

	public function update_note( WP_REST_Request $req ) {
		$svc = $this->notes_service();
		if ( is_wp_error( $svc ) ) return $svc;

		$id = (int) $req['id'];
		$data = [];
		foreach ( [ 'title', 'content', 'is_starred' ] as $k ) {
			$v = $req->get_param( $k );
			if ( $v !== null ) $data[ $k ] = $v;
		}
		$ok = $svc->update( $id, $data );
		return rest_ensure_response( [ 'ok' => (bool) $ok, 'note_id' => $id ] );
	}

	public function delete_note( WP_REST_Request $req ) {
		$svc = $this->notes_service();
		if ( is_wp_error( $svc ) ) return $svc;

		$id = (int) $req['id'];
		$ok = $svc->delete( $id );
		return rest_ensure_response( [ 'ok' => (bool) $ok, 'note_id' => $id ] );
	}
}
