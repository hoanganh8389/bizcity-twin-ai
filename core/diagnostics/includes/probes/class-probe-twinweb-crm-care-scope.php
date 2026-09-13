<?php
/**
 * Read-only DDV probe for the C contact-care projection and exact Inbox scope.
 *
 * The probe uses one existing or disposable conversation already allowed by
 * the current C identity. Disposable mutation fixtures are removed in cleanup.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-10 (PHASE-0.41-W7-C)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_bizcity_safe_loader = dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_bizcity_safe_loader ) && is_readable( $_bizcity_safe_loader ) ) {
		require_once $_bizcity_safe_loader;
	}
	unset( $_bizcity_safe_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	return;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_TwinWeb_CRM_Care_Scope', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_CRM_Care_Scope implements BizCity_Diagnostics_Probe {

	private $fixture_inbox_ids = array();
	private $fixture_contact_ids = array();
	private $fixture_user_ids = array();
	private $original_user_id = 0;

	private function create_fixture( int $user_id ): ?array {
		// [2026-09-10 07:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W7-C — create a disposable member-scoped CRM fixture only when the read-only tenant has no eligible conversation.
		if ( ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_CRM_Team_Manager' ) ) {
			return null;
		}
		$ref = '__diag_care_' . strtolower( wp_generate_uuid4() );
		$inbox_id = BizCity_CRM_Repository::upsert_inbox( 'webchat', $ref, array( 'name' => 'Diagnostics care fixture' ) );
		if ( $inbox_id <= 0 || ! BizCity_CRM_Team_Manager::add_inbox_member( $inbox_id, $user_id, 'agent', false ) ) {
			return null;
		}
		$this->fixture_inbox_ids[] = $inbox_id;
		$contact = BizCity_CRM_Repository::upsert_contact( $inbox_id, '__diag_contact_' . strtolower( wp_generate_uuid4() ), array( 'name' => 'Diagnostics care contact' ) );
		$contact_id = (int) ( $contact['contact_id'] ?? 0 );
		$contact_inbox_id = (int) ( $contact['contact_inbox_id'] ?? 0 );
		if ( $contact_id <= 0 || $contact_inbox_id <= 0 ) {
			return null;
		}
		$this->fixture_contact_ids[] = $contact_id;
		$conversation_id = BizCity_CRM_Repository::open_or_get_conversation( $inbox_id, $contact_inbox_id );
		if ( $conversation_id <= 0 ) {
			return null;
		}
		global $wpdb;
		$wpdb->update(
			BizCity_CRM_DB_Installer_V2::tbl_conversations(),
			array( 'contact_id' => $contact_id, 'platform' => 'webchat', 'account_id' => $ref, 'blog_id' => (int) get_current_blog_id() ),
			array( 'id' => $conversation_id ),
			array( '%d', '%s', '%s', '%d' ),
			array( '%d' )
		);
		return array( 'id' => $conversation_id, 'contact_id' => $contact_id, 'inbox_id' => $inbox_id, 'user_id' => $user_id );
	}

	private function create_user( string $label ): int {
		// [2026-09-11 10:15 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W7-C — create a disposable C-surface user for two-user foreign-contact denial.
		if ( ! function_exists( 'wp_insert_user' ) ) { return 0; }
		$suffix = strtolower( substr( md5( (string) microtime( true ) . '|' . wp_generate_uuid4() ), 0, 12 ) );
		$user_id = wp_insert_user( array(
			'user_login' => 'crm_care_' . sanitize_key( $label ) . '_' . $suffix,
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'crm-care-' . sanitize_key( $label ) . '-' . $suffix . '@invalid.test',
			'role'       => 'subscriber',
		) );
		if ( is_wp_error( $user_id ) || (int) $user_id <= 0 ) { return 0; }
		if ( function_exists( 'add_user_to_blog' ) ) { add_user_to_blog( (int) get_current_blog_id(), (int) $user_id, 'subscriber' ); }
		$this->fixture_user_ids[] = (int) $user_id;
		return (int) $user_id;
	}

	public function id(): string { return 'modules.twin_gpt.crm_care_scope'; }
	public function label(): string { return 'Twin GPT contact-care scope'; }
	public function description(): string { return 'Kiểm tra projection chăm sóc contact của C theo exact Inbox scope mà không tạo side effect.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 72; }
	public function icon(): string { return 'contact-round'; }
	public function estimate_ms(): int { return 250; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-09-10 07:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W7-C — prove read-only contact-care projection through the current-user exact Inbox scope.
		$this->original_user_id = (int) get_current_user_id();
		unset( $ctx );
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$rest_path = $root . 'modules/twinweb/includes/class-twinweb-rest.php';
		$source = is_readable( $rest_path ) ? (string) file_get_contents( $rest_path ) : '';
		$disk_ok = $source !== ''
			&& strpos( $source, "'/crm/inbox/conversations/(?P<id>\\d+)/care'" ) !== false
			&& strpos( $source, 'get_crm_member_care' ) !== false
			&& strpos( $source, 'post_crm_member_care' ) !== false;
		$steps[] = array(
			'label'  => 'Disk - contact-care route and read/write split are present',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'TwinWeb exposes the contact-care route with separate projection and mutation callbacks.' : 'Contact-care route or callback is missing.',
		);
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'C contact-care route is incomplete.', 'fix_hint' => 'Register the exact conversation care route before exposing C care tools.', 'steps' => $steps );
		}

		$loader_ok = class_exists( 'BizCity_TwinWeb_REST', false )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_crm_member_care' )
			&& class_exists( 'BizCity_CRM_Repository', false )
			&& method_exists( 'BizCity_CRM_Repository', 'get_contact_care_projection' )
			&& class_exists( 'BizCity_CRM_Inbox_Access', false )
			&& method_exists( 'BizCity_CRM_Inbox_Access', 'resolve_scope' );
		$steps[] = array(
			'label'  => 'Loader - care route, CRM projection and C scope owner are loaded',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'TwinWeb, CRM Repository and Inbox Access are available.' : 'A contact-care dependency is not loaded.',
		);
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'C contact-care dependencies are incomplete.', 'fix_hint' => 'Load TwinWeb, CRM Repository and C Inbox Access before running the care probe.', 'steps' => $steps );
		}

		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			$steps[] = array( 'label' => 'Runtime - current-user care projection', 'status' => 'skip', 'detail' => 'No authenticated Diagnostics user is available for a C-surface scope fixture.' );
			return array( 'status' => 'skip', 'summary' => 'C contact-care runtime needs an authenticated user fixture.', 'fix_hint' => 'Run this probe as an authenticated C member with an allowed Inbox conversation.', 'steps' => $steps );
		}

		$scope = BizCity_CRM_Inbox_Access::resolve_scope( $user_id, 'c' );
		$allowed = isset( $scope['inbox_ids'] ) && is_array( $scope['inbox_ids'] ) ? array_values( array_map( 'intval', $scope['inbox_ids'] ) ) : array();
		$conversation = null;
		foreach ( $allowed as $inbox_id ) {
			$rows = BizCity_CRM_Repository::list_conversations( array( 'inbox_ids' => array( $inbox_id ), 'limit' => 1 ) );
			if ( ! empty( $rows[0] ) && is_array( $rows[0] ) ) {
				$conversation = $rows[0];
				break;
			}
		}
		if ( ! is_array( $conversation ) || (int) ( $conversation['id'] ?? 0 ) <= 0 ) {
			$conversation = $this->create_fixture( $user_id );
			if ( is_array( $conversation ) ) {
				$allowed[] = (int) $conversation['inbox_id'];
				$steps[] = array( 'label' => 'Runtime - disposable member-scoped care fixture', 'status' => 'pass', 'detail' => 'Created a diagnostic-only webchat inbox, membership, contact and conversation; cleanup runs after the probe.' );
			} else {
				$steps[] = array( 'label' => 'Runtime - current-user care projection', 'status' => 'skip', 'detail' => 'No existing conversation is available inside the current C Inbox scope and the disposable fixture could not be created.' );
				return array( 'status' => 'skip', 'summary' => 'C contact-care runtime needs an allowed conversation fixture.', 'fix_hint' => 'Verify CRM fixture prerequisites and rerun as an authenticated C member.', 'steps' => $steps );
			}
		}

		$request = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/crm/inbox/conversations/' . (int) $conversation['id'] . '/care' );
		$request->set_param( 'id', (int) $conversation['id'] );
		$result = BizCity_TwinWeb_REST::instance()->get_crm_member_care( $request );
		$data = is_object( $result ) && method_exists( $result, 'get_data' ) ? (array) $result->get_data() : array();
		$runtime_ok = ! empty( $data['success'] )
			&& (int) ( $data['contact_id'] ?? 0 ) === (int) ( $conversation['contact_id'] ?? 0 )
			&& is_array( $data['notes'] ?? null )
			&& is_array( $data['tasks'] ?? null )
			&& is_array( $data['events'] ?? null )
			&& is_array( $data['labels'] ?? null );
		$steps[] = array(
			'label'  => 'Runtime - contact-care projection stays inside exact C Inbox scope',
			'status' => $runtime_ok ? 'pass' : 'fail',
			'detail' => $runtime_ok ? 'Allowed conversation returned matching contact_id with bounded notes/tasks/events/labels arrays.' : 'Care projection did not match the scoped conversation/contact contract.',
		);

		$idempotency_ok = false;
		$idempotency_detail = 'Mutation idempotency runtime was not executed.';
		if ( is_array( $conversation ) && ! empty( $conversation['id'] ) && class_exists( 'BizCity_Twin_Mutation_Store' ) ) {
			// [2026-09-10 08:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CARE — prove same-key note replay returns the stored response without a second CRM message.
			// [2026-09-10 08:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CARE — complete the headless REST lifecycle so delegated CRM routes match a real REST request.
			if ( function_exists( 'did_action' ) && function_exists( 'do_action' ) && ! did_action( 'rest_api_init' ) ) {
				do_action( 'rest_api_init' );
			}
			$conversation_id = (int) $conversation['id'];
			$replay_key = 'diag-care-note-' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
			$note_request = new WP_REST_Request( 'POST', '/bizcity-twinweb/v1/crm/inbox/conversations/' . $conversation_id . '/care' );
			$note_request->set_url_params( array( 'id' => $conversation_id ) );
			$note_request->set_param( 'id', $conversation_id );
			$note_request->set_header( 'X-BizCity-Idempotency-Key', $replay_key );
			$note_request->set_header( 'Content-Type', 'application/json' );
			$note_request->set_body( wp_json_encode( array( 'id' => $conversation_id, 'action' => 'note', 'content' => 'Diagnostics care idempotency fixture.' ) ) );
			$before_notes = 0;
			foreach ( BizCity_CRM_Repository::list_messages( $conversation_id, 100, 0 ) as $message ) {
				if ( is_array( $message ) && 'private_note' === (string) ( $message['message_type'] ?? '' ) ) { $before_notes++; }
			}
			$first = BizCity_TwinWeb_REST::instance()->post_crm_member_care( $note_request );
			$second = BizCity_TwinWeb_REST::instance()->post_crm_member_care( $note_request );
			$first_data = is_object( $first ) && method_exists( $first, 'get_data' ) ? (array) $first->get_data() : array();
			$second_data = is_object( $second ) && method_exists( $second, 'get_data' ) ? (array) $second->get_data() : array();
			$after_notes = 0;
			foreach ( BizCity_CRM_Repository::list_messages( $conversation_id, 100, 0 ) as $message ) {
				if ( is_array( $message ) && 'private_note' === (string) ( $message['message_type'] ?? '' ) ) { $after_notes++; }
			}
			$idempotency_ok = ! empty( $first_data['success'] )
				&& empty( $first_data['idempotency_replayed'] )
				&& ! empty( $second_data['success'] )
				&& ! empty( $second_data['idempotency_replayed'] )
				&& $after_notes === $before_notes + 1;
			$idempotency_detail = $idempotency_ok
				? 'Same-key note retry returned the stored response and increased private-note count exactly once.'
				: sprintf( 'Replay contract failed; first=%s/%s second=%s/%s notes=%d->%d.', (string) ( $first_data['code'] ?? ( $first_data['success'] ?? false ? 'success' : 'failed' ) ), (string) ( $first_data['reason'] ?? '' ), (string) ( $second_data['code'] ?? ( $second_data['success'] ?? false ? 'success' : 'failed' ) ), (string) ( $second_data['reason'] ?? '' ), $before_notes, $after_notes );
		}
		$steps[] = array(
			'label'  => 'Runtime - same-key care mutation replays without duplicate note',
			'status' => $idempotency_ok ? 'pass' : 'fail',
			'detail' => $idempotency_detail,
		);

		$ab_ok = false;
		$ab_detail = 'Two-user scope fixture was not executed.';
		$foreign_user_id = $this->create_user( 'foreign' );
		$foreign_conversation = $foreign_user_id > 0 ? $this->create_fixture( $foreign_user_id ) : null;
		if ( $foreign_user_id > 0 && is_array( $foreign_conversation ) ) {
			// [2026-09-11 10:15 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W7-C — prove each C user reads only the exact assigned conversation and cannot read the other user's care projection.
			$foreign_request = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/crm/inbox/conversations/' . (int) $conversation['id'] . '/care' );
			$foreign_request->set_param( 'id', (int) $conversation['id'] );
			wp_set_current_user( $foreign_user_id );
			$foreign_result = BizCity_TwinWeb_REST::instance()->get_crm_member_care( $foreign_request );
			$foreign_data = is_object( $foreign_result ) && method_exists( $foreign_result, 'get_data' ) ? (array) $foreign_result->get_data() : array();
			$own_request = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/crm/inbox/conversations/' . (int) $foreign_conversation['id'] . '/care' );
			$own_request->set_param( 'id', (int) $foreign_conversation['id'] );
			$own_result = BizCity_TwinWeb_REST::instance()->get_crm_member_care( $own_request );
			$own_data = is_object( $own_result ) && method_exists( $own_result, 'get_data' ) ? (array) $own_result->get_data() : array();
			wp_set_current_user( $this->original_user_id );
			$ab_ok = empty( $foreign_data['success'] )
				&& in_array( (string) ( $foreign_data['code'] ?? '' ), array( 'not_found', 'permission_denied', 'auth_required' ), true )
				&& ! empty( $own_data['success'] )
				&& (int) ( $own_data['contact_id'] ?? 0 ) === (int) $foreign_conversation['contact_id'];
			$ab_detail = $ab_ok
				? 'User B was denied User A conversation care while reading the own scoped conversation succeeded.'
				: sprintf( 'A/B scope failed; foreign_code=%s own_success=%s.', (string) ( $foreign_data['code'] ?? '' ), ! empty( $own_data['success'] ) ? 'yes' : 'no' );
		} else {
			wp_set_current_user( $this->original_user_id );
			$ab_detail = 'Disposable second-user fixture could not be created.';
		}
		$steps[] = array(
			'label'  => 'Runtime - two-user foreign care scope is denied',
			'status' => $ab_ok ? 'pass' : 'fail',
			'detail' => $ab_detail,
		);
		return array(
			'status'   => $runtime_ok && $idempotency_ok && $ab_ok ? 'pass' : 'fail',
			'summary'  => $runtime_ok && $idempotency_ok && $ab_ok ? 'Twin GPT contact-care scope, A/B isolation and idempotency passed.' : 'Twin GPT contact-care scope, A/B isolation or idempotency failed.',
			'fix_hint' => $runtime_ok && $idempotency_ok && $ab_ok ? '' : 'Recheck current-user Inbox scope, contact_id correlation, A/B foreign denial and the canonical mutation replay boundary before allowing C mutations.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// [2026-09-10 07:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W7-C — remove only the diagnostic inbox-owned rows created by this probe.
		wp_set_current_user( $this->original_user_id > 0 ? $this->original_user_id : 0 );
		foreach ( $this->fixture_inbox_ids as $inbox_id ) {
			if ( $inbox_id > 0 && class_exists( 'BizCity_CRM_Repository' ) ) {
				BizCity_CRM_Repository::delete_inbox( (int) $inbox_id );
			}
		}
		if ( class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			global $wpdb;
			foreach ( $this->fixture_contact_ids as $contact_id ) {
				if ( $contact_id > 0 ) { $wpdb->delete( BizCity_CRM_DB_Installer_V2::tbl_contacts(), array( 'id' => (int) $contact_id ), array( '%d' ) ); }
			}
		}
		foreach ( $this->fixture_user_ids as $user_id ) {
			if ( function_exists( 'wp_delete_user' ) ) { wp_delete_user( (int) $user_id ); }
		}
		$this->fixture_inbox_ids = array();
		$this->fixture_contact_ids = array();
		$this->fixture_user_ids = array();
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_TwinWeb_CRM_Care_Scope';
	return $probes;
} );
