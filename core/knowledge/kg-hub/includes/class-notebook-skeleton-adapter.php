<?php
/**
 * Bizcity Twin AI — Notebook Skeleton Adapter
 *
 * Single point of truth for reading/marking the per-notebook reflected
 * "skeleton" — the canonical structural summary of a notebook's source
 * corpus. Implements the public API mandated by PHASE-0-RULE-SKELETON
 * (RULE-1) so every artifact-generating tool inside the BizCity ecosystem
 * shares the same context-injection surface.
 *
 *     BizCity_KG_Skeleton_Adapter::get_prompt_block( $notebook_id, $mode )
 *
 * is the only sanctioned way for a downstream plugin (bizcity-doc,
 * bizcity-design, biz-chat-agent, bizcity-tool-slide, bizcity-video-agent,
 * etc.) to inject the user's notebook intent into an LLM prompt.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-05-11
 * @see        PHASE-0-RULE-SKELETON.md   RULE-1
 * @see        PHASE-0-RULE-NAMESPACE.md  §2 (class naming)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Skeleton_Adapter {

	/** Per-request decoded-skeleton cache, keyed by notebook id. */
	private static $cache = [];

	/** Per-request status-row cache, keyed by notebook id. */
	private static $status_cache = [];

	/** Hard cap on bytes returned by get_prompt_block() (F-6). */
	const PROMPT_BLOCK_MAX_BYTES = 16384;

	/** Lifecycle states allowed in `kg_notebooks.skeleton_status`. */
	const STATUS_PENDING  = 'pending';
	const STATUS_BUILDING = 'building';
	const STATUS_READY    = 'ready';
	const STATUS_STALE    = 'stale';
	const STATUS_FAILED   = 'failed';

	const MODE_FULL    = 'full';
	const MODE_COMPACT = 'compact';
	const MODE_OUTLINE = 'outline';

	/* ──────────────────────────────────────────────────────────────────
	 *  PUBLIC API — RULE-1
	 * ──────────────────────────────────────────────────────────────── */

	/**
	 * List the user's notebooks with skeleton metadata, suitable for the
	 * NotebookSkeletonSelector dropdown (RULE-3).
	 *
	 * @param int   $user_id
	 * @param array $opts {
	 *   @type bool|null $has_skeleton  true → only ready, false → only without, null → all
	 *   @type int       $limit         Default 50
	 *   @type string    $search        Optional name LIKE filter
	 * }
	 * @return array  Rows: { id, name, color, skeleton_status,
	 *                        skeleton_version, skeleton_built_at, source_count }
	 */
	public static function get_notebook_list( int $user_id, array $opts = [] ): array {
		global $wpdb;

		$db      = BizCity_KG_Database::instance();
		$tbl_nb  = $db->tbl_notebooks();
		$tbl_pas = $db->tbl_passages();

		$limit  = isset( $opts['limit'] ) ? max( 1, min( 200, (int) $opts['limit'] ) ) : 50;
		$where  = [ 'n.owner_id = %d' ];
		$args   = [ $user_id ];

		if ( array_key_exists( 'has_skeleton', $opts ) && null !== $opts['has_skeleton'] ) {
			if ( $opts['has_skeleton'] ) {
				$where[] = "n.skeleton_status = %s";
				$args[]  = self::STATUS_READY;
			} else {
				$where[] = "( n.skeleton_status IS NULL OR n.skeleton_status <> %s )";
				$args[]  = self::STATUS_READY;
			}
		}

		if ( ! empty( $opts['search'] ) ) {
			$where[]  = 'n.name LIKE %s';
			$args[]   = '%' . $wpdb->esc_like( (string) $opts['search'] ) . '%';
		}

		$where_sql = implode( ' AND ', $where );
		$args[]    = $limit;

		$sql = "SELECT n.id,
		               n.name,
		               n.color,
		               n.skeleton_status,
		               n.skeleton_version,
		               n.skeleton_built_at,
		               ( SELECT COUNT(DISTINCT p.source_id)
		                   FROM {$tbl_pas} p
		                  WHERE p.notebook_id = n.id
		                    AND p.source_id IS NOT NULL ) AS source_count
		        FROM {$tbl_nb} n
		       WHERE {$where_sql}
		    ORDER BY n.updated_at DESC
		       LIMIT %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( static function ( $row ) {
			return [
				'id'                => (int) $row['id'],
				'name'              => (string) $row['name'],
				'color'             => (string) ( $row['color'] ?? '' ),
				'skeleton_status'   => (string) ( $row['skeleton_status'] ?? '' ),
				'skeleton_version'  => (int) ( $row['skeleton_version'] ?? 0 ),
				'skeleton_built_at' => $row['skeleton_built_at'] ?: null,
				'source_count'      => (int) ( $row['source_count'] ?? 0 ),
			];
		}, $rows );
	}

	/** Return the full skeleton JSON for one notebook, or null if not built. */
	public static function get_skeleton( int $notebook_id ): ?array {
		if ( $notebook_id <= 0 ) {
			return null;
		}
		if ( array_key_exists( $notebook_id, self::$cache ) ) {
			return self::$cache[ $notebook_id ];
		}

		$row = self::fetch_status_row( $notebook_id );
		if ( ! $row || empty( $row['skeleton_json'] ) ) {
			return self::$cache[ $notebook_id ] = null;
		}

		$decoded = json_decode( (string) $row['skeleton_json'], true );
		if ( ! is_array( $decoded ) ) {
			return self::$cache[ $notebook_id ] = null;
		}

		$decoded['_meta'] = [
			'version'  => (int) $row['skeleton_version'],
			'built_at' => $row['skeleton_built_at'],
			'status'   => (string) $row['skeleton_status'],
		];

		return self::$cache[ $notebook_id ] = $decoded;
	}

	/** Is the notebook's skeleton ready to inject? */
	public static function is_ready( int $notebook_id ): bool {
		if ( $notebook_id <= 0 ) {
			return false;
		}
		$row = self::fetch_status_row( $notebook_id );
		return $row
		       && self::STATUS_READY === (string) $row['skeleton_status']
		       && ! empty( $row['skeleton_json'] );
	}

	/**
	 * Build a Markdown prompt block to inject as `system` after primary
	 * system prompt and before user brief. Returns '' if no usable skeleton.
	 *
	 * @param int    $notebook_id
	 * @param string $mode  full | compact | outline
	 */
	public static function get_prompt_block( int $notebook_id, string $mode = self::MODE_FULL ): string {
		$skeleton = self::get_skeleton( $notebook_id );
		if ( ! $skeleton ) {
			return '';
		}

		$mode = in_array( $mode, [ self::MODE_FULL, self::MODE_COMPACT, self::MODE_OUTLINE ], true )
			? $mode : self::MODE_FULL;

		// Allow downstream plugins to override the formatter.
		$override = apply_filters(
			'bizcity_kg_skeleton_prompt_block',
			null, $skeleton, $mode, $notebook_id
		);
		// Legacy alias (PHASE-0-RULE-NAMESPACE §3.2 — 2-release window).
		$override = apply_filters( 'bzkg_skeleton_prompt_block', $override, $skeleton, $mode, $notebook_id );

		$block = is_string( $override ) && $override !== ''
			? $override
			: self::format_prompt_block( $skeleton, $mode );

		// F-6 hard cap, byte-based, cut on a line boundary when possible.
		if ( strlen( $block ) > self::PROMPT_BLOCK_MAX_BYTES ) {
			$block  = substr( $block, 0, self::PROMPT_BLOCK_MAX_BYTES );
			$nl_pos = strrpos( $block, "\n" );
			if ( $nl_pos !== false && $nl_pos > self::PROMPT_BLOCK_MAX_BYTES * 0.8 ) {
				$block = substr( $block, 0, $nl_pos );
			}
			$block .= "\n\n> *(skeleton truncated to fit context budget)*\n";
		}

		return $block;
	}

	/**
	 * Mark a notebook's skeleton dirty + schedule the debounced rebuild.
	 * Idempotent.
	 */
	public static function mark_dirty( int $notebook_id ): void {
		if ( $notebook_id <= 0 ) {
			return;
		}

		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_notebooks();
		$wpdb->update(
			$tbl,
			[ 'skeleton_status' => self::STATUS_PENDING ],
			[ 'id' => $notebook_id ],
			[ '%s' ],
			[ '%d' ]
		);

		self::flush_cache( $notebook_id );

		if ( class_exists( 'BizCity_KG_Skeleton_Service' ) ) {
			BizCity_KG_Skeleton_Service::schedule_rebuild( $notebook_id );
		}

		do_action( 'bizcity_kg_notebook_skeleton_marked_dirty', $notebook_id );
		do_action( 'bzkg_notebook_skeleton_marked_dirty', $notebook_id ); // legacy
	}

	/** Has the consumer's cached artifact fallen behind the notebook? */
	public static function is_artifact_stale( int $notebook_id, int $artifact_skeleton_version ): bool {
		$current = self::get_version( $notebook_id );
		return $current > 0 && $current > max( 0, $artifact_skeleton_version );
	}

	/** Current skeleton version (0 if never built). */
	public static function get_version( int $notebook_id ): int {
		if ( $notebook_id <= 0 ) {
			return 0;
		}
		$row = self::fetch_status_row( $notebook_id );
		return $row ? (int) $row['skeleton_version'] : 0;
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  Permission helper — F-5 extensible
	 * ──────────────────────────────────────────────────────────────── */

	/**
	 * Default check: notebook owner OR `manage_options`.
	 * Pluggable via filter `bizcity_kg_user_can_read_notebook`.
	 */
	public static function user_can_read( int $notebook_id, int $user_id ): bool {
		if ( $notebook_id <= 0 || $user_id <= 0 ) {
			return false;
		}
		$can = false;
		if ( user_can( $user_id, 'manage_options' ) ) {
			$can = true;
		} else {
			global $wpdb;
			$tbl   = BizCity_KG_Database::instance()->tbl_notebooks();
			$owner = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT owner_id FROM {$tbl} WHERE id = %d", $notebook_id
			) );
			$can = ( $owner > 0 && $owner === $user_id );
		}
		return (bool) apply_filters(
			'bizcity_kg_user_can_read_notebook',
			$can, $notebook_id, $user_id
		);
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  Internal helpers
	 * ──────────────────────────────────────────────────────────────── */

	private static function fetch_status_row( int $notebook_id ): ?array {
		if ( array_key_exists( $notebook_id, self::$status_cache ) ) {
			return self::$status_cache[ $notebook_id ];
		}
		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_notebooks();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT skeleton_status, skeleton_version, skeleton_built_at, skeleton_json
			   FROM {$tbl} WHERE id = %d",
			$notebook_id
		), ARRAY_A );
		return self::$status_cache[ $notebook_id ] = ( $row ?: null );
	}

	private static function format_prompt_block( array $skeleton, string $mode ): string {
		$nucleus = isset( $skeleton['nucleus'] ) && is_array( $skeleton['nucleus'] )
			? $skeleton['nucleus'] : [];
		$title   = (string) ( $nucleus['title']  ?? '' );
		$thesis  = (string) ( $nucleus['thesis'] ?? '' );
		$version = (int) ( $skeleton['_meta']['version'] ?? 0 );

		$out  = "## NOTEBOOK REFERENCE — \"{$title}\" (v{$version})\n";
		$out .= "> Đây là khung sườn ý đồ do người dùng nạp vào. Khi tạo nội dung, BẮT BUỘC bám theo nucleus và các mục chính dưới đây.\n";
		if ( $thesis !== '' ) {
			$out .= "\n**Thesis:** {$thesis}\n";
		}

		$key_points = isset( $skeleton['key_points'] ) && is_array( $skeleton['key_points'] )
			? array_values( $skeleton['key_points'] ) : [];

		if ( self::MODE_COMPACT === $mode ) {
			if ( $key_points ) {
				$out .= "\n**Dữ kiện quan trọng:**\n";
				foreach ( array_slice( $key_points, 0, 8 ) as $kp ) {
					$out .= '- ' . trim( (string) $kp ) . "\n";
				}
			}
			return $out;
		}

		$nodes = isset( $skeleton['skeleton'] ) && is_array( $skeleton['skeleton'] )
			? $skeleton['skeleton'] : [];

		if ( $nodes ) {
			$out .= "\n**Cấu trúc chính:**\n";
			$idx = 0;
			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) { continue; }
				$idx++;
				$label   = trim( (string) ( $node['label']   ?? '' ) );
				$summary = trim( (string) ( $node['summary'] ?? '' ) );

				if ( self::MODE_OUTLINE === $mode ) {
					$out .= "{$idx}. {$label}\n";
				} else {
					$out .= "{$idx}. {$label}" . ( $summary !== '' ? " — {$summary}" : '' ) . "\n";
				}

				if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
					$cidx = 0;
					foreach ( $node['children'] as $child ) {
						if ( ! is_array( $child ) ) { continue; }
						$cidx++;
						$clabel = trim( (string) ( $child['label'] ?? '' ) );
						$out   .= "   {$idx}.{$cidx} {$clabel}\n";
					}
				}
			}
		}

		if ( self::MODE_FULL === $mode ) {
			if ( $key_points ) {
				$out .= "\n**Dữ kiện quan trọng:**\n";
				foreach ( array_slice( $key_points, 0, 10 ) as $kp ) {
					$out .= '- ' . trim( (string) $kp ) . "\n";
				}
			}
			$entities = isset( $skeleton['entities'] ) && is_array( $skeleton['entities'] )
				? array_values( array_filter( array_map( 'strval', $skeleton['entities'] ) ) ) : [];
			if ( $entities ) {
				$out .= "\n**Thực thể chính:** " . implode( ', ', array_slice( $entities, 0, 12 ) ) . "\n";
			}
		}

		return $out;
	}

	/**
	 * Clear the per-request caches (both decoded + status row) — F-10.
	 *
	 * @internal
	 */
	public static function flush_cache( int $notebook_id = 0 ): void {
		if ( $notebook_id > 0 ) {
			unset( self::$cache[ $notebook_id ], self::$status_cache[ $notebook_id ] );
		} else {
			self::$cache        = [];
			self::$status_cache = [];
		}
	}
}

// Back-compat alias — PHASE-0-RULE-NAMESPACE §2.2 (2-release window).
if ( ! class_exists( 'BZKG_Skeleton_Adapter' ) ) {
	class_alias( 'BizCity_KG_Skeleton_Adapter', 'BZKG_Skeleton_Adapter' );
}
