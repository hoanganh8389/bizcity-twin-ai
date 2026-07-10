<?php
/**
 * BizCity Diagnostics — kg.seeding probe (Phase 0.41 L9.a T3).
 *
 * Inserts a tagged test row into `bizcity_kg_sources`, selects it back,
 * and deletes it. Verifies that:
 *   1. The shard host (slave3/slave10 via WPDB_Router) is reachable.
 *   2. The DDL on disk matches what the schema class declares (no missing
 *      columns surfacing as "Unknown column" warnings).
 *   3. SELECT can find what INSERT just wrote (write+read consistency on
 *      multisite blogs).
 *
 * Test rows use:
 *   - `origin_plugin` = `__healthtest__`
 *   - `title`         = `__healthtest__ <microtime>`
 * so the cleanup pass + auto orphan cleaner can wipe them deterministically.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-21 (Phase 0.41 L9.a)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_KG_Seeding', false ) ) {
	return;
}

final class BizCity_Probe_KG_Seeding implements BizCity_Diagnostics_Probe {

	private const ORIGIN_TAG = '__healthtest__';

	/** @var array<int,int> ids inserted during this probe instance — for cleanup. */
	private $created_ids = [];

	public function id(): string          { return 'kg.seeding'; }
	public function label(): string       { return 'Seeding nguồn tài liệu'; }
	public function description(): string {
		return 'Insert → select-back → delete một row test trong bizcity_kg_sources. Verify shard host + DDL khớp + write/read nhất quán.';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 10; }
	public function icon(): string        { return 'database'; }
	public function estimate_ms(): int    { return 1500; }

	/**
	 * Precondition: table must exist (otherwise Repair Hub button is the right
	 * action, not running this probe).
	 *
	 * @return true|WP_Error
	 */
	public function precondition() {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_kg_sources';
		$exists = bizcity_tbl_exists( $tbl ) ? $tbl : null; // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		if ( $exists !== $tbl ) {
			return new WP_Error(
				'table_missing',
				sprintf( 'Bảng %s không tồn tại. Vào Diagnostics → Repair Hub để tạo trước.', $tbl )
			);
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;
		$tbl   = $wpdb->prefix . 'bizcity_kg_sources';
		$now   = current_time( 'mysql' );
		$uuid  = wp_generate_uuid4();
		$title = self::ORIGIN_TAG . ' ' . microtime( true );

		// Step 1 — INSERT
		$insert_ok = $wpdb->insert(
			$tbl,
			[
				'uuid'          => $uuid,
				'blog_id'       => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
				'origin_plugin' => self::ORIGIN_TAG,
				'origin_kind'   => 'probe',
				'title'         => $title,
				'status'        => 'active',
				'scope_type'    => 'notebook',
				'scope_id'      => '__healthtest__',
				'created_at'    => $now,
				'updated_at'    => $now,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( $insert_ok === false ) {
			$err = $wpdb->last_error ?: 'unknown';
			$ctx->emit_step( [ 'label' => 'INSERT vào kg_sources', 'status' => 'fail', 'detail' => $err ] );
			return [
				'status'   => 'fail',
				'error'    => sprintf( 'INSERT thất bại: %s', $err ),
				'fix_hint' => 'Có thể schema drift (missing column). Mở Diagnostics → Columns tab để so DDL.',
			];
		}

		$insert_id = (int) $wpdb->insert_id;
		$this->created_ids[] = $insert_id;
		$ctx->emit_step( [
			'label'  => 'INSERT vào kg_sources',
			'status' => 'pass',
			'detail' => sprintf( 'id=%d uuid=%s', $insert_id, $uuid ),
		] );

		// Step 2 — SELECT back by uuid
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, uuid, title, origin_plugin FROM {$tbl} WHERE uuid = %s LIMIT 1",
			$uuid
		), ARRAY_A );

		if ( ! $row || (int) $row['id'] !== $insert_id ) {
			$ctx->emit_step( [
				'label'  => 'SELECT back by uuid',
				'status' => 'fail',
				'detail' => $row ? 'row mismatch' : 'no row returned',
			] );
			return [
				'status'    => 'fail',
				'error'     => 'INSERT thành công nhưng SELECT không tìm thấy row vừa ghi.',
				'fix_hint'  => 'Có thể write-read drift giữa shard host (WPDB_Router slave3/slave10). Kiểm tra cấu hình BizCity_WPDB_Router.',
				'artifacts' => $this->describe_artifacts(),
			];
		}
		$ctx->emit_step( [
			'label'  => 'SELECT back by uuid',
			'status' => 'pass',
			'detail' => sprintf( 'matched id=%d', (int) $row['id'] ),
		] );

		// Step 3 — count tagged rows (sanity)
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl} WHERE origin_plugin = %s",
			self::ORIGIN_TAG
		) );
		$ctx->emit_step( [
			'label'  => 'Count tagged rows trước cleanup',
			'status' => 'pass',
			'detail' => sprintf( '%d rows tagged %s', $count, self::ORIGIN_TAG ),
		] );

		return [
			'status'    => 'pass',
			'summary'   => sprintf( 'Seeding pipeline OK — INSERT/SELECT round-trip trên kg_sources (id=%d).', $insert_id ),
			'artifacts' => $this->describe_artifacts(),
		];
	}

	/**
	 * Delete every row this probe instance created. Also opportunistically
	 * cleans up orphan __healthtest__ rows older than 1 hour (safety net for
	 * crashed runs).
	 */
	public function cleanup(): void {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_kg_sources';

		// Targeted cleanup of this run.
		foreach ( $this->created_ids as $id ) {
			$wpdb->delete( $tbl, [ 'id' => (int) $id ], [ '%d' ] );
		}
		$this->created_ids = [];

		// Safety-net sweep — anything tagged + older than 1h is leftover from
		// a previous crashed run. Limit 100 per call so a misconfigured DB
		// cannot stall this method.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$tbl}
			 WHERE origin_plugin = %s
			   AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
			 LIMIT 100",
			self::ORIGIN_TAG
		) );
	}

	/** @return array<int,array{kind:string,id:int,label:string}> */
	private function describe_artifacts(): array {
		$out = [];
		foreach ( $this->created_ids as $id ) {
			$out[] = [ 'kind' => 'kg_source', 'id' => (int) $id, 'label' => self::ORIGIN_TAG ];
		}
		return $out;
	}
}

// Self-register through the standard filter.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_KG_Seeding';
	return $list;
} );
