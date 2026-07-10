<?php
/**
 * BizCity TwinBrain Tool Intent Matcher — Stage 1B.
 *
 * Sprint TBR.3 (2026-05-13): cosine match prompt-embedding vs cached
 * skill-description embeddings. Cache lives in transient
 * `bizcity_twinbrain_skill_emb_v1` (24h TTL) keyed by sha1(description)
 * so we don't touch the third-party `bizcity_skills` schema. First call
 * primes the cache with one batched embeddings request; subsequent calls
 * are pure in-PHP cosine.
 *
 * Falls back to the keyword-overlap heuristic when the LLM gateway isn't
 * configured or the user has zero active skills.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinBrain
 * @since      2026-05-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinBrain_Tool_Intent_Matcher {

	const CACHE_KEY    = 'bizcity_twinbrain_skill_emb_v1';
	const CACHE_TTL    = DAY_IN_SECONDS;
	const TOP_K        = 3;
	const SKILL_LIMIT  = 200;

	private static $instance = null;
	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/**
	 * @return array<int,array{skill_slug:string,score:float,reason:string}>
	 */
	public function match( string $prompt, int $user_id, array $opts = [] ): array {
		/* Phase C.2 (PHASE-0.35 / R-MPRT-5) — optional guru scope.
		 * If `guru_id` is set + bridge non-empty, we boost & filter results.
		 * Empty whitelist = guru has no binding row → treat as "no restriction"
		 * (matches BizCity_Guru_Skill_Bridge contract used by CRM policy). */
		$guru_id        = isset( $opts['guru_id'] ) ? (int) $opts['guru_id'] : 0;
		$guru_whitelist = array();
		if ( $guru_id > 0 && class_exists( 'BizCity_Guru_Skill_Bridge' ) ) {
			$guru_whitelist = BizCity_Guru_Skill_Bridge::tools_for_guru( $guru_id );
		}
		$wl_map = array();
		foreach ( $guru_whitelist as $w ) { $wl_map[ (string) $w ] = true; }

		// Forced slugs (e.g. user pinned a tool) win.
		if ( ! empty( $opts['force_slugs'] ) ) {
			$out = [];
			foreach ( array_unique( (array) $opts['force_slugs'] ) as $slug ) {
				$slug = sanitize_key( (string) $slug );
				if ( $slug === '' ) continue;
				$reason = 'forced';
				if ( $guru_id > 0 && ! empty( $wl_map ) && ! isset( $wl_map[ $slug ] ) ) {
					/* R-MPRT-5: forced tool out of guru scope — surface, do NOT drop.
					 * Caller (start_turn) already guards #tool path with HTTP 400; this
					 * branch is for force_tools coming from non-token sources. */
					$reason = 'forced_out_of_guru_scope';
				}
				$out[] = [ 'skill_slug' => $slug, 'score' => 1.0, 'reason' => $reason ];
			}
			return $out;
		}

		$skills = $this->load_user_skills( $user_id );

		/* Phase C.5.1 (PHASE-0.35 / R-MPRT-6) — Inject guru-bound tools that
		 * are NOT in wp_bizcity_skills. Persona providers (tarot, content
		 * creator, ...) register only via filter `bizcity_persona_tool_providers`
		 * so the matcher would never see them. Without this injection, the
		 * +0.05 boost loop would have nothing to boost → tool_candidates=[]
		 * → decide_tool returns no_candidates even though guru has bindings. */
		if ( $guru_id > 0 && ! empty( $wl_map ) ) {
			$skills = $this->inject_guru_tools( $skills, $wl_map );
		}

		if ( empty( $skills ) ) return [];

		// Try cosine first; fall back to keyword overlap on any failure.
		$cosine = $this->match_with_cosine( $prompt, $skills );
		$result = ! empty( $cosine ) ? $cosine : $this->match_keyword_overlap( $prompt, $skills );

		/* Phase C.2 — boost slugs that are in guru.tools_for_guru by +0.05 (cap 1.0)
		 * and re-sort. Annotate `reason` so timeline can show the boost. */
		if ( ! empty( $wl_map ) && ! empty( $result ) ) {
			foreach ( $result as &$r ) {
				if ( isset( $wl_map[ (string) ( $r['skill_slug'] ?? '' ) ] ) ) {
					$boosted    = min( 1.0, (float) ( $r['score'] ?? 0 ) + 0.05 );
					$r['score']  = round( $boosted, 4 );
					$r['reason'] = (string) ( $r['reason'] ?? '' ) . ' +guru_scope';
				}
			}
			unset( $r );
			usort( $result, static function( $a, $b ) { return ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 ); } );
		}

		return $result;
	}

	/* =================================================================
	 *  Sprint TBR.3 — cosine path
	 * ================================================================ */

	public function match_with_cosine( string $prompt, array $skills ): array {
		if ( empty( $skills ) ) return [];

		$prompt_vec = $this->embed_prompt( $prompt );
		if ( empty( $prompt_vec ) ) return [];

		$desc_map = $this->ensure_skill_embeddings( $skills );
		if ( empty( $desc_map ) ) return [];

		$threshold = (float) BIZCITY_TWINBRAIN_TOOL_INTENT_THRESHOLD;
		$ranked    = [];
		foreach ( $skills as $s ) {
			$slug = (string) ( $s['slug'] ?? '' );
			$desc = trim( (string) ( $s['description'] ?? '' ) . ' ' . (string) ( $s['title'] ?? '' ) );
			if ( $slug === '' || $desc === '' ) continue;

			$key = sha1( $desc );
			$vec = $desc_map[ $key ] ?? null;
			if ( ! is_array( $vec ) || empty( $vec ) ) continue;

			$cos = $this->cosine( $prompt_vec, $vec );
			if ( $cos < $threshold ) continue;

			$ranked[] = [
				'skill_slug' => $slug,
				'score'      => round( $cos, 4 ),
				'reason'     => sprintf( 'cosine=%.3f', $cos ),
			];
		}
		usort( $ranked, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return array_slice( $ranked, 0, self::TOP_K );
	}

	/**
	 * Lazily batch-embed every skill description we don't already have cached.
	 * Returns the full sha1=>vector map for the given skill set.
	 */
	private function ensure_skill_embeddings( array $skills ): array {
		$cache = get_transient( self::CACHE_KEY );
		if ( ! is_array( $cache ) ) $cache = [];

		$missing       = [];
		$missing_keys  = [];
		foreach ( $skills as $s ) {
			$desc = trim( (string) ( $s['description'] ?? '' ) . ' ' . (string) ( $s['title'] ?? '' ) );
			if ( $desc === '' ) continue;
			$key = sha1( $desc );
			if ( isset( $cache[ $key ] ) ) continue;
			$missing[]      = $desc;
			$missing_keys[] = $key;
		}

		if ( ! empty( $missing ) && class_exists( 'BizCity_LLM_Client' ) ) {
			try {
				$client = BizCity_LLM_Client::instance();
				if ( ! $client->is_ready() ) return $cache;
				$res = $client->embeddings( $missing, [ 'purpose' => 'twinbrain_tool_intent' ] );
			} catch ( \Throwable $e ) {
				error_log( '[TwinBrain][tool] embed throw: ' . $e->getMessage() );
				return $cache;
			}
			if ( empty( $res['success'] ) || empty( $res['embeddings'] ) ) {
				error_log( '[TwinBrain][tool] embed fail: ' . ( $res['error'] ?? 'unknown' ) );
				return $cache;
			}
			foreach ( $res['embeddings'] as $i => $vec ) {
				if ( ! isset( $missing_keys[ $i ] ) ) continue;
				$cache[ $missing_keys[ $i ] ] = (array) $vec;
			}
			set_transient( self::CACHE_KEY, $cache, self::CACHE_TTL );
		}
		return $cache;
	}

	private function embed_prompt( string $prompt ): array {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) return [];
		try {
			$client = BizCity_LLM_Client::instance();
			if ( ! $client->is_ready() ) return [];
			$res = $client->embeddings( $prompt, [ 'purpose' => 'twinbrain_tool_intent_q' ] );
		} catch ( \Throwable $e ) { return []; }
		if ( empty( $res['success'] ) || empty( $res['embeddings'][0] ) ) return [];
		return (array) $res['embeddings'][0];
	}

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

	/* =================================================================
	 *  Wave 0 fallback — keyword overlap
	 * ================================================================ */

	public function match_keyword_overlap( string $prompt, array $skills ): array {
		$tokens = $this->tokenize( $prompt );
		if ( empty( $tokens ) ) return [];

		$ranked = [];
		foreach ( $skills as $s ) {
			$desc_tokens = $this->tokenize( ( $s['description'] ?? '' ) . ' ' . ( $s['title'] ?? '' ) );
			if ( empty( $desc_tokens ) ) continue;
			$overlap = count( array_intersect_key( $tokens, $desc_tokens ) );
			if ( $overlap === 0 ) continue;
			$score = min( 1.0, $overlap / max( 4, count( $tokens ) ) );
			if ( $score < BIZCITY_TWINBRAIN_TOOL_INTENT_THRESHOLD ) continue;
			$ranked[] = [
				'skill_slug' => (string) $s['slug'],
				'score'      => round( $score, 3 ),
				'reason'     => 'keyword_overlap_x' . $overlap,
			];
		}
		usort( $ranked, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return array_slice( $ranked, 0, self::TOP_K );
	}

	private function load_user_skills( int $user_id ): array {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_skills';
		$prev = $wpdb->suppress_errors( true );
		$found = bizcity_tbl_exists( $tbl ) ? $tbl : null; // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		if ( $found !== $tbl ) {
			$wpdb->suppress_errors( $prev );
			return [];
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT slug, title, description FROM {$tbl} WHERE status = 'active' LIMIT %d",
				self::SKILL_LIMIT
			), ARRAY_A
		);
		$wpdb->suppress_errors( $prev );
		return (array) $rows;
	}

	private function tokenize( string $text ): array {
		$text = mb_strtolower( wp_strip_all_tags( $text ), 'UTF-8' );
		$text = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
		$parts = preg_split( '/\s+/u', trim( (string) $text ) );
		$out = [];
		foreach ( (array) $parts as $p ) {
			if ( mb_strlen( $p, 'UTF-8' ) >= 3 ) $out[ $p ] = true;
		}
		return $out;
	}

	/* =================================================================
	 *  Phase C.5.1 — Guru tool injection (R-MPRT-6 fix)
	 * ================================================================ */

	/**
	 * Walk Layer 2 (`bizcity_register_agent`) + Layer 3
	 * (`bizcity_persona_tool_providers`) to find tool defs whose slug is in
	 * the guru whitelist, and synthesize skill rows {slug,title,description}
	 * so cosine + keyword match can score them. Skips slugs already present
	 * in $skills (avoid double-counting wp_bizcity_skills entries).
	 */
	private function inject_guru_tools( array $skills, array $wl_map ): array {
		$have = array();
		foreach ( $skills as $s ) {
			$slug = (string) ( $s['slug'] ?? '' );
			if ( $slug !== '' ) { $have[ $slug ] = true; }
		}

		$found = array();

		// Layer 2 — agents.
		$agents = apply_filters( 'bizcity_register_agent', array() );
		if ( is_array( $agents ) ) {
			foreach ( $agents as $agent ) {
				$tools = is_array( $agent ) ? ( $agent['tools'] ?? null )
					: ( is_object( $agent ) ? ( $agent->tools ?? null ) : null );
				if ( ! is_array( $tools ) ) { continue; }
				foreach ( $tools as $tool ) {
					$slug = $this->probe_tool_field( $tool, array( 'name', 'id', 'slug' ) );
					if ( $slug === '' || ! isset( $wl_map[ $slug ] ) ) { continue; }
					if ( isset( $found[ $slug ] ) || isset( $have[ $slug ] ) ) { continue; }
					$found[ $slug ] = array(
						'slug'        => $slug,
						'title'       => $this->probe_tool_field( $tool, array( 'tool_label', 'label', 'title' ) ),
						'description' => $this->probe_tool_field( $tool, array( 'description', 'tool_description', 'desc' ) ),
					);
				}
			}
		}

		// Layer 3 — persona providers.
		$providers = apply_filters( 'bizcity_persona_tool_providers', array() );
		if ( is_array( $providers ) ) {
			foreach ( $providers as $p ) {
				if ( ! is_object( $p ) || ! method_exists( $p, 'get_tool_definitions' ) ) { continue; }
				$defs = $p->get_tool_definitions();
				if ( ! is_array( $defs ) ) { continue; }
				foreach ( $defs as $def ) {
					$slug = $this->probe_tool_field( $def, array( 'name', 'id', 'slug' ) );
					if ( $slug === '' || ! isset( $wl_map[ $slug ] ) ) { continue; }
					if ( isset( $found[ $slug ] ) || isset( $have[ $slug ] ) ) { continue; }
					$found[ $slug ] = array(
						'slug'        => $slug,
						'title'       => $this->probe_tool_field( $def, array( 'tool_label', 'label', 'title' ) ),
						'description' => $this->probe_tool_field( $def, array( 'description', 'tool_description', 'desc' ) ),
					);
				}
			}
		}

		if ( empty( $found ) ) { return $skills; }

		foreach ( $found as $row ) {
			if ( $row['title'] === '' ) { $row['title'] = $row['slug']; }
			$skills[] = $row;
		}
		return $skills;
	}

	/** @param mixed $thing */
	private function probe_tool_field( $thing, array $keys ): string {
		foreach ( $keys as $k ) {
			if ( is_array( $thing ) && array_key_exists( $k, $thing ) ) { return (string) $thing[ $k ]; }
			if ( is_object( $thing ) ) {
				if ( isset( $thing->{$k} ) ) { return (string) $thing->{$k}; }
				$g = 'get_' . $k;
				if ( method_exists( $thing, $g ) ) { return (string) $thing->{$g}(); }
			}
		}
		return '';
	}
}
