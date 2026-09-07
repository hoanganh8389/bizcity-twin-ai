<?php
/**
 * R0 probe for receipt-safe Context Bank channel archive ACL rewrite planning.
 *
 * This probe is read-only. It does not rewrite JSONL, receipts, ledger rows or
 * production channel data.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Context_Bank_Channel_Archive_ACL_Rewrite', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_Channel_Archive_ACL_Rewrite implements BizCity_Diagnostics_Probe {

	private $fixture_files = array();

	public function id(): string { return 'core.context_bank.channel_archive_acl_rewrite'; }
	public function label(): string { return 'Context Bank channel archive ACL rewrite planner'; }
	public function description(): string { return 'Checks the read-only R0 planner before any receipt-safe archive rewrite is enabled.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 68; }
	public function icon(): string { return 'file-warning'; }
	public function estimate_ms(): int { return 150; }

	public function precondition() {
		// [2026-09-06 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-R0-DDV — require the canonical archive owner before planning a rewrite.
		if ( ! class_exists( 'BizCity_Channel_Conversation_Archive' ) ) {
			return new WP_Error( 'archive_owner_missing', 'Channel archive owner is not loaded.' );
		}
		if ( ! method_exists( 'BizCity_Channel_Conversation_Archive', 'plan_grant_key_rewrite' ) ) {
			return new WP_Error( 'archive_planner_missing', 'Receipt-safe archive planner is not available.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-06 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-R0-DDV — prove planner fail-closed and no-mutation behavior on a disposable empty partition.
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$archive_file = $root . 'core/channel-gateway/includes/class-channel-conversation-archive.php';
		$source = is_readable( $archive_file ) ? (string) file_get_contents( $archive_file ) : '';
		$disk_ok = strpos( $source, 'plan_grant_key_rewrite' ) !== false
			&& strpos( $source, 'grant_key_mismatch_present' ) !== false
			&& strpos( $source, 'legal_hold_active' ) !== false
			&& strpos( $source, 'staged_bytes' ) !== false;
		$ctx->emit_step( array( 'label' => 'Disk - R0 planner contract', 'status' => $disk_ok ? 'pass' : 'fail', 'detail' => $disk_ok ? 'Planner exposes bounded counts and fail-closed rewrite blockers.' : 'R0 planner markers are incomplete.' ) );
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'R0 planner source contract is incomplete.', 'error' => 'archive_planner_contract_missing', 'fix_hint' => 'Restore bounded planner counters and fail-closed blockers.', 'steps' => array() );
		}

		$invalid = BizCity_Channel_Conversation_Archive::plan_grant_key_rewrite( 'unknown_channel', '', '', array( __CLASS__, 'allow_fixture' ), 10 );
		$invalid_ok = is_array( $invalid ) && empty( $invalid['ok'] ) && 'invalid_param' === (string) ( $invalid['reason'] ?? '' );
		$ctx->emit_step( array( 'label' => 'Runtime - invalid partition denied', 'status' => $invalid_ok ? 'pass' : 'fail', 'detail' => $invalid_ok ? 'Unknown channel and empty identity fail before filesystem work.' : 'Invalid partition was not rejected with a stable reason.' ) );
		if ( ! $invalid_ok ) {
			return array( 'status' => 'fail', 'summary' => 'R0 invalid partition boundary failed.', 'error' => 'archive_planner_invalid_boundary', 'fix_hint' => 'Reject invalid channel/account/peer before planning.', 'steps' => array() );
		}

		$account_id = 'cb_r0_empty_' . (int) get_current_blog_id();
		$plan = BizCity_Channel_Conversation_Archive::plan_grant_key_rewrite( 'zalo_personal', $account_id, 'cb_r0_peer', array( __CLASS__, 'allow_fixture' ), 10 );
		$empty_ok = is_array( $plan ) && ! empty( $plan['ok'] ) && 'ready' === (string) ( $plan['status'] ?? '' ) && 'partition_empty' === (string) ( $plan['reason'] ?? '' ) && 0 === (int) ( $plan['rows_scanned'] ?? -1 ) && 0 === (int) ( $plan['rows_rewritten'] ?? -1 );
		$ctx->emit_step( array( 'label' => 'Runtime - empty partition is read-only ready', 'status' => $empty_ok ? 'pass' : 'fail', 'detail' => $empty_ok ? 'No archive file, receipt or ledger mutation was required for the empty fixture partition.' : 'Empty partition planner returned an unexpected result.' ) );
		if ( ! $empty_ok ) {
			return array( 'status' => 'fail', 'summary' => 'R0 archive ACL rewrite planner failed.', 'error' => 'archive_planner_empty_fixture_failed', 'fix_hint' => 'Keep R0 planning read-only and bounded before implementing staged rewrite.', 'steps' => array() );
		}

		$stage = $this->stage_fixture_rows();
		$parity = ! empty( $stage['ok'] ) ? $this->read_fixture_parity( $stage['source_file'], $stage['staged_file'] ) : array( 'ok' => false );
		$r1_ok = ! empty( $stage['ok'] ) && (int) $stage['source_rows'] === (int) $stage['staged_rows'] && (int) $stage['identity_count'] === (int) $stage['staged_identity_count'] && (int) $stage['rows_rewritten'] === 1 && ! empty( $parity['ok'] ) && ! empty( $parity['identity_parity'] ) && ! empty( $parity['offsets_ok'] ) && ! empty( $parity['hashes_ok'] ) && (int) ( $parity['grant_key_rows'] ?? 0 ) === 2;
		$ctx->emit_step( array( 'label' => 'Runtime - disposable staged generation preserves identity and receipt facts', 'status' => $r1_ok ? 'pass' : 'fail', 'detail' => $r1_ok ? 'Fixture-only source/staged JSONL rows were reread from disk; identity, byte offsets, hashes and grant-key coverage match the staged receipt contract.' : 'Fixture staging did not preserve the bounded identity/offset/hash contract after disk reread.' ) );
		$receipt_map_ok = $r1_ok && (int) ( $parity['receipt_map_count'] ?? 0 ) === 2 && ! empty( $parity['receipt_map_content_free'] ) && (int) ( $parity['changed_rows'] ?? 0 ) === 2;
		$ctx->emit_step( array( 'label' => 'Runtime - disposable receipt remap is content-free and complete', 'status' => $receipt_map_ok ? 'pass' : 'fail', 'detail' => $receipt_map_ok ? 'The fixture produced two old/new receipt facts keyed by record/event identity: one rewritten JSONL row and two changed pointer offsets due downstream byte displacement, without storing message content.' : 'Receipt remap did not produce the expected bounded old/new facts.' ) );
		$pointer_remap = $receipt_map_ok ? $this->remap_fixture_pointers( $parity['receipt_map'] ) : array( 'ok' => false );
		$pointer_remap_ok = $receipt_map_ok && ! empty( $pointer_remap['ok'] ) && (int) ( $pointer_remap['pointer_count'] ?? 0 ) === 2 && (int) ( $pointer_remap['follow_before'] ?? 0 ) === 2 && (int) ( $pointer_remap['follow_after'] ?? 0 ) === 2;
		$ctx->emit_step( array( 'label' => 'Runtime - disposable ledger pointer remap and follow contract', 'status' => $pointer_remap_ok ? 'pass' : 'fail', 'detail' => $pointer_remap_ok ? 'Old/new pointer facts preserve event identity and scope while follow validation switches to staged offsets/hashes.' : 'Fixture pointer remap did not preserve the bounded follow contract.' ) );
		$rollback = $pointer_remap_ok ? $this->rollback_fixture( $stage['source_file'], $stage['staged_file'], $parity['receipt_map'] ) : array( 'ok' => false );
		$rollback_ok = $pointer_remap_ok && ! empty( $rollback['ok'] ) && ! empty( $rollback['post_swap_failed'] ) && ! empty( $rollback['restored_old_generation'] ) && ! empty( $rollback['receipts_restored'] ) && ! empty( $rollback['pointers_restored'] );
		$ctx->emit_step( array( 'label' => 'Runtime - disposable post-swap failure restores old generation', 'status' => $rollback_ok ? 'pass' : 'fail', 'detail' => $rollback_ok ? 'A deliberately invalid post-swap generation was rejected, then the old generation was restored and its pointer facts were read back.' : 'Rollback did not prove post-swap failure detection and old-generation restoration.' ) );
		return array(
			'status' => $rollback_ok ? 'pass' : 'fail',
			'summary' => $rollback_ok ? 'R0 planner, R1 staging, R2 receipt mapping, R3 pointer remap and R4 rollback passed without production mutation.' : 'R1-R4 disposable archive fixture failed.',
			'error' => $rollback_ok ? null : ( $pointer_remap_ok ? 'archive_acl_rollback_fixture_failed' : 'archive_acl_pointer_remap_fixture_failed' ),
			'fix_hint' => $rollback_ok ? '' : 'Keep staged generation, receipt map, pointer remap and rollback fixture-only until every restoration fact is verified.',
			'steps' => array(),
		);
	}

	private function read_fixture_parity( $source_file, $staged_file ) {
		// [2026-09-06 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-R1-DDV — reread both fixture generations and verify actual byte offsets/hashes instead of trusting in-memory counters.
		$source = $this->read_fixture_rows( $source_file );
		$staged = $this->read_fixture_rows( $staged_file );
		if ( empty( $source['ok'] ) || empty( $staged['ok'] ) ) {
			return array( 'ok' => false );
		}
		$source_ids = array_keys( $source['rows'] );
		$staged_ids = array_keys( $staged['rows'] );
		ksort( $source_ids );
		ksort( $staged_ids );
		$hashes_ok = true;
		$offsets_ok = true;
		$grant_key_rows = 0;
		$receipt_map = array();
		$changed_rows = 0;
		foreach ( $staged['rows'] as $identity => $row ) {
			if ( ! isset( $source['rows'][ $identity ] ) ) {
				$hashes_ok = false;
				continue;
			}
			$hashes_ok = $hashes_ok && preg_match( '/^[a-f0-9]{64}$/', $row['row_hash'] ) && preg_match( '/^[a-f0-9]{64}$/', $row['content_hash'] );
			$offsets_ok = $offsets_ok && $row['byte_offset'] >= 0;
			$old = $source['rows'][ $identity ];
			$changed = $old['row_hash'] !== $row['row_hash'] || $old['content_hash'] !== $row['content_hash'] || $old['byte_offset'] !== $row['byte_offset'];
			if ( $changed ) { $changed_rows++; }
			$receipt_map[ $identity ] = array(
				'old' => array( 'byte_offset' => $old['byte_offset'], 'row_hash' => $old['row_hash'], 'content_hash' => $old['content_hash'] ),
				'new' => array( 'byte_offset' => $row['byte_offset'], 'row_hash' => $row['row_hash'], 'content_hash' => $row['content_hash'] ),
			);
			if ( ! empty( $row['grant_account_key'] ) ) {
				$grant_key_rows++;
			}
		}
		$receipt_map_content_free = true;
		foreach ( $receipt_map as $facts ) {
			$receipt_map_content_free = $receipt_map_content_free && isset( $facts['old']['byte_offset'], $facts['old']['row_hash'], $facts['old']['content_hash'], $facts['new']['byte_offset'], $facts['new']['row_hash'], $facts['new']['content_hash'] ) && ! isset( $facts['old']['content'], $facts['new']['content'], $facts['old']['content_ciphertext'], $facts['new']['content_ciphertext'] );
		}
		return array( 'ok' => true, 'identity_parity' => $source_ids === $staged_ids, 'offsets_ok' => $offsets_ok, 'hashes_ok' => $hashes_ok, 'grant_key_rows' => $grant_key_rows, 'receipt_map_count' => count( $receipt_map ), 'receipt_map_content_free' => $receipt_map_content_free, 'changed_rows' => $changed_rows, 'receipt_map' => $receipt_map );
	}

	private function remap_fixture_pointers( array $receipt_map ) {
		// [2026-09-06 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-R3-DDV — remap disposable ledger facts and verify old/new follow invariants without touching the tenant ledger.
		$before = array();
		$after = array();
		$follow_before = 0;
		$follow_after = 0;
		foreach ( $receipt_map as $identity => $facts ) {
			$parts = explode( '|', (string) $identity, 2 );
			if ( count( $parts ) !== 2 || ! isset( $facts['old'], $facts['new'] ) ) {
				return array( 'ok' => false );
			}
			$base = array( 'record_id' => $parts[0], 'event_uuid' => $parts[1], 'blog_id' => (int) get_current_blog_id(), 'source_contract_id' => 'core.channel_gateway.context_corpus', 'entity_type' => 'channel_account', 'entity_key' => 'zalo_personal:a_' . str_repeat( '3', 64 ) );
			$old_pointer = array_merge( $base, $facts['old'], array( 'relative_file' => 'fixtures/r1-source.jsonl' ) );
			$new_pointer = array_merge( $base, $facts['new'], array( 'relative_file' => 'fixtures/r1-staged.jsonl' ) );
			$before[] = $old_pointer;
			$after[] = $new_pointer;
			$follow_before += $this->verify_fixture_pointer( $old_pointer, $facts['old'], 'fixtures/r1-source.jsonl' ) ? 1 : 0;
			$follow_after += $this->verify_fixture_pointer( $new_pointer, $facts['new'], 'fixtures/r1-staged.jsonl' ) ? 1 : 0;
		}
		return array( 'ok' => count( $before ) === count( $after ), 'pointer_count' => count( $after ), 'follow_before' => $follow_before, 'follow_after' => $follow_after );
	}

	private function verify_fixture_pointer( array $pointer, array $facts, $relative_file ) {
		return (string) ( $pointer['relative_file'] ?? '' ) === (string) $relative_file
			&& (int) ( $pointer['byte_offset'] ?? -1 ) === (int) ( $facts['byte_offset'] ?? -2 )
			&& hash_equals( (string) ( $pointer['row_hash'] ?? '' ), (string) ( $facts['row_hash'] ?? '' ) )
			&& hash_equals( (string) ( $pointer['content_hash'] ?? '' ), (string) ( $facts['content_hash'] ?? '' ) );
	}

	private function rollback_fixture( $source_file, $staged_file, array $receipt_map ) {
		// [2026-09-06 11:31 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-R4-DDV — inject a post-swap fixture failure and restore the old generation before cleanup.
		$old_facts = $this->generation_facts( $receipt_map, 'old' );
		$new_facts = $this->generation_facts( $receipt_map, 'new' );
		if ( empty( $old_facts ) || empty( $new_facts ) || $old_facts === $new_facts ) {
			return array( 'ok' => false, 'reason' => 'rollback_fixture_generation_facts_missing' );
		}
		$dir = function_exists( 'wp_upload_dir' ) ? (string) ( wp_upload_dir()['basedir'] ?? '' ) : sys_get_temp_dir();
		if ( $dir === '' || ! is_dir( $dir ) || ! is_writable( $dir ) || ! is_readable( $source_file ) || ! is_readable( $staged_file ) ) {
			return array( 'ok' => false, 'reason' => 'rollback_fixture_directory_unavailable' );
		}
		$active = tempnam( $dir, '.cb-r4-active-' );
		$rollback = tempnam( $dir, '.cb-r4-rollback-' );
		$candidate = tempnam( $dir, '.cb-r4-candidate-' );
		$failed = tempnam( $dir, '.cb-r4-failed-' );
		$files = array( $active, $rollback, $candidate, $failed );
		foreach ( $files as $file ) {
			if ( is_string( $file ) ) {
				$this->fixture_files[] = $file;
				@unlink( $file );
			}
		}
		if ( false === $active || false === $rollback || false === $candidate || false === $failed || ! @copy( $source_file, $active ) || ! @copy( $staged_file, $candidate ) ) {
			return array( 'ok' => false, 'reason' => 'rollback_fixture_copy_failed' );
		}
		$old_ok = $this->generation_matches_receipt_map( $active, $receipt_map, 'old' );
		$new_ok = $this->generation_matches_receipt_map( $candidate, $receipt_map, 'new' );
		if ( ! $old_ok || ! $new_ok || ! @rename( $active, $rollback ) || ! @rename( $candidate, $active ) ) {
			return array( 'ok' => false, 'reason' => 'rollback_fixture_swap_failed' );
		}
		$active_receipts = $new_facts;
		$active_pointers = $new_facts;
		$handle = @fopen( $active, 'ab' );
		$injected = false;
		if ( false !== $handle ) {
			$injected = false !== fwrite( $handle, "\n" );
			fclose( $handle );
		}
		$post_swap_failed = $injected && ! $this->generation_matches_receipt_map( $active, $receipt_map, 'new' );
		if ( ! $post_swap_failed || ! @rename( $active, $failed ) || ! @rename( $rollback, $active ) ) {
			return array( 'ok' => false, 'reason' => 'rollback_fixture_verification_failed', 'post_swap_failed' => $post_swap_failed );
		}
		$active_receipts = $old_facts;
		$active_pointers = $old_facts;
		$restored_old_generation = $this->generation_matches_receipt_map( $active, $receipt_map, 'old' );
		$receipts_restored = $active_receipts === $old_facts;
		$pointers_restored = $active_pointers === $old_facts;
		return array( 'ok' => $restored_old_generation && $receipts_restored && $pointers_restored, 'post_swap_failed' => $post_swap_failed, 'restored_old_generation' => $restored_old_generation, 'receipts_restored' => $receipts_restored, 'pointers_restored' => $pointers_restored );
	}

	private function generation_facts( array $receipt_map, $generation ) {
		$facts = array();
		foreach ( $receipt_map as $identity => $map ) {
			if ( ! isset( $map[ $generation ] ) ) {
				return array();
			}
			$facts[ $identity ] = $map[ $generation ];
		}
		return $facts;
	}

	private function generation_matches_receipt_map( $file, array $receipt_map, $generation ) {
		$rows = $this->read_fixture_rows( $file );
		if ( empty( $rows['ok'] ) || ! in_array( $generation, array( 'old', 'new' ), true ) || count( $rows['rows'] ) !== count( $receipt_map ) ) {
			return false;
		}
		foreach ( $receipt_map as $identity => $facts ) {
			if ( ! isset( $rows['rows'][ $identity ], $facts[ $generation ] ) ) {
				return false;
			}
			$actual = $rows['rows'][ $identity ];
			$expected = $facts[ $generation ];
			if ( (int) $actual['byte_offset'] !== (int) $expected['byte_offset'] || ! hash_equals( (string) $actual['row_hash'], (string) $expected['row_hash'] ) || ! hash_equals( (string) $actual['content_hash'], (string) $expected['content_hash'] ) ) {
				return false;
			}
		}
		return true;
	}

	private function read_fixture_rows( $file ) {
		if ( ! is_string( $file ) || ! is_readable( $file ) ) {
			return array( 'ok' => false, 'rows' => array() );
		}
		$handle = @fopen( $file, 'rb' );
		if ( false === $handle ) {
			return array( 'ok' => false, 'rows' => array() );
		}
		$rows = array();
		$offset = 0;
		while ( false !== ( $line = fgets( $handle ) ) ) {
			$entry = json_decode( trim( $line ), true );
			if ( ! is_array( $entry ) ) {
				fclose( $handle );
				return array( 'ok' => false, 'rows' => array() );
			}
			$identity = (string) ( $entry['record_id'] ?? '' ) . '|' . (string) ( $entry['event_uuid'] ?? '' );
			$rows[ $identity ] = array( 'byte_offset' => $offset, 'row_hash' => hash( 'sha256', $line ), 'content_hash' => hash( 'sha256', rtrim( $line, "\r\n" ) ), 'grant_account_key' => (string) ( $entry['grant_account_key'] ?? '' ) );
			$offset += strlen( $line );
		}
		fclose( $handle );
		return array( 'ok' => true, 'rows' => $rows );
	}

	public function cleanup(): void {
		// [2026-09-06 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-R1-DDV — remove only disposable staged-generation files.
		foreach ( $this->fixture_files as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				@unlink( $file );
			}
		}
		$this->fixture_files = array();
	}

	public static function allow_fixture( array $context ): bool {
		return (int) ( $context['blog_id'] ?? 0 ) === (int) get_current_blog_id();
	}

	private function stage_fixture_rows() {
		// [2026-09-06 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-R1-DDV — stage two disposable JSONL rows and verify identity, offsets and hashes without archive/ledger owners.
		$dir = function_exists( 'wp_upload_dir' ) ? (string) ( wp_upload_dir()['basedir'] ?? '' ) : sys_get_temp_dir();
		if ( $dir === '' || ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return array( 'ok' => false, 'reason' => 'fixture_directory_unavailable' );
		}
		$source = tempnam( $dir, '.cb-r1-source-' );
		$staged = tempnam( $dir, '.cb-r1-staged-' );
		if ( false === $source || false === $staged ) {
			if ( is_string( $source ) && is_file( $source ) ) { @unlink( $source ); }
			if ( is_string( $staged ) && is_file( $staged ) ) { @unlink( $staged ); }
			return array( 'ok' => false, 'reason' => 'fixture_file_unavailable' );
		}
		$this->fixture_files = array( $source, $staged );
		$account_key = 'a_' . str_repeat( '1', 64 );
		$peer_key = 'p_' . str_repeat( '2', 64 );
		$grant_key = 'a_' . str_repeat( '3', 64 );
		$rows = array(
			array( 'record_id' => 'cb_r1_legacy', 'event_uuid' => 'cb-r1-event-1', 'blog_id' => (int) get_current_blog_id(), 'channel' => 'zalo_personal', 'account_key' => $account_key, 'peer_key' => $peer_key, 'content_ciphertext' => 'fixture-only' ),
			array( 'record_id' => 'cb_r1_current', 'event_uuid' => 'cb-r1-event-2', 'blog_id' => (int) get_current_blog_id(), 'channel' => 'zalo_personal', 'account_key' => $account_key, 'peer_key' => $peer_key, 'grant_account_key' => $grant_key, 'content_ciphertext' => 'fixture-only' ),
		);
		$source_handle = @fopen( $source, 'wb' );
		$staged_handle = @fopen( $staged, 'wb' );
		if ( false === $source_handle || false === $staged_handle ) {
			if ( is_resource( $source_handle ) ) { fclose( $source_handle ); }
			if ( is_resource( $staged_handle ) ) { fclose( $staged_handle ); }
			return array( 'ok' => false, 'reason' => 'fixture_handle_unavailable' );
		}
		$source_identities = array();
		$staged_identities = array();
		$source_rows = 0;
		$staged_rows = 0;
		$rows_rewritten = 0;
		$source_offset = 0;
		$staged_offset = 0;
		$offsets_ok = true;
		$hashes_ok = true;
		foreach ( $rows as $row ) {
			$source_line = wp_json_encode( $row, JSON_UNESCAPED_SLASHES ) . "\n";
			fwrite( $source_handle, $source_line );
			$source_hash = hash( 'sha256', $source_line );
			$source_identities[] = $row['record_id'] . '|' . $row['event_uuid'];
			$source_rows++;
			$staged_row = $row;
			if ( empty( $staged_row['grant_account_key'] ) ) {
				$staged_row['grant_account_key'] = $grant_key;
				$rows_rewritten++;
			}
			$staged_line = wp_json_encode( $staged_row, JSON_UNESCAPED_SLASHES ) . "\n";
			fwrite( $staged_handle, $staged_line );
			$staged_hash = hash( 'sha256', $staged_line );
			$staged_identities[] = $staged_row['record_id'] . '|' . $staged_row['event_uuid'];
			$staged_rows++;
			$offsets_ok = $offsets_ok && $source_offset >= 0 && $staged_offset >= 0;
			$hashes_ok = $hashes_ok && preg_match( '/^[a-f0-9]{64}$/', $source_hash ) && preg_match( '/^[a-f0-9]{64}$/', $staged_hash );
			$source_offset += strlen( $source_line );
			$staged_offset += strlen( $staged_line );
		}
		fflush( $source_handle );
		fflush( $staged_handle );
		fclose( $source_handle );
		fclose( $staged_handle );
		return array(
			'ok' => $source_rows === 2 && $staged_rows === 2,
			'source_file' => $source,
			'staged_file' => $staged,
			'source_rows' => $source_rows,
			'staged_rows' => $staged_rows,
			'identity_count' => count( array_unique( $source_identities ) ),
			'staged_identity_count' => count( array_unique( $staged_identities ) ),
			'rows_rewritten' => $rows_rewritten,
			'offsets_ok' => $offsets_ok,
			'hashes_ok' => $hashes_ok,
		);
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Context_Bank_Channel_Archive_ACL_Rewrite';
	return $probes;
} );
