<?php
/**
 * BizCity CRM — Ingest Notification Center (Phase 3.5-WC P8).
 *
 * Listens for `bizcity_ingest_document_complete` action fired by the
 * `ingest_document` TwinBrain tool, stores a short queue in a WP option,
 * and shows a dismissible admin notice on CRM pages so admins know new
 * documents were ingested into TwinBrain notebooks.
 *
 * Storage:
 *   - Option  `bizcity_crm_ingest_queue`         : array<IngestItem> (max 50)
 *   - UserMeta `bizcity_crm_ingest_seen_at`       : unix timestamp of last dismiss
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 3.5-WC
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Ingest_Notif {

	const OPTION_KEY  = 'bizcity_crm_ingest_queue';
	const USER_META   = 'bizcity_crm_ingest_seen_at';
	const MAX_ITEMS   = 50;
	const AJAX_ACTION = 'bizcity_crm_dismiss_ingest_notifs';

	// [2026-06-13 Johnny Chu] PHASE-0.40 G3 P8 — register hooks
	public static function register(): void {
		// Listen for ingest events (runs in REST/cron context too).
		add_action( 'bizcity_ingest_document_complete', array( __CLASS__, 'on_ingest' ), 10, 4 );

		// Admin notice + AJAX dismiss (admin context only).
		if ( is_admin() ) {
			add_action( 'admin_notices',                         array( __CLASS__, 'maybe_show_notice' ) );
			add_action( 'wp_ajax_' . self::AJAX_ACTION,          array( __CLASS__, 'ajax_dismiss' ) );
		}
	}

	/**
	 * Store ingest event in queue.
	 *
	 * @param int   $guru_id
	 * @param int   $user_id     WP user who triggered the ingest.
	 * @param int   $notebook_id
	 * @param array $meta        {source_id, chunk_count, type, title}
	 */
	public static function on_ingest( int $guru_id, int $user_id, int $notebook_id, array $meta ): void {
		$queue = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		array_unshift( $queue, array(
			'guru_id'     => $guru_id,
			'user_id'     => $user_id,
			'notebook_id' => $notebook_id,
			'source_id'   => (int) ( $meta['source_id']   ?? 0 ),
			'chunk_count' => (int) ( $meta['chunk_count'] ?? 0 ),
			'type'        => (string) ( $meta['type']     ?? 'text' ),
			'title'       => mb_substr( (string) ( $meta['title'] ?? '' ), 0, 120 ),
			'ts'          => time(),
		) );

		update_option( self::OPTION_KEY, array_slice( $queue, 0, self::MAX_ITEMS ), false );
	}

	/**
	 * Show dismissible admin notice on CRM admin pages.
	 * Only fires after `current_screen` is available (called from admin_notices).
	 */
	public static function maybe_show_notice(): void {
		// Scope to CRM pages only (avoid showing on every WP admin page).
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		if ( false === strpos( (string) $screen->id, 'bizcity-crm' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'bizcity_crm_manage' ) ) {
			return;
		}

		$seen_at = (int) get_user_meta( $user_id, self::USER_META, true );
		$queue   = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		$new = array_filter( $queue, static function ( $item ) use ( $seen_at ) {
			return ( (int) $item['ts'] ) > $seen_at;
		} );

		if ( empty( $new ) ) {
			return;
		}

		$count      = count( $new );
		$nonce      = wp_create_nonce( self::AJAX_ACTION );
		$ajax_url   = esc_url( admin_url( 'admin-ajax.php' ) );
		$ajax_act   = esc_attr( self::AJAX_ACTION );

		// Build a short summary of the first 3 items.
		$lines = '';
		$shown = 0;
		foreach ( $new as $item ) {
			if ( $shown >= 3 ) {
				break;
			}
			$type_label = $item['type'] === 'url' ? 'URL' : 'Text';
			$title      = $item['title'] !== '' ? esc_html( $item['title'] ) : '#' . $item['source_id'];
			$chunks     = (int) $item['chunk_count'];
			$nb         = (int) $item['notebook_id'];
			$lines     .= '<li>' . $type_label . ': <em>' . $title . '</em>'
				. ' — <strong>' . $chunks . '</strong> passages → notebook #' . $nb . '</li>';
			$shown++;
		}
		if ( $count > 3 ) {
			$lines .= '<li>… và ' . ( $count - 3 ) . ' tài liệu khác.</li>';
		}
		?>
		<div class="notice notice-info is-dismissible" id="bzc-ingest-notif">
			<p>
				📥 <strong><?php echo esc_html( $count ); ?> tài liệu vừa được ingest</strong>
				vào TwinBrain Notebook.
			</p>
			<ul style="margin:4px 0 0 20px;list-style:disc"><?php echo wp_kses_post( $lines ); ?></ul>
		</div>
		<script>
		(function(){
			var el = document.getElementById('bzc-ingest-notif');
			if ( ! el ) { return; }
			el.addEventListener('click', function(e){
				if ( e.target && e.target.classList.contains('notice-dismiss') ) {
					var fd = new FormData();
					fd.append('action', '<?php echo esc_js( $ajax_act ); ?>');
					fd.append('_ajax_nonce', '<?php echo esc_js( $nonce ); ?>');
					fetch('<?php echo esc_js( $ajax_url ); ?>', { method:'POST', credentials:'same-origin', body: fd });
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * AJAX: mark current user's "last seen" timestamp to now.
	 * Verifies nonce; no capability check needed beyond authentication (already nonce-gated).
	 */
	public static function ajax_dismiss(): void {
		check_ajax_referer( self::AJAX_ACTION );
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::USER_META, time() );
		}
		wp_die( '', '', array( 'response' => 204 ) );
	}

	/**
	 * Return unseen item count for a given user (used by admin bar badge — optional).
	 */
	public static function unseen_count( int $user_id = 0 ): int {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		$seen_at = (int) get_user_meta( $user_id, self::USER_META, true );
		$queue   = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $queue ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $queue as $item ) {
			if ( ( (int) $item['ts'] ) > $seen_at ) {
				$count++;
			}
		}
		return $count;
	}
}
