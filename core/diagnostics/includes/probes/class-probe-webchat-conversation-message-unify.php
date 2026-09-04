<?php
/**
 * DDV probe for the message-owned WebChat conversation compatibility adapter.
 *
 * The probe uses disposable message rows and removes them in cleanup().
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_WebChat_Conversation_Message_Unify', false ) ) {
	return;
}

final class BizCity_Probe_WebChat_Conversation_Message_Unify implements BizCity_Diagnostics_Probe {

	/** @var array */
	private $fixture_ids = array();

	public function id(): string {
		return 'core.webchat.conversation_message_unify';
	}

	public function label(): string {
		return 'WebChat conversation/message unification';
	}

	public function description(): string {
		return 'Checks that WebChat conversation compatibility APIs are backed by webchat_messages and preserve platform/session isolation.';
	}

	public function severity(): string {
		return 'blocking';
	}

	public function order(): int {
		return 79;
	}

	public function icon(): string {
		return 'MessageSquare';
	}

	public function estimate_ms(): int {
		return 160;
	}

	public function precondition() {
		return class_exists( 'BizCity_WebChat_Database' )
			? true
			: new WP_Error( 'webchat_database_missing', 'BizCity_WebChat_Database is not loaded.' );
	}

	public function run( $ctx ): array {
		// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — exercise the marker-backed compatibility contract with disposable rows.
		$db = BizCity_WebChat_Database::instance();
		$steps = array();
		$pass = true;
		$add_step = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array(
				'label'  => $label,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $detail,
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $ok ) {
				$pass = false;
			}
		};

		$session_id = 'diag_unify_' . wp_generate_uuid4();
		$admin_session_id = $session_id;
		$marker_id = $db->get_or_create_conversation( $session_id, array(
			'user_id'       => get_current_user_id(),
			'client_name'   => 'diagnostics',
			'platform_type' => 'WEBCHAT',
			'title'         => 'Diagnostic conversation',
		) );
		$message_id = $db->log_message( array(
			'session_id'   => $session_id,
			'user_id'      => get_current_user_id(),
			'platform_type'=> 'WEBCHAT',
			'message_text' => 'diagnostic message',
			'message_from' => 'user',
			'message_type' => 'text',
		) );
		$webchat = $db->get_conversation_by_session( $session_id );
		$history = $db->get_conversation_history( $session_id, 20, 0, 'WEBCHAT' );
		$messages = $marker_id > 0 ? $db->get_messages_by_conversation_id( $marker_id, 20 ) : array();
		$basic_ok = $marker_id > 0 && $message_id !== false && $webchat && (int) $webchat->id === (int) $marker_id
			&& count( $history ) === 1 && count( $messages ) === 1 && (int) $webchat->message_count === 1;
		$this->fixture_ids[] = $marker_id;
		$add_step(
			'Runtime - create and read message-owned conversation',
			$basic_ok,
			$basic_ok ? 'Conversation metadata and one normal message are served from webchat_messages.' : 'Marker, message, history, or compatibility view did not match the message-owned contract.'
		);

		$title_ok = $marker_id > 0 && $db->update_session_title( $marker_id, 'Renamed diagnostic conversation' )
			&& $db->get_conversation_by_session( $session_id )->title === 'Renamed diagnostic conversation';
		$close_ok = $marker_id > 0 && $db->close_session( $marker_id )
			&& $db->get_conversation_by_session( $session_id )->status === 'closed';
		$reopened_id = $db->get_or_create_conversation( $session_id, array( 'platform_type' => 'WEBCHAT' ) );
		$reopen_ok = $reopened_id === $marker_id && $db->get_conversation_by_session( $session_id )->status === 'active';
		$add_step(
			'Runtime - title, close, and reopen use marker metadata',
			$title_ok && $close_ok && $reopen_ok,
			$title_ok && $close_ok && $reopen_ok ? 'Title, close, and reopen operations update the marker in webchat_messages.' : 'Title, close, or reopen operation did not update message-owned metadata.'
		);

		$admin_marker_id = $db->get_or_create_conversation( $admin_session_id, array(
			'user_id'       => get_current_user_id(),
			'client_name'   => 'diagnostics',
			'platform_type' => 'ADMINCHAT',
		) );
		$admin_message_id = $db->log_message( array(
			'session_id'   => $admin_session_id,
			'user_id'      => get_current_user_id(),
			'platform_type'=> 'ADMINCHAT',
			'message_text' => 'diagnostic admin message',
			'message_from' => 'user',
			'message_type' => 'text',
		) );
		$this->fixture_ids[] = $admin_marker_id;
		$groups = $db->get_conversations( 'all', 100, 0 );
		$scoped = array();
		foreach ( $groups as $group ) {
			if ( $group->session_id === $session_id ) {
				$scoped[] = $group;
			}
		}
		$platforms = array();
		foreach ( $scoped as $group ) {
			$platforms[] = $group->platform_type;
		}
		sort( $platforms );
		$isolation_ok = $admin_marker_id > 0 && $admin_message_id !== false && count( $scoped ) === 2
			&& $platforms === array( 'ADMINCHAT', 'WEBCHAT' );
		$add_step(
			'Runtime - platform/session isolation',
			$isolation_ok,
			$isolation_ok ? 'The same session identifier remains isolated by platform_type in the message-owned view.' : 'Platform-specific message groups were merged or not exposed independently.'
		);

		$disk_ok = true;
		$class_file = defined( 'BIZCITY_TWIN_AI_DIR' )
			? BIZCITY_TWIN_AI_DIR . 'modules/webchat/includes/class-webchat-database.php'
			: '';
		if ( $class_file && is_readable( $class_file ) ) {
			$source = (string) file_get_contents( $class_file );
			$disk_ok = false === strpos( $source, '$wpdb->prefix . \'bizcity_webchat_conversations\'' );
		}
		$add_step(
			'Disk - conversation table writer removed',
			$disk_ok,
			$disk_ok ? 'The active WebChat database owner has no conversation-table writer expression.' : 'The active WebChat database owner still contains a conversation-table writer expression.'
		);

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'WebChat conversations are message-owned with platform isolation.' : 'WebChat conversation/message unification failed.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
			return;
		}
		$db = BizCity_WebChat_Database::instance();
		foreach ( $this->fixture_ids as $marker_id ) {
			if ( (int) $marker_id > 0 ) {
				$db->delete_session( $marker_id );
			}
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_WebChat_Conversation_Message_Unify';
	return $list;
} );
