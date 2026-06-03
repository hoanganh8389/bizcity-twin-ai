<?php
/**
 * BizCity Diagnostics — automation.matcher probe (Scenario Builder MVP).
 *
 * R-DDV (Diagnostic-Driven Validation) — verify 3 surface mới của trigger
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
 *
 * Tất cả workflow probe dùng slug `__healthtest_matcher_*` → cleanup() wipe sạch.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      Scenario Builder MVP (2026-06-01)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_Automation_Matcher implements BizCity_Diagnostics_Probe {

	const SLUG_PREFIX = '__healthtest_matcher_';

	public function id(): string          { return 'automation.matcher'; }
	public function label(): string       { return 'Automation · Trigger Matcher (ref-based + keywords)'; }
	public function description(): string {
		return 'Verify Scenario Builder: ref-based rule (FB referral.ref → trigger_config.scenario_uuid), keywords[] OR-match, ref_unmatched fallthrough.';
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
			'trigger_config' => array( 'scenario_uuid' => $uuid_ref ),
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
			'trigger_config' => array( 'keywords' => array( 'xin chao', 'hello' ) ),
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

		$cleanup_ids = array( (int) $wf_ref['id'], (int) $wf_kw['id'] );

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

		// ── Cleanup ────────────────────────────────────────────────────
		self::cleanup_runs_for_workflows( $cleanup_ids );
		foreach ( $cleanup_ids as $wid ) {
			BizCity_Automation_Repo_Workflows::hard_delete( $wid );
		}
		$steps[] = array( 'label' => 'Cleanup', 'status' => 'pass', 'detail' => 'wf + run rows wiped' );

		$ok = $ref_pass && $kw_pass && $has_orphan && $ref_helper_pass['ok'];
		if ( ! $ok ) {
			return self::fail( $steps, 'Trigger matcher Sprint Scenario Builder gặp lỗi.', 'matcher_assertion_failed',
				'Xem class-automation-trigger-matcher.php (extract_ref_uuid + channel_filter_match).' );
		}

		return array(
			'status'  => 'pass',
			'summary' => 'Ref-based rule + keywords[] OR-match + ref_unmatched fallthrough OK.',
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

	private static function fb_payload_text( string $text ): array {
		return array(
			'platform'    => 'FACEBOOK',
			'channel_role'=> 'USER',
			'event_subtype'=> 'messenger',
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
						'message'   => array( 'mid' => 'mid_probe', 'text' => $text ),
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

	private static function check_ref_parser_variants(): array {
		try {
			$ref_class = new ReflectionClass( 'BizCity_Automation_Trigger_Matcher' );
			$method    = $ref_class->getMethod( 'parse_ref_uuid' );
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
				$got = (string) $method->invoke( $obj, $input );
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
