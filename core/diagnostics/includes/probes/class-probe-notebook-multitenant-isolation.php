<?php
/**
 * BizCity Diagnostics — core.kg.notebook_multitenant_isolation probe.
 *
 * PHASE-0.51: verifies notebook ownership scope and the public Guru attachment
 * boundary without using real user content. Every row is tagged __healthtest_.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

// [2026-07-27 Johnny Chu] HOTFIX — match standard probe loading and stop duplicate class declarations.
if ( class_exists( 'BizCity_Probe_Notebook_Multitenant_Isolation_20260727', false ) ) {
	return;
}

final class BizCity_Probe_Notebook_Multitenant_Isolation_20260727 implements BizCity_Diagnostics_Probe {

	private $notebook_ids = [];
	private $character_id = 0;
	private $attachment_ids = [];

	public function id(): string { /* [2026-07-27 Johnny Chu] PHASE-0.51 — stable DDV probe id. */ return 'core.kg.notebook_multitenant_isolation'; }
	public function label(): string { /* [2026-07-27 Johnny Chu] PHASE-0.51 — DDV probe label. */ return 'KG notebook multi-tenant isolation and public Guru guard'; }
	public function description(): string {
		// [2026-07-27 Johnny Chu] PHASE-0.51 — describe ownership and attachment assertions.
		return 'Kiểm tra notebook cá nhân của user A/B, quarantine owner_id=0 legacy, public scope rõ ràng và chặn public Guru gắn notebook cá nhân.';
	}
	public function severity(): string { /* [2026-07-27 Johnny Chu] PHASE-0.51 — critical isolation severity. */ return 'critical'; }
	public function order(): int { /* [2026-07-27 Johnny Chu] PHASE-0.51 — stable diagnostics order. */ return 70; }
	public function icon(): string { /* [2026-07-27 Johnny Chu] PHASE-0.51 — shield icon for isolation. */ return 'shield-check'; }
	public function estimate_ms(): int { /* [2026-07-27 Johnny Chu] PHASE-0.51 — bounded disposable fixture runtime. */ return 3000; }

	public function precondition() {
		// [2026-07-27 Johnny Chu] PHASE-0.51 — require all runtime dependencies before fixture creation.
		$need = [ 'BizCity_KG_Database', 'BizCity_KG_Notebook_Service', 'BizCity_TwinBrain_Notebook_Selector', 'BizCity_Knowledge_Database', 'BizCity_KG_Rest_Controller', 'BizCity_KG_Channel_Notebook_Bridge' ];
		foreach ( $need as $class ) {
			if ( ! class_exists( $class ) ) {
				return new WP_Error( 'kg_isolation_class_missing', $class . ' chưa load.' );
			}
		}
		if ( ! function_exists( 'wp_generate_uuid4' ) ) {
			return new WP_Error( 'kg_isolation_uuid_missing', 'wp_generate_uuid4() không tồn tại.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-27 Johnny Chu] PHASE-0.51 — execute disposable multi-tenant isolation assertions.
		global $wpdb;
		$steps = [];
		$failures = [];
		$uniq = substr( md5( uniqid( 'kgiso', true ) ), 0, 8 );
		$original_user = get_current_user_id();
		$user_a = (int) $original_user;
		$user_b = $user_a + 9100002;
		if ( $user_a <= 0 ) {
			return [ 'status' => 'fail', 'error' => 'authenticated_user_required', 'steps' => $steps ];
		}
		$service = BizCity_KG_Notebook_Service::instance();
		$db = BizCity_KG_Database::instance();

		$nb_a = $service->create( [ 'name' => '__healthtest_iso_a_' . $uniq ], $user_a );
		$nb_b = $service->create( [ 'name' => '__healthtest_iso_b_' . $uniq ], $user_b );
		$business_a = $this->insert_notebook_fixture( '__healthtest_iso_business_a_' . $uniq, $user_a, 'business_kb' );
		$this->remember_notebook( $nb_a );
		$this->remember_notebook( $nb_b );
		$this->remember_notebook( $business_a );
		$this->step( $ctx, $steps, 'Runtime - create personal notebooks for user A and B', ! is_wp_error( $nb_a ) && ! is_wp_error( $nb_b ), 'A=' . $this->notebook_id( $nb_a ) . '; B=' . $this->notebook_id( $nb_b ) );
		if ( is_wp_error( $nb_a ) || is_wp_error( $nb_b ) ) {
			return [ 'status' => 'fail', 'error' => 'personal_notebook_create_failed', 'steps' => $steps ];
		}

		$legacy = $this->insert_owner_zero( '__healthtest_iso_legacy_' . $uniq, 'personal' );
		$public = $this->insert_owner_zero( '__healthtest_iso_public_' . $uniq, 'business_kb' );
		$this->remember_notebook( $legacy );
		$this->remember_notebook( $public );
		$selector = BizCity_TwinBrain_Notebook_Selector::instance();
		$selected_a = $selector->select( '__healthtest_no_match_' . $uniq, $user_a, 50 );
		$selected_ids = array_map( 'intval', array_column( $selected_a, 'notebook_id' ) );
		$a_visible = in_array( $this->notebook_id( $nb_a ), $selected_ids, true );
		$b_hidden = ! in_array( $this->notebook_id( $nb_b ), $selected_ids, true );
		$legacy_hidden = ! in_array( $this->notebook_id( $legacy ), $selected_ids, true );
		$public_visible = in_array( $this->notebook_id( $public ), $selected_ids, true );
		$selected_anonymous = $selector->select( '__healthtest_no_match_anon_' . $uniq, 0, 50 );
		$anonymous_ids = array_map( 'intval', array_column( $selected_anonymous, 'notebook_id' ) );
		$anonymous_legacy_hidden = ! in_array( $this->notebook_id( $legacy ), $anonymous_ids, true );
		$anonymous_public_visible = in_array( $this->notebook_id( $public ), $anonymous_ids, true );
		$this->step( $ctx, $steps, 'Runtime - selector returns user A personal notebook', $a_visible, 'selected=' . implode( ',', $selected_ids ) );
		$this->step( $ctx, $steps, 'Runtime - selector keeps user B personal notebook out of user A results', $b_hidden, 'selected=' . implode( ',', $selected_ids ) );
		$this->step( $ctx, $steps, 'Runtime - owner_id=0 legacy personal notebook remains quarantined', $legacy_hidden, 'legacy_id=' . $this->notebook_id( $legacy ) );
		$this->step( $ctx, $steps, 'Runtime - explicit owner_id=0 business_kb is public-compatible', $public_visible, 'public_id=' . $this->notebook_id( $public ) );
		$this->step( $ctx, $steps, 'Runtime - anonymous selector excludes legacy owner_id=0 personal notebook', $anonymous_legacy_hidden, 'selected=' . implode( ',', $anonymous_ids ) );
		$this->step( $ctx, $steps, 'Runtime - anonymous selector may read explicit public scope only', $anonymous_public_visible, 'selected=' . implode( ',', $anonymous_ids ) );
		if ( ! $a_visible ) $failures[] = 'user_a_personal_missing';
		if ( ! $b_hidden ) $failures[] = 'user_b_personal_leaked';
		if ( ! $legacy_hidden ) $failures[] = 'legacy_owner_zero_leaked';
		if ( ! $public_visible ) $failures[] = 'explicit_public_scope_missing';
		if ( ! $anonymous_legacy_hidden ) $failures[] = 'anonymous_legacy_owner_zero_leaked';
		if ( ! $anonymous_public_visible ) $failures[] = 'anonymous_public_scope_missing';

		// [2026-07-28 Johnny Chu] PHASE-0.51 — exercise the same REST callback used by GuruQuickEditSheet, then prove the selector reads the canonical attachment registry.
		$char = BizCity_Knowledge_Database::instance()->create_character( [
			'name' => '__healthtest_iso_public_guru_' . $uniq,
			'status' => 'draft',
		] );
		$this->character_id = is_wp_error( $char ) ? 0 : (int) $char;
		$char_detail = is_wp_error( $char )
			? $char->get_error_code() . ': ' . $char->get_error_message()
			: 'character_id=' . $this->character_id;
		$this->step( $ctx, $steps, 'Runtime - create disposable public Guru character', $this->character_id > 0, $char_detail );
		if ( $this->character_id <= 0 ) {
			$failures[] = 'create_character_failed';
			return [
				'status'   => 'fail',
				'error'    => $char_detail,
				'summary'  => 'Notebook fixtures passed, but the disposable Guru character could not be created.',
				'fix_hint' => 'Kiểm tra bảng bizcity_characters và lỗi DB ở bước tạo character.',
				'steps'    => $steps,
			];
		}
		$guru_uuid = wp_generate_uuid4();
		$character_update = BizCity_Knowledge_Database::instance()->update_character( $this->character_id, [ 'guru_uuid' => $guru_uuid, 'visibility' => 'marketplace' ] );
		$character_update_detail = is_wp_error( $character_update )
			? $character_update->get_error_code() . ': ' . $character_update->get_error_message()
			: 'guru_uuid=' . $guru_uuid;
		$this->step( $ctx, $steps, 'Runtime - stamp public Guru metadata', true === $character_update, $character_update_detail );
		if ( true !== $character_update ) {
			$failures[] = 'stamp_guru_metadata_failed';
			return [ 'status' => 'fail', 'error' => $character_update_detail, 'steps' => $steps ];
		}

		$attach_request = new WP_REST_Request( 'POST', '/bizcity-knowledge/v2/notebooks/' . $this->notebook_id( $public ) . '/attached-gurus' );
		$attach_request->set_param( 'id', $this->notebook_id( $public ) );
		$attach_request->set_param( 'guru_uuid', $guru_uuid );
		$attach_request->set_param( 'source', 'self' );
		$attach_request->set_param( 'read_only', true );
		$attach_response = BizCity_KG_Rest_Controller::instance()->attach_guru( $attach_request );
		$attach_data = $attach_response instanceof WP_REST_Response ? $attach_response->get_data() : $attach_response;
		$attach_ok = is_array( $attach_data ) && ! empty( $attach_data['ok'] ) && ! empty( $attach_data['attachment']['id'] );
		if ( $attach_ok ) $this->attachment_ids[] = (int) $attach_data['attachment']['id'];
		$this->step( $ctx, $steps, 'Runtime - GuruQuickEditSheet attach writes attachment registry', $attach_ok, $attach_ok ? 'attachment_id=' . (int) $attach_data['attachment']['id'] : 'attachment_failed' );
		if ( ! $attach_ok ) $failures[] = 'guru_attachment_registry_write_failed';

		$guru_selected = $selector->select( '__healthtest_guru_registry_' . $uniq, $user_a, 10, [ 'guru_id' => $this->character_id ] );
		$guru_selected_ids = array_map( 'intval', array_column( $guru_selected, 'notebook_id' ) );
		$registry_visible = in_array( $this->notebook_id( $public ), $guru_selected_ids, true );
		$this->step( $ctx, $steps, 'Runtime - selector reads Guru attachment registry', $registry_visible, 'selected=' . implode( ',', $guru_selected_ids ) );
		if ( ! $registry_visible ) $failures[] = 'guru_attachment_registry_not_selected';

		$scope_request = new WP_REST_Request( 'GET', '/bizcity-knowledge/v2/notebooks' );
		$scope_request->set_param( 'scope', 'business_kb' );
		$scope_request->set_param( 'include_public', true );
		wp_set_current_user( $user_a );
		$scope_response = BizCity_KG_Rest_Controller::instance()->list_notebooks( $scope_request );
		wp_set_current_user( $original_user );
		$scope_rows = $scope_response instanceof WP_REST_Response ? $scope_response->get_data() : $scope_response;
		$scope_ids = is_array( $scope_rows ) ? array_map( 'intval', array_column( $scope_rows, 'id' ) ) : [];
		$scope_ok = in_array( $this->notebook_id( $business_a ), $scope_ids, true )
			&& in_array( $this->notebook_id( $public ), $scope_ids, true )
			&& ! in_array( $this->notebook_id( $nb_a ), $scope_ids, true );
		$this->step( $ctx, $steps, 'Runtime - GET notebooks?scope=business_kb returns scoped set', $scope_ok, 'selected=' . implode( ',', $scope_ids ) );
		if ( ! $scope_ok ) $failures[] = 'business_scope_rest_filter_incorrect';

		$unlinked_name = '__healthtest_unlinked_capture_' . $uniq;
		$before_unlinked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$db->tbl_notebooks()} WHERE name = %s AND owner_id = 0", $unlinked_name ) );
		$unlinked_capture = BizCity_KG_Channel_Notebook_Bridge::instance()->capture( [
			'channel' => 'zalobot', 'user_id' => 0, 'chat_id' => 'healthtest-' . $uniq,
			'title_hint' => $unlinked_name, 'kind' => 'text', 'content' => 'must not persist',
		] );
		$after_unlinked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$db->tbl_notebooks()} WHERE name = %s AND owner_id = 0", $unlinked_name ) );
		$unlinked_ok = is_wp_error( $unlinked_capture ) && 'notebook_bridge_invalid_identity' === $unlinked_capture->get_error_code() && $before_unlinked === $after_unlinked;
		$this->step( $ctx, $steps, 'Runtime - unlinked Zalo Bot capture rejected without owner_id=0 notebook', $unlinked_ok, is_wp_error( $unlinked_capture ) ? $unlinked_capture->get_error_code() . '; rows=' . $after_unlinked : 'capture unexpectedly accepted' );
		if ( ! $unlinked_ok ) $failures[] = 'zalo_unlinked_capture_created_owner_zero';

		$attach = $this->character_id > 0 ? $db->attach_guru( $this->notebook_id( $nb_a ), $guru_uuid, [ 'public_serving' => true ] ) : new WP_Error( 'character_create_failed', 'character create failed' );
		$guard_ok = is_wp_error( $attach ) && 'kg_attach_personal_notebook_forbidden' === $attach->get_error_code();
		$this->step( $ctx, $steps, 'Runtime - public Guru rejects user-owned personal notebook', $guard_ok, is_wp_error( $attach ) ? $attach->get_error_code() : 'attachment unexpectedly accepted' );
		if ( ! $guard_ok ) $failures[] = 'public_guru_personal_attach_allowed';
		$legacy_attach = $this->character_id > 0 ? $db->attach_guru( $this->notebook_id( $legacy ), $guru_uuid, [ 'public_serving' => true ] ) : new WP_Error( 'character_create_failed', 'character create failed' );
		$legacy_guard_ok = is_wp_error( $legacy_attach ) && 'kg_attach_personal_notebook_forbidden' === $legacy_attach->get_error_code();
		$this->step( $ctx, $steps, 'Runtime - public Guru rejects owner_id=0 personal notebook', $legacy_guard_ok, is_wp_error( $legacy_attach ) ? $legacy_attach->get_error_code() : 'attachment unexpectedly accepted' );
		if ( ! $legacy_guard_ok ) $failures[] = 'public_guru_legacy_personal_attach_allowed';

		$status = empty( $failures ) ? 'pass' : 'fail';
		return [
			'status' => $status,
			'summary' => $status === 'pass' ? 'Notebook scope and public Guru isolation controls passed.' : 'Notebook isolation failed: ' . implode( ', ', $failures ),
			'error' => empty( $failures ) ? '' : implode( '; ', $failures ),
			'fix_hint' => empty( $failures ) ? '' : 'Kiểm tra class-kg-notebook-service.php, class-twinbrain-notebook-selector.php và attach_guru().',
			'steps' => $steps,
		];
	}

	public function cleanup(): void {
		// [2026-07-27 Johnny Chu] PHASE-0.51 — remove every disposable fixture after the run.
		global $wpdb;
		if ( class_exists( 'BizCity_KG_Database' ) && ! empty( $this->attachment_ids ) ) {
			$wpdb->query( 'DELETE FROM ' . BizCity_KG_Database::instance()->tbl_notebook_character_attachments() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $this->attachment_ids ) ) . ')' );
		}
		if ( $this->character_id > 0 && class_exists( 'BizCity_Knowledge_Database' ) ) {
			BizCity_Knowledge_Database::instance()->delete_character( $this->character_id );
		}
		if ( class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			$service = BizCity_KG_Notebook_Service::instance();
			foreach ( $this->notebook_ids as $id ) {
				if ( $id > 0 ) $service->delete( $id );
			}
		}
	}

	private function insert_owner_zero( string $name, string $scope ) {
		return $this->insert_notebook_fixture( $name, 0, $scope );
	}

	private function insert_notebook_fixture( string $name, int $owner_id, string $scope ) {
		// [2026-07-27 Johnny Chu] PHASE-0.51 — create explicit legacy/public owner-zero fixtures.
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$ok = $wpdb->insert( $db->tbl_notebooks(), [
			'uuid' => wp_generate_uuid4(),
			'name' => $name,
			'description' => 'DDV probe - safe to delete',
			'owner_id' => $owner_id,
			'notebook_scope' => $scope,
			'settings' => '{}',
			'stats' => '{}',
		] );
		if ( false === $ok ) return null;
		return [ 'id' => (int) $wpdb->insert_id ];
	}

	private function remember_notebook( $row ): void {
		// [2026-07-27 Johnny Chu] PHASE-0.51 — retain fixture ids for deterministic cleanup.
		$id = $this->notebook_id( $row );
		if ( $id > 0 ) $this->notebook_ids[] = $id;
	}

	private function notebook_id( $row ): int {
		// [2026-07-27 Johnny Chu] HOTFIX — use a domain-specific helper name for notebook fixture ids.
		return is_array( $row ) ? (int) ( $row['id'] ?? 0 ) : 0;
	}

	private function step( $ctx, array &$steps, string $label, bool $ok, string $detail ): void {
		// [2026-07-27 Johnny Chu] PHASE-0.51 — emit each assertion as DDV evidence.
		$step = [ 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail ];
		$steps[] = $step;
		$ctx->emit_step( $step );
}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Notebook_Multitenant_Isolation_20260727';
	return $probes;
} );
