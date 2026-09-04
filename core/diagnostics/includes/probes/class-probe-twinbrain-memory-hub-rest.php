<?php
/**
 * BizCity Diagnostics — twinbrain.memory.hub-rest probe
 * (Wave 2.8c TBR.MEM-C7, 2026-05-24).
 *
 * Real-call probe cho 4 REST endpoint owner-self memory hub
 * (`/wp-json/bizcity-twinbrain/v1/memory/me{,/:id}`). Plant qua REST
 * (không bypass) → list → update → delete. Verify:
 *
 *   • POST /memory/me trả về `ok=true` + `item.id > 0`.
 *   • GET  /memory/me thấy sentinel row.
 *   • PUT  /memory/me/{id} cập nhật text + reflect ngay.
 *   • DELETE /memory/me/{id} → GET sau đó không còn row.
 *
 * Permission guard: dùng `rest_do_request()` (in-process) với user hiện tại;
 * cross-user check defer (sẽ cần switch_to_user — bỏ qua trong probe
 * baseline). Tất cả route phải register dưới namespace
 * `bizcity-twinbrain/v1`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-24 (Phase 0.36-UNIFIED Wave 2.8c TBR.MEM-C7)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_TwinBrain_Memory_Hub_Rest', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Memory_Hub_Rest implements BizCity_Diagnostics_Probe {

	const SENTINEL = '__healthtest_hubrest_token_wallaby57';
	const NS       = 'bizcity-twinbrain/v1';

	public function id(): string          { return 'twinbrain.memory.hub-rest'; }
	public function label(): string       { return 'TwinBrain Memory Hub REST (/memory/me)'; }
	public function description(): string {
		return 'Real-call 4 endpoint owner-self CRUD: POST/GET/PUT/DELETE /memory/me — verify schema + permission + cleanup.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int       { return 64; }
	public function icon(): string     { return 'list-checks'; }
	public function estimate_ms(): int { return 1500; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_REST_Memory_Me' ) ) {
			return 'BizCity_TwinBrain_REST_Memory_Me chưa load (twinbrain bootstrap).';
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			return 'BizCity_User_Memory chưa load.';
		}
		if ( get_current_user_id() <= 0 ) {
			return 'Probe cần admin login.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$this->cleanup();

		// [2026-07-31 Johnny Chu] PHASE-1.22-MEMORY-UNIFY — verify REST/Notes mirror hooks before planting the CRUD sentinel.
		$plugin_root = dirname( __DIR__, 4 );
		// [2026-07-31 Johnny Chu] PHASE-1.22-MEMORY-UNIFY — point static checks at the active core module paths.
		$rest_file   = $plugin_root . '/core/twinbrain/includes/class-twinbrain-rest-memory-me.php';
		$writer_file = $plugin_root . '/core/memory/includes/class-memory-unified-writer.php';
		$notes_file  = $plugin_root . '/modules/twinchat/notebooklm/includes/class-twinchat-notes-service.php';
		$user_memory_file = $plugin_root . '/core/knowledge/includes/class-user-memory.php';
		$rest_src    = is_readable( $rest_file ) ? (string) file_get_contents( $rest_file ) : '';
		$writer_src  = is_readable( $writer_file ) ? (string) file_get_contents( $writer_file ) : '';
		$notes_src   = is_readable( $notes_file ) ? (string) file_get_contents( $notes_file ) : '';
		$user_memory_src = is_readable( $user_memory_file ) ? (string) file_get_contents( $user_memory_file ) : '';
		$mirror_contract_ok = strpos( $rest_src, 'upsert_public' ) !== false
			&& strpos( $rest_src, 'delete_with_receipt' ) !== false
			&& strpos( $writer_src, "add_action( 'bizcity_memory_mirror_delete'" ) !== false
			&& strpos( $writer_src, 'function on_mirror_delete' ) !== false
			&& strpos( $notes_src, "bizcity_memory_mirror_delete', 'note'" ) !== false
			&& strpos( $user_memory_src, "bizcity_memory_mirror_delete', 'user'" ) !== false
			&& strpos( $user_memory_src, 'delete_with_receipt' ) !== false;
		$ctx->emit_step( [
			'label'  => 'Mirror contract · REST update/delete + Notes delete',
			'status' => $mirror_contract_ok ? 'pass' : 'fail',
			'detail' => $mirror_contract_ok ? 'Owner-self and Notes delete/update hooks are wired before the CRUD runtime check.' : 'One or more unified mirror hooks are missing.',
		] );
		if ( ! $mirror_contract_ok ) {
			return [
				'status'  => 'fail',
				'error'   => 'Unified mirror contract is incomplete.',
				'fix_hint' => 'Check class-memory-unified-writer.php, class-twinbrain-rest-memory-me.php, and class-twinchat-notes-service.php.',
			];
		}

		// Step 1 — POST create.
		$req = new WP_REST_Request( 'POST', '/' . self::NS . '/memory/me' );
		$req->set_param( 'memory_text', 'Test hub-rest: ' . self::SENTINEL . ' codename diagnostics' );
		$req->set_param( 'memory_type', 'fact' );
		$req->set_param( 'memory_tier', 'explicit' );
		$req->set_param( 'score', 80 );
		$res = rest_do_request( $req );
		if ( $res->is_error() ) {
			return [ 'status' => 'fail', 'error' => 'POST error: ' . $res->as_error()->get_error_message() ];
		}
		$data = $res->get_data();
		$created_id = (string) ( $data['item']['record_id'] ?? '' );
		$ctx->emit_step( [
			'label'  => 'POST /memory/me',
			'status' => $created_id !== '' ? 'pass' : 'fail',
			'detail' => 'record_id=' . $created_id . ' · op=' . ( $data['op'] ?? '?' ),
		] );
		if ( $created_id === '' ) {
			return [ 'status' => 'fail', 'error' => 'POST returned no record_id.' ];
		}

		// Step 2 — GET list, verify visible.
		$req = new WP_REST_Request( 'GET', '/' . self::NS . '/memory/me' );
		$req->set_param( 'q', self::SENTINEL );
		$res = rest_do_request( $req );
		if ( $res->is_error() ) {
			$this->cleanup();
			return [ 'status' => 'fail', 'error' => 'GET error: ' . $res->as_error()->get_error_message() ];
		}
		$items = (array) ( $res->get_data()['items'] ?? [] );
		$found = false;
		foreach ( $items as $it ) {
			if ( (string) ( $it['record_id'] ?? '' ) === $created_id ) { $found = true; break; }
		}
		$ctx->emit_step( [
			'label'  => 'GET /memory/me?q=sentinel',
			'status' => $found ? 'pass' : 'fail',
			'detail' => 'rows=' . count( $items ) . ' · sentinel ' . ( $found ? 'found' : 'missing' ),
		] );
		if ( ! $found ) {
			$this->cleanup();
			return [ 'status' => 'fail', 'error' => 'Sentinel row không xuất hiện trong GET list.' ];
		}

		// Step 3 — PUT update.
		$new_text = 'Test hub-rest UPDATED: ' . self::SENTINEL;
		$req = new WP_REST_Request( 'PUT', '/' . self::NS . '/memory/me/' . $created_id );
		$req->set_param( 'id', $created_id );
		$req->set_param( 'memory_text', $new_text );
		$req->set_param( 'score', 95 );
		$res = rest_do_request( $req );
		if ( $res->is_error() ) {
			$this->cleanup();
			return [ 'status' => 'fail', 'error' => 'PUT error: ' . $res->as_error()->get_error_message() ];
		}
		$upd = $res->get_data();
		$updated_text  = (string) ( $upd['item']['memory_text'] ?? '' );
		$updated_score = (int)    ( $upd['item']['score']       ?? 0 );
		$ok_upd = ( $updated_text === $new_text ) && ( $updated_score === 95 );
		$ctx->emit_step( [
			'label'  => 'PUT /memory/me/' . $created_id,
			'status' => $ok_upd ? 'pass' : 'fail',
			'detail' => 'text_match=' . ( $updated_text === $new_text ? 'y' : 'n' ) . ' · score=' . $updated_score,
		] );

		// Step 4 — DELETE.
		$req = new WP_REST_Request( 'DELETE', '/' . self::NS . '/memory/me/' . $created_id );
		$req->set_param( 'id', $created_id );
		$res = rest_do_request( $req );
		$ok_del = ! $res->is_error() && ! empty( $res->get_data()['ok'] );
		$ctx->emit_step( [
			'label'  => 'DELETE /memory/me/' . $created_id,
			'status' => $ok_del ? 'pass' : 'fail',
			'detail' => $ok_del ? 'deleted' : 'failed',
		] );

		// Step 5 — verify gone.
		$req = new WP_REST_Request( 'GET', '/' . self::NS . '/memory/me' );
		$req->set_param( 'q', self::SENTINEL );
		$res = rest_do_request( $req );
		$gone = empty( (array) ( $res->get_data()['items'] ?? [] ) );
		$ctx->emit_step( [
			'label'  => 'Verify sentinel removed',
			'status' => $gone ? 'pass' : 'fail',
			'detail' => $gone ? 'list empty' : 'still present',
		] );

		$this->cleanup();

		if ( ! ( $ok_upd && $ok_del && $gone ) ) {
			return [
				'status'   => 'fail',
				'summary'  => 'CRUD chain hub-rest có sai sót.',
				'fix_hint' => 'Check REST handler trong class-twinbrain-rest-memory-me.php — verify upsert_public + delete + GET filter.',
			];
		}

		return [
			'status'  => 'pass',
			'summary' => 'Hub REST OK — POST · GET · PUT · DELETE round-trip.',
		];
	}

	public function cleanup(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_memory_users';
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE memory_text LIKE %s",
			'%' . $wpdb->esc_like( self::SENTINEL ) . '%'
		) );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Memory_Hub_Rest';
	return $list;
} );
