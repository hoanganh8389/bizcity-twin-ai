<?php
/**
 * BizCity Diagnostics — kg.guru.notebook_binding probe (PHASE-0.45-KG-FILE-GRAPH).
 *
 * Plants a REAL, disposable notebook + a REAL, disposable character/Guru
 * (tagged `__healthtest_`) and drives the ACTUAL cross-notebook binding
 * contract in `BizCity_KG_Database` — `attach_guru()`/`detach_guru()`/
 * `get_attached_guru_uuids()`/`build_virtual_merge_where()` — proving a
 * Guru's OWN entities (character_uuid-scoped, living outside this notebook)
 * become visible through the notebook's virtual merge WHERE clause once
 * attached, and disappear again once detached. This is a genuine gap: no
 * existing probe exercises `attach_guru()`/`build_virtual_merge_where()` at
 * all (`class-probe-guru-runtime.php` only tests LLM reply DTO shape;
 * `class-probe-channel-notebook-bridge.php` only tests channel "@notebook"
 * capture, never Guru attachment).
 *
 * Confirms:
 *   1. `attach_guru()` succeeds and is idempotent-safe (returns array, not
 *      WP_Error).
 *   2. `get_attached_guru_uuids()` includes the guru right after attach.
 *   3. POSITIVE control: `build_virtual_merge_where()` for the ATTACHED
 *      notebook includes the guru-owned entity (cross-notebook link works).
 *   4. NEGATIVE control: the SAME guru-owned entity is NOT visible through
 *      a virtual-merge WHERE built for a DIFFERENT, unattached notebook —
 *      proving the scoping is real isolation, not an always-true bug.
 *   5. `detach_guru()` succeeds and the entity disappears from the
 *      previously-attached notebook's virtual merge again.
 *
 * All test rows (entity + character + both notebooks) are deleted in
 * cleanup(), which always runs (pass or fail).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-25 (PHASE-0.45-KG-FILE-GRAPH)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

// [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — double-load guard.
if ( class_exists( 'BizCity_Probe_KG_Guru_Notebook_Binding', false ) ) {
	return;
}

final class BizCity_Probe_KG_Guru_Notebook_Binding implements BizCity_Diagnostics_Probe {

	/** @var int */
	private $nb_id = 0;
	/** @var string */
	private $nb_uuid = '';
	/** @var int */
	private $nb2_id = 0;
	/** @var string */
	private $nb2_uuid = '';
	/** @var int */
	private $char_id = 0;
	/** @var string */
	private $guru_uuid = '';
	/** @var int */
	private $entity_id = 0;

	public function id(): string { return 'kg.guru.notebook_binding'; }
	public function label(): string { return 'KG Guru ↔ Notebook Binding — cross-notebook virtual merge (attach/detach)'; }
	public function description(): string {
		return 'Tạo notebook + character/Guru thật (tagged __healthtest_), gắn 1 entity guru-owned (character_uuid), gọi attach_guru()/build_virtual_merge_where() để xác nhận entity của Guru xuất hiện qua notebook đã gắn (positive) nhưng KHÔNG xuất hiện qua notebook khác chưa gắn (negative control), rồi detach_guru() và xác nhận entity biến mất trở lại.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 69; }
	public function icon(): string { return 'link-2'; }
	public function estimate_ms(): int { return 3000; } // pure DB-level contract, no LLM/embed calls

	public function precondition() {
		$need = [ 'BizCity_KG_Notebook_Service', 'BizCity_KG_Graph_Service', 'BizCity_KG_Database', 'BizCity_KG_Notebook_Folder', 'BizCity_Knowledge_Database' ];
		foreach ( $need as $cls ) {
			if ( ! class_exists( $cls ) ) {
				return new WP_Error( 'kg_class_missing', $cls . ' chưa load — knowledge/kg-hub bootstrap không hoàn tất.' );
			}
		}
		if ( ! function_exists( 'wp_generate_uuid4' ) ) {
			return new WP_Error( 'wp_uuid_missing', 'wp_generate_uuid4() không tồn tại — WordPress core quá cũ.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;
		$steps    = [];
		$failures = [];
		$uniq     = substr( md5( uniqid( 'kgguru', true ) ), 0, 8 );

		$db = BizCity_KG_Database::instance();

		// ── Step 1: two disposable notebooks — one to attach, one as negative control ─
		$nb = BizCity_KG_Notebook_Service::instance()->create( [ 'name' => '__healthtest_kg_guru_nb_' . $uniq, 'description' => 'DDV probe — safe to delete' ], get_current_user_id() ?: 1 );
		$this->nb_id   = (int) ( $nb['id'] ?? 0 );
		$this->nb_uuid = (string) ( $nb['uuid'] ?? '' );

		$nb2 = BizCity_KG_Notebook_Service::instance()->create( [ 'name' => '__healthtest_kg_guru_nb2_' . $uniq, 'description' => 'DDV probe — safe to delete (negative control, never attached)' ], get_current_user_id() ?: 1 );
		$this->nb2_id   = (int) ( $nb2['id'] ?? 0 );
		$this->nb2_uuid = (string) ( $nb2['uuid'] ?? '' );

		$this->step( $ctx, $steps, 'Runtime - create 2 disposable notebooks (target + negative control)', $this->nb_id > 0 && $this->nb2_id > 0, 'notebook_id=' . $this->nb_id . '; control_notebook_id=' . $this->nb2_id );
		if ( $this->nb_id <= 0 || $this->nb2_id <= 0 ) {
			return [ 'status' => 'fail', 'error' => 'Notebook create failed', 'steps' => $steps ];
		}

		// ── Step 2: disposable character stamped as a Guru (mirrors BizCity_KG_Guru_Builder::promote_notebook()) ─
		$this->guru_uuid = wp_generate_uuid4();
		$char_result = BizCity_Knowledge_Database::instance()->create_character( [
			'name'        => '__healthtest_kg_guru_char_' . $uniq,
			'description' => 'DDV probe Guru — safe to delete',
			'status'      => 'draft',
		] );
		$this->char_id = is_wp_error( $char_result ) ? 0 : (int) $char_result;
		$this->step( $ctx, $steps, 'Runtime - create disposable character', $this->char_id > 0, is_wp_error( $char_result ) ? $char_result->get_error_message() : ( 'character_id=' . $this->char_id ) );
		if ( $this->char_id <= 0 ) {
			$failures[] = 'create_character_failed';
			return [ 'status' => 'fail', 'error' => implode( ';', $failures ), 'steps' => $steps ];
		}

		$upd = BizCity_Knowledge_Database::instance()->update_character( $this->char_id, [ 'guru_uuid' => $this->guru_uuid, 'visibility' => 'private' ] );
		$this->step( $ctx, $steps, 'Runtime - stamp guru_uuid onto character (promote_notebook() pattern)', $upd === true, 'guru_uuid=' . $this->guru_uuid );
		if ( $upd !== true ) { $failures[] = 'stamp_guru_uuid_failed'; }

		// ── Step 3: plant 1 entity owned by the Guru (character_uuid-scoped, notebook_id=0 sentinel) ─
		$this->entity_id = (int) BizCity_KG_Graph_Service::instance()->upsert_entity( 0, 'GuruEntity' . $uniq, 'Other', 'Guru-owned entity for cross-notebook binding probe' );
		if ( $this->entity_id > 0 ) {
			$wpdb->update( $db->tbl_entities(), [ 'character_uuid' => $this->guru_uuid ], [ 'id' => $this->entity_id ] );
		}
		$this->step( $ctx, $steps, 'Runtime - plant guru-owned entity (character_uuid stamped)', $this->entity_id > 0, 'entity_id=' . $this->entity_id );
		if ( $this->entity_id <= 0 ) {
			$failures[] = 'plant_entity_failed';
			return [ 'status' => 'fail', 'error' => implode( ';', $failures ), 'steps' => $steps ];
		}

		// ── Step 4: attach_guru() to the TARGET notebook only ───────────────
		$attach = $db->attach_guru( $this->nb_id, $this->guru_uuid );
		$attach_ok = is_array( $attach ) && ! is_wp_error( $attach );
		$this->step( $ctx, $steps, 'Runtime - attach_guru() succeeds', $attach_ok, $attach_ok ? ( 'attachment_id=' . (int) ( $attach['id'] ?? 0 ) ) : ( is_wp_error( $attach ) ? $attach->get_error_message() : 'unexpected return' ) );
		if ( ! $attach_ok ) { $failures[] = 'attach_guru_failed'; }

		$attached_uuids = $db->get_attached_guru_uuids( $this->nb_id );
		$attach_visible = in_array( $this->guru_uuid, $attached_uuids, true );
		$this->step( $ctx, $steps, 'Runtime - get_attached_guru_uuids() includes the guru right after attach', $attach_visible, 'attached=' . implode( ',', $attached_uuids ) );
		if ( ! $attach_visible ) { $failures[] = 'guru_not_in_attached_list'; }

		// ── Step 5: POSITIVE control — virtual merge on the ATTACHED notebook finds the guru entity ─
		$where_attached = $db->build_virtual_merge_where( $this->nb_id );
		$found_attached = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db->tbl_entities()} WHERE ({$where_attached}) AND id=" . (int) $this->entity_id );
		$this->step( $ctx, $steps, 'Runtime - POSITIVE: virtual_merge_where(attached notebook) finds the guru entity', $found_attached === 1, 'found=' . $found_attached );
		if ( $found_attached !== 1 ) { $failures[] = 'virtual_merge_positive_failed'; }

		// ── Step 6: NEGATIVE control — virtual merge on the UNATTACHED notebook does NOT find it ─
		$where_control = $db->build_virtual_merge_where( $this->nb2_id );
		$found_control = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db->tbl_entities()} WHERE ({$where_control}) AND id=" . (int) $this->entity_id );
		$this->step( $ctx, $steps, 'Runtime - NEGATIVE: virtual_merge_where(unattached control notebook) does NOT find it (real isolation, not always-true)', $found_control === 0, 'found=' . $found_control );
		if ( $found_control !== 0 ) { $failures[] = 'virtual_merge_negative_leaked'; }

		// ── Step 7: detach_guru() reverses the binding ──────────────────────
		$detach = $db->detach_guru( $this->nb_id, $this->guru_uuid );
		$detach_ok = is_array( $detach ) && (int) ( $detach['deleted'] ?? 0 ) > 0;
		$this->step( $ctx, $steps, 'Runtime - detach_guru() succeeds', $detach_ok, is_array( $detach ) ? ( 'deleted=' . (int) ( $detach['deleted'] ?? 0 ) ) : 'unexpected return' );
		if ( ! $detach_ok ) { $failures[] = 'detach_guru_failed'; }

		$attached_after = $db->get_attached_guru_uuids( $this->nb_id );
		$gone_from_list = ! in_array( $this->guru_uuid, $attached_after, true );
		$this->step( $ctx, $steps, 'Runtime - get_attached_guru_uuids() no longer includes the guru after detach', $gone_from_list, 'attached_after=' . implode( ',', $attached_after ) );
		if ( ! $gone_from_list ) { $failures[] = 'guru_still_in_attached_list_after_detach'; }

		$where_after = $db->build_virtual_merge_where( $this->nb_id );
		$found_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db->tbl_entities()} WHERE ({$where_after}) AND id=" . (int) $this->entity_id );
		$this->step( $ctx, $steps, 'Runtime - virtual_merge_where(same notebook) no longer finds the entity after detach', $found_after === 0, 'found=' . $found_after );
		if ( $found_after !== 0 ) { $failures[] = 'virtual_merge_still_visible_after_detach'; }

		$status = empty( $failures ) ? 'pass' : 'fail';
		return [
			'status'   => $status,
			'summary'  => $status === 'pass'
				? 'KG Guru↔Notebook binding: attach_guru() correctly exposes a Guru-owned entity through the attached notebook virtual merge, isolates it from an unattached notebook, and detach_guru() correctly reverses it.'
				: 'KG Guru↔Notebook binding FAILED: ' . implode( ', ', array_unique( $failures ) ) . '.',
			'error'    => empty( $failures ) ? '' : implode( '; ', array_unique( $failures ) ),
			'fix_hint' => empty( $failures ) ? '' : 'Xem class-kg-database.php::attach_guru()/detach_guru()/get_attached_guru_uuids()/build_virtual_merge_where().',
			'steps'    => $steps,
		];
	}

	public function cleanup(): void {
		global $wpdb;
		// [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — best-effort detach first (in case
		// a mid-run failure left the binding attached), then wipe entity/character/notebooks.
		if ( $this->nb_id > 0 && $this->guru_uuid !== '' && class_exists( 'BizCity_KG_Database' ) ) {
			BizCity_KG_Database::instance()->detach_guru( $this->nb_id, $this->guru_uuid );
		}
		if ( $this->entity_id > 0 && class_exists( 'BizCity_KG_Database' ) ) {
			$db = BizCity_KG_Database::instance();
			$wpdb->delete( $db->tbl_entities(), [ 'id' => $this->entity_id ] );
		}
		if ( $this->char_id > 0 && class_exists( 'BizCity_Knowledge_Database' ) ) {
			BizCity_Knowledge_Database::instance()->delete_character( $this->char_id );
		}
		foreach ( [ [ $this->nb_id, $this->nb_uuid ], [ $this->nb2_id, $this->nb2_uuid ] ] as $pair ) {
			list( $id, $nbuuid ) = $pair;
			if ( $id > 0 && class_exists( 'BizCity_KG_Notebook_Service' ) ) {
				BizCity_KG_Notebook_Service::instance()->delete( $id );
			}
			if ( $nbuuid !== '' && class_exists( 'BizCity_KG_Notebook_Folder' ) ) {
				BizCity_KG_Notebook_Folder::instance()->purge( 'notebooks', $nbuuid );
			}
		}
	}

	/**
	 * @param object           $ctx
	 * @param array<int,array> $steps
	 */
	private function step( $ctx, array &$steps, string $label, bool $ok, string $detail ): void {
		$step = [ 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail ];
		$steps[] = $step;
		$ctx->emit_step( $step );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_KG_Guru_Notebook_Binding';
	return $probes;
} );
