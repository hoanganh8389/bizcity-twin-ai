<?php
/**
 * BizCity TwinBrain Notebook Selector — Stage 1A.
 *
 * Picks K = 5..7 notebooks most relevant to the user prompt, scored as:
 *   score = 0.7 * cosine(prompt_emb, notebook.perspective_embedding)
 *         + 0.2 * recency
 *         + 0.1 * user_priority
 * Then a diversity filter so the K picked notebooks span distinct topics
 * (greedy MMR — keep candidate only if cosine vs already-picked < 0.85).
 *
 * Sprint TBR.2 (2026-05-13): real cosine over `kg_notebooks.perspective_embedding`
 * (LONGTEXT JSON 1536-d). Falls back to recency-only when:
 *   • LLM gateway not ready → can't embed prompt
 *   • Zero notebooks have perspective_embedding populated yet (PHASE-0.8 cron pending)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinBrain
 * @since      2026-05-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinBrain_Notebook_Selector {

	const W_COSINE   = 0.7;
	const W_RECENCY  = 0.2;
	const W_PRIORITY = 0.1;
	const DIVERSITY_THRESHOLD = 0.85; // skip candidate if cosine vs already picked >= this
	const RECENCY_HALFLIFE_DAYS = 14;

	private static $instance = null;
	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/**
	 * Public entry — preserves existing contract.
	 *
	 * @return array<int,array{notebook_id:int,label:string,score:float,reason:string,guru_uuid:string}>
	 */
	public function select( string $prompt, int $user_id, int $k, array $opts = [] ): array {
		$k = max( 1, min( BIZCITY_TWINBRAIN_K_MAX, $k ) );

		// Forced ids win — clamp to k.
		if ( ! empty( $opts['force_ids'] ) ) {
			$ids  = array_slice( array_unique( array_map( 'intval', (array) $opts['force_ids'] ) ), 0, $k );
			$rows = $this->fetch_notebooks( $ids );
			return $this->shape_rows( $rows, 1.0, 'forced' );
		}

		if ( ! class_exists( 'BizCity_KG_Database' ) ) return [];

		/* PHASE-0.35 / F7.D2 — Guru bucket priority. When user pins/parses
		 * `@guru`, runtime forwards $opts['guru_id']. Notebooks owned by that
		 * character (kg_notebooks.character_id) get reserved slots ABOVE the
		 * generic cosine/density/recency pipeline. Without this, Ask Brain
		 * (whole-KG) ignores guru pin → selector picks 5 unrelated notebooks
		 * → synthesizer constrained to wrong corpus → "no notebook can
		 * interpret Tarot" type misses. */
		$guru_id  = isset( $opts['guru_id'] ) ? (int) $opts['guru_id'] : 0;
		$guru_pre = ( $guru_id > 0 ) ? $this->fetch_guru_notebooks( $guru_id, $user_id, $k ) : [];
		$reserved = array();
		foreach ( $guru_pre as $row ) { $reserved[ (int) $row['notebook_id'] ] = true; }
		$slots_left = max( 0, $k - count( $guru_pre ) );

		// Try cosine first; recency-fallback on any failure.
		$picked = ( $slots_left > 0 )
			? $this->select_with_cosine( $prompt, $user_id, $slots_left + count( $reserved ) )
			: array();
		if ( ! empty( $picked ) ) {
			return $this->merge_with_guru_bucket( $guru_pre, $picked, $reserved, $k );
		}

		/* Phase D.1 (PHASE-0.35 / R-MPRT-1) — Passage-density middle tier.
		 * When kg_notebooks.perspective_embedding is empty (PHASE-0.8 W0.6.E
		 * cron pending), notebook-level cosine returns []. Instead of dropping
		 * straight to recency (which ignores content), score notebooks by their
		 * passage-level cosine match density. Catches the "untracked title but
		 * indexed content" case (e.g. notebook 'test dao tao sp' contains
		 * 838g.txt about FS 836G but title doesn't mention it). */
		$picked = ( $slots_left > 0 )
			? $this->select_by_passage_density( $prompt, $user_id, $slots_left + count( $reserved ) )
			: array();
		if ( ! empty( $picked ) ) {
			return $this->merge_with_guru_bucket( $guru_pre, $picked, $reserved, $k );
		}

		/* Phase D.3 (TBR.SEL-LEX, 2026-05-22) — Lexical / keyword tier.
		 * Last query-aware tier before pure recency. Works even when embeddings
		 * chưa generate (notebook.perspective_embedding và passage.embedding
		 * đều NULL). Tokenize prompt, LIKE search trên notebook title/label/
		 * summary + passage.content, score theo title-hits + body-hits +
		 * token coverage. Tránh case "5 notebooks score 0.50 recency_fallback
		 * unrelated" mà runtime đang gặp khi guru bound + cosine empty. */
		$picked = ( $slots_left > 0 )
			? $this->select_by_keyword( $prompt, $user_id, $slots_left + count( $reserved ) )
			: array();
		if ( ! empty( $picked ) ) {
			return $this->merge_with_guru_bucket( $guru_pre, $picked, $reserved, $k );
		}

		$tail = ( $slots_left > 0 ) ? $this->select_recency_fallback( $user_id, $slots_left + count( $reserved ) ) : array();
		return $this->merge_with_guru_bucket( $guru_pre, $tail, $reserved, $k );
	}

	/* =================================================================
	 *  Phase D.2 — Guru bucket (notebooks bound via character_id)
	 * ================================================================ */

	/**
	 * Fetch notebooks where kg_notebooks.character_id = $guru_id and the user
	 * can read them (owner or shared). Returned in selector shape with reason
	 * `guru_bound` so it stands out in the perspective UI. Score = 1.0 — these
	 * are explicit user pins, deliberately above cosine candidates.
	 */
	private function fetch_guru_notebooks( int $guru_id, int $user_id, int $k ): array {
		global $wpdb;
		$tnb  = BizCity_KG_Database::instance()->tbl_notebooks();
		$prev = $wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, perspective_label
			 FROM {$tnb}
			 WHERE character_id = %d
			   AND owner_id IN (%d, 0)
			 ORDER BY updated_at DESC
			 LIMIT %d",
			$guru_id, $user_id, max( 1, $k )
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );

		if ( empty( $rows ) ) { return array(); }

		$out = array();
		foreach ( $rows as $r ) {
			$nb = (int) $r['id'];
			$out[] = array(
				'notebook_id' => $nb,
				'label'       => (string) ( ! empty( $r['perspective_label'] ) ? $r['perspective_label'] : $r['name'] ),
				'score'       => 1.0,
				'reason'      => sprintf( 'guru_bound character_id=%d', $guru_id ),
				'guru_uuid'   => '',
			);
		}
		return $out;
	}

	/**
	 * Merge guru-bucket (priority) with downstream pipeline result, dedup by
	 * notebook_id, and clamp to $k. Guru bucket always comes first.
	 */
	private function merge_with_guru_bucket( array $guru_pre, array $tail, array $reserved, int $k ): array {
		if ( empty( $guru_pre ) ) {
			return array_slice( $tail, 0, $k );
		}
		$out = $guru_pre;
		foreach ( $tail as $row ) {
			$nb = (int) ( $row['notebook_id'] ?? 0 );
			if ( $nb === 0 || isset( $reserved[ $nb ] ) ) { continue; }
			$out[] = $row;
			if ( count( $out ) >= $k ) { break; }
		}
		return array_slice( $out, 0, $k );
	}

	/* =================================================================
	 *  Sprint TBR.2 — cosine + diversity + recency + priority
	 * ================================================================ */

	/**
	 * Returns the same shape as select(); empty array signals caller to
	 * fall back to recency. Public so diagnostics probe can target it.
	 */
	public function select_with_cosine( string $prompt, int $user_id, int $k ): array {
		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_notebooks();

		// Probe column existence — DDL migration may not have run yet.
		$prev = $wpdb->suppress_errors( true );
		$has_col = (bool) $wpdb->get_var( $wpdb->prepare(
			"SHOW COLUMNS FROM {$tbl} LIKE %s", 'perspective_embedding'
		) );
		if ( ! $has_col ) {
			$wpdb->suppress_errors( $prev );
			return [];
		}

		// Fetch candidate pool (cap at 50 — cosine is in-PHP).
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, perspective_label, perspective_embedding,
			        user_priority, last_summary_at, updated_at
			 FROM {$tbl}
			 WHERE owner_id IN (%d, 0)
			   AND perspective_embedding IS NOT NULL
			   AND perspective_embedding <> ''
			 ORDER BY updated_at DESC
			 LIMIT 50",
			$user_id
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );

		if ( empty( $rows ) ) {
			return [];
		}

		$prompt_vec = $this->embed_prompt( $prompt );
		if ( empty( $prompt_vec ) ) {
			return [];
		}

		// Score each candidate.
		$scored = [];
		$now    = time();
		foreach ( $rows as $r ) {
			$vec = json_decode( (string) $r['perspective_embedding'], true );
			if ( ! is_array( $vec ) || empty( $vec ) ) continue;

			$cos = $this->cosine( $prompt_vec, $vec );
			if ( $cos <= 0 ) continue;

			$rec_ts = $r['last_summary_at'] ? strtotime( $r['last_summary_at'] ) : strtotime( $r['updated_at'] );
			$age_days = max( 0.0, ( $now - (int) $rec_ts ) / 86400.0 );
			$recency  = exp( - $age_days / self::RECENCY_HALFLIFE_DAYS ); // [0..1]

			$priority = max( 0.0, min( 1.0, ( (int) $r['user_priority'] ) / 5.0 ) );

			$score = self::W_COSINE * $cos + self::W_RECENCY * $recency + self::W_PRIORITY * $priority;

			$scored[] = [
				'id'        => (int) $r['id'],
				'name'      => (string) $r['name'],
				'label'     => (string) ( $r['perspective_label'] ?: $r['name'] ),
				'vec'       => $vec,
				'score'     => $score,
				'cos'       => $cos,
				'recency'   => $recency,
				'priority'  => $priority,
			];
		}

		if ( empty( $scored ) ) return [];

		usort( $scored, static fn( $a, $b ) => $b['score'] <=> $a['score'] );

		// Greedy MMR diversity — drop candidate too similar to one already picked.
		$picked = [];
		foreach ( $scored as $cand ) {
			$too_close = false;
			foreach ( $picked as $p ) {
				if ( $this->cosine( $cand['vec'], $p['vec'] ) >= self::DIVERSITY_THRESHOLD ) {
					$too_close = true;
					break;
				}
			}
			if ( ! $too_close ) {
				$picked[] = $cand;
			}
			if ( count( $picked ) >= $k ) break;
		}

		// If diversity filter starved us, top up by score (still better than fallback).
		if ( count( $picked ) < $k ) {
			foreach ( $scored as $cand ) {
				if ( count( $picked ) >= $k ) break;
				$dup = false;
				foreach ( $picked as $p ) { if ( $p['id'] === $cand['id'] ) { $dup = true; break; } }
				if ( ! $dup ) $picked[] = $cand;
			}
		}

		$out = [];
		foreach ( $picked as $p ) {
			$out[] = [
				'notebook_id' => $p['id'],
				'label'       => $p['label'],
				'score'       => round( $p['score'], 4 ),
				'reason'      => sprintf(
					'cosine=%.3f recency=%.2f priority=%.2f',
					$p['cos'], $p['recency'], $p['priority']
				),
				'guru_uuid'   => '',
			];
		}
		return $out;
	}

	/* =================================================================
	 *  Phase D.1 — Passage-density middle tier
	 * ================================================================ */

	/**
	 * Score notebooks by aggregating cosine over their passages instead of a
	 * single notebook-level perspective_embedding. Keeps the same return shape
	 * as select_with_cosine(); empty array signals caller to drop to recency.
	 * Public so diagnostics can probe it.
	 */
	public function select_by_passage_density( string $prompt, int $user_id, int $k ): array {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return [];

		$db   = BizCity_KG_Database::instance();
		$tp   = $db->tbl_passages();
		$tnb  = $db->tbl_notebooks();

		$prev = $wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.notebook_id, p.embedding,
			        nb.name, nb.perspective_label
			 FROM {$tp} p
			 INNER JOIN {$tnb} nb ON nb.id = p.notebook_id
			 WHERE nb.owner_id IN (%d, 0)
			   AND p.embedding IS NOT NULL AND p.embedding <> ''
			   AND p.extraction_status = 'done'
			 ORDER BY p.id DESC
			 LIMIT 300",
			$user_id
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );

		if ( empty( $rows ) ) return [];

		$pvec = $this->embed_prompt( $prompt );
		if ( empty( $pvec ) ) return [];

		// Aggregate per-notebook: best cosine + density.
		$acc = [];
		foreach ( $rows as $r ) {
			$vec = json_decode( (string) $r['embedding'], true );
			if ( ! is_array( $vec ) || empty( $vec ) ) continue;
			$cos = $this->cosine( $pvec, $vec );
			if ( $cos <= 0.20 ) continue; // noise floor

			$nb = (int) $r['notebook_id'];
			if ( ! isset( $acc[ $nb ] ) ) {
				$acc[ $nb ] = [
					'best'  => 0.0, 'sum' => 0.0, 'count' => 0,
					'name'  => (string) $r['name'],
					'label' => (string) ( $r['perspective_label'] ?: $r['name'] ),
				];
			}
			if ( $cos > $acc[ $nb ]['best'] ) $acc[ $nb ]['best'] = $cos;
			$acc[ $nb ]['sum']   += $cos;
			$acc[ $nb ]['count'] += 1;
		}

		if ( empty( $acc ) ) return [];

		// Score = 0.7 * best + 0.3 * avg — prefer notebooks with strong AND many matches.
		$out = [];
		foreach ( $acc as $nb => $a ) {
			$avg   = $a['count'] > 0 ? ( $a['sum'] / $a['count'] ) : 0.0;
			$score = 0.7 * $a['best'] + 0.3 * $avg;
			$out[] = [
				'notebook_id' => $nb,
				'label'       => $a['label'],
				'score'       => round( $score, 4 ),
				'reason'      => sprintf( 'passage_density best=%.3f avg=%.3f n=%d', $a['best'], $avg, $a['count'] ),
				'guru_uuid'   => '',
			];
		}

		usort( $out, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return array_slice( $out, 0, $k );
	}

	/* =================================================================
	 *  Math helpers
	 * ================================================================ */

	private function cosine( array $a, array $b ): float {
		$n = min( count( $a ), count( $b ) );
		if ( $n === 0 ) return 0.0;
		$dot = 0.0; $na = 0.0; $nb = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$x = (float) $a[ $i ]; $y = (float) $b[ $i ];
			$dot += $x * $y; $na += $x * $x; $nb += $y * $y;
		}
		if ( $na <= 0 || $nb <= 0 ) return 0.0;
		return $dot / ( sqrt( $na ) * sqrt( $nb ) );
	}

	private function embed_prompt( string $prompt ): array {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) return [];
		try {
			$client = BizCity_LLM_Client::instance();
			if ( ! $client->is_ready() ) return [];
			$res = $client->embeddings( $prompt, [ 'purpose' => 'twinbrain_selector' ] );
		} catch ( \Throwable $e ) {
			error_log( '[TwinBrain][selector] embed throw: ' . $e->getMessage() );
			return [];
		}
		if ( empty( $res['success'] ) || empty( $res['embeddings'][0] ) ) {
			error_log( '[TwinBrain][selector] embed fail: ' . ( $res['error'] ?? 'unknown' ) );
			return [];
		}
		return (array) $res['embeddings'][0];
	}

	/* =================================================================
	 *  Phase D.3 — Lexical / keyword tier (TBR.SEL-LEX, 2026-05-22)
	 * ================================================================ */

	/**
	 * Token-based match scoring. Tokenize prompt, LIKE on
	 *   - kg_notebooks.name / perspective_label / perspective_summary   (title hit)
	 *   - kg_passages.content                                            (body hit)
	 * Score = 3 * title_hits + log(1 + body_hits) + 2 * token_coverage.
	 * Designed for "embeddings chưa populate" case khi cosine + density
	 * đều trả [] — thay vì recency mù, vẫn match được "giấy cellox" →
	 * notebook chứa từ "cellox" trong body.
	 *
	 * Returns same shape as other select_* methods. Empty array → caller
	 * tiếp tục fall through tới recency_fallback.
	 *
	 * Public so diagnostics có thể probe trực tiếp.
	 */
	public function select_by_keyword( string $prompt, int $user_id, int $k ): array {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return [];

		$tokens = self::tokenize_for_search( $prompt );
		if ( empty( $tokens ) ) return [];

		$db  = BizCity_KG_Database::instance();
		$tnb = $db->tbl_notebooks();
		$tp  = $db->tbl_passages();

		$acc = []; // notebook_id => { tokens, title_hits, body_hits, name, label }

		foreach ( $tokens as $tok ) {
			$like = '%' . $wpdb->esc_like( $tok ) . '%';
			$prev = $wpdb->suppress_errors( true );

			// 1) Title-side hits: name / perspective_label / perspective_summary.
			$title_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id AS notebook_id, name, perspective_label
				 FROM {$tnb}
				 WHERE owner_id IN (%d, 0)
				   AND ( name LIKE %s
				      OR perspective_label LIKE %s
				      OR perspective_summary LIKE %s )
				 LIMIT 30",
				$user_id, $like, $like, $like
			), ARRAY_A );

			if ( is_array( $title_rows ) ) {
				foreach ( $title_rows as $r ) {
					$nb = (int) $r['notebook_id'];
					if ( ! isset( $acc[ $nb ] ) ) {
						$acc[ $nb ] = [
							'tokens'     => [],
							'title_hits' => 0,
							'body_hits'  => 0,
							'name'       => (string) $r['name'],
							'label'      => (string) ( $r['perspective_label'] ?: $r['name'] ),
						];
					}
					$acc[ $nb ]['tokens'][ $tok ] = true;
					$acc[ $nb ]['title_hits'] += 1;
				}
			}

			// 2) Body-side hits: aggregate count per notebook from passages.
			$body_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.notebook_id, COUNT(*) AS hit_count, nb.name, nb.perspective_label
				 FROM {$tp} p
				 INNER JOIN {$tnb} nb ON nb.id = p.notebook_id
				 WHERE nb.owner_id IN (%d, 0)
				   AND p.content LIKE %s
				 GROUP BY p.notebook_id
				 ORDER BY hit_count DESC
				 LIMIT 30",
				$user_id, $like
			), ARRAY_A );

			if ( is_array( $body_rows ) ) {
				foreach ( $body_rows as $r ) {
					$nb = (int) $r['notebook_id'];
					if ( ! isset( $acc[ $nb ] ) ) {
						$acc[ $nb ] = [
							'tokens'     => [],
							'title_hits' => 0,
							'body_hits'  => 0,
							'name'       => (string) $r['name'],
							'label'      => (string) ( $r['perspective_label'] ?: $r['name'] ),
						];
					}
					$acc[ $nb ]['tokens'][ $tok ] = true;
					$acc[ $nb ]['body_hits'] += (int) $r['hit_count'];
				}
			}

			$wpdb->suppress_errors( $prev );
		}

		if ( empty( $acc ) ) return [];

		$token_total = max( 1, count( $tokens ) );
		$out = [];
		foreach ( $acc as $nb => $a ) {
			$cov     = count( $a['tokens'] ) / $token_total; // [0..1]
			$th      = min( 3, $a['title_hits'] );           // cap title boost
			$score   = ( 3.0 * $th ) + log( 1 + $a['body_hits'] ) + ( 2.0 * $cov );
			$matched = array_keys( $a['tokens'] );
			$out[]   = [
				'notebook_id' => $nb,
				'label'       => $a['label'],
				'score'       => round( $score, 4 ),
				'reason'      => sprintf(
					'keyword title=%d body=%d cov=%d/%d hits=[%s]',
					$a['title_hits'], $a['body_hits'],
					count( $matched ), $token_total,
					implode( ',', array_slice( $matched, 0, 5 ) )
				),
				'guru_uuid'   => '',
			];
		}

		usort( $out, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return array_slice( $out, 0, $k );
	}

	/**
	 * Tokenize prompt for lexical search. Strip @mentions / URLs /
	 * punctuation, lowercase (mb-aware), drop stopwords (VN + EN), drop
	 * tokens < 3 chars, dedup, cap at 8 to bound SQL cost.
	 *
	 * Public + static so other layers (runtime emit `brain_keywords`,
	 * Perspective_Runner passage rerank, FE highlight) đều dùng cùng 1
	 * tokenizer → trace + UI luôn nhất quán. (TBR.SEL-LEX 2026-05-22)
	 */
	public static function tokenize_for_search( string $prompt ): array {
		$clean = preg_replace( '/@[a-z0-9_\-]+/iu', ' ', $prompt );
		$clean = preg_replace( '#\bhttps?://\S+#i', ' ', (string) $clean );
		$clean = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', (string) $clean );
		$clean = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $clean ) : strtolower( (string) $clean );

		static $stop = null;
		if ( $stop === null ) {
			$stop = array_flip( [
				// VN function words / interrogatives
				'và','là','của','có','không','được','cho','từ','để','một','các','những',
				'này','đó','với','như','sẽ','đã','thì','mà','hay','hoặc','nhưng','vì',
				'làm','sao','nào','đâu','bao','hãy','vui','lòng','tôi','bạn','mình',
				'ở','trên','dưới','trong','ngoài','sau','trước','về','vào','ra','lên','xuống',
				'gì','ai','xin','rất','đang','nếu','khi','còn','cũng','thêm','rồi','chưa',
				'cần','muốn','giúp','biết','nói','xem','dùng','phải','nên','theo','bằng',
				// EN function words
				'the','and','or','for','of','to','in','on','at','by','with','as','an','a',
				'is','are','was','were','be','been','being','do','does','did','have','has',
				'this','that','these','those','what','which','who','how','why','when','where',
			] );
		}

		$parts = preg_split( '/\s+/u', trim( (string) $clean ) );
		if ( ! is_array( $parts ) ) return [];

		$out  = [];
		$seen = [];
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( $p === '' ) continue;
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $p ) : strlen( $p );
			if ( $len < 3 ) continue;
			if ( isset( $stop[ $p ] ) ) continue;
			if ( isset( $seen[ $p ] ) ) continue;
			$seen[ $p ] = true;
			$out[]      = $p;
			if ( count( $out ) >= 8 ) break;
		}
		return $out;
	}

	/* =================================================================
	 *  Recency fallback (Wave 0 path, kept for resilience)
	 * ================================================================ */

	private function select_recency_fallback( int $user_id, int $k ): array {
		global $wpdb;
		$tbl  = BizCity_KG_Database::instance()->tbl_notebooks();
		$prev = $wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name FROM {$tbl}
			 WHERE owner_id IN (%d, 0)
			 ORDER BY updated_at DESC
			 LIMIT %d",
			$user_id, $k
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );
		return $this->shape_rows( (array) $rows, 0.5, 'recency_fallback' );
	}

	private function fetch_notebooks( array $ids ): array {
		global $wpdb;
		if ( empty( $ids ) || ! class_exists( 'BizCity_KG_Database' ) ) return [];
		$tbl = BizCity_KG_Database::instance()->tbl_notebooks();
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$prev = $wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM {$tbl} WHERE id IN ({$ph})", $ids ), ARRAY_A );
		$wpdb->suppress_errors( $prev );
		return (array) $rows;
	}

	private function shape_rows( array $rows, float $score, string $reason ): array {
		$out = [];
		foreach ( $rows as $r ) {
			$out[] = [
				'notebook_id' => (int) $r['id'],
				'label'       => (string) ( $r['name'] ?? '' ),
				'score'       => $score,
				'reason'      => $reason,
				'guru_uuid'   => '',
			];
		}
		return $out;
	}
}
