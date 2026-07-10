<?php
/**
 * TwinBrain — Memory Recall (Layer 0.5 — Wave 2.8 TBR.MEM-2).
 *
 * Collects user memory for the active turn and renders a compact
 * `Memory_Block` that downstream layers (Perspective_Runner system prompt,
 * Final_Composer system prompt) can prepend. Reuses
 * BizCity_User_Memory + Notebook_Selector::tokenize_for_search().
 *
 * 4 tiers gathered (cap per tier to keep block ≤2500 chars):
 *   Tier A — explicit user memory (always, cap 20)
 *   Tier B — extracted memory, top-K by keyword overlap + score
 *   Tier C — episodic chunks (cap 5)   — table optional
 *   Tier D — rolling summary (cap 5)   — table optional
 *
 * Each row stamped with [mem:U#<id>] so the Final_Composer can echo the
 * citation token verbatim (R-BRAIN-1 namespace).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-05-22 (Phase 0.36-UNIFIED Wave 2.8 TBR.MEM-2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Memory_Recall {

	const BLOCK_CAP_CHARS = 2500;
	const TIER_A_CAP      = 20;
	const TIER_B_CAP      = 12;
	const TIER_C_CAP      = 5;
	const TIER_D_CAP      = 5;

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/**
	 * Collect memories for this turn.
	 *
	 * @param int    $user_id Current WP user id (0 = guest).
	 * @param string $prompt  User prompt (used for keyword scoring).
	 * @param array  $opts    { session_id, keyword_tokens? }
	 * @return array { block:string, citations:array, counts:array{A:int,B:int,C:int,D:int}, latency_ms:int }
	 */
	public function collect( int $user_id, string $prompt, array $opts = [] ): array {
		$t0          = microtime( true );
		$session_id  = (string) ( $opts['session_id'] ?? '' );
		$tokens      = (array)  ( $opts['keyword_tokens'] ?? [] );
		if ( empty( $tokens ) && class_exists( 'BizCity_TwinBrain_Notebook_Selector' ) ) {
			$tokens = BizCity_TwinBrain_Notebook_Selector::tokenize_for_search( $prompt );
		}

		// ── Wave 2.8d TBR.MEM-D6 — Unified read cutover.
		// When flag `bizcity_memory_unified_enabled` is TRUE AND unified table
		// is provisioned, read from `bizcity_memory` (single query 4 classes)
		// instead of 3 legacy tables. Falls back gracefully to legacy path on
		// any error so production traffic is never disrupted.
		if (
			class_exists( 'BizCity_Memory_Unified_Installer' )
			&& BizCity_Memory_Unified_Installer::is_enabled()
		) {
			try {
				$unified = $this->collect_from_unified( $user_id, $session_id, $tokens, $t0 );
				if ( is_array( $unified ) ) {
					return $unified;
				}
			} catch ( \Throwable $e ) {
				error_log( '[BizCity_TwinBrain_Memory_Recall][unified] read failed — falling back to legacy: ' . $e->getMessage() );
			}
		}

		return $this->collect_from_legacy( $user_id, $session_id, $tokens, $t0 );
	}

	/**
	 * Legacy 4-tier collector (pre-Wave 2.8d). Reads 3 tables:
	 * bizcity_memory_users + bizcity_memory_episodic + bizcity_memory_rolling.
	 */
	private function collect_from_legacy( int $user_id, string $session_id, array $tokens, float $t0 ): array {
		$citations = [];
		$lines_a   = [];
		$lines_b   = [];
		$lines_c   = [];
		$lines_d   = [];

		// Guard: user_memory class missing → return empty block (graceful).
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			return $this->empty_result( $t0 );
		}

		$mem = BizCity_User_Memory::instance();

		// ── Tier A — explicit (always include, cap 20) ──
		$rows_a = (array) $mem->get_memories( [
			'user_id'     => $user_id,
			'session_id'  => $user_id > 0 ? '' : $session_id,
			'memory_tier' => 'explicit',
			'limit'       => self::TIER_A_CAP,
			'order_by'    => 'score',
		] );
		foreach ( $rows_a as $r ) {
			$line = $this->format_line( $r, $tokens );
			if ( $line === '' ) continue;
			$lines_a[]   = $line;
			$citations[] = $this->cite( $r );
		}

		// ── Tier B — extracted, score by keyword overlap + base score ──
		$rows_b = (array) $mem->get_memories( [
			'user_id'     => $user_id,
			'session_id'  => $user_id > 0 ? '' : $session_id,
			'memory_tier' => 'extracted',
			'limit'       => 80,
			'order_by'    => 'score',
		] );
		$scored = [];
		foreach ( $rows_b as $r ) {
			$text     = (string) ( $r->memory_text ?? '' );
			$overlap  = $this->keyword_overlap( $text, $tokens );
			$rank     = (int) ( $r->score ?? 0 ) + ( $overlap * 25 );
			$scored[] = [ 'row' => $r, 'rank' => $rank, 'overlap' => $overlap ];
		}
		usort( $scored, static function ( $a, $b ) { return $b['rank'] <=> $a['rank']; } );
		$scored = array_slice( $scored, 0, self::TIER_B_CAP );
		foreach ( $scored as $hit ) {
			// If we have keyword tokens, only keep rows with overlap >0 (skip noise).
			if ( ! empty( $tokens ) && $hit['overlap'] === 0 ) continue;
			$line = $this->format_line( $hit['row'], $tokens );
			if ( $line === '' ) continue;
			$lines_b[]   = $line;
			$citations[] = $this->cite( $hit['row'] );
		}

		// ── Tier C — episodic (optional table) ──
		$lines_c = $this->collect_episodic( $user_id, $session_id, $tokens, $citations );

		// ── Tier D — rolling summary (optional table) ──
		$lines_d = $this->collect_rolling( $user_id, $session_id, $citations );

		// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-4 — Tier F (Feelings).
		// Surfaces the latest sampled mood for the session so Final_Composer
		// can stay empathically consistent across turns. No cost — single
		// VIEW lookup. Empty when no session_id or no mood sampled yet.
		$line_f = $this->collect_session_mood( $session_id );

		// Build block. Sections only render when populated.
		$parts = [];
		$parts[] = "### 🧠 MEMORY (recall layer 0.5)";
		if ( ! empty( $lines_a ) ) {
			$parts[] = "**📌 User đã dặn (explicit):**\n" . implode( "\n", $lines_a );
		}
		if ( ! empty( $lines_b ) ) {
			$parts[] = "**🧩 Đã biết về user (extracted):**\n" . implode( "\n", $lines_b );
		}
		if ( ! empty( $lines_c ) ) {
			$parts[] = "**🎞️ Episodic:**\n" . implode( "\n", $lines_c );
		}
		if ( ! empty( $lines_d ) ) {
			$parts[] = "**🔁 Rolling summary:**\n" . implode( "\n", $lines_d );
		}
		if ( $line_f !== '' ) {
			$parts[] = "**🌱 Trạng thái cảm xúc (latest):**\n" . $line_f;
		}

		$block = ( count( $parts ) > 1 ) ? implode( "\n\n", $parts ) : '';

		// Cap total length — keep head; tail truncation safer than mid-cut.
		if ( mb_strlen( $block ) > self::BLOCK_CAP_CHARS ) {
			$block = mb_substr( $block, 0, self::BLOCK_CAP_CHARS - 1 ) . '…';
		}

		return [
			'block'      => $block,
			'citations'  => array_values( array_filter( $citations ) ),
			'counts'     => [
				'A' => count( $lines_a ),
				'B' => count( $lines_b ),
				'C' => count( $lines_c ),
				'D' => count( $lines_d ),
				'F' => $line_f !== '' ? 1 : 0,
			],
			'latency_ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
			'source'     => 'legacy',
		];
	}

	/* =================================================================
	 *  Tier helpers
	 * ================================================================ */

	private function format_line( $row, array $tokens ): string {
		$text = trim( (string) ( $row->memory_text ?? '' ) );
		if ( $text === '' ) return '';
		$type = (string) ( $row->memory_type ?? 'fact' );
		$id   = (int)    ( $row->id          ?? 0 );
		// Cap individual lines so a single noisy memory can't blow budget.
		if ( mb_strlen( $text ) > 220 ) {
			$text = mb_substr( $text, 0, 219 ) . '…';
		}
		$cite = $id > 0 ? sprintf( ' [mem:U#%d]', $id ) : '';
		return sprintf( '- [%s] %s%s', $type, $text, $cite );
	}

	private function cite( $row ): array {
		$id = (int) ( $row->id ?? 0 );
		if ( $id <= 0 ) return [];
		return [
			'token' => sprintf( '[mem:U#%d]', $id ),
			'id'    => $id,
			'type'  => (string) ( $row->memory_type ?? 'fact' ),
			'tier'  => (string) ( $row->memory_tier ?? 'extracted' ),
		];
	}

	/**
	 * Cheap keyword overlap — count of distinct tokens appearing in text.
	 */
	private function keyword_overlap( string $text, array $tokens ): int {
		if ( empty( $tokens ) ) return 0;
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$hits  = 0;
		foreach ( $tokens as $t ) {
			$t = (string) $t;
			if ( $t === '' ) continue;
			if ( mb_strpos( $lower, mb_strtolower( $t, 'UTF-8' ) ) !== false ) {
				$hits++;
			}
		}
		return $hits;
	}

	private function collect_episodic( int $user_id, string $session_id, array $tokens, array &$citations ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_memory_episodic';
		if ( ! bizcity_tbl_exists( $table ) ) { // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			return [];
		}
		$sql = "SELECT * FROM {$table}
		         WHERE blog_id = %d AND ( user_id = %d OR session_id = %s )
		         ORDER BY created_at DESC LIMIT %d";
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			$sql, get_current_blog_id(), $user_id, $session_id, self::TIER_C_CAP * 3
		) );
		if ( empty( $rows ) ) return [];

		$lines = [];
		foreach ( $rows as $r ) {
			$text = (string) ( $r->summary ?? $r->content ?? $r->memory_text ?? '' );
			if ( $text === '' ) continue;
			if ( ! empty( $tokens ) && $this->keyword_overlap( $text, $tokens ) === 0 ) continue;
			if ( mb_strlen( $text ) > 200 ) $text = mb_substr( $text, 0, 199 ) . '…';
			$id   = (int) ( $r->id ?? 0 );
			$cite = $id > 0 ? sprintf( ' [mem:E#%d]', $id ) : '';
			$lines[] = '- ' . $text . $cite;
			if ( $id > 0 ) {
				$citations[] = [
					'token' => sprintf( '[mem:E#%d]', $id ),
					'id'    => $id,
					'type'  => 'episodic',
					'tier'  => 'episodic',
				];
			}
			if ( count( $lines ) >= self::TIER_C_CAP ) break;
		}
		return $lines;
	}

	private function collect_rolling( int $user_id, string $session_id, array &$citations ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_memory_rolling';
		if ( ! bizcity_tbl_exists( $table ) ) { // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			return [];
		}
		/* Wave 2.8d D6.9g (2026-05-24) — schema-tolerant recall.
		 * `bizcity_memory_rolling` upstream schema (class-rolling-memory.php)
		 * KHÔNG có column blog_id; field summary tên là `window_summary`.
		 * Production trước đây query `WHERE blog_id = %d` → SQL error +
		 * Tier D rỗng. Detect cột thật trước khi build WHERE/SELECT. */
		$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		$cols = array_map( 'strtolower', $cols );
		$has_blog    = in_array( 'blog_id', $cols, true );
		$has_summary = in_array( 'summary', $cols, true );
		$has_window  = in_array( 'window_summary', $cols, true );
		$has_content = in_array( 'content', $cols, true );

		$where  = '( user_id = %d OR session_id = %s )';
		$params = [ $user_id, $session_id ];
		if ( $has_blog ) {
			$where  = 'blog_id = %d AND ' . $where;
			array_unshift( $params, get_current_blog_id() );
		}
		$params[] = self::TIER_D_CAP;

		$sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC LIMIT %d";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		if ( empty( $rows ) ) return [];

		$lines = [];
		foreach ( $rows as $r ) {
			$text = '';
			if ( $has_window && ! empty( $r->window_summary ) ) {
				$text = (string) $r->window_summary;
			} elseif ( $has_summary && ! empty( $r->summary ) ) {
				$text = (string) $r->summary;
			} elseif ( $has_content && ! empty( $r->content ) ) {
				$text = (string) $r->content;
			} else {
				$text = (string) ( $r->window_summary ?? $r->summary ?? $r->content ?? '' );
			}
			if ( $text === '' ) continue;
			if ( mb_strlen( $text ) > 240 ) $text = mb_substr( $text, 0, 239 ) . '…';
			$id   = (int) ( $r->id ?? 0 );
			$cite = $id > 0 ? sprintf( ' [mem:R#%d]', $id ) : '';
			$lines[] = '- ' . $text . $cite;
			if ( $id > 0 ) {
				$citations[] = [
					'token' => sprintf( '[mem:R#%d]', $id ),
					'id'    => $id,
					'type'  => 'rolling',
					'tier'  => 'rolling',
				];
			}
		}
		return $lines;
	}

	private function empty_result( float $t0 ): array {
		return [
			'block'      => '',
			'citations'  => [],
			'counts'     => [ 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0 ],
			'latency_ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
		];
	}

	/* =================================================================
	 *  Wave 2.8d TBR.MEM-D6 — Unified read path
	 * ================================================================ */

	/**
	 * Read all 4 tiers from unified `bizcity_memory` via 1 indexed query.
	 *
	 * Citation tokens use `legacy_id` (preserved by mirror writer) so that
	 * downstream Final_Composer + REST `/citations/resolve` continue to
	 * round-trip identical `[mem:U#<id>]` / `[mem:E#<id>]` / `[mem:R#<id>]`
	 * tokens regardless of read source — guarantees zero-drift cutover.
	 *
	 * @return array|null  Returns null when table missing so caller falls back.
	 */
	private function collect_from_unified( int $user_id, string $session_id, array $tokens, float $t0 ) {
		global $wpdb;
		$table = BizCity_Memory_Unified_Installer::table();
		if ( ! bizcity_tbl_exists( $table ) ) { // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			return null; // table not provisioned → fall back to legacy
		}

		$blog_id = get_current_blog_id();

		// Single query pulls top 200 candidates across all 4 classes. We then
		// bucket + filter in PHP. Indexed by idx_user_class + idx_class_score.
		// [2026-07-09 Johnny Chu] HOTFIX — keep unified read scoping parity with
		// legacy get_memories(): logged-in => user_id only; guest => session_id when provided.
		$where_sql = 'blog_id = %d AND memory_class IN (\'user\',\'episodic\',\'rolling\')';
		$params    = [ $blog_id ];
		if ( $user_id > 0 ) {
			$where_sql .= ' AND user_id = %d';
			$params[]   = $user_id;
		} elseif ( $session_id !== '' ) {
			$where_sql .= ' AND session_id = %s';
			$params[]   = $session_id;
		}
		$params[] = 200;

		$sql = "SELECT id, legacy_id, memory_class, memory_tier, memory_type,
		               memory_key, memory_text, score, importance,
		               event_type, goal, goal_label, window_summary,
		               window_turn_count, status, updated_at, created_at
		         FROM {$table}
		         WHERE {$where_sql}
		         ORDER BY score DESC, updated_at DESC
		         LIMIT %d";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		$citations = [];
		$lines_a   = [];
		$lines_b   = [];
		$lines_c   = [];
		$lines_d   = [];

		// Bucket rows by class.
		$bucket_user_explicit  = [];
		$bucket_user_extracted = [];
		$bucket_episodic       = [];
		$bucket_rolling        = [];
		foreach ( $rows as $r ) {
			$class = (string) ( $r->memory_class ?? '' );
			if ( $class === 'user' ) {
				$tier = (string) ( $r->memory_tier ?? 'extracted' );
				if ( $tier === 'explicit' ) $bucket_user_explicit[] = $r;
				else                         $bucket_user_extracted[] = $r;
			} elseif ( $class === 'episodic' ) {
				$bucket_episodic[] = $r;
			} elseif ( $class === 'rolling' ) {
				$bucket_rolling[] = $r;
			}
		}

		// ── Tier A — explicit (cap 20) ──
		$bucket_user_explicit = array_slice( $bucket_user_explicit, 0, self::TIER_A_CAP );
		foreach ( $bucket_user_explicit as $r ) {
			$line = $this->format_line_unified( $r, $tokens, 'U' );
			if ( $line === '' ) continue;
			$lines_a[] = $line;
			$cite = $this->cite_unified( $r, 'U' );
			if ( $cite ) $citations[] = $cite;
		}

		// ── Tier B — extracted, scored by overlap (cap 12) ──
		$scored = [];
		foreach ( $bucket_user_extracted as $r ) {
			$text    = (string) ( $r->memory_text ?? '' );
			$overlap = $this->keyword_overlap( $text, $tokens );
			$rank    = (int) ( $r->score ?? 0 ) + ( $overlap * 25 );
			$scored[] = [ 'row' => $r, 'rank' => $rank, 'overlap' => $overlap ];
		}
		usort( $scored, static function ( $a, $b ) { return $b['rank'] <=> $a['rank']; } );
		$scored = array_slice( $scored, 0, self::TIER_B_CAP );
		foreach ( $scored as $hit ) {
			if ( ! empty( $tokens ) && $hit['overlap'] === 0 ) continue;
			$line = $this->format_line_unified( $hit['row'], $tokens, 'U' );
			if ( $line === '' ) continue;
			$lines_b[] = $line;
			$cite = $this->cite_unified( $hit['row'], 'U' );
			if ( $cite ) $citations[] = $cite;
		}

		// ── Tier C — episodic (cap 5) ──
		foreach ( $bucket_episodic as $r ) {
			$text = (string) ( $r->memory_text ?? '' );
			if ( $text === '' ) continue;
			if ( ! empty( $tokens ) && $this->keyword_overlap( $text, $tokens ) === 0 ) continue;
			if ( mb_strlen( $text ) > 200 ) $text = mb_substr( $text, 0, 199 ) . '…';
			$legacy_id = (int) ( $r->legacy_id ?? 0 );
			$cite_id   = $legacy_id > 0 ? $legacy_id : (int) ( $r->id ?? 0 );
			$lines_c[] = '- ' . $text . ( $cite_id > 0 ? sprintf( ' [mem:E#%d]', $cite_id ) : '' );
			if ( $cite_id > 0 ) {
				$citations[] = [
					'token' => sprintf( '[mem:E#%d]', $cite_id ),
					'id'    => $cite_id,
					'type'  => 'episodic',
					'tier'  => 'episodic',
				];
			}
			if ( count( $lines_c ) >= self::TIER_C_CAP ) break;
		}

		// ── Tier D — rolling (cap 5) ──
		foreach ( $bucket_rolling as $r ) {
			$text = (string) ( $r->window_summary ?? $r->memory_text ?? '' );
			if ( $text === '' ) continue;
			if ( mb_strlen( $text ) > 240 ) $text = mb_substr( $text, 0, 239 ) . '…';
			$legacy_id = (int) ( $r->legacy_id ?? 0 );
			$cite_id   = $legacy_id > 0 ? $legacy_id : (int) ( $r->id ?? 0 );
			$lines_d[] = '- ' . $text . ( $cite_id > 0 ? sprintf( ' [mem:R#%d]', $cite_id ) : '' );
			if ( $cite_id > 0 ) {
				$citations[] = [
					'token' => sprintf( '[mem:R#%d]', $cite_id ),
					'id'    => $cite_id,
					'type'  => 'rolling',
					'tier'  => 'rolling',
				];
			}
			if ( count( $lines_d ) >= self::TIER_D_CAP ) break;
		}

		// Build block.
		$parts = [];
		$parts[] = "### 🧠 MEMORY (recall layer 0.5 · unified)";
		if ( ! empty( $lines_a ) ) $parts[] = "**📌 User đã dặn (explicit):**\n" . implode( "\n", $lines_a );
		if ( ! empty( $lines_b ) ) $parts[] = "**🧩 Đã biết về user (extracted):**\n" . implode( "\n", $lines_b );
		if ( ! empty( $lines_c ) ) $parts[] = "**🎞️ Episodic:**\n" . implode( "\n", $lines_c );
		if ( ! empty( $lines_d ) ) $parts[] = "**🔁 Rolling summary:**\n" . implode( "\n", $lines_d );

		// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-4 — Tier F (unified path).
		$line_f = $this->collect_session_mood( $session_id );
		if ( $line_f !== '' ) {
			$parts[] = "**🌱 Trạng thái cảm xúc (latest):**\n" . $line_f;
		}

		$block = ( count( $parts ) > 1 ) ? implode( "\n\n", $parts ) : '';
		if ( mb_strlen( $block ) > self::BLOCK_CAP_CHARS ) {
			$block = mb_substr( $block, 0, self::BLOCK_CAP_CHARS - 1 ) . '…';
		}

		return [
			'block'      => $block,
			'citations'  => array_values( array_filter( $citations ) ),
			'counts'     => [
				'A' => count( $lines_a ),
				'B' => count( $lines_b ),
				'C' => count( $lines_c ),
				'D' => count( $lines_d ),
				'F' => $line_f !== '' ? 1 : 0,
			],
			'latency_ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
			'source'     => 'unified',
		];
	}

	private function format_line_unified( $row, array $tokens, string $cite_ns ): string {
		$text = trim( (string) ( $row->memory_text ?? '' ) );
		if ( $text === '' ) return '';
		$type = (string) ( $row->memory_type ?? 'fact' );
		$legacy_id = (int) ( $row->legacy_id ?? 0 );
		$cite_id   = $legacy_id > 0 ? $legacy_id : (int) ( $row->id ?? 0 );
		if ( mb_strlen( $text ) > 220 ) $text = mb_substr( $text, 0, 219 ) . '…';
		$cite = $cite_id > 0 ? sprintf( ' [mem:%s#%d]', $cite_ns, $cite_id ) : '';
		return sprintf( '- [%s] %s%s', $type, $text, $cite );
	}

	private function cite_unified( $row, string $cite_ns ): array {
		$legacy_id = (int) ( $row->legacy_id ?? 0 );
		$cite_id   = $legacy_id > 0 ? $legacy_id : (int) ( $row->id ?? 0 );
		if ( $cite_id <= 0 ) return [];
		return [
			'token' => sprintf( '[mem:%s#%d]', $cite_ns, $cite_id ),
			'id'    => $cite_id,
			'type'  => (string) ( $row->memory_type ?? 'fact' ),
			'tier'  => (string) ( $row->memory_tier ?? 'extracted' ),
		];
	}

	/**
	 * [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-4 — Tier F (Feelings).
	 * Reads the latest sampled mood for the active session via
	 * Sessions_Manager and renders a single bullet line. Returns '' when
	 * no session_id, no Sessions_Manager, or no mood sampled yet.
	 */
	private function collect_session_mood( string $session_id ): string {
		if ( $session_id === '' ) return '';
		if ( ! class_exists( 'BizCity_TwinBrain_Sessions_Manager' ) ) return '';
		try {
			$mgr = BizCity_TwinBrain_Sessions_Manager::instance();
			if ( ! method_exists( $mgr, 'latest_mood' ) ) return '';
			$mood = $mgr->latest_mood( $session_id );
		} catch ( \Throwable $e ) {
			return '';
		}
		if ( ! is_array( $mood ) || empty( $mood ) ) return '';

		$valence    = isset( $mood['valence'] ) ? (float) $mood['valence'] : 0.0;
		$label      = (string) ( $mood['label'] ?? '' );
		$turn_index = (int)    ( $mood['turn_index'] ?? 0 );
		if ( $label === '' ) $label = 'neutral';

		return sprintf(
			'- valence=%s (%s) — sampled at turn %d',
			number_format( $valence, 2 ),
			$label,
			$turn_index
		);
	}
}
