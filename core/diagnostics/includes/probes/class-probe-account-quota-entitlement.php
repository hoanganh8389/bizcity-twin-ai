<?php
/**
 * BizCity Diagnostics — account.quota.entitlement probe
 *
 * [2026-06-04 Johnny Chu] HOTFIX R-GW-8 — phân tích đầy đủ tài khoản trên
 * bizcity.vn hub: credits, tier, cấu hình quota theo tier.
 *
 * 3-layer R-DDV:
 *   Layer 1 (Disk)    — BizCity_LLM_Client file exists, KG Cost Guard file exists.
 *   Layer 2 (Loader)  — BizCity_LLM_Client::instance()->is_ready() (key + URL có).
 *   Layer 3 (Runtime) — 3 hub calls:
 *       A. GET /bizcity/v1/account/info   → tier, balance, plan, requests_today
 *       B. GET /bizcity/v1/account/limits → video/faceswap/vto giới hạn/ngày
 *       C. GET /bizcity/v1/account/entitlement → features, models, bypass flag
 *     + Local KG cost guard config snapshot (passages/day, USD cap, exempt IDs)
 *
 * Không tốn LLM credit — toàn bộ là REST read-only GET.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-04 (HOTFIX R-GW-8 quota.entitlement)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Account_Quota_Entitlement', false ) ) {
	return;
}

final class BizCity_Probe_Account_Quota_Entitlement implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'account.quota.entitlement'; }
	public function label(): string       { return 'Account · Quota & Entitlement (hub)'; }
	public function description(): string {
		return 'Đọc credits, tier và cấu hình quota đầy đủ từ bizcity.vn hub — giúp chẩn đoán lý do job learning bị dừng vì hết quota.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 38; }
	public function icon(): string        { return 'shield'; }
	public function estimate_ms(): int    { return 3000; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return new WP_Error(
				'llm_client_missing',
				'BizCity_LLM_Client chưa load. Kiểm core/bizcity-llm bootstrap.'
			);
		}
		return true;
	}

	public function run( $ctx ): array {
		$llm = BizCity_LLM_Client::instance();

		// ── Layer 1 · Disk ───────────────────────────────────────────
		$llm_file = class_exists( 'BizCity_LLM_Client' )
			? ( new ReflectionClass( 'BizCity_LLM_Client' ) )->getFileName()
			: '';
		$kg_file  = class_exists( 'BizCity_KG_Cost_Guard' )
			? ( new ReflectionClass( 'BizCity_KG_Cost_Guard' ) )->getFileName()
			: '';

		$ctx->emit_step( [
			'label'  => 'Layer 1 · Disk',
			'status' => ( $llm_file && $kg_file ) ? 'pass' : ( $llm_file ? 'warn' : 'fail' ),
			'detail' => sprintf(
				'BizCity_LLM_Client: %s | BizCity_KG_Cost_Guard: %s',
				$llm_file ? 'loaded' : 'MISSING',
				$kg_file  ? 'loaded' : 'not loaded (no KG local config)'
			),
		] );

		// ── Layer 2 · Loader ─────────────────────────────────────────
		$ready    = $llm->is_ready();
		$gateway  = $llm->get_gateway_url();
		$has_key  = ! empty( $llm->get_api_key() );

		$ctx->emit_step( [
			'label'  => 'Layer 2 · Loader',
			'status' => $ready ? 'pass' : 'fail',
			'detail' => sprintf(
				'gateway=%s | api_key=%s | is_ready=%s',
				$gateway ? parse_url( $gateway, PHP_URL_HOST ) : 'not set',
				$has_key ? 'set' : 'MISSING',
				$ready   ? 'true' : 'false'
			),
		] );

		if ( ! $ready ) {
			return [
				'status'   => 'fail',
				'summary'  => 'BizCity_LLM_Client chưa sẵn sàng — gateway URL hoặc API key chưa cấu hình.',
				'fix_hint' => 'Settings → BizCity LLM: nhập gateway_url + api_key.',
			];
		}

		// ── Layer 3A · account/info ───────────────────────────────────
		$t_start   = microtime( true );
		$acct_info = $llm->get_account_info( [ 'timeout' => 8 ] );
		$ms_info   = intval( ( microtime( true ) - $t_start ) * 1000 );

		$info_ok = is_array( $acct_info ) && isset( $acct_info['tier'] );
		$ctx->emit_step( [
			'label'  => 'Layer 3A · GET /account/info',
			'status' => $info_ok ? 'pass' : 'fail',
			'detail' => $info_ok
				? sprintf(
					'tier=%s | balance=$%.4f | plan=%s | is_free=%s | req_today=%s/%s (%dms)',
					$acct_info['tier']            ?? '-',
					(float)  ( $acct_info['balance_usd']    ?? 0 ),
					$acct_info['plan']            ?? '-',
					isset( $acct_info['is_free_tier'] ) ? ( $acct_info['is_free_tier'] ? 'yes' : 'no' ) : '?',
					$acct_info['requests_today']  ?? '-',
					$acct_info['requests_limit']  ?? '∞',
					$ms_info
				)
				: ( is_wp_error( $acct_info )
					? sprintf( 'WP_Error %s: %s (%dms)', $acct_info->get_error_code(), $acct_info->get_error_message(), $ms_info )
					: sprintf( 'Unexpected response (%dms)', $ms_info )
				),
		] );

		// ── Layer 3B · account/limits ─────────────────────────────────
		$t_start   = microtime( true );
		$limits    = $llm->get_account_limits( [ 'timeout' => 8 ] );
		$ms_lim    = intval( ( microtime( true ) - $t_start ) * 1000 );

		$lim_ok = is_array( $limits ) && isset( $limits['services'] );
		$ctx->emit_step( [
			'label'  => 'Layer 3B · GET /account/limits',
			'status' => $lim_ok ? 'pass' : 'warn',
			'detail' => $lim_ok
				? sprintf(
					'video=%s/ngày | faceswap=%s/ngày | vto=%s/ngày | reset=%s (%dms)',
					$limits['services']['video']['limit']    ?? '-',
					$limits['services']['faceswap']['limit'] ?? '-',
					$limits['services']['vto']['limit']      ?? '-',
					isset( $limits['reset_at'] ) ? substr( $limits['reset_at'], 0, 10 ) : '-',
					$ms_lim
				)
				: ( is_wp_error( $limits )
					? sprintf( 'WP_Error %s: %s (%dms)', $limits->get_error_code(), $limits->get_error_message(), $ms_lim )
					: sprintf( 'No services data (%dms)', $ms_lim )
				),
		] );

		// ── Layer 3C · account/entitlement ───────────────────────────
		$current_uid = get_current_user_id();
		$t_start     = microtime( true );
		$ent         = $llm->get_entitlement( max( 1, $current_uid ), [ 'timeout' => 8 ] );
		$ms_ent      = intval( ( microtime( true ) - $t_start ) * 1000 );

		$ent_ok = is_array( $ent ) && isset( $ent['tier'] );
		$bypass = $ent_ok && ! empty( $ent['bypass'] );
		$via    = $ent_ok ? ( $ent['_via'] ?? 'direct' ) : '-';

		$ctx->emit_step( [
			'label'  => sprintf( 'Layer 3C · GET /account/entitlement (user=%d)', $current_uid ),
			'status' => $ent_ok ? 'pass' : 'warn',
			'detail' => $ent_ok
				? sprintf(
					'tier=%s | bypass=%s | features=%d | via=%s (%dms)',
					$ent['tier']      ?? '-',
					$bypass ? 'YES (quota exempt)' : 'no',
					is_array( $ent['features'] ?? null ) ? count( $ent['features'] ) : 0,
					$via,
					$ms_ent
				)
				: ( is_wp_error( $ent )
					? sprintf( 'WP_Error %s: %s (%dms)', $ent->get_error_code(), $ent->get_error_message(), $ms_ent )
					: sprintf( 'Unexpected response (%dms)', $ms_ent )
				),
		] );

		// ── Layer 3D · Local KG Cost Guard config ─────────────────────
		$kg_config = $this->snapshot_kg_config();
		$ctx->emit_step( [
			'label'  => 'Layer 3D · Local KG Cost Guard config',
			'status' => $kg_config['enabled'] ? 'pass' : 'warn',
			'detail' => sprintf(
				'enabled=%s | passages/user/ngày=%d | site_cap_usd=$%.2f | batch=%d | dedupe=%.2f | exempt_ids=[%s] | source=%s',
				$kg_config['enabled'] ? 'ON' : 'OFF',
				$kg_config['quota_per_user'],
				$kg_config['daily_cap_usd'],
				$kg_config['batch_size'],
				$kg_config['dedupe_threshold'],
				implode( ', ', $kg_config['exempt_user_ids'] ),
				$kg_config['hub_quota_synced']
					? 'hub-synced'
					: ( $kg_config['filters_hooked'] ? 'bridge/hook' : 'local defaults (50)' )
			),
		] );

		// ── Assemble summary ──────────────────────────────────────────
		$tier     = $info_ok ? ( $acct_info['tier']       ?? 'unknown' ) : 'unknown';
		$balance  = $info_ok ? (float) ( $acct_info['balance_usd'] ?? 0.0 ) : 0.0;
		$plan     = $info_ok ? ( $acct_info['plan']       ?? '-' ) : '-';
		$is_free  = $info_ok ? ! empty( $acct_info['is_free_tier'] ) : true;

		$quota_status = $bypass
			? 'BYPASS (exempt — no daily limit)'
			: sprintf( '%d passages/ngày (limit)', $kg_config['quota_per_user'] );

		$all_pass = $info_ok && $ent_ok;
		$status   = $all_pass ? 'pass' : ( $info_ok ? 'warn' : 'fail' );

		return [
			'status'  => $status,
			'summary' => sprintf(
				'Tier: %s · Balance: $%.4f · Plan: %s · KG quota: %s',
				$tier, $balance, $plan, $quota_status
			),
			'data'    => [
				/* ── Tài khoản ──────────────────────────────────────── */
				'account' => [
					'tier'              => $tier,
					'plan'              => $plan,
					'balance_usd'       => $balance,
					'is_free_tier'      => $is_free,
					'email'             => $info_ok ? ( $acct_info['email'] ?? '' ) : '',
					'display_name'      => $info_ok ? ( $acct_info['display_name'] ?? '' ) : '',
					'api_key_prefix'    => $info_ok ? ( $acct_info['api_key_prefix'] ?? '' ) : '',
					'requests_today'    => $info_ok ? ( $acct_info['requests_today'] ?? null ) : null,
					'requests_limit'    => $info_ok ? ( $acct_info['requests_limit'] ?? null ) : null,
					'requests_remaining'=> $info_ok ? ( $acct_info['requests_remaining'] ?? null ) : null,
					'auto_topup'        => $info_ok ? ( $acct_info['auto_topup'] ?? null ) : null,
					'my_account_url'    => $info_ok ? ( $acct_info['my_account_url'] ?? '' ) : '',
				],

				/* ── Giới hạn theo tier ──────────────────────────────── */
				'service_limits' => $lim_ok ? [
					'video_per_day'    => $limits['services']['video']['limit']    ?? null,
					'faceswap_per_day' => $limits['services']['faceswap']['limit'] ?? null,
					'vto_per_day'      => $limits['services']['vto']['limit']      ?? null,
					'reset_at'         => $limits['reset_at'] ?? null,
					'video_used_today'    => $limits['services']['video']['used']    ?? null,
					'faceswap_used_today' => $limits['services']['faceswap']['used'] ?? null,
					'vto_used_today'      => $limits['services']['vto']['used']      ?? null,
				] : null,

				/* ── Entitlement + quota bypass ──────────────────────── */
				'entitlement' => $ent_ok ? [
					'tier'          => $ent['tier'] ?? '-',
					'bypass'        => $bypass,
					'via'           => $via,
					'features_count'=> is_array( $ent['features'] ?? null ) ? count( $ent['features'] ) : 0,
					'features'      => $ent['features'] ?? [],
					'balance_usd'   => (float) ( $ent['balance_usd'] ?? 0.0 ),
				] : [ 'error' => is_wp_error( $ent ) ? $ent->get_error_message() : 'no data' ],

				/* ── KG Extraction Quota (local config) ───────────────── */
				'kg_quota' => $kg_config,
			],
		];
	}

	/* ── helpers ─────────────────────────────────────────────────── */

	/**
	 * Snapshot BizCity_KG_Cost_Guard config via apply_filters (same path as runtime).
	 * Returns safe defaults if KG Cost Guard is not loaded.
	 *
	 * @return array
	 */
	private function snapshot_kg_config(): array {
		$enabled          = (bool) apply_filters( 'bizcity_kg_cost_guard_enabled',      true );
		// [2026-06-04 Johnny Chu] R-GW-API-CATALOG — prefer hub-synced value (stored by get_entitlement()).
		// After Layer 3C runs, bizcity_hub_kg_quota_per_user is already populated with the hub's value.
		// [2026-06-10 Johnny Chu] HOTFIX — per-site option
		$hub_quota        = (int) get_option( 'bizcity_hub_kg_quota_per_user', 0 );
		$quota_per_user   = $hub_quota > 0
			? $hub_quota
			: (int) apply_filters( 'bizcity_kg_quota_per_user', 50 );
		$daily_cap_usd    = (float) apply_filters( 'bizcity_kg_daily_cap_usd',          5.0 );
		$dedupe_threshold = (float) apply_filters( 'bizcity_kg_dedupe_cosine_threshold', 0.92 );
		$batch_size       = (int)  apply_filters( 'bizcity_kg_extract_batch_size',       5 );

		// Detect whether the hub KG bridge has hooked filters (vs. defaults only).
		$filters_hooked = has_filter( 'bizcity_kg_quota_per_user' )
		               || has_filter( 'bizcity_kg_user_is_exempt' )
		               || class_exists( 'BizCity_Router_KG_Bridge' )
		               || $hub_quota > 0; // hub-synced via entitlement

		// Exempt user IDs: read from BizCity_KG_Cost_Guard if available,
		// else check the site option directly (won't exist on client sites).
		$exempt_user_ids = [];
		if ( class_exists( 'BizCity_KG_Cost_Guard' ) ) {
			// Probe a known non-existent user to collect which IDs are exempt
			// by reading the underlying option (public static on bridge, not on guard).
			// Safest: read the sitemeta option if it exists locally.
			$raw = (string) get_site_option( 'bizcity_free_tier_exempt_users', '' );
			if ( $raw !== '' ) {
				$exempt_user_ids = array_values( array_filter(
					array_map( 'intval', preg_split( '/[\s,;]+/', $raw ) )
				) );
			}
		}

		// Current user quota usage today.
		$uid             = get_current_user_id();
		$passages_today  = 0;
		$is_exempt       = (bool) apply_filters( 'bizcity_kg_user_is_exempt', false, $uid );
		if ( $uid > 0 && class_exists( 'BizCity_KG_Cost_Guard' ) ) {
			$cg = BizCity_KG_Cost_Guard::instance();
			if ( method_exists( $cg, 'user_passages_today' ) ) {
				$passages_today = (int) $cg->user_passages_today( $uid );
			}
		}

		return [
			'enabled'           => $enabled,
			'quota_per_user'    => $quota_per_user,
			'hub_quota_synced'  => $hub_quota > 0,
			'daily_cap_usd'     => $daily_cap_usd,
			'dedupe_threshold'  => $dedupe_threshold,
			'batch_size'        => $batch_size,
			'exempt_user_ids'   => $exempt_user_ids,
			'filters_hooked'    => $filters_hooked,
			'current_user'      => [
				'user_id'        => $uid,
				'passages_today' => $passages_today,
				'quota_remaining'=> $is_exempt ? 'unlimited' : max( 0, $quota_per_user - $passages_today ),
				'is_exempt'      => $is_exempt,
			],
		];
	}

	public function cleanup(): void {
		// Read-only probe; no state to clean.
	}
}

/* ── Register ────────────────────────────────────────────────────── */

add_filter( 'bizcity_diagnostics_register_probes', function ( array $probes ): array {
	$probes[] = new BizCity_Probe_Account_Quota_Entitlement();
	return $probes;
} );
