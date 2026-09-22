<?php
/**
 * Read-only DDV probe for Twin GPT C-surface CRM Inbox parity with /crm/.
 *
 * The probe proves that the C message/document projection carries the same
 * safe fields the admin Inbox already shows: real inbound sender name, group
 * label, media attachments and the Zalo file inventory. It never creates or
 * mutates CRM rows, never calls a provider and never widens the C scope.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-17 (PHASE-0.48C-CRM-CONTEXT)
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
if ( class_exists( 'BizCity_Probe_TwinWeb_CRM_Inbox_Parity', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_CRM_Inbox_Parity implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.crm_inbox_parity'; }
	public function label(): string { return 'Twin GPT CRM Inbox content parity'; }
	public function description(): string { return 'Kiểm tra message/document projection của /gpt/crm/ mang đủ tên người gửi, tên nhóm, media và danh sách tệp Zalo giống /crm/.'; }
	public function severity(): string { return 'warning'; }
	public function order(): int { return 72; }
	public function icon(): string { return 'messages-square'; }
	public function estimate_ms(): int { return 200; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-09-17 11:40 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CRM-CONTEXT — prove the C projection no longer degrades inbound content to a placeholder.
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';

		$rest_path = $root . 'modules/twinweb/includes/class-twinweb-rest.php';
		$rest_source = is_readable( $rest_path ) ? (string) file_get_contents( $rest_path ) : '';
		$rest_markers = array( "'sender_name'", "'attachments'", "'reply_to'", 'resolve_mychannels_group_name', 'Tệp từ Zalo' );
		$missing_rest = array();
		foreach ( $rest_markers as $marker ) {
			if ( strpos( $rest_source, $marker ) === false ) { $missing_rest[] = $marker; }
		}
		$disk_ok = $rest_source !== '' && empty( $missing_rest );
		$steps[] = array(
			'layer'  => 'Disk',
			'label'  => 'C message/document projection declares the parity fields',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok
				? 'C message DTO exposes sender_name/attachments/reply_to and the document projection mirrors Zalo files.'
				: 'Missing projection markers: ' . implode( ', ', $missing_rest ),
		);
		if ( ! $disk_ok ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Twin GPT C CRM inbox parity projection is incomplete.',
				'fix_hint' => 'Restore the C message/document parity fields before shipping the Inbox UI change.',
				'steps'    => $steps,
			);
		}

		$frontend_path = $root . 'modules/twinweb/ui/src/pages/CrmInboxPage.tsx';
		$frontend_source = is_readable( $frontend_path ) ? (string) file_get_contents( $frontend_path ) : '';
		if ( $frontend_source === '' ) {
			$steps[] = array(
				'layer'  => 'Disk',
				'label'  => 'C inbox renderer source',
				'status' => 'skip',
				'detail' => 'Development source is absent; the built Twin GPT dist bundle remains authoritative on the deployed server.',
			);
		} else {
			$render_markers = array( 'messageSenderLabel( message, selected )', 'messageAttachments( message )', 'messageReplyPreview( message )' );
			$missing_render = array();
			foreach ( $render_markers as $marker ) {
				if ( strpos( $frontend_source, $marker ) === false ) { $missing_render[] = $marker; }
			}
			$steps[] = array(
				'layer'  => 'Disk',
				'label'  => 'C inbox renderer consumes the parity fields',
				'status' => empty( $missing_render ) ? 'pass' : 'fail',
				'detail' => empty( $missing_render )
					? 'Renderer resolves the per-message sender, media attachments and reply preview.'
					: 'Renderer does not consume: ' . implode( ', ', $missing_render ),
			);
		}

		$loaded = class_exists( 'BizCity_TwinWeb_REST', false )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_mychannels_zalo_personal_messages' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_crm_member_documents' )
			&& class_exists( 'BizCity_Zalo_Bridge_Client', false )
			&& method_exists( 'BizCity_Zalo_Bridge_Client', 'get_group_name' );
		$steps[] = array(
			'layer'  => 'Loader',
			'label'  => 'C parity route and bridge group-label reader are loaded',
			'status' => $loaded ? 'pass' : 'fail',
			'detail' => $loaded
				? 'Message/document projection callbacks plus the server-side group-label boundary are available.'
				: 'TwinWeb projection callback or the bridge group-label reader is unavailable.',
		);
		if ( ! $loaded ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Twin GPT C CRM inbox dependencies are incomplete.',
				'fix_hint' => 'Load TwinWeb REST and the Zalo bridge client with get_group_name() before the C Inbox reads.',
				'steps'    => $steps,
			);
		}

		$runtime_ok = $this->runtime_projection_step( $steps );

		$overall = $disk_ok && $loaded && $runtime_ok !== false;
		return array(
			'status'   => $overall ? 'pass' : 'fail',
			'summary'  => $overall
				? 'Twin GPT C CRM inbox projects the same safe content fields as /crm/.'
				: 'Twin GPT C CRM inbox parity projection has failures.',
			'fix_hint' => $overall ? '' : 'Check the C message/document projection shape and the bridge group-label boundary.',
			'steps'    => $steps,
		);
	}

	/**
	 * Prove the C message DTO shape on a synthetic row without touching the database.
	 *
	 * @param array $steps Step accumulator, passed by reference through array merge.
	 * @return bool|null True when the synthetic projection carries the parity fields, false when it does not, null when it cannot be exercised.
	 */
	private function runtime_projection_step( array &$steps ) {
		// [2026-09-17 11:40 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CRM-CONTEXT — the synthetic row never leaves this method and is never persisted.
		$reflection = new ReflectionClass( 'BizCity_TwinWeb_REST' );
		if ( ! $reflection->hasMethod( 'shape_mychannels_zalo_personal_message' ) ) {
			$steps[] = array(
				'layer'  => 'Runtime',
				'label'  => 'C message DTO carries group sender, media and reply preview',
				'status' => 'skip',
				'detail' => 'Synthetic projection helper is not reachable in this runtime.',
			);
			return null;
		}
		$method = $reflection->getMethod( 'shape_mychannels_zalo_personal_message' );
		$method->setAccessible( true );
		$synthetic = array(
			'id'               => 0,
			'conversation_id'  => 0,
			'content'          => 'probe',
			'content_type'     => 'image',
			'message_type'     => 'incoming',
			'sender_type'      => 'contact',
			'status'           => 'sent',
			'created_at'       => '1970-01-01 00:00:00',
			'ai_metadata_json' => wp_json_encode( array(
				'thread_kind' => 'group',
				'sender_name' => 'Thành viên thử',
				'reply_to'    => array( 'sender_name' => 'Bạn', 'content' => 'probe' ),
			) ),
			'attachments'      => array(
				array( 'id' => 0, 'file_type' => 'image', 'data_url' => 'https://example.invalid/probe.png', 'thumb_url' => null, 'meta_json' => '' ),
			),
		);
		$shaped = $method->invoke( BizCity_TwinWeb_REST::instance(), $synthetic );
		$runtime_ok = is_array( $shaped )
			&& (string) ( $shaped['thread_kind'] ?? '' ) === 'group'
			&& (string) ( $shaped['sender_name'] ?? '' ) === 'Thành viên thử'
			&& is_array( $shaped['attachments'] ?? null )
			&& count( $shaped['attachments'] ) === 1
			&& (string) ( $shaped['attachments'][0]['url'] ?? '' ) === 'https://example.invalid/probe.png'
			&& is_array( $shaped['reply_to'] ?? null )
			&& (string) ( $shaped['reply_to']['sender_name'] ?? '' ) === 'Bạn'
			&& ! isset( $shaped['ai_metadata_json'] )
			&& ! isset( $shaped['attachments'][0]['data_url'] );
		$steps[] = array(
			'layer'  => 'Runtime',
			'label'  => 'C message DTO carries group sender, media and reply preview',
			'status' => $runtime_ok ? 'pass' : 'fail',
			'detail' => $runtime_ok
				? 'Synthetic row projects sender_name, one media attachment and a reply preview with no provider column leak.'
				: 'Synthetic C message projection is missing a parity field or leaked a storage column.',
		);
		return $runtime_ok;
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_TwinWeb_CRM_Inbox_Parity';
	return $probes;
} );
