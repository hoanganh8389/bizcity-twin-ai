<?php
/**
 * Bizcity Twin AI — KG_Cost_Guard
 *
 * Phase 0.5 Sprint 1.
 *
 * Hard guard against runaway LLM/embedding costs.
 *
 *  - Per-user daily passage extraction quota
 *  - Site-wide daily USD cap (hard stop)
 *  - Dedupe by content hash (skip identical passages)
 *  - Records every billable operation into bizcity_kg_usage_log
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Cost_Guard {

	const OPT_QUOTA_USER = 'bizcity_kg_daily_quota_per_user';   // passages
	const OPT_CAP_USD    = 'bizcity_kg_daily_cap_usd';          // dollars (float)
	const OPT_DEDUPE_TH  = 'bizcity_kg_dedupe_cosine_threshold';// 0..1
	const OPT_BATCH_SIZE = 'bizcity_kg_extract_batch_size';     // passages per LLM call
	const OPT_ENABLED    = 'bizcity_kg_cost_guard_enabled';

	const DEFAULT_QUOTA_USER = 50;
	const DEFAULT_CAP_USD    = 5.0;
	const DEFAULT_DEDUPE_TH  = 0.92;
	const DEFAULT_BATCH_SIZE = 5;

	// Approximate pricing (USD per 1k tokens) — gpt-4o-mini & text-embedding-3-small.
	const PRICE_EXTRACT_INPUT_PER_1K  = 0.00015;
	const PRICE_EXTRACT_OUTPUT_PER_1K = 0.00060;
	const PRICE_EMBED_PER_1K          = 0.00002;

	const OP_EXTRACT = 'extract';
	const OP_EMBED   = 'embed';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_kg_usage_log';
	}

	/**
	 * Run by KG_Database migration.
	 */
	public function ensure_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cs = $wpdb->get_charset_collate();
		$t  = $this->table();
		dbDelta( "CREATE TABLE IF NOT EXISTS {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			day DATE NOT NULL,
			operation VARCHAR(20) NOT NULL,
			notebook_id BIGINT UNSIGNED DEFAULT NULL,
			passage_id BIGINT UNSIGNED DEFAULT NULL,
			input_tokens INT UNSIGNED DEFAULT 0,
			output_tokens INT UNSIGNED DEFAULT 0,
			cost_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
			meta TEXT,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY day_op (day, operation),
			KEY user_day (user_id, day)
		) {$cs};" );
	}

	/* ── Public guard API ───────────────────────────────────────────────── */

	/**
	 * All config values come from filters — KG Cost Guard never reads options directly.
	 * On standalone installs (no LLM Router), hardcoded DEFAULT_* constants apply.
	 * When bizcity-llm-router is active it hooks these filters and reads from sitemeta.
	 */

	public function is_enabled(): bool {
		return (bool) apply_filters( 'bizcity_kg_cost_guard_enabled', true );
	}

	public function quota_per_user(): int {
		return max( 1, (int) apply_filters( 'bizcity_kg_quota_per_user', self::DEFAULT_QUOTA_USER ) );
	}

	public function daily_cap_usd(): float {
		return max( 0.10, (float) apply_filters( 'bizcity_kg_daily_cap_usd', self::DEFAULT_CAP_USD ) );
	}

	public function dedupe_threshold(): float {
		return max( 0.5, min( 1.0, (float) apply_filters( 'bizcity_kg_dedupe_cosine_threshold', self::DEFAULT_DEDUPE_TH ) ) );
	}

	public function batch_size(): int {
		return max( 1, min( 20, (int) apply_filters( 'bizcity_kg_extract_batch_size', self::DEFAULT_BATCH_SIZE ) ) );
	}

	/**
	 * Decide whether an extract operation is allowed right now.
	 *
	 * @return true|WP_Error  true if OK, WP_Error('quota_exceeded'|'cap_exceeded') otherwise
	 */
	public function can_extract( $user_id, $estimated_passages = 1 ) {
		if ( ! $this->is_enabled() ) {
			return true;
		}

		$user_id = (int) $user_id;
		$diag    = $this->diagnose_quota_chain( $user_id );

		/**
		 * Filter: bizcity_kg_user_is_exempt
		 * Return true to bypass all KG quota checks for this user.
		 * LLM Router hooks this to exempt admins + the explicit exempt-users list.
		 */
		if ( (bool) apply_filters( 'bizcity_kg_user_is_exempt', false, $user_id ) ) {
			return true;
		}

		// R-GW-8 fallback: when bizcity-llm-router is NOT on this client site
		// the `bizcity_kg_user_is_exempt` filter is never hooked, so the central
		// exception list never applies. Fetch entitlement from bizcity.vn (cached
		// transient) and treat tier>=paid OR balance>=$0.001 OR explicit bypass
		// flag as exempt. This makes the central "Ngoại lệ quota (User IDs)" work
		// end-to-end as long as that user pays for credits.
		if ( $diag['entitlement_bypass'] ) {
			return true;
		}

		// 1. Site-wide USD cap
		$spent = $this->spent_today_usd();
		if ( $spent >= $this->daily_cap_usd() ) {
			$msg  = sprintf( 'Site-wide daily cap reached: $%.2f / $%.2f', $spent, $this->daily_cap_usd() );
			$msg .= "\n" . $this->compose_diagnostic_hint( $diag );
			return new WP_Error( 'cap_exceeded', $msg, array_merge( $diag, [
				'spent_usd' => $spent,
				'cap_usd'   => $this->daily_cap_usd(),
				'user_id'   => $user_id,
			] ) );
		}

		// 2. Per-user quota
		$used = $this->user_passages_today( $user_id );
		if ( $used + $estimated_passages > $this->quota_per_user() ) {
			$msg  = sprintf( 'User #%d: %d / %d passages today', $user_id, $used, $this->quota_per_user() );
			$msg .= "\n" . $this->compose_diagnostic_hint( $diag );
			return new WP_Error( 'quota_exceeded', $msg, array_merge( $diag, [
				'used'    => $used,
				'cap'     => $this->quota_per_user(),
				'user_id' => $user_id,
			] ) );
		}

		return true;
	}

	/**
	 * Inspect the filter chain + bizcity.vn entitlement to build a structured
	 * diagnostic snapshot. Used by can_extract() to enrich quota errors so the
	 * FE can show actionable hints (e.g. "central settings không sync xuống
	 * client" or "user chưa nạp credit").
	 *
	 * @return array {
	 *   quota_per_user, quota_filter_hooked, exempt_filter_hooked,
	 *   has_llm_router_local, has_llm_client, entitlement_status,
	 *   entitlement_tier, entitlement_balance, entitlement_bypass,
	 *   entitlement_message
	 * }
	 */
	protected function diagnose_quota_chain( int $user_id ): array {
		$quota_hooked  = (bool) has_filter( 'bizcity_kg_quota_per_user' );
		$exempt_hooked = (bool) has_filter( 'bizcity_kg_user_is_exempt' );
		$has_router    = class_exists( 'BizCity_Router_KG_Bridge' );
		$has_client    = class_exists( 'BizCity_LLM_Client' );

		$ent_status  = $has_client ? 'pending' : 'missing';
		$ent_tier    = '';
		$ent_balance = 0.0;
		$ent_bypass  = false;
		$ent_msg     = '';

		if ( $has_client && $user_id > 0 ) {
			// Short-lived per-request cache so a tick that already paid the round-trip
			// once doesn't pay it again for site-cap + per-user-quota checks.
			static $ent_cache = [];
			$key = 'ent_' . $user_id;
			if ( ! isset( $ent_cache[ $key ] ) ) {
				$ent_cache[ $key ] = BizCity_LLM_Client::instance()->get_entitlement( $user_id, [ 'timeout' => 3 ] );
			}
			$ent = $ent_cache[ $key ];
			if ( is_wp_error( $ent ) ) {
				$ent_status = 'error';
				$ent_msg    = $ent->get_error_message();
			} elseif ( is_array( $ent ) ) {
				$ent_status  = 'ok';
				$ent_tier    = isset( $ent['tier'] )        ? (string) $ent['tier']        : '';
				$ent_balance = isset( $ent['balance_usd'] ) ? (float)  $ent['balance_usd'] : 0.0;
				// Treat as exempt when: explicit bypass flag, OR paid/enterprise tier,
				// OR balance > free-tier threshold ($0.001 — mirrors central).
				$ent_bypass = ! empty( $ent['bypass'] )
					|| in_array( $ent_tier, [ 'paid', 'enterprise', 'pro' ], true )
					|| $ent_balance >= 0.001;
				if ( ! empty( $ent['_degraded']['message'] ) ) {
					$ent_msg = (string) $ent['_degraded']['message'];
				}
			}
		}

		return [
			'quota_per_user'         => $this->quota_per_user(),
			'quota_filter_hooked'    => $quota_hooked,
			'exempt_filter_hooked'   => $exempt_hooked,
			'has_llm_router_local'   => $has_router,
			'has_llm_client'         => $has_client,
			'entitlement_status'     => $ent_status,
			'entitlement_tier'       => $ent_tier,
			'entitlement_balance'    => $ent_balance,
			'entitlement_bypass'     => $ent_bypass,
			'entitlement_message'    => $ent_msg,
		];
	}

	/**
	 * Compose a human-readable Vietnamese hint that pinpoints WHY the quota
	 * tripped — distinguishing between (a) client topology (R-GW-8) missing
	 * central settings sync, (b) entitlement upstream unreachable, and (c)
	 * user genuinely on free tier with $0 balance.
	 */
	protected function compose_diagnostic_hint( array $diag ): string {
		$lines = [];
		$lines[] = sprintf(
			'Quota nguồn: %s (= %d passages/ngày).',
			$diag['quota_filter_hooked'] ? 'filter đã hook (router/local)' : 'DEFAULT_QUOTA_USER local',
			(int) $diag['quota_per_user']
		);
		if ( ! $diag['quota_filter_hooked'] && ! $diag['has_llm_router_local'] ) {
			$lines[] = 'Site này KHÔNG cài bizcity-llm-router (R-GW-8 client topology) → cấu hình quota & danh sách ngoại lệ trên bizcity.vn không tự sync xuống. Cần nạp credit cho user hoặc đặt filter `bizcity_kg_quota_per_user` / `bizcity_kg_user_is_exempt` ở mu-plugin local.';
		}
		if ( $diag['entitlement_status'] === 'ok' ) {
			$lines[] = sprintf(
				'Entitlement bizcity.vn: tier=%s · balance=$%.4f · bypass=%s.',
				$diag['entitlement_tier'] ?: 'free',
				(float) $diag['entitlement_balance'],
				$diag['entitlement_bypass'] ? 'yes' : 'no'
			);
			if ( ! $diag['entitlement_bypass'] ) {
				$lines[] = 'User đang ở free tier (balance < $0.001). Nạp credit ở bizcity.vn để gỡ rate-limit tự động, hoặc thêm User ID vào "Ngoại lệ quota (User IDs)" rồi kích hoạt bridge filter local.';
			}
		} elseif ( $diag['entitlement_status'] === 'error' ) {
			$lines[] = sprintf( 'Entitlement bizcity.vn FAIL: %s — không xác định được tier/balance, fallback dùng quota default.', $diag['entitlement_message'] ?: 'unknown' );
		} elseif ( $diag['entitlement_status'] === 'missing' ) {
			$lines[] = 'BizCity_LLM_Client chưa load → không thể hỏi entitlement từ bizcity.vn.';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Record a billable LLM call.
	 */
	public function record_usage( array $args ) {
		global $wpdb;
		$row = wp_parse_args( $args, [
			'user_id'       => 0,
			'operation'     => self::OP_EXTRACT,
			'notebook_id'   => null,
			'passage_id'    => null,
			'input_tokens'  => 0,
			'output_tokens' => 0,
			'cost_usd'      => null,
			'meta'          => null,
		] );

		if ( $row['cost_usd'] === null ) {
			$row['cost_usd'] = $this->estimate_cost(
				$row['operation'], (int) $row['input_tokens'], (int) $row['output_tokens']
			);
		}

		$wpdb->insert( $this->table(), [
			'user_id'       => (int) $row['user_id'],
			'day'           => $this->today(),
			'operation'     => sanitize_key( $row['operation'] ),
			'notebook_id'   => $row['notebook_id'] !== null ? (int) $row['notebook_id'] : null,
			'passage_id'    => $row['passage_id']  !== null ? (int) $row['passage_id']  : null,
			'input_tokens'  => (int) $row['input_tokens'],
			'output_tokens' => (int) $row['output_tokens'],
			'cost_usd'      => (float) $row['cost_usd'],
			'meta'          => is_array( $row['meta'] ) ? wp_json_encode( $row['meta'] ) : ( $row['meta'] ?: null ),
		] );

		// Alert at 80% cap (once per day).
		$spent  = $this->spent_today_usd();
		$cap    = $this->daily_cap_usd();
		$alert  = get_option( 'bizcity_kg_cap_alert_sent_' . $this->today(), 0 );
		if ( ! $alert && $spent >= $cap * 0.8 ) {
			update_option( 'bizcity_kg_cap_alert_sent_' . $this->today(), 1, false );
			/**
			 * Hook: admin or external notifier may listen.
			 */
			do_action( 'bizcity_kg_cost_alert_80', $spent, $cap );
		}
	}

	/* ── Stats helpers ──────────────────────────────────────────────────── */

	public function spent_today_usd() {
		global $wpdb;
		return (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(cost_usd),0) FROM {$this->table()} WHERE day = %s",
			$this->today()
		) );
	}

	public function user_passages_today( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT passage_id) FROM {$this->table()}
			 WHERE day = %s AND user_id = %d AND operation = %s AND passage_id IS NOT NULL",
			$this->today(), (int) $user_id, self::OP_EXTRACT
		) );
	}

	public function summary_today() {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COALESCE(SUM(cost_usd),0)        AS spent_usd,
				COALESCE(SUM(input_tokens),0)    AS in_tokens,
				COALESCE(SUM(output_tokens),0)   AS out_tokens,
				COUNT(*)                         AS calls
			 FROM {$this->table()} WHERE day = %s",
			$this->today()
		), ARRAY_A );
		return [
			'spent_usd'  => (float) ( $row['spent_usd']  ?? 0 ),
			'in_tokens'  => (int)   ( $row['in_tokens']  ?? 0 ),
			'out_tokens' => (int)   ( $row['out_tokens'] ?? 0 ),
			'calls'      => (int)   ( $row['calls']      ?? 0 ),
			'cap_usd'    => $this->daily_cap_usd(),
			'pct'        => min( 1.0, ( (float) ( $row['spent_usd'] ?? 0 ) ) / $this->daily_cap_usd() ),
		];
	}

	/* ── Cost math ──────────────────────────────────────────────────────── */

	public function estimate_cost( $operation, $in_tokens, $out_tokens ) {
		if ( $operation === self::OP_EMBED ) {
			return ( $in_tokens / 1000 ) * self::PRICE_EMBED_PER_1K;
		}
		// extract / default
		return ( $in_tokens / 1000 ) * self::PRICE_EXTRACT_INPUT_PER_1K
		     + ( $out_tokens / 1000 ) * self::PRICE_EXTRACT_OUTPUT_PER_1K;
	}

	/* ── Dedupe helper ─────────────────────────────────────────────────── */

	/**
	 * Returns existing passage_id if a near-duplicate (same hash OR cosine ≥ threshold)
	 * is already present in the same notebook. Only the hash check is run here for
	 * cheapness — embedding-cosine dedupe is done separately by KG_Source_Service
	 * before insert.
	 */
	public function find_duplicate_by_hash( $notebook_id, $content_hash ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$db->tbl_passages()}
			 WHERE notebook_id = %d AND content_hash = %s LIMIT 1",
			(int) $notebook_id, (string) $content_hash
		) );
	}

	private function today() {
		return current_time( 'Y-m-d' );
	}
}
