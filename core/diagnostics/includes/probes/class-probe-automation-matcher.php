<?php
/**
 * BizCity Diagnostics — automation.matcher probe (Scenario Builder MVP).
 *
 * R-DDV (Diagnostic-Driven Validation) — verify 4 surface mới của trigger
 * matcher đã ship trong Sprint Scenario Builder 2026-06-01:
 *
 *   1. **Ref-based rule (BE-7.D)** — synthetic FB payload với
 *      `entry[].messaging[].referral.ref = "f.<probe_uuid>"` PHẢI khớp đúng
 *      workflow có `trigger_config.scenario_uuid = <probe_uuid>` và preempt
 *      luồng keyword/fallback (matcher trace event = `matched_ref`).
 *   2. **Keyword OR-match** — `cfg.keywords[] = ['xin chao', 'hello']` +
 *      text 'Hello there' PHẢI match (matcher trace = `matched_keyword`),
 *      trong khi text 'goodbye' PHẢI rớt sang fallback hoặc skip.
 *   3. **Ref unmatched** — payload có ref nhưng KHÔNG workflow nào claim →
 *      trace event `ref_unmatched` được ghi (không crash, không pre-empt).
 *   4. **Single-claim @priority** — message `@ghichu ... marketing` PHẢI
 *      cho workflow `@ghichu` thắng, workflow marketing bị suppress với reason
 *      `at_keyword_priority`.
 *
 * Tất cả workflow probe dùng slug `__healthtest_matcher_*` → cleanup() wipe sạch.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      Scenario Builder MVP (2026-06-01)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Automation_Matcher', false ) ) {
	return;
}

final class BizCity_Probe_Automation_Matcher implements BizCity_Diagnostics_Probe {

	const SLUG_PREFIX = '__healthtest_matcher_';

	public function id(): string          { return 'automation.matcher'; }
	public function label(): string       { return 'Automation · Trigger Matcher (ref + keywords + single-claim)'; }
	public function description(): string {
		return 'Verify Scenario Builder: ref-based rule, keywords[] OR-match, ref_unmatched fallthrough, and @ghichu single-claim precedence over marketing.';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 39; }
	public function icon(): string        { return 'admin-network'; }
	public function estimate_ms(): int    { return 1200; }

	public function precondition() {
		foreach ( array(
			'BizCity_Automation_Trigger_Matcher',
			'BizCity_Automation_Repo_Workflows',
			'BizCity_Automation_Repo_Runs',
			'BizCity_Automation_Matcher_Trace',
		) as $cls ) {
			if ( ! class_exists( $cls ) ) {
				return new WP_Error( 'class_missing', $cls . ' chưa load.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps   = array();
		$matcher = BizCity_Automation_Trigger_Matcher::instance();

		// Generate unique uuids per run so multiple invocations don't collide.
		$uuid_ref = strtolower( wp_generate_password( 32, false, false ) );
		$uuid_orphan = strtolower( wp_generate_password( 32, false, false ) );

		// ── Setup: 2 workflows fb_message ──────────────────────────────
		$wf_ref = BizCity_Automation_Repo_Workflows::create( array(
			'slug'           => self::SLUG_PREFIX . 'ref_' . wp_generate_password( 6, false, false ),
			'name'           => '__healthtest matcher ref-based',
			'trigger_type'   => 'fb_message',
			// [2026-07-11 Johnny Chu] HOTFIX — FB Messenger now maps to zone=crm in matcher; probe wf must opt into crm zone.
			'trigger_config' => array(
				'scenario_uuid' => $uuid_ref,
				'zone'          => 'crm',
			),
			'graph_json'     => wp_json_encode( array(
				'nodes' => array( array( 'id' => 't1', 'type' => 'trigger', 'data' => array( 'blockId' => 'trigger.fb_message' ) ) ),
				'edges' => array(),
			) ),
			'enabled' => 1,
		) );
		if ( is_wp_error( $wf_ref ) ) {
			return self::fail( $steps, 'Tạo workflow ref-based fail', 'create_failed', $wf_ref->get_error_message() );
		}

		$wf_kw = BizCity_Automation_Repo_Workflows::create( array(
			'slug'           => self::SLUG_PREFIX . 'kw_' . wp_generate_password( 6, false, false ),
			'name'           => '__healthtest matcher keywords',
			'trigger_type'   => 'fb_message',
			// [2026-07-11 Johnny Chu] HOTFIX — keep keyword probe in Zone 1 (crm) for FB messenger path.
			'trigger_config' => array(
				'keywords' => array( 'xin chao', 'hello' ),
				'zone'     => 'crm',
			),
			'graph_json'     => wp_json_encode( array(
				'nodes' => array( array( 'id' => 't1', 'type' => 'trigger', 'data' => array( 'blockId' => 'trigger.fb_message' ) ) ),
				'edges' => array(),
			) ),
			'enabled' => 1,
		) );
		if ( is_wp_error( $wf_kw ) ) {
			BizCity_Automation_Repo_Workflows::hard_delete( (int) $wf_ref['id'] );
			return self::fail( $steps, 'Tạo workflow keywords fail', 'create_failed', $wf_kw->get_error_message() );
		}

		// [2026-07-26 Johnny Chu] AUTOMATION BE-4 — synthetic pair for @ghichu vs marketing single-claim regression.
		$wf_at = BizCity_Automation_Repo_Workflows::create( array(
			'slug'           => self::SLUG_PREFIX . 'at_' . wp_generate_password( 6, false, false ),
			'name'           => '__healthtest matcher at-ghichu winner',
			'trigger_type'   => 'fb_message',
			'trigger_config' => array(
				'filter'   => '@ghichu',
				'priority' => 9999,
				'zone'     => 'crm',
			),
			'graph_json'     => wp_json_encode( array(
				'nodes' => array( array( 'id' => 't1', 'type' => 'trigger', 'data' => array( 'blockId' => 'trigger.fb_message' ) ) ),
				'edges' => array(),
			) ),
			'enabled' => 1,
		) );
		if ( is_wp_error( $wf_at ) ) {
			BizCity_Automation_Repo_Workflows::hard_delete( (int) $wf_ref['id'] );
			BizCity_Automation_Repo_Workflows::hard_delete( (int) $wf_kw['id'] );
			return self::fail( $steps, 'Tạo workflow @ghichu fail', 'create_failed', $wf_at->get_error_message() );
		}

		$wf_marketing = BizCity_Automation_Repo_Workflows::create( array(
			'slug'           => self::SLUG_PREFIX . 'mk_' . wp_generate_password( 6, false, false ),
			'name'           => '__healthtest matcher marketing loser',
			'trigger_type'   => 'fb_message',
			'trigger_config' => array(
				'keywords' => array( 'kịch bản', 'marketing' ),
				'zone'     => 'crm',
			),
			'graph_json'     => wp_json_encode( array(
				'nodes' => array( array( 'id' => 't1', 'type' => 'trigger', 'data' => array( 'blockId' => 'trigger.fb_message' ) ) ),
				'edges' => array(),
			) ),
			'enabled' => 1,
		) );
		if ( is_wp_error( $wf_marketing ) ) {
			BizCity_Automation_Repo_Workflows::hard_delete( (int) $wf_ref['id'] );
			BizCity_Automation_Repo_Workflows::hard_delete( (int) $wf_kw['id'] );
			BizCity_Automation_Repo_Workflows::hard_delete( (int) $wf_at['id'] );
			return self::fail( $steps, 'Tạo workflow marketing fail', 'create_failed', $wf_marketing->get_error_message() );
		}

		$cleanup_ids = array( (int) $wf_ref['id'], (int) $wf_kw['id'], (int) $wf_at['id'], (int) $wf_marketing['id'] );

		// ── Test 1: ref-based hit ──────────────────────────────────────
		BizCity_Automation_Matcher_Trace::clear();
		$matcher->on_channel_message( self::fb_payload_with_ref( 'f.' . $uuid_ref, '' ) );
		$found_ref = self::find_run_for( (int) $wf_ref['id'] );
		$traces    = self::recent_traces( 10 );
		$has_event = self::trace_has( $traces, 'matched_ref' );
		$ref_pass  = $found_ref && $has_event;

		$steps[] = $s = array(
			'label'  => 'Ref-based · matched_ref event + run row',
			'status' => $ref_pass ? 'pass' : 'fail',
			'detail' => sprintf( 'run=%s · matched_ref=%s', $found_ref ?: 'NONE', $has_event ? 'yes' : 'no' ),
		);
		$ctx->emit_step( $s );

		// ── Test 2: keyword OR-match ──────────────────────────────────
		BizCity_Automation_Matcher_Trace::clear();
		$matcher->on_channel_message( self::fb_payload_text( 'Hello there friend' ) );
		$found_kw = self::find_run_for( (int) $wf_kw['id'] );
		$traces2  = self::recent_traces( 10 );
		$has_kw   = self::trace_has( $traces2, 'matched_keyword' );
		$kw_pass  = $found_kw && $has_kw;

		$steps[] = $s = array(
			'label'  => 'Keywords[] OR-match · "hello" → matched_keyword',
			'status' => $kw_pass ? 'pass' : 'fail',
			'detail' => sprintf( 'run=%s · matched_keyword=%s', $found_kw ?: 'NONE', $has_kw ? 'yes' : 'no' ),
		);
		$ctx->emit_step( $s );

		// ── Test 3: ref unmatched (orphan uuid → fall through) ─────────
		BizCity_Automation_Matcher_Trace::clear();
		$matcher->on_channel_message( self::fb_payload_with_ref( 'f.' . $uuid_orphan, '' ) );
		$traces3 = self::recent_traces( 10 );
		$has_orphan = self::trace_has( $traces3, 'ref_unmatched' );

		$steps[] = $s = array(
			'label'  => 'Ref unmatched · orphan uuid → ref_unmatched trace',
			'status' => $has_orphan ? 'pass' : 'fail',
			'detail' => $has_orphan ? 'ref_unmatched event present' : 'event MISSING',
		);
		$ctx->emit_step( $s );

		// ── Test 4: parse_ref_uuid hardening (prefix variants) ─────────
		// Use reflection to invoke private method.
		$ref_helper_pass = self::check_ref_parser_variants();
		$steps[] = $s = array(
			'label'  => 'parse_ref_uuid · prefix variants (f./z./t_/<FLOW>_/.ref.<id>)',
			'status' => $ref_helper_pass['ok'] ? 'pass' : 'fail',
			'detail' => $ref_helper_pass['detail'],
		);
		$ctx->emit_step( $s );

		// [2026-07-26 Johnny Chu] AUTOMATION BE-4 — verify @ghichu wins and marketing is suppressed with at_keyword_priority.
		BizCity_Automation_Matcher_Trace::clear();
		$matcher->on_channel_message( self::fb_payload_text( 'ghichu kịch bản marketing', '@ghichu kịch bản marketing' ) );
		$found_at   = self::find_run_for( (int) $wf_at['id'] );
		$found_mk   = self::find_run_for( (int) $wf_marketing['id'] );
		$traces4    = self::recent_traces( 20 );
		$has_reduce = self::trace_has( $traces4, 'matched_keyword_singleclaim_reduced' );
		$has_at_sup = self::trace_has_reduced_reason(
			$traces4,
			(int) $wf_at['id'],
			(int) $wf_marketing['id'],
			'at_keyword_priority'
		);
		$at_priority_pass = ( $found_at !== '' ) && ( $found_mk === '' ) && $has_reduce && $has_at_sup;

		$steps[] = $s = array(
			'label'  => '@ghichu priority · winner note, suppress marketing',
			'status' => $at_priority_pass ? 'pass' : 'fail',
			'detail' => sprintf(
				'run_note=%s · run_marketing=%s · reduced=%s · reason=%s',
				$found_at ?: 'NONE',
				$found_mk ?: 'NONE',
				$has_reduce ? 'yes' : 'no',
				$has_at_sup ? 'yes' : 'no'
			),
		);
		$ctx->emit_step( $s );

		// ── Cleanup ────────────────────────────────────────────────────
		self::cleanup_runs_for_workflows( $cleanup_ids );
		foreach ( $cleanup_ids as $wid ) {
			BizCity_Automation_Repo_Workflows::hard_delete( $wid );
		}
		$steps[] = array( 'label' => 'Cleanup', 'status' => 'pass', 'detail' => 'wf + run rows wiped' );

		$ok = $ref_pass && $kw_pass && $has_orphan && $ref_helper_pass['ok'] && $at_priority_pass;
		if ( ! $ok ) {
			return self::fail( $steps, 'Trigger matcher Sprint Scenario Builder gặp lỗi.', 'matcher_assertion_failed',
				'Xem class-automation-trigger-matcher.php (parse_ref_uuid + channel_filter_eval + resolve_single_claim).' );
		}

		return array(
			'status'  => 'pass',
			'summary' => 'Ref-based rule + keywords[] OR-match + ref_unmatched + @ghichu single-claim priority OK.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		global $wpdb;
		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) { return; }
		$tbl = BizCity_Automation_Repo_Workflows::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tbl} WHERE slug LIKE %s", self::SLUG_PREFIX . '%' ) );
	}

	// ─── helpers ─────────────────────────────────────────────────────────

	private static function fb_payload_with_ref( string $ref, string $text = '' ): array {
		return array(
			'platform'    => 'FACEBOOK',
			'channel_role'=> 'USER',
			'event_subtype'=> 'messenger',
			// [2026-08-16 Johnny Chu] R-ZONE/R-CH-IDMEM — synthetic Messenger must carry a linked owner or the matcher correctly fails closed before ref dispatch.
			'wp_user_id'  => max( 1, (int) get_current_user_id() ),
			'identity_uuid' => 'probe_identity_matcher',
			'message'     => $text,
			'instance_id' => '',
			'chat_id'     => 'probe_chat_' . wp_generate_password( 6, false, false ),
			'sender_id'   => 'probe_user',
			'raw'         => array(
				'entry' => array( array(
					'id'        => 'probe_page',
					'messaging' => array( array(
						'sender'    => array( 'id' => 'probe_user' ),
						'recipient' => array( 'id' => 'probe_page' ),
						'referral'  => array( 'ref' => $ref, 'source' => 'SHORTLINK', 'type' => 'OPEN_THREAD' ),
					) ),
				) ),
			),
		);
	}

	private static function fb_payload_text( string $text, string $raw_text = '' ): array {
		$raw_text = (string) ( $raw_text !== '' ? $raw_text : $text );
		return array(
			'platform'    => 'FACEBOOK',
			'channel_role'=> 'USER',
			'event_subtype'=> 'messenger',
			// [2026-08-16 Johnny Chu] R-ZONE/R-CH-IDMEM — keep keyword and single-claim fixtures on an authenticated owner path.
			'wp_user_id'  => max( 1, (int) get_current_user_id() ),
			'identity_uuid' => 'probe_identity_matcher',
			'message'     => $text,
			'raw_text'    => $raw_text,
			'instance_id' => '',
			'chat_id'     => 'probe_chat_' . wp_generate_password( 6, false, false ),
			'sender_id'   => 'probe_user',
			'raw'         => array(
				'entry' => array( array(
					'id'        => 'probe_page',
					'messaging' => array( array(
						'sender'    => array( 'id' => 'probe_user' ),
						'recipient' => array( 'id' => 'probe_page' ),
						'message'   => array( 'mid' => 'mid_probe', 'text' => $raw_text ),
					) ),
				) ),
			),
		);
	}

	private static function find_run_for( int $workflow_id ): string {
		$out = BizCity_Automation_Repo_Runs::query( array(
			'workflow_id' => $workflow_id,
			'limit'       => 1,
		) );
		$row = $out['rows'][0] ?? null;
		return $row ? (string) $row['run_id'] : '';
	}

	private static function recent_traces( int $limit ): array {
		if ( ! method_exists( 'BizCity_Automation_Matcher_Trace', 'recent' ) ) { return array(); }
		$rows = BizCity_Automation_Matcher_Trace::recent( $limit );
		return is_array( $rows ) ? $rows : array();
	}

	private static function trace_has( array $traces, string $decision ): bool {
		foreach ( $traces as $t ) {
			$d = (string) ( $t['decision'] ?? $t['event'] ?? '' );
			if ( $d === $decision ) { return true; }
		}
		return false;
	}

	// [2026-07-26 Johnny Chu] AUTOMATION BE-4 — assert matched_keyword_singleclaim_reduced keeps winner and suppression reason.
	private static function trace_has_reduced_reason( array $traces, int $winner_wf_id, int $suppressed_wf_id, string $reason ): bool {
		if ( $winner_wf_id <= 0 || $suppressed_wf_id <= 0 || $reason === '' ) { return false; }
		$winner_needle = 'winner_wf_id=' . $winner_wf_id;
		$supp_needle   = $suppressed_wf_id . ':' . $reason;
		foreach ( $traces as $t ) {
			$decision = (string) ( $t['decision'] ?? $t['event'] ?? '' );
			if ( $decision !== 'matched_keyword_singleclaim_reduced' ) { continue; }
			$detail = (string) ( $t['detail'] ?? '' );
			if ( strpos( $detail, $winner_needle ) === false ) { continue; }
			if ( strpos( $detail, $supp_needle ) !== false ) { return true; }
		}
		return false;
	}

	private static function check_ref_parser_variants(): array {
		try {
			$ref_class = new ReflectionClass( 'BizCity_Automation_Trigger_Matcher' );
			// [2026-08-16 Johnny Chu] CCG-1 — follow the active matcher API after parse_ref_uuid was renamed to extract_ref_uuid.
			$method_name = $ref_class->hasMethod( 'extract_ref_uuid' ) ? 'extract_ref_uuid' : 'parse_ref_uuid';
			$method    = $ref_class->getMethod( $method_name );
			$method->setAccessible( true );
			$obj = BizCity_Automation_Trigger_Matcher::instance();
			$uuid = 'abcdef0123456789abcdef0123456789';

			$cases = array(
				'f.' . $uuid                => $uuid,
				'z.' . $uuid                => $uuid,
				't_' . $uuid                => $uuid,
				'<FLOW>_' . $uuid           => $uuid,
				'f.' . $uuid . '.ref.cli01' => $uuid,
				'short'                     => '',
			);
			$fails = array();
			foreach ( $cases as $input => $expected ) {
				// [2026-08-16 Johnny Chu] CCG-1 — extract_ref_uuid() accepts normalized payload + platform; exercise direct ref fields for parser variants.
				$got = (string) $method->invoke( $obj, array( 'ref' => $input ), 'FACEBOOK' );
				if ( $got !== $expected ) { $fails[] = "{$input}=>{$got}"; }
			}
			return $fails
				? array( 'ok' => false, 'detail' => 'mismatch: ' . implode( '; ', $fails ) )
				: array( 'ok' => true,  'detail' => count( $cases ) . ' variants OK' );
		} catch ( Throwable $e ) {
			return array( 'ok' => false, 'detail' => 'reflection error: ' . $e->getMessage() );
		}
	}

	private static function cleanup_runs_for_workflows( array $ids ): void {
		global $wpdb;
		if ( empty( $ids ) || ! class_exists( 'BizCity_Automation_Repo_Runs' ) ) { return; }
		$ids_csv = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( "DELETE FROM " . BizCity_Automation_Repo_Runs::table_runs() . " WHERE workflow_id IN ({$ids_csv})" );
		$wpdb->query( "DELETE FROM " . BizCity_Automation_Repo_Runs::table_logs() . " WHERE run_id NOT IN (SELECT run_id FROM " . BizCity_Automation_Repo_Runs::table_runs() . ")" );
	}

	private static function fail( array $steps, string $summary, string $error, string $hint ): array {
		return array(
			'status'   => 'fail',
			'summary'  => $summary,
			'error'    => $error,
			'fix_hint' => $hint,
			'steps'    => $steps,
		);
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Automation_Matcher';
	return $list;
} );
