<?php
/**
 * Bizcity Twin AI — KG_Source_Adapter_Studio
 *
 * Phase 0.5 Sprint 2.
 *
 * Listens for studio output writes (bizcity_webchat_studio_outputs) and mirrors
 * them into the KG-Hub as kg_passages, with provenance back to the origin row.
 *
 * Strategy:
 *  - Listen action `bcn_studio_output_saved` (preferred — fired by writers)
 *    Falls back to a poll cron if the hook is not yet fired by the writers.
 *  - For each output:
 *      • Determine target notebook (per-user "Studio Memory" auto-notebook)
 *      • Build passage content from {title, content, caption}
 *      • Skip if already mirrored (UNIQUE on origin_table+origin_id)
 *      • Insert passage via KG_Source_Service (which dedupes + records embed cost)
 *      • Insert provenance row
 *
 * Cost-safe: relies on KG_Cost_Guard inside add_passage().
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Source_Adapter_Studio {

	const ORIGIN_TABLE  = 'webchat_studio_outputs';
	const NOTEBOOK_NAME = '🎨 Studio Memory';
	const CRON_HOOK     = 'bizcity_kg_studio_backfill';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		// Real-time hook fired by class-studio.php after a successful generate.
		// Signature: ( int $output_id, string $tool_type, string $project_id )
		add_action( 'bcn_studio_generated', [ $this, 'on_studio_output_saved' ], 20, 3 );

		// Manual / cron backfill.
		add_action( self::CRON_HOOK, [ $this, 'run_backfill_batch' ] );
	}

	/**
	 * Hook callback. Re-loads the full row to be safe.
	 */
	public function on_studio_output_saved( $output_id, $tool_type = '', $project_id = '' ) {
		$output_id = (int) $output_id;
		if ( ! $output_id ) return;

		$full = $this->fetch_output_row( $output_id );
		if ( ! $full ) return;
		$this->mirror_one( $full );
	}

	/** Load full studio_outputs row by id. */
	private function fetch_output_row( $output_id ) {
		global $wpdb;
		$t = $this->table_studio_outputs();
		if ( ! $t ) return null;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $output_id ), ARRAY_A );
	}

	/* ── Backfill ────────────────────────────────────────────────────────── */

	/**
	 * Mirror a batch of studio_outputs that aren't yet in kg_provenance.
	 *
	 * @return array{processed:int, mirrored:int, skipped:int}
	 */
	public function run_backfill_batch( $limit = 50 ) {
		global $wpdb;
		$db   = BizCity_KG_Database::instance();
		$so_t = $this->table_studio_outputs();
		if ( ! $so_t ) {
			return [ 'processed' => 0, 'mirrored' => 0, 'skipped' => 0, 'error' => 'studio_outputs table not detected' ];
		}

		$prov = $db->tbl_provenance();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.* FROM {$so_t} s
			 LEFT JOIN {$prov} p ON p.origin_table = %s AND p.origin_id = s.id
			 WHERE p.id IS NULL AND s.status = 'ready'
			 ORDER BY s.id DESC
			 LIMIT %d",
			self::ORIGIN_TABLE, max( 1, (int) $limit )
		), ARRAY_A ) ?: [];

		$mirrored = 0; $skipped = 0;
		foreach ( $rows as $row ) {
			$res = $this->mirror_one( $row );
			if ( is_wp_error( $res ) || $res === null ) $skipped++;
			else $mirrored++;
		}
		return [ 'processed' => count( $rows ), 'mirrored' => $mirrored, 'skipped' => $skipped ];
	}

	/* ── Core mirror logic ───────────────────────────────────────────────── */

	/**
	 * @return int|null|WP_Error  passage_id on success, null if skipped
	 */
	public function mirror_one( array $row ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		$origin_id = (int) ( $row['id'] ?? 0 );
		if ( ! $origin_id ) return null;

		// Already mirrored? Idempotent skip.
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$db->tbl_provenance()} WHERE origin_table=%s AND origin_id=%d LIMIT 1",
			self::ORIGIN_TABLE, $origin_id
		) );
		if ( $exists ) return null;

		// Compose passage content from text + media metadata.
		$content = $this->compose_content( $row );
		if ( $content === '' ) return null; // nothing to learn from

		// Determine target notebook for this user.
		$user_id     = (int) ( $row['user_id'] ?? 0 );
		$notebook_id = $this->get_or_create_studio_notebook( $user_id );
		if ( ! $notebook_id ) return new WP_Error( 'no_notebook', 'Failed to resolve notebook' );

		// Origin type from tool_type / caller.
		$origin_type = $this->derive_origin_type( $row );

		// Insert passage (Cost Guard handles dedupe + embed cost).
		$pid = BizCity_KG_Source_Service::instance()->add_passage(
			$notebook_id,
			$content,
			'studio',
			[
				'origin_type' => $origin_type,
				'tool_id'     => $row['tool_id']   ?? '',
				'project_id'  => $row['project_id']?? '',
				'session_id'  => $row['session_id']?? '',
				'media_type'  => $row['media_type']?? '',
				'file_url'    => $row['file_url']  ?? '',
			]
		);
		if ( is_wp_error( $pid ) ) return $pid;
		if ( ! $pid ) return null;

		// Provenance.
		$wpdb->insert( $db->tbl_provenance(), [
			'passage_id'    => (int) $pid,
			'origin_table'  => self::ORIGIN_TABLE,
			'origin_id'     => $origin_id,
			'origin_type'   => $origin_type,
			'extractor'     => 'mirror_v1',
			'confidence'    => 0.90,
			'user_verified' => 0,
			'user_corrected'=> 0,
		] );

		return (int) $pid;
	}

	private function compose_content( array $row ) {
		$parts = [];
		$title = trim( (string) ( $row['title'] ?? '' ) );
		if ( $title !== '' ) $parts[] = $title;

		$content = trim( (string) ( $row['content'] ?? '' ) );
		if ( $content !== '' ) {
			// content_format may be json — try to flatten.
			$fmt = (string) ( $row['content_format'] ?? 'text' );
			if ( $fmt === 'json' ) {
				$decoded = json_decode( $content, true );
				$content = is_array( $decoded ) ? $this->flatten_json( $decoded ) : $content;
			}
			$parts[] = wp_strip_all_tags( $content );
		}

		$media = trim( (string) ( $row['media_type'] ?? '' ) );
		if ( $media !== '' ) {
			$file = trim( (string) ( $row['file_url'] ?? '' ) );
			$parts[] = sprintf( '[Media: %s%s]', $media, $file ? ' — ' . $file : '' );
		}

		$text = trim( implode( "\n\n", array_filter( $parts ) ) );
		// Hard cap to keep embedding cost bounded.
		if ( mb_strlen( $text ) > 8000 ) {
			$text = mb_substr( $text, 0, 8000 );
		}
		return $text;
	}

	private function flatten_json( $data, $depth = 0 ) {
		if ( $depth > 4 ) return '';
		if ( is_string( $data ) ) return $data;
		if ( is_scalar( $data ) ) return (string) $data;
		if ( ! is_array( $data ) ) return '';
		$out = [];
		foreach ( $data as $k => $v ) {
			$line = is_string( $k ) ? "{$k}: " : '';
			$out[] = $line . $this->flatten_json( $v, $depth + 1 );
		}
		return implode( "\n", array_filter( $out ) );
	}

	private function derive_origin_type( array $row ) {
		$tool = sanitize_key( (string) ( $row['tool_type'] ?? $row['tool_id'] ?? 'studio' ) );
		if ( $tool === '' ) $tool = 'studio';
		return 'studio_' . $tool;
	}

	/* ── Notebook auto-provisioning ──────────────────────────────────────── */

	private function get_or_create_studio_notebook( $user_id ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		$id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$db->tbl_notebooks()}
			 WHERE owner_id = %d AND name = %s LIMIT 1",
			(int) $user_id, self::NOTEBOOK_NAME
		) );
		if ( $id ) return $id;

		$nb = BizCity_KG_Notebook_Service::instance()->create( [
			'name'        => self::NOTEBOOK_NAME,
			'description' => 'Auto-collected from Studio outputs (research, reflection, drafts, media).',
			'color'       => '#8b5cf6',
			'settings'    => [ 'auto_extract' => true, 'auto_provisioned' => true ],
		], (int) $user_id );
		return $nb ? (int) $nb['id'] : 0;
	}

	/* ── Discover the studio_outputs table name ─────────────────────────── */

	private function table_studio_outputs() {
		if ( class_exists( 'BCN_Schema_Extend' ) && method_exists( 'BCN_Schema_Extend', 'table_studio_outputs' ) ) {
			return BCN_Schema_Extend::table_studio_outputs();
		}
		global $wpdb;
		$candidate = $wpdb->prefix . 'bizcity_webchat_studio_outputs';
		$exists = bizcity_tbl_exists( $candidate ) ? $candidate : null; // [2026-06-21 R-SHOW-TABLES]
		return $exists === $candidate ? $candidate : '';
	}

	/**
	 * Stats for a notebook — count of passages mirrored from each origin_type.
	 */
	public function origin_stats( $notebook_id = 0 ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$where = '';
		$args  = [];
		if ( $notebook_id > 0 ) {
			$where  = ' WHERE p.notebook_id = %d ';
			$args[] = (int) $notebook_id;
		}
		$sql = "SELECT pr.origin_type, COUNT(*) AS cnt
		        FROM {$db->tbl_provenance()} pr
		        INNER JOIN {$db->tbl_passages()} p ON p.id = pr.passage_id
		        {$where}
		        GROUP BY pr.origin_type ORDER BY cnt DESC";
		return $args
			? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A )
			: $wpdb->get_results( $sql, ARRAY_A );
	}
}
