<?php
/**
 * DDV probe for the WebChat session working-brief filestore owner.
 *
 * The probe writes one disposable encrypted business record and removes it in
 * cleanup(). It does not create or update any SQL session row.
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

if ( class_exists( 'BizCity_Probe_WebChat_Session_Memory_Spec_Filestore', false ) ) {
	return;
}

final class BizCity_Probe_WebChat_Session_Memory_Spec_Filestore implements BizCity_Diagnostics_Probe {

	const CONTRACT = 'modules.webchat.session_memory_spec';

	/** @var string */
	private $record_id = '';

	public function id(): string {
		return 'core.webchat.session_memory_spec_filestore';
	}

	public function label(): string {
		return 'WebChat session spec filestore parity';
	}

	public function description(): string {
		return 'Checks that BizCity_Session_Memory_Spec reads and writes encrypted filestore records with tenant and platform isolation.';
	}

	public function severity(): string {
		return 'blocking';
	}

	public function order(): int {
		return 80;
	}

	public function icon(): string {
		return 'FileText';
	}

	public function estimate_ms(): int {
		return 180;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_Session_Memory_Spec' ) ) {
			return new WP_Error( 'session_spec_owner_missing', 'BizCity_Session_Memory_Spec is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_File_Contract_Registry' ) || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
			return new WP_Error( 'filestore_classes_missing', 'Filestore contract/store classes are not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-SPEC-FILESTORE — verify the session working brief through its owner API and disposable filestore record.
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

		$contract_ok = BizCity_File_Contract_Registry::has( self::CONTRACT );
		$add_step(
			'Disk/Loader - session spec filestore contract',
			$contract_ok,
			$contract_ok ? self::CONTRACT . ' is registered.' : self::CONTRACT . ' is missing.'
		);
		if ( ! $contract_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Session spec filestore contract is missing.', 'steps' => $steps );
		}

		$class_file = defined( 'BIZCITY_TWIN_AI_DIR' )
			? BIZCITY_TWIN_AI_DIR . 'modules/webchat/includes/class-session-memory-spec.php'
			: '';
		$source = $class_file && is_readable( $class_file ) ? (string) file_get_contents( $class_file ) : '';
		$disk_ok = $source !== ''
			&& false !== strpos( $source, 'BizCity_Business_JSONL_File_Store::write_with_receipt' )
			&& false !== strpos( $source, 'BizCity_Business_JSONL_File_Store::find' )
			&& false === strpos( $source, 'get_session_v3_by_session_id' )
			&& false === strpos( $source, 'update_session_v3' );
		$add_step(
			'Disk - session spec has no SQL owner calls',
			$disk_ok,
			$disk_ok ? 'Session spec source uses the encrypted filestore API and contains no session SQL owner call.' : 'Session spec source still contains a SQL session owner call or lacks filestore calls.'
		);

		$session_id = 'diag_session_spec_' . wp_generate_uuid4();
		$spec = BizCity_Session_Memory_Spec::blank( 'pipeline' );
		$spec['current_topic'] = 'diagnostic session spec';
		$spec['recent_facts'] = array( 'filestore parity' );
		$write_ok = BizCity_Session_Memory_Spec::persist( $session_id, $spec, 'WEBCHAT' );
		$reflection = new ReflectionMethod( 'BizCity_Session_Memory_Spec', 'filestore_record_id' );
		$reflection->setAccessible( true );
		$this->record_id = (string) $reflection->invoke( null, $session_id, 'WEBCHAT' );
		BizCity_Session_Memory_Spec::reset();
		$read = BizCity_Session_Memory_Spec::get( $session_id, 'WEBCHAT' );
		$raw = BizCity_Business_JSONL_File_Store::find( self::CONTRACT, $this->record_id, array( 'blog_id' => get_current_blog_id() ) );
		$raw_ok = is_array( $raw ) && ( $raw['record_kind'] ?? '' ) === 'session_memory_spec';
		$read_ok = is_array( $read ) && ( $read['current_topic'] ?? '' ) === 'diagnostic session spec';
		$add_step(
			'Runtime - persist/read filestore working brief',
			$write_ok && $raw_ok && $read_ok,
			$write_ok && $raw_ok && $read_ok ? 'Working brief persisted and read back through the canonical filestore owner.' : 'Working brief write, raw contract record, or owner read-back failed.'
		);

		BizCity_Session_Memory_Spec::reset();
		$other_platform = BizCity_Session_Memory_Spec::get( $session_id, 'ADMINCHAT' );
		$isolation_ok = null === $other_platform;
		$add_step(
			'Runtime - platform/session isolation',
			$isolation_ok,
			$isolation_ok ? 'WEBCHAT and ADMINCHAT use distinct tenant/platform/session records.' : 'A session spec crossed the platform boundary.'
		);

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'Session memory spec is filestore-owned with platform isolation.' : 'Session memory spec filestore parity failed.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		if ( $this->record_id !== '' && class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
			BizCity_Business_JSONL_File_Store::delete( self::CONTRACT, $this->record_id, array( 'blog_id' => get_current_blog_id() ) );
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_WebChat_Session_Memory_Spec_Filestore';
	return $list;
} );
